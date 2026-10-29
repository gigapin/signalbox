<?php

declare(strict_types=1);

/** Exercise incident pages, timeline changes and maintenance routes through HTTP on PostgreSQL. */

use App\Data\Incidents\IncidentListFilter;
use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Monitoring\CheckError;
use App\Events\Incidents\IncidentResolved;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Incidents\IncidentListing;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Owning team.
 * @param string $name Monitor label.
 * @return Monitor Persisted synthetic HTTP monitor.
 */
function ipMonitor(Team $team, string $name = 'Checkout API'): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create(['name' => $name]));
}

/**
 * @param Monitor $monitor Failing monitor.
 * @param string $startedAt UTC start of the first failure.
 * @param bool $resolved Whether the incident is resolved ten minutes after confirmation.
 * @param CheckError $cause Confirming failure, which also sets the opening severity.
 * @return Incident Persisted incident confirmed three minutes after its start.
 */
function ipIncident(Monitor $monitor, string $startedAt, bool $resolved = false, CheckError $cause = CheckError::Timeout): Incident
{
    return app(TenantContext::class)->run($monitor->team_id, function () use ($monitor, $startedAt, $resolved, $cause): Incident {
        $start = CarbonImmutable::parse($startedAt, 'UTC');
        $factory = $resolved ? Incident::factory()->resolved() : Incident::factory();

        return $factory->create([
            'monitor_id' => $monitor->id,
            'cause' => $cause,
            'severity' => IncidentSeverity::forCause($cause),
            'started_at' => $start,
            'confirmed_at' => $start->addMinutes(3),
        ])->fresh();
    });
}

/**
 * @param Incident $incident Owning incident.
 * @param User|null $author Writing user, or null for a system entry.
 * @param string $message Entry text.
 * @return IncidentUpdate Persisted private entry.
 */
function ipEntry(Incident $incident, ?User $author, string $message): IncidentUpdate
{
    return app(TenantContext::class)->run($incident->team_id, fn (): IncidentUpdate => IncidentUpdate::factory()->create([
        'incident_id' => $incident->id,
        'author_id' => $author?->id,
        'status' => $incident->status,
        'message' => $message,
    ]));
}

/**
 * @param Monitor $monitor Monitor under maintenance.
 * @param string $start Inclusive UTC start; may lie in the past to build history.
 * @param string $end Exclusive UTC end.
 * @param string $reason Explanation.
 * @return MaintenanceWindow Window written directly, bypassing the past-immutability rule for setup.
 */
function ipWindow(Monitor $monitor, string $start, string $end, string $reason = 'Planned work'): MaintenanceWindow
{
    return app(TenantContext::class)->run($monitor->team_id, function () use ($monitor, $start, $end, $reason): MaintenanceWindow {
        $window = new MaintenanceWindow();
        $window->forceFill([
            'monitor_id' => $monitor->id,
            'starts_at' => CarbonImmutable::parse($start, 'UTC'),
            'ends_at' => CarbonImmutable::parse($end, 'UTC'),
            'reason' => $reason,
        ])->save();

        return $window;
    });
}

/**
 * @param Team $team Team receiving the member.
 * @param string $role Persisted membership role.
 * @return User New member with that role.
 */
function ipMember(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->users()->attach($user->id, ['role' => $role]);

    return $user;
}

/**
 * @param Incident $incident Incident to reload.
 * @return Incident Current persisted state.
 */
function ipFresh(Incident $incident): Incident
{
    return app(TenantContext::class)->run($incident->team_id, fn (): Incident => Incident::query()->findOrFail($incident->id));
}

/**
 * @param Incident $incident Incident to inspect.
 * @return list<string> Timeline messages, oldest first.
 */
function ipMessages(Incident $incident): array
{
    return app(TenantContext::class)->run($incident->team_id, fn (): array => $incident->updates()->pluck('message')->all());
}

/**
 * @param Monitor $monitor Monitor whose windows are read.
 * @return list<MaintenanceWindow> Windows in ID order.
 */
function ipWindows(Monitor $monitor): array
{
    return app(TenantContext::class)->run($monitor->team_id, fn (): array => MaintenanceWindow::query()->where('monitor_id', $monitor->id)->orderBy('id')->get()->all());
}

it('lists unresolved incidents first, then the newest, and filters by state and severity', function (): void {
    $team = Team::factory()->create();
    $api = ipMonitor($team);
    $site = ipMonitor($team, 'Website');
    $oldResolved = ipIncident($api, '2026-10-20 08:00:00', resolved: true);
    $newResolved = ipIncident($api, '2026-10-27 08:00:00', resolved: true, cause: CheckError::TlsExpiring);
    $open = ipIncident($site, '2026-10-21 09:00:00', cause: CheckError::UnexpectedStatus);
    $foreign = ipIncident(ipMonitor(Team::factory()->create(), 'Foreign API'), '2026-10-28 09:00:00');

    $ids = function (array $query) use ($team): array {
        $ids = [];
        test()->actingAs($team->owner)
            ->get('/teams/'.$team->id.'/incidents?'.http_build_query($query))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(function (AssertableInertia $page) use (&$ids): void {
                $ids = array_column($page->toArray()['props']['incidents']['data'], 'id');
            });

        return $ids;
    };

    expect($ids([]))->toBe([$open->id, $newResolved->id, $oldResolved->id])
        ->and($ids(['state' => 'open']))->toBe([$open->id])
        ->and($ids(['state' => 'resolved']))->toBe([$newResolved->id, $oldResolved->id])
        ->and($ids(['severity' => 'minor']))->toBe([$newResolved->id])
        ->and($ids(['state' => 'resolved', 'severity' => 'major']))->toBe([])
        ->and($ids([]))->not->toContain($foreign->id);

    $this->get('/teams/'.$team->id.'/incidents')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Incidents/Index')
        ->where('incidents.data.0.monitor', ['id' => $site->id, 'name' => 'Website'])
        ->where('incidents.data.0.status', 'investigating')
        ->where('incidents.data.0.severity', 'major')
        ->where('incidents.data.0.cause', 'unexpected_status')
        ->where('incidents.data.0.startedAt', '2026-10-21T09:00:00+00:00')
        ->where('incidents.data.0.durationSeconds', null)
        ->where('incidents.data.0.acknowledged', false)
        ->where('filtered', false)
        ->where('options.states', ['open', 'resolved'])
        ->where('options.severities', ['minor', 'major', 'critical']));
});

it('reports invalid filters without redirecting and keeps the valid ones', function (): void {
    $team = Team::factory()->create();
    $resolved = ipIncident(ipMonitor($team), '2026-10-20 08:00:00', resolved: true);

    $this->actingAs($team->owner)
        ->get('/teams/'.$team->id.'/incidents?state=resolved&severity=fatal&page=0')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rejectedFilters', ['severity', 'page'])
            ->where('filters', ['state' => 'resolved', 'severity' => null])
            ->where('filtered', true)
            ->where('incidents.data.0.id', $resolved->id));

    $this->get('/teams/'.$team->id.'/incidents?page=2')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('incidents.data', 0)
            ->where('incidents.currentPage', 2)
            ->where('incidents.total', 1));
});

it('adds an explicit team predicate to the listing beyond the tenant scope', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    ipIncident(ipMonitor($alpha), '2026-10-27 08:00:00');
    ipIncident(ipMonitor($beta), '2026-10-27 08:00:00');

    $total = app(TenantContext::class)->run($alpha->id, fn (): int => app(IncidentListing::class)->page($beta->id, new IncidentListFilter())->total());

    expect($total)->toBe(0);
});

it('projects the detail and an oldest-first timeline with names but never emails', function (): void {
    $team = Team::factory()->create();
    $member = ipMember($team, 'member');
    $incident = ipIncident(ipMonitor($team), '2026-10-28 10:00:00');
    $system = ipEntry($incident, null, 'Failure confirmed automatically.');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 12:05:00', 'UTC'));
    $written = ipEntry($incident, $member, 'Investigating the payment provider.');

    $this->actingAs($team->owner)
        ->get('/teams/'.$team->id.'/incidents/'.$incident->id)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertDontSee($member->email, false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Incidents/Show')
            ->where('incident.id', $incident->id)
            ->where('incident.confirmedAt', '2026-10-28T10:03:00+00:00')
            ->where('incident.responsible', null)
            ->where('incident.rootCauseNote', null)
            ->where('incident.timeline.0.id', $system->id)
            ->where('incident.timeline.0.author', null)
            ->where('incident.timeline.0.isPublic', false)
            ->where('incident.timeline.1.id', $written->id)
            ->where('incident.timeline.1.author', $member->name)
            ->missing('incident.timeline.1.author_id')
            ->missing('incident.team_id')
            ->where('postableStatuses', ['investigating', 'identified', 'monitoring', 'resolved'])
            ->where('textMaxLength', 5000)
            ->where('can.update', true));

    $resolved = ipIncident(ipMonitor($team, 'Website'), '2026-10-27 10:00:00', resolved: true);
    $this->get('/teams/'.$team->id.'/incidents/'.$resolved->id)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('postableStatuses', ['resolved'])
            ->where('incident.durationSeconds', 780));
});

it('posts updates, resolves through a status change and refuses a manual reopening', function (): void {
    Event::fake([IncidentResolved::class]);
    $team = Team::factory()->create();
    $incident = ipIncident(ipMonitor($team), '2026-10-28 10:00:00');
    $url = '/teams/'.$team->id.'/incidents/'.$incident->id;

    $this->actingAs($team->owner)
        ->from($url)
        ->post($url.'/updates', ['status' => 'identified', 'message' => '  Provider outage identified.  '])
        ->assertRedirect($url)
        ->assertSessionHas('status', 'incident-update-posted')
        ->assertSessionHasNoErrors();
    expect(ipFresh($incident)->status)->toBe(IncidentStatus::Identified);

    $this->from($url)->post($url.'/updates', ['status' => 'resolved', 'message' => 'Provider recovered.'])
        ->assertRedirect($url)
        ->assertSessionHasNoErrors();
    expect(ipFresh($incident)->status)->toBe(IncidentStatus::Resolved)
        ->and(ipFresh($incident)->resolved_at?->toIso8601String())->toBe('2026-10-28T12:00:00+00:00');
    Event::assertDispatched(IncidentResolved::class);

    $this->from($url)->post($url.'/updates', ['status' => 'investigating', 'message' => 'Still failing?'])
        ->assertRedirect($url)
        ->assertSessionHasErrors(['incident' => 'Only a newly confirmed failure can reopen a resolved incident.']);
    expect(ipFresh($incident)->status)->toBe(IncidentStatus::Resolved);

    $this->from($url)->post($url.'/updates', ['status' => 'resolved', 'message' => 'Post-incident note.'])
        ->assertSessionHasNoErrors();
    expect(ipMessages($incident))->toBe(['Provider outage identified.', 'Provider recovered.', 'Post-incident note.']);
});

it('rejects invalid entries with field errors and writes nothing', function (array $payload, string $field): void {
    $team = Team::factory()->create();
    $incident = ipIncident(ipMonitor($team), '2026-10-28 10:00:00');
    $url = '/teams/'.$team->id.'/incidents/'.$incident->id;

    $this->actingAs($team->owner)
        ->from($url)
        ->post($url.'/updates', $payload)
        ->assertRedirect($url)
        ->assertSessionHasErrors($field);
    expect(ipMessages($incident))->toBe([])
        ->and(ipFresh($incident)->status)->toBe(IncidentStatus::Investigating);
})->with([
    'missing message' => [['status' => 'identified'], 'message'],
    'blank message' => [['status' => 'identified', 'message' => '   '], 'message'],
    'message too long' => [['status' => 'identified', 'message' => str_repeat('é', 5001)], 'message'],
    'unknown status' => [['status' => 'fixed', 'message' => 'Note'], 'status'],
    'array status' => [['status' => ['resolved'], 'message' => 'Note'], 'status'],
]);

it('authorizes viewers before validating their input', function (): void {
    $team = Team::factory()->create();
    $viewer = ipMember($team, 'viewer');
    $incident = ipIncident(ipMonitor($team), '2026-10-28 10:00:00');
    $url = '/teams/'.$team->id.'/incidents/'.$incident->id;

    $this->actingAs($viewer)->post($url.'/updates', ['status' => 'bogus'])->assertForbidden();
    $this->patch($url.'/severity', [])->assertForbidden();
    $this->patch($url.'/root-cause', [])->assertForbidden();
    $this->post('/teams/'.$team->id.'/monitors/'.$incident->monitor_id.'/maintenance', [])->assertForbidden();
    $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', false));
    expect(ipMessages($incident))->toBe([]);
});

it('takes responsibility, refuses it after resolution and reports the refusal on the incident key', function (): void {
    $team = Team::factory()->create();
    $member = ipMember($team, 'member');
    $incident = ipIncident(ipMonitor($team), '2026-10-28 10:00:00');
    $resolved = ipIncident(ipMonitor($team, 'Website'), '2026-10-27 10:00:00', resolved: true);
    $base = '/teams/'.$team->id.'/incidents/';

    $this->actingAs($member)
        ->post($base.$incident->id.'/acknowledge')
        ->assertSessionHas('status', 'incident-acknowledged');
    $this->get($base.$incident->id)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('incident.responsible', $member->name)
        ->where('incident.acknowledgedAt', '2026-10-28T12:00:00+00:00')
        ->where('incident.acknowledged', true));

    $this->from($base.$resolved->id)
        ->post($base.$resolved->id.'/acknowledge')
        ->assertRedirect($base.$resolved->id)
        ->assertSessionHasErrors(['incident' => 'A resolved incident cannot be acknowledged.']);
    expect(ipFresh($resolved)->acknowledged_by)->toBeNull();
});

it('changes severity and declares or clears the cause without copying the note into the timeline', function (): void {
    $team = Team::factory()->create();
    $incident = ipIncident(ipMonitor($team), '2026-10-28 10:00:00');
    $url = '/teams/'.$team->id.'/incidents/'.$incident->id;
    $this->actingAs($team->owner);

    $this->patch($url.'/severity', ['severity' => 'minor'])->assertSessionHas('status', 'incident-severity-changed');
    $this->patch($url.'/severity', ['severity' => 'minor'])->assertSessionHasNoErrors();
    $this->patch($url.'/severity', ['severity' => 'catastrophic'])->assertSessionHasErrors('severity');
    expect(ipFresh($incident)->severity)->toBe(IncidentSeverity::Minor);

    $this->patch($url.'/root-cause', ['root_cause_note' => 'Expired upstream credentials.'])->assertSessionHas('status', 'incident-root-cause-saved');
    expect(ipFresh($incident)->root_cause_note)->toBe('Expired upstream credentials.');

    $this->patch($url.'/root-cause', ['root_cause_note' => str_repeat('x', 5001)])->assertSessionHasErrors('root_cause_note');
    $this->patch($url.'/root-cause', [])->assertSessionHasErrors('root_cause_note');
    $this->patch($url.'/root-cause', ['root_cause_note' => ''])->assertSessionHasNoErrors();
    expect(ipFresh($incident)->root_cause_note)->toBeNull()
        ->and(ipMessages($incident))->toBe(['Severity changed from critical to minor.', 'Root cause declared.', 'Root cause cleared.']);
});

it('publishes an entry once and refuses an entry of another incident under this incident', function (): void {
    $team = Team::factory()->create();
    $monitor = ipMonitor($team);
    $incident = ipIncident($monitor, '2026-10-28 10:00:00');
    $other = ipIncident(ipMonitor($team, 'Website'), '2026-10-28 09:00:00');
    $entry = ipEntry($incident, null, 'Confirmed.');
    $otherEntry = ipEntry($other, null, 'Other confirmed.');
    $url = '/teams/'.$team->id.'/incidents/'.$incident->id;
    $this->actingAs($team->owner);

    $this->post($url.'/updates/'.$entry->id.'/publish')->assertSessionHas('status', 'incident-update-published');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 13:00:00', 'UTC'));
    $this->post($url.'/updates/'.$entry->id.'/publish')->assertSessionHasNoErrors();

    $row = DB::table('incident_updates')->where('id', $entry->id)->first();
    expect($row->is_public)->toBeTrue()
        ->and(CarbonImmutable::parse($row->published_at)->utc()->toIso8601String())->toBe('2026-10-28T12:00:00+00:00');

    $this->post($url.'/updates/'.$otherEntry->id.'/publish')->assertNotFound();
    expect(DB::table('incident_updates')->where('id', $otherEntry->id)->value('is_public'))->toBeFalse();

    $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('incident.timeline.0.isPublic', true)
        ->where('incident.timeline.0.publishedAt', '2026-10-28T12:00:00+00:00'));
});

it('schedules maintenance from instants with an offset and stores them in UTC', function (): void {
    $team = Team::factory()->create();
    $monitor = ipMonitor($team);
    $url = '/teams/'.$team->id.'/monitors/'.$monitor->id.'/maintenance';
    $this->actingAs($team->owner);

    $this->post($url, ['starts_at' => '2026-10-29T10:00:00+02:00', 'ends_at' => '2026-10-29T11:30:00.000+02:00', 'reason' => ' Database upgrade '])
        ->assertSessionHas('status', 'maintenance-scheduled')
        ->assertSessionHasNoErrors();
    $this->post($url, ['starts_at' => '', 'ends_at' => '2026-10-28T13:00:00.000Z', 'reason' => 'Hotfix'])
        ->assertSessionHasNoErrors();

    $windows = ipWindows($monitor);
    expect($windows)->toHaveCount(2)
        ->and($windows[0]->starts_at->toIso8601String())->toBe('2026-10-29T08:00:00+00:00')
        ->and($windows[0]->ends_at->toIso8601String())->toBe('2026-10-29T09:30:00+00:00')
        ->and($windows[0]->reason)->toBe('Database upgrade')
        ->and($windows[0]->created_by)->toBe($team->owner->id)
        ->and($windows[1]->starts_at->toIso8601String())->toBe('2026-10-28T12:00:00+00:00');
});

it('rejects malformed or past maintenance without writing a window', function (array $payload, string $field): void {
    $team = Team::factory()->create();
    $monitor = ipMonitor($team);
    $show = '/teams/'.$team->id.'/monitors/'.$monitor->id;

    $this->actingAs($team->owner)
        ->from($show)
        ->post($show.'/maintenance', $payload)
        ->assertRedirect($show)
        ->assertSessionHasErrors($field);
    expect(ipWindows($monitor))->toBe([]);
})->with([
    'no offset' => [['starts_at' => '2026-10-29T10:00', 'ends_at' => '2026-10-29T11:00:00Z', 'reason' => 'Work'], 'starts_at'],
    'not a date' => [['ends_at' => '2026-02-30T11:00:00Z', 'reason' => 'Work'], 'ends_at'],
    'missing end' => [['reason' => 'Work'], 'ends_at'],
    'missing reason' => [['ends_at' => '2026-10-29T11:00:00Z'], 'reason'],
    'reason too long' => [['ends_at' => '2026-10-29T11:00:00Z', 'reason' => str_repeat('r', 1001)], 'reason'],
    'start in the past' => [['starts_at' => '2026-10-28T11:59:00Z', 'ends_at' => '2026-10-29T11:00:00Z', 'reason' => 'Work'], 'maintenance'],
    'end before start' => [['starts_at' => '2026-10-29T11:00:00Z', 'ends_at' => '2026-10-29T10:00:00Z', 'reason' => 'Work'], 'maintenance'],
]);

it('reschedules or deletes upcoming windows, ends ongoing ones and keeps finished ones', function (): void {
    $team = Team::factory()->create();
    $monitor = ipMonitor($team);
    $upcoming = ipWindow($monitor, '2026-10-29 08:00:00', '2026-10-29 09:00:00');
    $ongoing = ipWindow($monitor, '2026-10-28 11:00:00', '2026-10-28 14:00:00');
    $finished = ipWindow($monitor, '2026-10-27 08:00:00', '2026-10-27 09:00:00');
    $base = '/teams/'.$team->id.'/monitors/'.$monitor->id.'/maintenance/';
    $replacement = ['starts_at' => '2026-10-30T08:00:00Z', 'ends_at' => '2026-10-30T10:00:00Z', 'reason' => 'Moved work'];
    $this->actingAs($team->owner);

    $this->patch($base.$upcoming->id, $replacement)->assertSessionHas('status', 'maintenance-rescheduled');
    $this->patch($base.$ongoing->id, $replacement)->assertSessionHasErrors(['maintenance' => 'A maintenance window can only be changed before it starts.']);
    $this->delete($base.$ongoing->id)->assertSessionHas('status', 'maintenance-ended');
    $this->delete($base.$finished->id)->assertSessionHasErrors(['maintenance' => 'A finished maintenance window cannot be changed.']);
    $this->delete($base.$upcoming->id)->assertSessionHas('status', 'maintenance-deleted');

    $windows = ipWindows($monitor);
    expect($windows)->toHaveCount(2)
        ->and($windows[0]->id)->toBe($ongoing->id)
        ->and($windows[0]->ends_at->toIso8601String())->toBe('2026-10-28T12:00:00+00:00')
        ->and($windows[1]->id)->toBe($finished->id)
        ->and($windows[1]->ends_at->toIso8601String())->toBe('2026-10-27T09:00:00+00:00');
});

it('refuses a window of another monitor of the same team under this monitor', function (): void {
    $team = Team::factory()->create();
    $monitor = ipMonitor($team);
    $sibling = ipMonitor($team, 'Website');
    $other = ipWindow($sibling, '2026-10-29 08:00:00', '2026-10-29 09:00:00');
    $base = '/teams/'.$team->id.'/monitors/'.$monitor->id.'/maintenance/';
    $this->actingAs($team->owner);

    $this->patch($base.$other->id, ['ends_at' => '2026-10-30T10:00:00Z', 'reason' => 'Moved'])->assertNotFound();
    $this->delete($base.$other->id)->assertNotFound();
    expect(ipWindows($sibling))->toHaveCount(1)
        ->and(ipWindows($sibling)[0]->ends_at->toIso8601String())->toBe('2026-10-29T09:00:00+00:00');
});

it('shows recent incidents and maintenance phases on the monitor page', function (): void {
    $team = Team::factory()->create();
    $monitor = ipMonitor($team);
    $sibling = ipMonitor($team, 'Website');

    foreach (range(1, 6) as $day) {
        ipIncident($monitor, sprintf('2026-10-%02d 08:00:00', 20 + $day), resolved: $day < 6);
    }

    $siblingIncident = ipIncident($sibling, '2026-10-28 11:00:00');
    $later = ipWindow($monitor, '2026-10-30 08:00:00', '2026-10-30 09:00:00', 'Later');
    $soon = ipWindow($monitor, '2026-10-29 08:00:00', '2026-10-29 09:00:00', 'Soon');
    $ongoing = ipWindow($monitor, '2026-10-28 11:00:00', '2026-10-28 13:00:00', 'Now');
    $finished = ipWindow($monitor, '2026-10-27 08:00:00', '2026-10-27 09:00:00', 'Done');
    ipWindow($sibling, '2026-10-29 08:00:00', '2026-10-29 09:00:00', 'Sibling');

    $this->actingAs($team->owner)
        ->get('/teams/'.$team->id.'/monitors/'.$monitor->id)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($siblingIncident, $later, $soon, $ongoing, $finished): void {
            $props = $page->toArray()['props'];
            $starts = array_column($props['recentIncidents'], 'startedAt');

            expect($props['recentIncidents'])->toHaveCount(5)
                ->and($starts[0])->toBe('2026-10-26T08:00:00+00:00')
                ->and($starts[4])->toBe('2026-10-22T08:00:00+00:00')
                ->and(array_column($props['recentIncidents'], 'id'))->not->toContain($siblingIncident->id)
                ->and($props['recentIncidents'][0]['status'])->toBe('investigating')
                ->and(array_column($props['maintenance']['current'], 'id'))->toBe([$ongoing->id, $soon->id, $later->id])
                ->and(array_column($props['maintenance']['current'], 'phase'))->toBe(['ongoing', 'upcoming', 'upcoming'])
                ->and($props['maintenance']['finished'])->toBe([[
                    'id' => $finished->id,
                    'startsAt' => '2026-10-27T08:00:00+00:00',
                    'endsAt' => '2026-10-27T09:00:00+00:00',
                    'reason' => 'Done',
                    'phase' => 'finished',
                ]])
                ->and($props['maintenance']['reasonMaxLength'])->toBe(1000);
        });
});
