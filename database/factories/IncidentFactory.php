<?php

declare(strict_types=1);

/** Build synthetic incidents only within an explicitly established tenant context. */

namespace Database\Factories;

use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Monitoring\CheckError;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incident> Open incidents by default; lifecycle states follow the allowed transitions. */
final class IncidentFactory extends Factory
{
    /** @return array<string, mixed> Investigating incident confirmed three minutes after its first failure. */
    public function definition(): array
    {
        $startedAt = CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC');

        return [
            'team_id' => app(TenantContext::class)->requireTeamId(),
            'monitor_id' => Monitor::factory(),
            'status' => IncidentStatus::Investigating,
            'severity' => IncidentSeverity::forCause(CheckError::Timeout),
            'cause' => CheckError::Timeout,
            'started_at' => $startedAt,
            'confirmed_at' => $startedAt->addMinutes(3),
        ];
    }

    /**
     * Resolution is applied after creation because new incidents must open in the investigating status.
     *
     * @param int $afterSeconds Resolution delay after confirmation, in seconds.
     * @return static Factory clone producing resolved incidents with a coherent duration.
     */
    public function resolved(int $afterSeconds = 600): static
    {
        return $this->afterCreating(static function (Incident $incident) use ($afterSeconds): void {
            $resolvedAt = $incident->confirmed_at->addSeconds($afterSeconds);

            $incident->forceFill([
                'status' => IncidentStatus::Resolved,
                'resolved_at' => $resolvedAt,
                'duration_seconds' => (int) $incident->started_at->diffInSeconds($resolvedAt),
            ])->save();
        });
    }

    /**
     * @param User $user Responsible user; membership is the caller's responsibility in fixtures.
     * @return static Factory clone acknowledged one minute after confirmation.
     */
    public function acknowledgedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'acknowledged_by' => $user->id,
            'acknowledged_at' => CarbonImmutable::parse($attributes['confirmed_at'])->addMinute(),
        ]);
    }
}
