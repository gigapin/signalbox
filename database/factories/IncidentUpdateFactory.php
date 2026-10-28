<?php

declare(strict_types=1);

/** Build synthetic private timeline entries only within an explicitly established tenant context. */

namespace Database\Factories;

use App\Enums\Incidents\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IncidentUpdate> Private system entries unless a test assigns an author. */
final class IncidentUpdateFactory extends Factory
{
    /** @return array<string, mixed> System entry recording the investigating status. */
    public function definition(): array
    {
        return [
            'team_id' => app(TenantContext::class)->requireTeamId(),
            'incident_id' => Incident::factory(),
            'author_id' => null,
            'status' => IncidentStatus::Investigating,
            'message' => 'Synthetic timeline entry.',
        ];
    }
}
