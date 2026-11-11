<?php

declare(strict_types=1);

/** Verify bounded operational lists on PostgreSQL with time boundaries, current membership and redaction. */

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
    $this->withoutVite();
    $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '']);
    $this->freezeTime();
});

/**
 * @param Team $team Owning tenant.
 * @param int $count Number of each synthetic row type.
 * @return Monitor Actual monitor referenced by both lists.
 */
function operationalFixtures(Team $team, int $count = 1): Monitor
{
    return app(TenantContext::class)->run($team->id, function () use ($count): Monitor {
        $monitor = Monitor::factory()->create(['name' => 'API 100%_ready']);
        $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);
        $channel = NotificationChannel::factory()->create(['name' => 'Ops channel']);
        $now = CarbonImmutable::now('UTC');
        for ($index = 0; $index < $count; ++$index) {
            MaintenanceWindow::query()->forceCreate([
                'monitor_id' => $monitor->id, 'starts_at' => $now, 'ends_at' => $now->addHour(), 'reason' => 'Synthetic window',
            ]);
            NotificationLog::factory()->create([
                'incident_id' => $incident->id, 'channel_id' => $channel->id,
                'dedupe_key' => 'private-key-'.$index, 'destination_hash' => 'private-destination',
                'message_payload' => ['secret' => 'private-payload'],
            ]);
        }

        return $monitor;
    });
}

it('allows all current roles and hides foreign or revoked teams', function (string $role, string $path): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    operationalFixtures($team);
    operationalFixtures($other);
    $actor = $role === 'owner' ? $team->owner : User::factory()->create();
    if ($role !== 'owner') {
        $team->users()->attach($actor->id, ['role' => $role]);
    }
    $this->actingAs($actor)->get("/teams/{$team->id}/{$path}")
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('props.listing.total', 1)->assertJsonCount(1, 'props.listing.data')
        ->assertJsonPath('props.team.id', $team->id);
    $this->get("/teams/{$other->id}/{$path}")->assertNotFound();
    $team->users()->detach($actor->id);
    $this->get("/teams/{$team->id}/{$path}")->assertNotFound();
})->with(['owner', 'admin', 'member', 'viewer'])->with(['maintenance', 'notifications/deliveries']);

it('classifies half-open maintenance boundaries and uses literal monitor search', function (): void {
    $team = Team::factory()->create();
    $monitor = operationalFixtures($team);
    app(TenantContext::class)->run($team->id, function () use ($monitor): void {
        $now = CarbonImmutable::now('UTC');
        foreach ([[$now->subHour(), $now], [$now->addSecond(), $now->addHour()]] as [$start, $end]) {
            MaintenanceWindow::query()->forceCreate(['monitor_id' => $monitor->id, 'starts_at' => $start, 'ends_at' => $end, 'reason' => 'Boundary']);
        }
    });
    foreach (['upcoming', 'ongoing', 'finished'] as $state) {
        $this->actingAs($team->owner)->get("/teams/{$team->id}/maintenance?state={$state}&q=100%25_")
            ->assertOk()->assertJsonPath('props.listing.total', 1)
            ->assertJsonPath('props.listing.data.0.phase', $state);
    }
    $this->get("/teams/{$team->id}/maintenance?q=not-present")->assertJsonPath('props.listing.total', 0);
});

it('paginates deterministically without reading private delivery columns', function (string $path): void {
    $team = Team::factory()->create();
    operationalFixtures($team, 21);
    $queries = [];
    DB::listen(function ($event) use (&$queries): void {
        $queries[] = $event->sql;
    });
    $first = $this->actingAs($team->owner)->get("/teams/{$team->id}/{$path}")
        ->assertOk()->assertJsonPath('props.listing.total', 21)->assertJsonCount(20, 'props.listing.data')
        ->assertDontSee('private-key-', false)->assertDontSee('private-destination', false)->assertDontSee('private-payload', false);
    $second = $this->get("/teams/{$team->id}/{$path}?page=2")->assertJsonCount(1, 'props.listing.data');
    expect(array_intersect(array_column($first->json('props.listing.data'), 'id'), array_column($second->json('props.listing.data'), 'id')))->toBe([]);
    if ($path === 'notifications/deliveries') {
        $reads = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'from "notification_logs"') && !str_contains($sql, 'count(*)'));
        expect($reads)->not->toBeEmpty();
        foreach ($reads as $sql) {
            expect($sql)->not->toContain('message_payload', 'dedupe_key', 'destination_hash', 'claim_token');
        }
    }
})->with(['maintenance', 'notifications/deliveries']);

it('drops invalid controls without redirects or reflecting their values', function (string $path): void {
    $team = Team::factory()->create();
    $this->actingAs($team->owner)->get("/teams/{$team->id}/{$path}?q[]=invalid&state[]=bad&page=9999999999")
        ->assertOk()->assertJsonPath('props.filters', ['q' => '', 'state' => null])
        ->assertJsonPath('props.rejectedFilters', ['q', 'state', 'page'])
        ->assertJsonPath('props.listing.currentPage', 1);
    $this->get("/teams/{$team->id}/{$path}?page=3")->assertOk()->assertJsonPath('props.listing.data', [])
        ->assertJsonPath('props.listing.currentPage', 3);
})->with(['maintenance', 'notifications/deliveries']);

it('filters delivery outcomes and keeps a deleted channel row', function (): void {
    $team = Team::factory()->create();
    operationalFixtures($team);
    app(TenantContext::class)->run($team->id, fn () => NotificationChannel::query()->delete());
    $this->actingAs($team->owner)->get("/teams/{$team->id}/notifications/deliveries?state=sent&q=API")
        ->assertOk()->assertJsonPath('props.listing.total', 1)
        ->assertJsonPath('props.listing.data.0.channelName', null)
        ->assertJsonPath('props.listing.data.0.monitor.name', 'API 100%_ready');
    $this->get("/teams/{$team->id}/notifications/deliveries?state=failed")->assertJsonPath('props.listing.total', 0);
});

it('bounds search and state input without redirect loops', function (string $path, string $query, array $rejected): void {
    $team = Team::factory()->create();
    $this->actingAs($team->owner)->get("/teams/{$team->id}/{$path}?{$query}")
        ->assertOk()->assertJsonPath('props.rejectedFilters', $rejected);
})->with(['maintenance', 'notifications/deliveries'])->with([
    'long search' => ['q='.str_repeat('a', 101), ['q']],
    'bounded search' => ['q='.str_repeat('a', 100), []],
    'control search' => ['q=bad%0Aquery', ['q']],
    'unknown state and negative page' => ['state=unknown&page=-1', ['state', 'page']],
]);

it('keeps relation query counts constant as visible rows grow', function (string $family): void {
    $team = Team::factory()->create();
    operationalFixtures($team, 21);
    app(TenantContext::class)->run($team->id, function () use ($team, $family): void {
        $monitor = Monitor::factory()->create(['name' => 'Unique row']);
        $now = CarbonImmutable::now('UTC');
        MaintenanceWindow::query()->forceCreate(['monitor_id' => $monitor->id, 'starts_at' => $now, 'ends_at' => $now->addHour(), 'reason' => 'Single row']);
        $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);
        NotificationLog::factory()->create(['incident_id' => $incident->id]);
        $listing = $family === 'maintenance'
            ? app(\App\Services\Operations\MaintenanceListing::class)
            : app(\App\Services\Operations\DeliveryListing::class);
        $counts = [];
        foreach (['Unique row', ''] as $q) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $filter = \App\Data\Operations\OperationalListFilter::fromInput(['q' => $q], []);
            $page = $family === 'maintenance' ? $listing->page($team->id, $filter, $now) : $listing->page($team->id, $filter);
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            expect($page->count())->toBe($q === '' ? 20 : 1);
        }
        expect($counts[0])->toBe($counts[1])->toBe($family === 'maintenance' ? 3 : 5);
    });
})->with(['maintenance', 'deliveries']);
