<?php

declare(strict_types=1);

/** Verify incident and timeline persistence, lifecycle guards, constraints and tenant boundaries on PostgreSQL. */

use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Monitoring\CheckError;
use App\Exceptions\MissingTenantContext;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires the isolated php-test PostgreSQL service.');
    }
});

/**
 * @param Team $team Owning tenant established for the callback.
 * @param Closure(): mixed $callback Work executed inside the tenant context.
 * @return mixed Callback result.
 */
function asIncidentTeam(Team $team, Closure $callback): mixed
{
    return app(TenantContext::class)->run($team->id, $callback);
}

/**
 * @param Closure(): mixed $statement Statement expected to violate a database rule.
 * Each attempt runs in its own savepoint so the outer test transaction stays usable.
 */
function expectIncidentRejection(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
}

it('round trips an opened incident with UTC instants, defaults and tenant relations', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    asIncidentTeam($team, function () use ($team, $user): void {
        $incident = Incident::factory()->acknowledgedBy($user)->create([
            'started_at' => CarbonImmutable::parse('2026-10-28 10:00:00', 'Europe/Rome'),
        ]);
        $fresh = $incident->fresh();

        expect($fresh->team_id)->toBe($team->id)
            ->and($fresh->status)->toBe(IncidentStatus::Investigating)
            ->and($fresh->severity)->toBe(IncidentSeverity::Critical)
            ->and($fresh->cause)->toBe(CheckError::Timeout)
            ->and($fresh->started_at->utc()->toIso8601String())->toBe('2026-10-28T09:00:00+00:00')
            ->and($fresh->acknowledgedBy?->is($user))->toBeTrue()
            ->and($fresh->resolved_at)->toBeNull()
            ->and($fresh->duration_seconds)->toBeNull()
            ->and($fresh->reopened_count)->toBe(0)
            ->and($fresh->last_reopened_at)->toBeNull()
            ->and($fresh->monitor->incidents->modelKeys())->toBe([$incident->id]);
    });
});

it('requires a tenant context and hides incidents and entries from other teams', function (): void {
    $owner = Team::factory()->create();
    $other = Team::factory()->create();
    $update = asIncidentTeam($owner, fn () => IncidentUpdate::factory()->create());

    expect(fn () => Incident::query()->count())->toThrow(MissingTenantContext::class)
        ->and(fn () => Incident::factory()->make())->toThrow(MissingTenantContext::class);

    asIncidentTeam($other, function () use ($update): void {
        expect(Incident::query()->count())->toBe(0)
            ->and(IncidentUpdate::query()->count())->toBe(0)
            ->and(IncidentUpdate::query()->find($update->id))->toBeNull();
    });
});

it('rejects incidents and entries that reference another team', function (): void {
    $owner = Team::factory()->create();
    $other = Team::factory()->create();
    $incident = asIncidentTeam($owner, fn () => Incident::factory()->create());

    asIncidentTeam($other, function () use ($incident): void {
        expectIncidentRejection(fn () => Incident::factory()->create(['monitor_id' => $incident->monitor_id]));
        expectIncidentRejection(fn () => IncidentUpdate::factory()->create(['incident_id' => $incident->id]));
    });
});

it('admits a single unresolved incident per monitor', function (): void {
    $team = Team::factory()->create();

    asIncidentTeam($team, function (): void {
        $monitor = Monitor::factory()->create();
        Incident::factory()->resolved()->create(['monitor_id' => $monitor->id]);
        Incident::factory()->create(['monitor_id' => $monitor->id]);

        expectIncidentRejection(fn () => Incident::factory()->create(['monitor_id' => $monitor->id]));

        // Another monitor of the same team keeps its own independent open incident.
        Incident::factory()->create();

        expect(Incident::query()->count())->toBe(3)
            ->and(Incident::query()->whereNull('resolved_at')->count())->toBe(2);
    });
});

it('enforces lifecycle coherence in PostgreSQL', function (array $changes): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    asIncidentTeam($team, function () use ($changes, $user): void {
        $incident = Incident::factory()->create();
        $changes = array_map(static fn (mixed $value): mixed => $value === 'user' ? $user->id : $value, $changes);

        expectIncidentRejection(fn () => DB::table('incidents')->where('id', $incident->id)->update($changes));
    });
})->with([
    'unknown status' => [['status' => 'suspected']],
    'unknown severity' => [['severity' => 'catastrophic']],
    'unknown cause' => [['cause' => 'gremlins']],
    'blank note' => [['root_cause_note' => '   ']],
    'confirmed before start' => [['confirmed_at' => '2026-10-28 09:59:59+00']],
    'resolved status without instant' => [['status' => 'resolved']],
    'resolution instant while open' => [['resolved_at' => '2026-10-28 11:00:00+00', 'duration_seconds' => 3600]],
    'resolution without duration' => [['status' => 'resolved', 'resolved_at' => '2026-10-28 11:00:00+00']],
    'resolved before confirmation' => [['status' => 'resolved', 'resolved_at' => '2026-10-28 10:01:00+00', 'duration_seconds' => 60]],
    'negative duration' => [['status' => 'resolved', 'resolved_at' => '2026-10-28 11:00:00+00', 'duration_seconds' => -1]],
    'responsible without instant' => [['acknowledged_by' => 'user']],
    'negative reopen count' => [['reopened_count' => -1]],
    'reopen count without instant' => [['reopened_count' => 1]],
    'reopen instant without count' => [['last_reopened_at' => '2026-10-28 11:00:00+00']],
    'reopened before confirmation' => [['reopened_count' => 1, 'last_reopened_at' => '2026-10-28 10:01:00+00']],
]);

it('declares database value lists identical to the enums', function (string $constraint, array $values): void {
    $definition = DB::scalar('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$constraint]);

    preg_match_all("/'([a-z_]+)'::/", (string) $definition, $matches);

    expect($matches[1])->toEqualCanonicalizing($values);
})->with([
    'incident status' => ['incidents_status_check', array_column(IncidentStatus::cases(), 'value')],
    'entry status' => ['incident_updates_status_check', array_column(IncidentStatus::cases(), 'value')],
    'severity' => ['incidents_severity_check', array_column(IncidentSeverity::cases(), 'value')],
    'cause' => ['incidents_cause_check', array_column(CheckError::cases(), 'value')],
]);

it('rejects model lifecycle changes outside the status graph', function (): void {
    $team = Team::factory()->create();

    asIncidentTeam($team, function (): void {
        expect(fn () => Incident::factory()->create(['status' => IncidentStatus::Monitoring]))->toThrow(LogicException::class);

        $incident = Incident::factory()->resolved()->create();

        $incident->status = IncidentStatus::Monitoring;
        expect(fn () => $incident->save())->toThrow(LogicException::class);

        // Reopening without counting it would let the record and the timeline disagree.
        $incident->status = IncidentStatus::Investigating;
        $incident->resolved_at = null;
        $incident->duration_seconds = null;
        expect(fn () => $incident->save())->toThrow(LogicException::class);
    });
});

it('moves freely between open statuses, resolves and reopens with a counted reopening', function (): void {
    $team = Team::factory()->create();

    asIncidentTeam($team, function (): void {
        $incident = Incident::factory()->create();

        foreach ([IncidentStatus::Identified, IncidentStatus::Monitoring, IncidentStatus::Investigating, IncidentStatus::Monitoring] as $status) {
            $incident->status = $status;
            $incident->save();
        }

        $resolvedAt = $incident->confirmed_at->addMinutes(10);
        $incident->forceFill([
            'status' => IncidentStatus::Resolved,
            'resolved_at' => $resolvedAt,
            'duration_seconds' => (int) $incident->started_at->diffInSeconds($resolvedAt),
        ])->save();

        $incident->forceFill([
            'status' => IncidentStatus::Investigating,
            'resolved_at' => null,
            'duration_seconds' => null,
            'reopened_count' => 1,
            'last_reopened_at' => $resolvedAt->addMinutes(5),
        ])->save();

        $fresh = $incident->fresh();

        expect($fresh->status)->toBe(IncidentStatus::Investigating)
            ->and($fresh->reopened_count)->toBe(1)
            ->and($fresh->resolved_at)->toBeNull()
            ->and($fresh->last_reopened_at?->equalTo($resolvedAt->addMinutes(5)))->toBeTrue();
    });
});

it('keeps timeline entries append-only while admitting a single publication', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    asIncidentTeam($team, function () use ($user): void {
        $update = IncidentUpdate::factory()->create();

        expect($update->fresh()->is_public)->toBeFalse()
            ->and($update->fresh()->published_at)->toBeNull();

        $update->message = 'Rewritten history.';
        expect(fn () => $update->save())->toThrow(LogicException::class);
        $update->refresh();

        expectIncidentRejection(fn () => DB::table('incident_updates')->where('id', $update->id)->update(['message' => 'Rewritten history.']));
        expectIncidentRejection(fn () => DB::table('incident_updates')->where('id', $update->id)->update(['author_id' => $user->id]));
        expectIncidentRejection(fn () => DB::table('incident_updates')->where('id', $update->id)->update(['is_public' => true]));
        expectIncidentRejection(fn () => DB::table('incident_updates')->where('id', $update->id)->update([
            'is_public' => true,
            'published_at' => now(),
            'message' => 'Published and rewritten.',
        ]));

        $update->forceFill(['is_public' => true, 'published_at' => CarbonImmutable::now()])->save();
        expect($update->fresh()->is_public)->toBeTrue();

        $update->forceFill(['is_public' => false, 'published_at' => null]);
        expect(fn () => $update->save())->toThrow(LogicException::class);
        expectIncidentRejection(fn () => DB::table('incident_updates')->where('id', $update->id)->update(['is_public' => false, 'published_at' => null]));
        expectIncidentRejection(fn () => DB::table('incident_updates')->where('id', $update->id)->update(['published_at' => now()->addMinute()]));
    });
});

it('rejects blank timeline messages and orders entries chronologically', function (): void {
    $team = Team::factory()->create();

    asIncidentTeam($team, function (): void {
        $incident = Incident::factory()->create();

        expectIncidentRejection(fn () => IncidentUpdate::factory()->create(['incident_id' => $incident->id, 'message' => ' ']));

        $later = IncidentUpdate::factory()->create(['incident_id' => $incident->id, 'created_at' => '2026-10-28 10:10:00+00']);
        $earlier = IncidentUpdate::factory()->create(['incident_id' => $incident->id, 'created_at' => '2026-10-28 10:05:00+00']);

        expect($incident->updates->modelKeys())->toBe([$earlier->id, $later->id]);
    });
});

it('removes incidents and their timeline with the monitor and keeps them when the responsible user is deleted', function (): void {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    asIncidentTeam($team, function () use ($user): void {
        $kept = Incident::factory()->acknowledgedBy($user)->create();
        IncidentUpdate::factory()->create(['incident_id' => $kept->id, 'author_id' => $user->id]);
        $removed = Incident::factory()->create();
        IncidentUpdate::factory()->create(['incident_id' => $removed->id]);

        $removed->monitor->delete();
        $user->delete();

        expect(Incident::query()->pluck('id')->all())->toBe([$kept->id])
            ->and(IncidentUpdate::query()->count())->toBe(1)
            ->and($kept->fresh()->acknowledged_by)->toBeNull()
            ->and($kept->fresh()->acknowledged_at)->not->toBeNull()
            ->and(IncidentUpdate::query()->sole()->author_id)->toBeNull();
    });
});
