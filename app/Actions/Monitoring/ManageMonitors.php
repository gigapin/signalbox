<?php

declare(strict_types=1);

/** Create, change, pause, resume and delete monitors under the team quota lock. */

namespace App\Actions\Monitoring;

use App\Data\Monitoring\MonitorInput;
use App\Enums\Monitoring\MonitorStatus;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Monitoring\MonitorConfigurationMapper;
use App\Services\Monitoring\MonitorInputValidator;
use App\Services\Monitoring\MonitorLimits;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Every transition locks the Team row, then the Monitor row, re-runs MonitorPolicy and re-validates
 * the explicit patch against fresh state, so early HTTP checks never authorize a stale write.
 * Scheduling slots are not computed here: a null next_check_at asks the M3 dispatcher to initialize one.
 */
final readonly class ManageMonitors
{
    /**
     * @param MonitorInputValidator $validator Shared network-free input validation, including save-time SSRF checks.
     * @param MonitorLimits $limits Central plan quota and cadence rules.
     * @param TenantContext $context Tenant unit of work for the explicit team.
     */
    public function __construct(
        private MonitorInputValidator $validator,
        private MonitorLimits $limits,
        private TenantContext $context,
    ) {
    }

    /**
     * @param User $actor Verified member performing the creation.
     * @param int $teamId Team receiving the monitor.
     * @param array<string, mixed> $patch Explicit submitted fields, never a serialized model.
     * @return Monitor Persisted monitor with unknown status and no scheduled slot.
     * @throws AuthorizationException When the actor's current role cannot manage monitors.
     * @throws ValidationException When input, quota or plan cadence is refused.
     */
    public function create(User $actor, int $teamId, #[\SensitiveParameter] array $patch): Monitor
    {
        return $this->locked($teamId, function (Team $team) use ($actor, $patch): Monitor {
            Gate::forUser($actor)->authorize('create', Monitor::class);

            $input = $this->validator->validate($patch)->data;

            $this->limits->assertCanCreate($team);
            $this->limits->assertInterval($team, $input->intervalSeconds);

            $monitor = new Monitor();
            $this->apply($monitor, $input);
            $monitor->save();

            return $monitor;
        });
    }

    /**
     * Configuration changes reset the observation summary of the old target; type or interval changes
     * also drop the scheduled slot so it is recalculated without bursts.
     *
     * @param User $actor Verified member performing the change.
     * @param int $teamId Owning team.
     * @param int $monitorId Monitor of that team.
     * @param array<string, mixed> $patch Explicit normalized fields; omitted secrets keep their stored values.
     * @return Monitor Saved monitor.
     * @throws AuthorizationException When the actor's current role cannot manage monitors.
     * @throws ValidationException When the merged input or a faster interval is refused.
     */
    public function update(User $actor, int $teamId, int $monitorId, #[\SensitiveParameter] array $patch): Monitor
    {
        return $this->locked($teamId, function (Team $team) use ($actor, $monitorId, $patch): Monitor {
            $monitor = $this->lockMonitor($team, $monitorId);
            Gate::forUser($actor)->authorize('update', $monitor);

            $before = MonitorInput::fromMonitor($monitor);
            $after = $this->validator->validate($patch, $before)->data;

            // A cadence kept unchanged after a downgrade is adjusted by plan reconciliation (M9), not by edits.
            if ($after->intervalSeconds !== $before->intervalSeconds) {
                $this->limits->assertInterval($team, $after->intervalSeconds);
            }

            $this->apply($monitor, $after);

            if (MonitorConfigurationMapper::attributes($after->configuration) != MonitorConfigurationMapper::attributes($before->configuration)) {
                $monitor->forceFill([
                    'last_status' => MonitorStatus::Unknown,
                    'consecutive_failures' => 0,
                    'last_checked_at' => null,
                    'ssl_expires_at' => null,
                ]);
            }

            if (
                $after->intervalSeconds !== $before->intervalSeconds
                || $after->configuration->type() !== $before->configuration->type()
            ) {
                $monitor->forceFill(['next_check_at' => null]);
            }

            $monitor->save();

            return $monitor;
        });
    }

    /**
     * @param User $actor Verified member performing the deletion.
     * @param int $teamId Owning team.
     * @param int $monitorId Monitor of that team; its quota slot is released immediately.
     * @throws AuthorizationException When the actor's current role cannot manage monitors.
     */
    public function delete(User $actor, int $teamId, int $monitorId): void
    {
        $this->locked($teamId, function (Team $team) use ($actor, $monitorId): void {
            $monitor = $this->lockMonitor($team, $monitorId);
            Gate::forUser($actor)->authorize('delete', $monitor);

            $monitor->delete();
        });
    }

    /**
     * Idempotent voluntary pause; plan suspension is left unchanged and the monitor keeps its quota slot.
     *
     * @param User $actor Verified member performing the pause.
     * @param int $teamId Owning team.
     * @param int $monitorId Monitor of that team.
     * @return Monitor Paused monitor without a scheduled slot.
     * @throws AuthorizationException When the actor's current role cannot manage monitors.
     */
    public function pause(User $actor, int $teamId, int $monitorId): Monitor
    {
        return $this->locked($teamId, function (Team $team) use ($actor, $monitorId): Monitor {
            $monitor = $this->lockMonitor($team, $monitorId);
            Gate::forUser($actor)->authorize('pause', $monitor);

            if (!$monitor->is_paused) {
                $monitor->forceFill(['is_paused' => true, 'next_check_at' => null])->save();
            }

            return $monitor;
        });
    }

    /**
     * Idempotent removal of the voluntary pause only. A plan suspension stays in place (cleared by M9.05),
     * and resuming requires the monitor's deterministic quota position and cadence to fit the current plan.
     *
     * @param User $actor Verified member performing the resume.
     * @param int $teamId Owning team.
     * @param int $monitorId Monitor of that team.
     * @return Monitor Monitor without voluntary pause, awaiting a new slot.
     * @throws AuthorizationException When the actor's current role cannot manage monitors.
     * @throws ValidationException When the monitor is beyond quota or its interval is below the plan minimum.
     */
    public function resume(User $actor, int $teamId, int $monitorId): Monitor
    {
        return $this->locked($teamId, function (Team $team) use ($actor, $monitorId): Monitor {
            $monitor = $this->lockMonitor($team, $monitorId);
            Gate::forUser($actor)->authorize('resume', $monitor);

            if (!$monitor->is_paused) {
                return $monitor;
            }

            if (!$this->limits->isWithinQuota($team, $monitor)) {
                throw ValidationException::withMessages([
                    'monitor' => 'This monitor is beyond your plan quota. Delete other monitors or upgrade before resuming it.',
                ]);
            }

            $this->limits->assertInterval($team, $monitor->interval_seconds);

            $monitor->forceFill(['is_paused' => false, 'next_check_at' => null])->save();

            return $monitor;
        });
    }

    /**
     * @template TResult
     * @param int $teamId Team to lock and use as tenant context.
     * @param \Closure(Team): TResult $operation Work performed while holding the Team row lock.
     * @return TResult Operation result after commit.
     */
    private function locked(int $teamId, \Closure $operation): mixed
    {
        return $this->context->run($teamId, fn (): mixed => DB::transaction(
            fn (): mixed => $operation(Team::query()->lockForUpdate()->findOrFail($teamId)),
        ));
    }

    /**
     * @param Team $team Locked owning team.
     * @param int $monitorId Requested monitor; another team's ID is reported as missing.
     * @return Monitor Row-locked monitor.
     */
    private function lockMonitor(Team $team, int $monitorId): Monitor
    {
        return Monitor::query()->where('team_id', $team->id)->lockForUpdate()->findOrFail($monitorId);
    }

    /**
     * @param Monitor $monitor New or locked existing monitor.
     * @param MonitorInput $input Validated complete definition.
     */
    private function apply(Monitor $monitor, MonitorInput $input): void
    {
        $monitor->forceFill([
            'name' => $input->name,
            'interval_seconds' => $input->intervalSeconds,
            'timeout_ms' => $input->timeoutMs,
            'confirmation_threshold' => $input->confirmationThreshold,
            'regions_json' => $input->regions,
        ]);

        $monitor->applyConfiguration($input->configuration);
    }
}
