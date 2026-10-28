<?php

declare(strict_types=1);

/** Verify manual incident changes, their authorization, timeline entries and interaction with automatic reopening. */

use App\Actions\Incidents\ManageIncidents;
use App\Data\Incidents\SlotVerdict;
use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Incidents\SlotVerdictKind;
use App\Enums\Monitoring\CheckError;
use App\Events\Incidents\IncidentResolved;
use App\Exceptions\IncidentChangeRejected;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Team;
use App\Models\User;
use App\Services\Incidents\IncidentAutomation;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires the isolated php-test PostgreSQL service.');
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 11:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Owning team.
 * @param bool $resolved Whether the incident is already resolved.
 * @return Incident Incident confirmed at 10:03 UTC.
 */
function manualIncident(Team $team, bool $resolved = false): Incident
{
    return app(TenantContext::class)->run($team->id, function () use ($resolved): Incident {
        $factory = Incident::factory();

        return ($resolved ? $factory->resolved() : $factory)->create()->fresh();
    });
}

/**
 * @param Team $team Team receiving the member.
 * @param string $role Persisted membership role.
 * @return User New member with that role.
 */
function incidentMember(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->users()->attach($user->id, ['role' => $role]);

    return $user;
}

/**
 * @param Incident $incident Incident to inspect.
 * @return list<array{string, string, int|null}> Timeline entries as status, message and author.
 */
function manualTimeline(Incident $incident): array
{
    return app(TenantContext::class)->run($incident->team_id, fn (): array => $incident->updates()->get()
        ->map(static fn (IncidentUpdate $update): array => [$update->status->value, $update->message, $update->author_id])->all());
}

it('lets owners, admins and members acknowledge while viewers are denied', function (string $role, bool $allowed): void {
    $team = Team::factory()->create();
    $incident = manualIncident($team);
    $actor = $role === 'owner' ? $team->owner : incidentMember($team, $role);
    $acknowledge = fn () => app(ManageIncidents::class)->acknowledge($actor, $team->id, $incident->id);

    if (!$allowed) {
        expect($acknowledge)->toThrow(AuthorizationException::class)
            ->and(manualTimeline($incident))->toBe([]);

        return;
    }

    expect($acknowledge()->acknowledged_by)->toBe($actor->id)
        ->and(manualTimeline($incident))->toBe([['investigating', 'Responsibility taken.', $actor->id]]);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'member' => ['member', true],
    'viewer' => ['viewer', false],
]);

it('hides incidents from non-members and from other teams', function (): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $incident = manualIncident($team);
    $foreignUpdate = app(TenantContext::class)->run($team->id, fn () => IncidentUpdate::factory()->create(['incident_id' => $incident->id]));
    $actions = app(ManageIncidents::class);

    expect(fn () => $actions->acknowledge(User::factory()->create(), $team->id, $incident->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $actions->acknowledge($other->owner, $other->id, $incident->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $actions->publish($other->owner, $other->id, $foreignUpdate->id))->toThrow(ModelNotFoundException::class)
        ->and(manualIncident($team)->acknowledged_by)->toBeNull();
});

it('keeps acknowledgement idempotent, records a takeover and rejects resolved incidents', function (): void {
    $team = Team::factory()->create();
    $incident = manualIncident($team);
    $member = incidentMember($team, 'member');
    $actions = app(ManageIncidents::class);

    $actions->acknowledge($team->owner, $team->id, $incident->id);
    $actions->acknowledge($team->owner, $team->id, $incident->id);
    $actions->acknowledge($member, $team->id, $incident->id);

    expect(manualTimeline($incident))->toBe([
        ['investigating', 'Responsibility taken.', $team->owner->id],
        ['investigating', 'Responsibility reassigned.', $member->id],
    ])->and(fn () => $actions->acknowledge($team->owner, $team->id, manualIncident($team, true)->id))->toThrow(IncidentChangeRejected::class);
});

it('posts updates across open statuses and resolves manually with an event', function (): void {
    Event::fake([IncidentResolved::class]);
    $team = Team::factory()->create();
    $incident = manualIncident($team);
    $actions = app(ManageIncidents::class);

    $actions->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Investigating, '  Looking into it.  ');
    $actions->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Identified, 'Database pool exhausted.');
    $actions->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Monitoring, 'Pool size increased.');
    $resolution = $actions->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Resolved, 'Stable for an hour.');
    $actions->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Resolved, 'Postmortem scheduled.');
    $resolved = app(TenantContext::class)->run($team->id, fn (): Incident => Incident::query()->findOrFail($incident->id));

    expect($resolution->status)->toBe(IncidentStatus::Resolved)
        ->and($resolution->message)->toBe('Stable for an hour.')
        ->and($resolved->status)->toBe(IncidentStatus::Resolved)
        ->and($resolved->resolved_at?->equalTo(CarbonImmutable::now('UTC')))->toBeTrue()
        ->and($resolved->duration_seconds)->toBe(3600)
        ->and(array_column(manualTimeline($incident), 0))->toBe(['investigating', 'identified', 'monitoring', 'resolved', 'resolved'])
        ->and(manualTimeline($incident)[0][1])->toBe('Looking into it.');
    Event::assertDispatchedTimes(IncidentResolved::class, 1);
});

it('rejects manual reopening and invalid messages', function (string $case): void {
    $team = Team::factory()->create();
    $actions = app(ManageIncidents::class);
    [$incident, $status, $message] = match ($case) {
        'reopen' => [manualIncident($team, true), IncidentStatus::Investigating, 'Reopening.'],
        'blank' => [manualIncident($team), IncidentStatus::Identified, "  \n "],
        default => [manualIncident($team), IncidentStatus::Identified, str_repeat('x', ManageIncidents::TEXT_MAX_LENGTH + 1)],
    };

    expect(fn () => $actions->postUpdate($team->owner, $team->id, $incident->id, $status, $message))->toThrow(IncidentChangeRejected::class)
        ->and(manualTimeline($incident))->toBe([]);
})->with(['reopen', 'blank', 'too long']);

it('reopens a manually resolved incident when the failure is confirmed again', function (): void {
    $team = Team::factory()->create();
    $incident = manualIncident($team);
    app(ManageIncidents::class)->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Resolved, 'Looks fixed.');
    $verdict = new SlotVerdict(SlotVerdictKind::Confirmed, CarbonImmutable::now('UTC'), CheckError::Timeout);

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinutes(5));
    app(TenantContext::class)->run($team->id, fn () => DB::transaction(fn () => app(IncidentAutomation::class)->apply($incident->monitor()->firstOrFail(), $verdict, CarbonImmutable::now('UTC'))));
    $reopened = app(TenantContext::class)->run($team->id, fn (): Incident => Incident::query()->findOrFail($incident->id));

    expect($reopened->status)->toBe(IncidentStatus::Investigating)
        ->and($reopened->reopened_count)->toBe(1)
        ->and($reopened->resolved_at)->toBeNull()
        ->and(app(TenantContext::class)->run($team->id, fn (): int => Incident::query()->count()))->toBe(1);
});

it('records severity changes and declared causes without copying the note into the timeline', function (): void {
    $team = Team::factory()->create();
    $incident = manualIncident($team);
    $actions = app(ManageIncidents::class);

    $actions->changeSeverity($team->owner, $team->id, $incident->id, IncidentSeverity::Minor);
    $actions->changeSeverity($team->owner, $team->id, $incident->id, IncidentSeverity::Minor);
    $declared = $actions->declareRootCause($team->owner, $team->id, $incident->id, '  Expired database credentials.  ');
    $actions->declareRootCause($team->owner, $team->id, $incident->id, 'Expired database credentials.');
    $cleared = $actions->declareRootCause($team->owner, $team->id, $incident->id, ' ');

    expect($declared->root_cause_note)->toBe('Expired database credentials.')
        ->and($cleared->root_cause_note)->toBeNull()
        ->and($cleared->severity)->toBe(IncidentSeverity::Minor)
        ->and(array_column(manualTimeline($incident), 1))->toBe([
            'Severity changed from critical to minor.',
            'Root cause declared.',
            'Root cause cleared.',
        ])
        ->and(fn () => $actions->declareRootCause($team->owner, $team->id, $incident->id, str_repeat('x', ManageIncidents::TEXT_MAX_LENGTH + 1)))
        ->toThrow(IncidentChangeRejected::class);
});

it('publishes a timeline entry exactly once', function (): void {
    $team = Team::factory()->create();
    $incident = manualIncident($team);
    $actions = app(ManageIncidents::class);
    $entry = $actions->postUpdate($team->owner, $team->id, $incident->id, IncidentStatus::Identified, 'Upstream provider outage.');

    $published = $actions->publish($team->owner, $team->id, $entry->id);
    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addMinute());
    $again = $actions->publish($team->owner, $team->id, $entry->id);

    expect($published->is_public)->toBeTrue()
        ->and($again->published_at?->equalTo($published->published_at))->toBeTrue()
        ->and(fn () => $actions->publish(incidentMember($team, 'viewer'), $team->id, $entry->id))->toThrow(AuthorizationException::class);
});
