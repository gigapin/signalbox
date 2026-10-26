<?php

declare(strict_types=1);

/** Verify complete scheduled observations, lifecycle changes and historical heartbeat slots on isolated services. */

use App\Actions\Monitoring\ManageHeartbeatCredentials;
use App\Actions\Monitoring\ManageMonitors;
use App\Contracts\Monitoring\DnsTransport;
use App\Contracts\Monitoring\TlsTransport;
use App\Contracts\Security\HostResolver;
use App\Enums\Monitoring\CheckError;
use App\Exceptions\CheckTransportFailed;
use App\Models\CheckExecution;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Monitoring\Scheduling\MonitorSlots;
use App\Services\Monitoring\Scheduling\PublishMonitorChecks;
use App\Services\Monitoring\Scheduling\ReserveMonitorChecks;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use Tests\Support\FakeDnsTransport;
use Tests\Support\FakeHostResolver;
use Tests\Support\FakeTlsTransport;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL and Redis.');
    }

    CarbonImmutable::setTestNow('2026-10-26T12:00:00Z');
    config(['monitor-scheduling.enabled' => true]);
    app()->instance(HostResolver::class, new FakeHostResolver(['example.invalid' => ['93.184.216.34']]));
});
afterEach(function (): void {
    Queue::connection('redis')->clear('checks:eu');
    Queue::connection('redis')->clear('checks:us');
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Authorized fixture owner.
 * @param string $type Structurally valid factory variant.
 * @return Monitor Uninitialized monitor using the production slot initializer.
 */
function engineMonitor(Team $team, string $type = 'http'): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->{$type}()->create());
}

/**
 * @param Monitor $monitor Local monitor with a future or uninitialized slot.
 * @return CheckExecution Latest reserved work after the real publisher and scalar Redis job complete.
 */
function engineTick(Monitor $monitor): CheckExecution
{
    $tenants = app(TenantContext::class);
    $fresh = $tenants->run($monitor->team_id, fn (): Monitor => $monitor->fresh());

    if ($fresh->next_check_at === null) {
        expect(app(ReserveMonitorChecks::class)->run())->toBe(0);
        $fresh = $tenants->run($monitor->team_id, fn (): Monitor => $monitor->fresh());
    }

    CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->max($fresh->next_check_at));
    expect(app(ReserveMonitorChecks::class)->run())->toBe(1)
        ->and(app(ReserveMonitorChecks::class)->run())->toBe(0)
        ->and(app(PublishMonitorChecks::class)->run())->toBe(1);
    $queue = Queue::connection('redis');
    $delivery = $queue->pop('checks:eu');
    expect($delivery)->not->toBeNull();
    $delivery->fire();
    expect($queue->size('checks:eu'))->toBe(0);

    return $tenants->run($monitor->team_id, fn (): CheckExecution => CheckExecution::query()
        ->where('monitor_id', $monitor->id)->orderByDesc('slot_at')->firstOrFail());
}

it('persists the complete success error timeout recovery sequence without automatic target retries', function (string $type): void {
    $team = Team::factory()->business()->create();
    $monitor = engineMonitor($team, $type);
    $transport = new ConnectException('synthetic-private-timeout', new Request('GET', 'https://example.invalid/'), handlerContext: ['errno' => 28]);
    Http::fakeSequence()->push('healthy')->push('unhealthy', 503)
        ->pushResponse(Create::rejectionFor(new ConnectionException('synthetic-private-timeout', previous: $transport)))
        ->push('healthy');
    $expected = [
        [null, 'up'],
        ['unexpected_status', 'down'],
        ['timeout', 'down'],
        [null, 'up'],
    ];
    $previousSlot = null;

    foreach ($expected as $position => [$code, $status]) {
        $work = engineTick($monitor);
        $result = DB::table('check_results')->where('execution_id', $work->id)->sole();
        expect($work->state)->toBe('completed')
            ->and($work->queue_uuid)->not->toBeNull()
            ->and($work->queue_attempt)->toBe(1)
            ->and($result->kind)->toBe('observed')
            ->and($result->status)->toBe($status)
            ->and($result->error_code)->toBe($code)
            ->and($result->checked_at)->not->toBeNull()
            ->and($result->error_message ?? '')->not->toContain('synthetic-private-timeout')
            ->and(DB::table('monitors')->where('id', $monitor->id)->value('last_status'))->toBe($status)
            ->and(DB::table('check_results')->count())->toBe($position + 1);

        if ($previousSlot !== null) {
            expect($work->slot_at->getTimestamp() - $previousSlot->getTimestamp())->toBe(300);
        }

        $previousSlot = $work->slot_at;
        // Laravel records response pairs only; rejected connections still authorize exactly one destination.
        Http::assertSentCount($position + 1 - ($position >= 2 ? 1 : 0));
        expect(app(HostResolver::class)->queries)->toHaveCount($position + 1);
    }
})->with(['http', 'keyword']);

it('records protocol failures and a later recovery through the same scheduled pipeline', function (string $type, CheckError $error): void {
    $team = Team::factory()->business()->create();
    $monitor = engineMonitor($team, $type);
    $dns = new FakeDnsTransport();
    $tls = new FakeTlsTransport();
    $name = 'example.invalid.|1';
    $answer = Message::createResponseWithAnswersForQuery(
        new Query('example.invalid.', 1, 1),
        [new Record('example.invalid', 1, 1, 60, $type === 'tls' ? '93.184.216.34' : '192.0.2.1')],
    );
    $dns->answers[$name] = $answer;
    app()->instance(DnsTransport::class, $dns);
    app()->instance(TlsTransport::class, $tls);
    Http::fake();

    if ($type === 'tls') {
        $tls->failure = new CheckTransportFailed($error);
    } else {
        $dns->answers[$name] = $error === CheckError::Timeout ? new CheckTransportFailed($error) : Message::createResponseWithAnswersForQuery(new Query('example.invalid.', 1, 1), []);
    }

    $failed = engineTick($monitor);
    $row = DB::table('check_results')->where('execution_id', $failed->id)->sole();
    expect($row->kind)->toBe('observed')
        ->and($row->status)->toBe('down')
        ->and($row->error_code)->toBe($error->value)
        ->and($failed->state)->toBe('completed');
    $tls->failure = null;
    $dns->answers[$name] = $answer;
    $recovered = engineTick($monitor);
    expect(DB::table('check_results')->where('execution_id', $recovered->id)->value('status'))->toBe('up')
        ->and(DB::table('check_results')->count())->toBe(2)
        ->and(DB::table('monitors')->value('last_status'))->toBe('up');

    if ($type === 'tls') {
        expect($tls->destinations)->toHaveCount(2)
            ->and(DB::table('monitors')->value('ssl_expires_at'))->not->toBeNull();
    } else {
        expect($dns->queries)->toHaveCount(2);
    }

    Http::assertNothingSent();
})->with([
    ['tls', CheckError::Tls],
    ['tls', CheckError::Timeout],
    ['dns', CheckError::DnsMissing],
    ['dns', CheckError::Timeout],
]);

it('evaluates the historical heartbeat boundary despite a later ping and recovers at the next slot', function (int $grace, int $offsetMicroseconds): void {
    $team = Team::factory()->business()->create();
    $monitor = engineMonitor($team, 'heartbeat');
    $monitor = app(ManageMonitors::class)->update($team->owner, $team->id, $monitor->id, ['heartbeat_grace_seconds' => $grace]);
    $token = app(ManageHeartbeatCredentials::class)->rotate($team->owner, $team->id, $monitor->id);
    $slot = app(MonitorSlots::class)->next($monitor->id, 300, CarbonImmutable::now()->addHour());
    CarbonImmutable::setTestNow($slot->subSeconds(300 + $grace)->addMicroseconds($offsetMicroseconds));
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token])->assertSuccessful();
    CarbonImmutable::setTestNow($slot->subMicrosecond());
    expect(app(ReserveMonitorChecks::class)->run())->toBe(0);
    CarbonImmutable::setTestNow($slot->addSeconds(2));
    $this->postJson('/heartbeats', [], ['Authorization' => 'Bearer '.$token])->assertSuccessful();
    $work = engineTick($monitor);
    $row = DB::table('check_results')->where('execution_id', $work->id)->sole();
    expect($row->status)->toBe($offsetMicroseconds > 0 ? 'up' : 'down')
        ->and($row->error_code)->toBe($offsetMicroseconds > 0 ? null : 'heartbeat_missed')
        ->and(CarbonImmutable::parse($row->checked_at)->equalTo($slot->addSeconds(2)))->toBeTrue()
        ->and($row->response_time_ms)->toBeNull();
    $recovered = engineTick($monitor);
    expect(DB::table('check_results')->where('execution_id', $recovered->id)->value('status'))->toBe('up')
        ->and(DB::table('heartbeat_receipts')->count())->toBe(2);
})->with([0, 7])->with([-1, 0, 1]);

it('cancels published work on pause and resumes only at a newly initialized future slot', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = engineMonitor($team);
    Http::fake(['*' => Http::response('healthy')]);
    app(ReserveMonitorChecks::class)->run();
    $next = DB::table('monitors')->value('next_check_at');
    CarbonImmutable::setTestNow(CarbonImmutable::parse($next));
    app(ReserveMonitorChecks::class)->run();
    app(PublishMonitorChecks::class)->run();
    $delivery = Queue::connection('redis')->pop('checks:eu');
    app(ManageMonitors::class)->pause($team->owner, $team->id, $monitor->id);
    $delivery->fire();
    expect(DB::table('check_results')->value('kind'))->toBe('cancelled')
        ->and(DB::table('check_results')->value('checked_at'))->toBeNull();
    Http::assertNothingSent();
    app(ManageMonitors::class)->resume($team->owner, $team->id, $monitor->id);
    expect(app(ReserveMonitorChecks::class)->run())->toBe(0)
        ->and(Queue::connection('redis')->size('checks:eu'))->toBe(0);
    $work = engineTick($monitor);
    expect($work->slot_at->isAfter(CarbonImmutable::parse($next)))->toBeTrue()
        ->and(DB::table('check_results')->where('execution_id', $work->id)->value('status'))->toBe('up');
    Http::assertSentCount(1);
});

it('retains the admitted observation during a real configuration mutation and uses the new configuration next', function (): void {
    $team = Team::factory()->business()->create();
    $monitor = engineMonitor($team);
    Http::fake(function () use ($team, $monitor) {
        app(ManageMonitors::class)->update($team->owner, $team->id, $monitor->id, ['expected_status' => 204]);

        return Http::response('healthy', 200);
    });
    $old = engineTick($monitor);
    expect(DB::table('check_results')->where('execution_id', $old->id)->value('status'))->toBe('up')
        ->and(DB::table('monitors')->value('last_status'))->toBe('unknown')
        ->and(DB::table('monitors')->value('last_checked_at'))->toBeNull();
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['*' => Http::response('', 204)]);
    $new = engineTick($monitor);
    expect($new->monitor_revision)->toBeGreaterThan($old->monitor_revision)
        ->and(DB::table('check_results')->where('execution_id', $new->id)->value('status_code'))->toBe(204)
        ->and(DB::table('monitors')->value('last_status'))->toBe('up');
    Http::assertSentCount(1);
});
