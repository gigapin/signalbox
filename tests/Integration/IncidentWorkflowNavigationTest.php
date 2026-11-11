<?php

declare(strict_types=1);

/** Verify normalized incident workflow returns and tenant authorization without new domain writes. */

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
    $this->withoutVite();
    $this->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(request()) ?? '');
    $this->freezeTime();
});

/**
 * @param Team $team Owning tenant established independently of navigation hints.
 * @return Incident Persisted synthetic incident with an actual monitor.
 */
function workflowIncident(Team $team): Incident
{
    return app(TenantContext::class)->run($team->id, function (): Incident {
        $monitor = Monitor::factory()->http()->create();

        return Incident::factory()->create(['monitor_id' => $monitor->id]);
    });
}

it('preserves accepted incident and monitor list context through a detail read', function (): void {
    $team = Team::factory()->create();
    $incident = workflowIncident($team);
    $list = '/teams/'.$team->id.'/incidents?state=open&severity=critical&page=2';
    $monitors = '/teams/'.$team->id.'/monitors?q=api&page=3';
    $query = http_build_query(['returnTo' => $list, 'monitorReturnTo' => $monitors]);
    $this->actingAs($team->owner)->get('/teams/'.$team->id.'/incidents/'.$incident->id.'?'.$query, ['X-Inertia' => 'true'])
        ->assertOk()->assertJsonPath('props.incidentListUrl', $list)
        ->assertJsonPath('props.monitorUrl', '/teams/'.$team->id.'/monitors/'.$incident->monitor_id.'?'.http_build_query(['returnTo' => $monitors]))
        ->assertJsonPath('props.incident.id', $incident->id)
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('rejects unsafe return hints for both list families', function (string $parameter, string $mode): void {
    $team = Team::factory()->create();
    $incident = workflowIncident($team);
    $base = '/teams/'.$team->id.($parameter === 'returnTo' ? '/incidents' : '/monitors');
    $hint = match ($mode) {
        'external' => 'https://evil.test'.$base,
        'foreign' => str_replace('/teams/'.$team->id.'/', '/teams/'.($team->id + 100).'/', $base),
        'detail' => $base.'/999',
        'array' => [$base],
        'oversized' => $base.'?q='.str_repeat('x', 2100),
        'control' => $base."\n",
        'duplicate' => $base.'?page=2',
        default => null,
    };
    $query = $hint === null ? '' : http_build_query([$parameter => $hint]);
    if ($mode === 'duplicate') {
        $query .= '&'.$parameter.'='.rawurlencode($base.'?page=3');
    }
    $path = '/teams/'.$team->id.'/incidents/'.$incident->id;
    $this->actingAs($team->owner)->get($path.'?'.$query, ['X-Inertia' => 'true'])
        ->assertOk()->assertJsonPath('props.incidentListUrl', '/teams/'.$team->id.'/incidents')
        ->assertJsonPath('props.monitorUrl', '/teams/'.$team->id.'/monitors/'.$incident->monitor_id);
})->with(['returnTo', 'monitorReturnTo'])->with(['external', 'foreign', 'detail', 'array', 'oversized', 'control', 'duplicate', 'absent']);

it('normalizes repeated inner controls and ignores nested or unknown filters', function (): void {
    $team = Team::factory()->create();
    $incident = workflowIncident($team);
    $base = '/teams/'.$team->id.'/incidents';
    $hint = $base.'?state=open&state=resolved&severity=major&page=3&returnTo=https://evil.test&private=secret';
    $this->actingAs($team->owner)->get($base.'/'.$incident->id.'?'.http_build_query(['returnTo' => $hint]), ['X-Inertia' => 'true'])
        ->assertOk()->assertJsonPath('props.incidentListUrl', $base.'?severity=major&page=3')
        ->assertJsonPath('props.monitorUrl', '/teams/'.$team->id.'/monitors/'.$incident->monitor_id);
});

it('keeps detail navigation inside current membership for every role', function (string $role): void {
    $team = Team::factory()->create();
    $foreign = Team::factory()->create();
    $incident = workflowIncident($team);
    $actor = $role === 'owner' ? $team->owner : User::factory()->create();
    if ($role !== 'owner') {
        $team->users()->attach($actor->id, ['role' => $role]);
    }
    $this->actingAs($actor)->get('/teams/'.$team->id.'/incidents/'.$incident->id, ['X-Inertia' => 'true'])
        ->assertOk()->assertJsonPath('props.can.update', $role !== 'viewer');
    $this->get('/teams/'.$foreign->id.'/incidents/'.$incident->id, ['X-Inertia' => 'true'])->assertNotFound();
    $team->users()->detach($actor->id);
    $this->get('/teams/'.$team->id.'/incidents/'.$incident->id, ['X-Inertia' => 'true'])->assertNotFound();
})->with(['owner', 'admin', 'member', 'viewer']);
