<?php

declare(strict_types=1);

/** Schedule, reschedule and cancel maintenance windows without rewriting the past. */

namespace App\Actions\Incidents;

use App\Exceptions\MaintenanceWindowRejected;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Past time is immutable: windows start now or later, change only before they start, and an ongoing window can
 * only end early. Managing maintenance is managing the monitor, so MonitorPolicy::update authorizes every change
 * under the Team → Monitor → window locks.
 */
final readonly class ManageMaintenanceWindows
{
    /** @var int Maximum characters of a maintenance reason, shared with future request validation. */
    public const int REASON_MAX_LENGTH = 1000;

    /** @param TenantContext $context Explicit tenant boundary for each change. */
    public function __construct(private TenantContext $context)
    {
    }

    /**
     * @param User $actor Manager scheduling the window.
     * @param int $teamId Active team.
     * @param int $monitorId Monitor of that team.
     * @param CarbonImmutable|null $startsAt Inclusive start; null starts now.
     * @param CarbonImmutable $endsAt Exclusive end after the start.
     * @param string $reason Explanation; surrounding whitespace is removed.
     * @return MaintenanceWindow Scheduled window.
     * @throws MaintenanceWindowRejected When the reason is invalid.
     */
    public function schedule(User $actor, int $teamId, int $monitorId, ?CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $reason): MaintenanceWindow
    {
        $text = $this->reason($reason);

        return $this->locked($teamId, $monitorId, $actor, function (Monitor $monitor, CarbonImmutable $now) use ($actor, $startsAt, $endsAt, $text): MaintenanceWindow {
            [$start, $end] = $this->interval($startsAt, $endsAt, $now);

            $window = new MaintenanceWindow();
            $window->forceFill([
                'monitor_id' => $monitor->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'reason' => $text,
                'created_by' => $actor->id,
            ])->save();

            return $window;
        });
    }

    /**
     * @param User $actor Manager changing the window.
     * @param int $teamId Active team.
     * @param int $windowId Window of that team that has not started yet.
     * @param CarbonImmutable|null $startsAt Replacement start; null starts now.
     * @param CarbonImmutable $endsAt Replacement exclusive end.
     * @param string $reason Replacement explanation.
     * @return MaintenanceWindow Updated window.
     * @throws MaintenanceWindowRejected When the reason is invalid.
     */
    public function reschedule(User $actor, int $teamId, int $windowId, ?CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $reason): MaintenanceWindow
    {
        $text = $this->reason($reason);

        return $this->lockedWindow($teamId, $windowId, $actor, function (MaintenanceWindow $window, CarbonImmutable $now) use ($startsAt, $endsAt, $text): MaintenanceWindow {
            if (!$window->starts_at->isAfter($now)) {
                throw new MaintenanceWindowRejected('A maintenance window can only be changed before it starts.');
            }

            [$start, $end] = $this->interval($startsAt, $endsAt, $now);
            $window->forceFill(['starts_at' => $start, 'ends_at' => $end, 'reason' => $text])->save();

            return $window;
        });
    }

    /**
     * A future window is deleted; an ongoing window ends now; a finished window is history and is rejected.
     *
     * @param User $actor Manager cancelling the window.
     * @param int $teamId Active team.
     * @param int $windowId Window of that team.
     * @return MaintenanceWindow|null Shortened window, or null when a future window was deleted.
     */
    public function cancel(User $actor, int $teamId, int $windowId): ?MaintenanceWindow
    {
        return $this->lockedWindow($teamId, $windowId, $actor, function (MaintenanceWindow $window, CarbonImmutable $now): ?MaintenanceWindow {
            if ($window->starts_at->isAfter($now)) {
                $window->delete();

                return null;
            }

            if (!$window->ends_at->isAfter($now)) {
                throw new MaintenanceWindowRejected('A finished maintenance window cannot be changed.');
            }

            $window->forceFill(['ends_at' => $now->max($window->starts_at->addSecond())])->save();

            return $window;
        });
    }

    /**
     * @param CarbonImmutable|null $startsAt Requested start; null means now.
     * @param CarbonImmutable $endsAt Requested exclusive end.
     * @param CarbonImmutable $now Current UTC instant of the change.
     * @return array{CarbonImmutable, CarbonImmutable} Validated UTC start and end.
     * @throws MaintenanceWindowRejected When the start is in the past or the end is not after the start.
     */
    private function interval(?CarbonImmutable $startsAt, CarbonImmutable $endsAt, CarbonImmutable $now): array
    {
        $start = ($startsAt ?? $now)->utc();
        $end = $endsAt->utc();

        if ($start->isBefore($now)) {
            throw new MaintenanceWindowRejected('A maintenance window cannot start in the past.');
        }

        if (!$end->isAfter($start)) {
            throw new MaintenanceWindowRejected('A maintenance window must end after it starts.');
        }

        return [$start, $end];
    }

    /**
     * @param string $value Submitted reason.
     * @return string Trimmed nonblank reason within the shared limit.
     * @throws MaintenanceWindowRejected When the reason is blank or too long.
     */
    private function reason(string $value): string
    {
        $text = trim($value);

        if ($text === '' || mb_strlen($text) > self::REASON_MAX_LENGTH) {
            throw new MaintenanceWindowRejected('A maintenance reason must be between 1 and '.self::REASON_MAX_LENGTH.' characters.');
        }

        return $text;
    }

    /**
     * A missing or foreign window raises ModelNotFoundException; an insufficient role AuthorizationException.
     *
     * @template TResult
     * @param int $teamId Active team.
     * @param int $windowId Window of that team.
     * @param User $actor Manager re-authorized under the locks.
     * @param Closure(MaintenanceWindow, CarbonImmutable): TResult $operation Change applied to the locked window.
     * @return TResult Operation result.
     */
    private function lockedWindow(int $teamId, int $windowId, User $actor, Closure $operation): mixed
    {
        return $this->context->run($teamId, function () use ($teamId, $windowId, $actor, $operation): mixed {
            $monitorId = MaintenanceWindow::query()->findOrFail($windowId, ['id', 'monitor_id'])->monitor_id;

            return $this->locked($teamId, $monitorId, $actor, function (Monitor $monitor, CarbonImmutable $now) use ($windowId, $operation): mixed {
                $window = MaintenanceWindow::query()->where('monitor_id', $monitor->id)->lockForUpdate()->findOrFail($windowId);

                return $operation($window, $now);
            });
        });
    }

    /**
     * A missing or foreign monitor raises ModelNotFoundException; an insufficient role AuthorizationException.
     *
     * @template TResult
     * @param int $teamId Active team.
     * @param int $monitorId Monitor of that team.
     * @param User $actor Manager re-authorized under the locks.
     * @param Closure(Monitor, CarbonImmutable): TResult $operation Change applied while the monitor is locked.
     * @return TResult Operation result.
     */
    private function locked(int $teamId, int $monitorId, User $actor, Closure $operation): mixed
    {
        return $this->context->run($teamId, fn (): mixed => DB::transaction(function () use ($teamId, $monitorId, $actor, $operation): mixed {
            Team::query()->lockForUpdate()->findOrFail($teamId);
            $monitor = Monitor::query()->where('team_id', $teamId)->lockForUpdate()->findOrFail($monitorId);
            Gate::forUser($actor)->authorize('update', $monitor);

            return $operation($monitor, CarbonImmutable::now('UTC'));
        }));
    }
}
