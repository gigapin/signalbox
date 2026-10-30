<?php

declare(strict_types=1);

/** Exercise durable notification planning, escalation, maintenance silencing, coalescing and fenced retries on isolated PostgreSQL. */

use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Data\Notifications\ChannelDeliveryReceipt;
use App\Enums\Monitoring\CheckError;
use App\Enums\Notifications\DeliveryStatus;
use App\Enums\Notifications\NotificationKind;
use App\Events\Incidents\IncidentNotificationRequested;
use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use App\Jobs\DeliverIncidentNotification;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Services\Incidents\IncidentLifecycle;
use App\Services\Notifications\DeliverNotification;
use App\Services\Notifications\NotificationDeliveryLedger;
use App\Services\Notifications\PrepareIncidentNotifications;
use App\Services\Notifications\PublishNotificationDeliveries;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakeHostResolver;
use Tests\Support\RecordingNotificationPublisher;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 10:00:00', 'UTC'));
    Event::fake([IncidentOpened::class, IncidentReopened::class, IncidentResolved::class]);
    $this->publisher = RecordingNotificationPublisher::bind();
    $this->team = Team::factory()->create();
    [$this->monitor, $this->channel] = app(TenantContext::class)->run($this->team->id, function (): array {
        $monitor = Monitor::factory()->create(['name' => 'Checkout']);
        $monitor->applyConfiguration(new HttpMonitorConfiguration('https://private-target.example.test/path?secret=target-secret', headers: ['Authorization' => 'monitor-secret'], body: 'private-monitor-body'));
        $monitor->save();
        $channel = NotificationChannel::factory()->webhook()->active()->create();
        EscalationPolicy::factory()->create([
            'is_default' => true,
            'steps_json' => [
                ['after_minutes' => 0, 'channel_ids' => [$channel->id]],
            ],
        ]);

        return [$monitor, $channel];
    });
    app()->instance(\App\Contracts\Security\HostResolver::class, new FakeHostResolver(['hooks.example.test' => ['93.184.216.34']]));
    $this->providerResponses = Http::sequence()->pushStatus(204)->whenEmpty(Http::response('', 204));
    $this->transactionBaseline = DB::transactionLevel();
    Http::fake(function (Request $request) {
        expect(DB::transactionLevel())->toBe($this->transactionBaseline);

        return ($this->providerResponses)($request);
    });
    Mail::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Owning tenant.
 * @param Monitor $monitor Monitor receiving a new confirmed incident.
 * @return Incident Incident and its outbox committed within the surrounding test transaction.
 */
function deliveryOpen(Team $team, Monitor $monitor): Incident
{
    return app(TenantContext::class)->run($team->id, fn (): Incident => DB::transaction(function () use ($team, $monitor): Incident {
        Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
        $locked = Monitor::query()->whereKey($monitor->id)->lockForUpdate()->firstOrFail();

        return app(IncidentLifecycle::class)->open($locked, CarbonImmutable::now()->subMinute(), CheckError::UnexpectedStatus, CarbonImmutable::now());
    }));
}

/**
 * @param Team $team Owning tenant.
 * @param Incident $incident Incident to transition.
 * @param string $operation resolve or reopen.
 */
function deliveryTransition(Team $team, Incident $incident, string $operation): void
{
    app(TenantContext::class)->run($team->id, function () use ($team, $incident, $operation): void {
        DB::transaction(function () use ($team, $incident, $operation): void {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            Monitor::query()->whereKey($incident->monitor_id)->lockForUpdate()->firstOrFail();
            $current = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            $lifecycle = app(IncidentLifecycle::class);

            if ($operation === 'resolve') {
                $lifecycle->resolve($current, null, 'Private resolution text with customer-secret.', CarbonImmutable::now());
            } else {
                $lifecycle->reopen($current, CarbonImmutable::now());
            }
        });
    });
}

/**
 * @param Team $team Owning tenant.
 * @return \Illuminate\Database\Eloquent\Collection<int, NotificationLog> Delivery snapshots ordered by preparation.
 */
function deliveryRows(Team $team): \Illuminate\Database\Eloquent\Collection
{
    return app(TenantContext::class)->run($team->id, fn () => NotificationLog::query()->orderBy('id')->get());
}

/**
 * @param NotificationLog $row Runnable delivery.
 * @return string Fresh queue-envelope token used by the attempt.
 */
function deliveryRun(NotificationLog $row): string
{
    $token = (string) Str::uuid();
    app(DeliverNotification::class)->run($row->team_id, $row->id, $token);

    return $token;
}

it('prepares an encrypted stable message atomically and sends it once after the coalescing boundary', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    $log = deliveryRows($this->team)->sole();
    $raw = DB::table('notification_logs')->where('id', $log->id)->value('message_payload');

    expect($log->status)->toBe(DeliveryStatus::Pending)
        ->and($log->cycle)->toBe(0)
        ->and($log->attempts)->toBe(0)
        ->and($log->available_at?->toIso8601String())->toBe('2026-10-29T10:00:05+00:00')
        ->and($raw)->not->toContain('Checkout', 'incident.opened', 'unexpected_status')
        ->and(json_encode($log->message()->payload()))->not->toContain('target-secret', 'monitor-secret', 'private-monitor-body', 'private-target')
        ->and($log->toArray())->not->toHaveKeys(['message_payload', 'destination_hash', 'claim_token', 'message_uuid']);

    $event = new IncidentNotificationRequested($this->team->id, $incident->id, $this->monitor->id, 0, 'incident.opened');
    app(PrepareIncidentNotifications::class)->record($event);
    expect(deliveryRows($this->team)->sole()->message_uuid)->toBe($log->message_uuid);
    deliveryRun($log);
    Http::assertNothingSent();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun($log);
    deliveryRun($log);

    $sent = deliveryRows($this->team)->sole();
    expect($sent->status)->toBe(DeliveryStatus::Sent)->and($sent->attempts)->toBe(1)->and($sent->sent_at)->not->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->header('X-Signalbox-Id') === [$log->message_uuid]
        && $request['event'] === 'incident.opened' && $request['data']['incident_id'] === $incident->id);
});

it('rolls back the notification outbox with the incident and timeline', function (): void {
    DB::beginTransaction();
    deliveryOpen($this->team, $this->monitor);
    expect(DB::table('notification_logs')->count())->toBe(1);
    DB::rollBack();

    expect(DB::table('notification_logs')->count())->toBe(0)
        ->and(DB::table('incident_notification_events')->count())->toBe(0)
        ->and(DB::table('incidents')->count())->toBe(0)
        ->and(DB::table('incident_updates')->count())->toBe(0)
        ->and($this->publisher->sent)->toBe([]);
    Http::assertNothingSent();
});

it('records bounded retries while preserving the same signed message identity and bytes', function (): void {
    $this->providerResponses = Http::sequence()->push('do not persist provider-body', 503)->push('do not persist credentials', 429)->pushStatus(204);
    deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $log = deliveryRows($this->team)->sole();
    deliveryRun($log);
    $failed = deliveryRows($this->team)->sole();

    expect($failed->status)->toBe(DeliveryStatus::Pending)
        ->and($failed->attempts)->toBe(1)
        ->and($failed->available_at?->toIso8601String())->toBe('2026-10-29T10:00:35+00:00')
        ->and($failed->response)->toBe('The notification provider refused the request (HTTP 503).');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(29));
    deliveryRun($log);
    Http::assertSentCount(1);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
    deliveryRun($log);
    expect(deliveryRows($this->team)->sole()->available_at?->toIso8601String())->toBe('2026-10-29T10:02:35+00:00');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));
    deliveryRun($log);
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

    expect($requests)->toHaveCount(3)
        ->and($requests->map(fn (Request $request): string => $request->body())->unique())->toHaveCount(1)
        ->and($requests->map(fn (Request $request): array => $request->header('X-Signalbox-Id'))->unique())->toHaveCount(1)
        ->and($requests->map(fn (Request $request): array => $request->header('X-Signalbox-Signature'))->unique())->toHaveCount(3)
        ->and(deliveryRows($this->team)->sole()->status)->toBe(DeliveryStatus::Sent)
        ->and(deliveryRows($this->team)->sole()->attempts)->toBe(3);
});

it('gives up after the configured provider attempt budget and ignores later queue deliveries', function (): void {
    $this->providerResponses = Http::sequence()->whenEmpty(Http::response('private provider response', 500));
    deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->sole();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));

    foreach ([30, 120, 0] as $advance) {
        deliveryRun($row);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($advance));
    }

    deliveryRun($row);
    $state = deliveryRows($this->team)->sole();
    expect($state->status)->toBe(DeliveryStatus::Failed)->and($state->attempts)->toBe(3)->and($state->response)->not->toContain('private provider');
    Http::assertSentCount(3);
});

it('coalesces recovery before the opening reaches its recipient into no notification', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(4));
    deliveryTransition($this->team, $incident, 'resolve');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    $rows = deliveryRows($this->team);
    deliveryRun($rows->sole());

    expect($rows->sole()->status)->toBe(DeliveryStatus::Suppressed)
        ->and($rows->sole()->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('sends one resolution summary to the notified destination even after its policy is removed', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun(deliveryRows($this->team)->sole());
    app(TenantContext::class)->run($this->team->id, function () use ($incident): void {
        $opening = NotificationLog::query()->firstOrFail();
        NotificationLog::factory()->create([
            'incident_id' => $incident->id,
            'channel_id' => $this->channel->id,
            'kind' => NotificationKind::Escalated,
            'cycle' => 0,
            'destination_hash' => $opening->destination_hash,
        ]);
    });
    app(TenantContext::class)->run($this->team->id, fn () => EscalationPolicy::query()->delete());
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    deliveryTransition($this->team, $incident, 'resolve');
    app(PrepareIncidentNotifications::class)->record(new IncidentNotificationRequested($this->team->id, $incident->id, $this->monitor->id, 0, 'incident.resolved'));
    $summary = deliveryRows($this->team)->last();

    expect(deliveryRows($this->team))->toHaveCount(3)
        ->and($summary->kind)->toBe(NotificationKind::Resolved)
        ->and($summary->message()->data['duration_seconds'])->toBe(365)
        ->and($summary->message()->text)->toContain('Duration: 365 seconds.', 'Reopenings: 0.')
        ->and(json_encode($summary->message()->payload()))->not->toContain('customer-secret', 'Private resolution');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun($summary);
    deliveryRun($summary);
    Http::assertSentCount(2);
    expect(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Sent);
});

it('waits for an in-flight opening before sending a resolution summary', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $opening = deliveryRows($this->team)->sole();
    $token = (string) Str::uuid();
    $ledger = app(NotificationDeliveryLedger::class);
    expect($ledger->acquire($this->team->id, $opening->id, $token))->not->toBeNull();
    deliveryTransition($this->team, $incident, 'resolve');
    $summary = deliveryRows($this->team)->last();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun($summary);
    Http::assertNothingSent();
    expect($ledger->complete($this->team->id, $opening->id, $token, new ChannelDeliveryReceipt()))->toBeTrue();
    deliveryRun($summary);
    Http::assertSentCount(1);
    expect(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Sent);
});

it('suppresses the summary when an in-flight opening was never accepted', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $opening = deliveryRows($this->team)->sole();
    $token = (string) Str::uuid();
    $ledger = app(NotificationDeliveryLedger::class);
    $ledger->acquire($this->team->id, $opening->id, $token);
    deliveryTransition($this->team, $incident, 'resolve');
    $ledger->fail($this->team->id, $opening->id, $token, 'Provider unavailable.');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun(deliveryRows($this->team)->last());

    expect(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Suppressed);
    Http::assertNothingSent();
});

it('coalesces a pending resolution across reopening and keeps an eventual summary for earlier notified channels', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun(deliveryRows($this->team)->sole());
    deliveryTransition($this->team, $incident, 'resolve');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
    deliveryTransition($this->team, $incident, 'reopen');
    $reopening = deliveryRows($this->team)->last();
    expect($reopening->message()->event)->toBe('incident.reopened')->and($reopening->cycle)->toBe(1);
    deliveryTransition($this->team, $incident, 'resolve');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));

    foreach (deliveryRows($this->team) as $row) {
        deliveryRun($row);
    }

    Http::assertSentCount(2);
    expect(deliveryRows($this->team)->pluck('status')->all())->toBe([DeliveryStatus::Sent, DeliveryStatus::Suppressed, DeliveryStatus::Suppressed, DeliveryStatus::Sent]);
});

it('rechecks pause, deletion and verified destination changes at attempt time', function (string $change): void {
    deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->sole();
    app(TenantContext::class)->run($this->team->id, function () use ($change): void {
        $channel = NotificationChannel::query()->findOrFail($this->channel->id);

        if ($change === 'delete') {
            EscalationPolicy::query()->delete();
            $channel->delete();
        } elseif ($change === 'pause') {
            $channel->forceFill(['is_active' => false])->save();
        } else {
            $values = $channel->config_json;
            $values['url'] = 'https://new-destination.example.test/secret';
            $channel->forceFill(['config_json' => $values, 'verified_at' => CarbonImmutable::now()])->save();
        }
    });
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun($row);
    expect(deliveryRows($this->team)->sole()->status)->toBe(DeliveryStatus::Suppressed)
        ->and(deliveryRows($this->team)->sole()->attempts)->toBe(0);
    Http::assertNothingSent();
})->with(['pause', 'delete', 'destination']);

it('uses a rotated webhook signing key without changing the prepared message or destination', function (): void {
    deliveryOpen($this->team, $this->monitor);
    app(TenantContext::class)->run($this->team->id, function (): void {
        $channel = NotificationChannel::query()->findOrFail($this->channel->id);
        $config = $channel->config_json;
        $config['signing_secret'] = str_repeat('b2', 32);
        $channel->forceFill(['config_json' => $config])->save();
    });
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun(deliveryRows($this->team)->sole());
    Http::assertSent(fn (Request $request): bool => $request->header('X-Signalbox-Signature') === ['v1='.hash_hmac('sha256', $request->header('X-Signalbox-Timestamp')[0].'.'.$request->body(), str_repeat('b2', 32))]);
});

it('marks corrupt stored recipient content terminal instead of retrying a poison row forever', function (): void {
    deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->sole();
    DB::table('notification_logs')->where('id', $row->id)->update(['message_payload' => 'corrupt-encrypted-content']);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun($row);

    expect(deliveryRows($this->team)->sole()->status)->toBe(DeliveryStatus::Failed)
        ->and(deliveryRows($this->team)->sole()->response)->not->toContain('corrupt-encrypted-content');
    Http::assertNothingSent();
});

it('fences duplicate workers, expired acknowledgements and failed hooks after recovery', function (): void {
    deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->sole();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $ledger = app(NotificationDeliveryLedger::class);
    $firstToken = (string) Str::uuid();
    $first = $ledger->acquire($this->team->id, $row->id, $firstToken);

    expect($first)->not->toBeNull()
        ->and($ledger->acquire($this->team->id, $row->id, (string) Str::uuid()))->toBeNull();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(75));
    expect($ledger->complete($this->team->id, $row->id, $firstToken, new ChannelDeliveryReceipt()))->toBeFalse()
        ->and($ledger->acquire($this->team->id, $row->id, (string) Str::uuid()))->toBeNull();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
    $secondToken = (string) Str::uuid();
    expect($ledger->acquire($this->team->id, $row->id, $secondToken))->not->toBeNull();
    (new DeliverIncidentNotification($this->team->id, $row->id, $firstToken))->failed(new RuntimeException('private failed exception'));
    expect($ledger->complete($this->team->id, $row->id, $firstToken, new ChannelDeliveryReceipt()))->toBeFalse()
        ->and($ledger->complete($this->team->id, $row->id, $secondToken, new ChannelDeliveryReceipt()))->toBeTrue()
        ->and(deliveryRows($this->team)->sole()->attempts)->toBe(2);
});

it('recovers broker loss and isolates a failing tenant while allowing another tenant to publish', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    $second = Team::factory()->create();
    $secondMonitor = app(TenantContext::class)->run($second->id, function (): Monitor {
        $channel = NotificationChannel::factory()->active()->create();
        EscalationPolicy::factory()->create(['is_default' => true, 'steps_json' => [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]]);

        return Monitor::factory()->create();
    });
    deliveryOpen($second, $secondMonitor);
    $this->publisher->failTeams = [$this->team->id];
    app(PublishNotificationDeliveries::class)->publish(new IncidentOpened($this->team->id, $incident->id, $this->monitor->id));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $this->artisan('notifications:dispatch')->assertFailed();

    expect(array_column($this->publisher->sent, 'team_id'))->toBe([$second->id])
        ->and(deliveryRows($this->team)->sole()->status)->toBe(DeliveryStatus::Pending);
    $this->publisher->failTeams = [];
    $this->artisan('notifications:dispatch')->assertSuccessful();
    expect(array_column($this->publisher->sent, 'team_id'))->toContain($this->team->id);
    Http::assertNothingSent();
});

it('ignores foreign, deleted and stale event/job identities and restores the surrounding tenant', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->sole();
    $foreign = Team::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $context = app(TenantContext::class);
    $context->run($foreign->id, function () use ($foreign, $row): void {
        app(DeliverNotification::class)->run($foreign->id, $row->id, (string) Str::uuid());
        expect(app(TenantContext::class)->requireTeamId())->toBe($foreign->id);
    });
    app(PrepareIncidentNotifications::class)->record(new IncidentNotificationRequested($foreign->id, $incident->id, $this->monitor->id, 0, 'incident.opened'));
    app(PrepareIncidentNotifications::class)->record(new IncidentNotificationRequested($this->team->id, $incident->id, $this->monitor->id, 9, 'incident.reopened'));
    app(PublishNotificationDeliveries::class)->publish(new IncidentOpened($this->team->id, $incident->id, 999999));
    (new DeliverIncidentNotification($foreign->id, $row->id, (string) Str::uuid()))->failed(new RuntimeException('private'));

    expect(deliveryRows($this->team))->toHaveCount(1)
        ->and(deliveryRows($this->team)->sole()->attempts)->toBe(0)
        ->and($this->publisher->sent)->toBe([]);
    Http::assertNothingSent();
});

it('honours an initial policy delay and does not dispatch later escalation steps prematurely', function (): void {
    app(TenantContext::class)->run($this->team->id, fn () => EscalationPolicy::query()->update(['steps_json' => [
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 10, 'channel_ids' => [$this->channel->id]],
    ]]));
    deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->firstOrFail();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(299));
    deliveryRun($row);
    Http::assertNothingSent();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
    deliveryRun($row);
    Http::assertSentCount(1);
    expect(deliveryRows($this->team))->toHaveCount(2)
        ->and(deliveryRows($this->team)->last()->attempts)->toBe(0)
        ->and(deliveryRows($this->team)->last()->available_at?->toIso8601String())->toBe('2026-10-29T10:10:00+00:00');
});

it('preserves database uniqueness and rejects incomplete outbox identities', function (): void {
    deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->sole();

    expect(fn () => DB::transaction(fn () => DB::table('notification_logs')->where('id', $row->id)->update(['available_at' => null])))
        ->toThrow(QueryException::class);
    app(TenantContext::class)->run($this->team->id, function () use ($row): void {
        expect(fn () => DB::transaction(function () use ($row): void {
            $copy = $row->replicate();
            $copy->save();
        }))->toThrow(QueryException::class);
    });
});

it('freezes the original audience even when a duplicate event arrives after a policy change', function (bool $initialPolicy): void {
    $originalSteps = app(TenantContext::class)->run($this->team->id, fn () => EscalationPolicy::query()->firstOrFail()->steps_json);

    if (!$initialPolicy) {
        app(TenantContext::class)->run($this->team->id, fn () => EscalationPolicy::query()->delete());
    }

    $incident = deliveryOpen($this->team, $this->monitor);
    app(TenantContext::class)->run($this->team->id, function () use ($initialPolicy, $originalSteps): void {
        $extra = NotificationChannel::factory()->active()->create();
        EscalationPolicy::query()->delete();
        $steps = $originalSteps;
        $steps[0]['channel_ids'][] = $extra->id;
        EscalationPolicy::factory()->create(['is_default' => true, 'steps_json' => $steps]);
    });
    app(PrepareIncidentNotifications::class)->record(new IncidentNotificationRequested($this->team->id, $incident->id, $this->monitor->id, 0, 'incident.opened'));

    expect(deliveryRows($this->team))->toHaveCount($initialPolicy ? 1 : 0)
        ->and(DB::table('incident_notification_events')->count())->toBe(1);
})->with([true, false]);

it('records inactive recipients as suppressed and never revives them by replaying an event', function (): void {
    app(TenantContext::class)->run($this->team->id, fn () => NotificationChannel::query()->whereKey($this->channel->id)->update(['is_active' => false]));
    $incident = deliveryOpen($this->team, $this->monitor);
    app(TenantContext::class)->run($this->team->id, fn () => NotificationChannel::query()->whereKey($this->channel->id)->update(['is_active' => true]));
    app(PrepareIncidentNotifications::class)->record(new IncidentNotificationRequested($this->team->id, $incident->id, $this->monitor->id, 0, 'incident.opened'));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    deliveryRun(deliveryRows($this->team)->sole());

    expect(deliveryRows($this->team)->sole()->status)->toBe(DeliveryStatus::Suppressed);
    Http::assertNothingSent();
});

it('uses the actual channel adapters for incident mail, Slack, Telegram and the suppressed SMS stub', function (string $family): void {
    app(TenantContext::class)->run($this->team->id, function () use ($family): void {
        $channel = match ($family) {
            'email' => NotificationChannel::factory()->active()->create(),
            'slack' => NotificationChannel::factory()->slack()->active()->create(),
            'telegram' => NotificationChannel::factory()->telegram()->active()->create(),
            'sms' => NotificationChannel::factory()->sms()->active()->create(),
        };
        EscalationPolicy::query()->update(['steps_json' => [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]]);
    });
    app()->instance(\App\Contracts\Security\HostResolver::class, new FakeHostResolver([
        'hooks.slack.com' => ['93.184.216.34'],
        'api.telegram.org' => ['93.184.216.35'],
    ]));
    $this->providerResponses = Http::sequence()->push($family === 'slack' ? 'ok' : ['ok' => true], 200);
    deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun(deliveryRows($this->team)->sole());
    $row = deliveryRows($this->team)->sole();

    expect($row->status)->toBe($family === 'sms' ? DeliveryStatus::Suppressed : DeliveryStatus::Sent)
        ->and($row->attempts)->toBe(1);

    if ($family === 'email') {
        Mail::assertSent(\App\Mail\ChannelNotificationMail::class, 1);
        Http::assertNothingSent();
    } elseif ($family === 'sms') {
        expect($row->sent_at)->toBeNull()->and($row->response)->toBe('SMS stub: recorded without sending.');
        Mail::assertNothingSent();
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(1);
    }
})->with(['email', 'slack', 'telegram', 'sms']);

it('keeps a failed hook fenced and schedules a retry using the persisted attempt count', function (): void {
    deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $row = deliveryRows($this->team)->sole();
    $token = (string) Str::uuid();
    app(NotificationDeliveryLedger::class)->acquire($this->team->id, $row->id, $token);
    (new DeliverIncidentNotification($this->team->id, $row->id, $token))->failed(new RuntimeException('Private exception must not be persisted.'));
    $state = deliveryRows($this->team)->sole();

    expect($state->attempts)->toBe(1)
        ->and($state->claim_until)->toBeNull()
        ->and($state->available_at?->toIso8601String())->toBe('2026-10-29T10:00:35+00:00')
        ->and($state->response)->not->toContain('Private exception');
});

it('exhausts crashed attempts without an unbounded provider retry loop', function (): void {
    deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $row = deliveryRows($this->team)->sole();
    $ledger = app(NotificationDeliveryLedger::class);

    foreach ([30, 120, 0] as $backoff) {
        expect($ledger->acquire($this->team->id, $row->id, (string) Str::uuid()))->not->toBeNull();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(75));
        expect($ledger->acquire($this->team->id, $row->id, (string) Str::uuid()))->toBeNull();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($backoff));
    }

    expect(deliveryRows($this->team)->sole()->status)->toBe(DeliveryStatus::Failed)
        ->and(deliveryRows($this->team)->sole()->attempts)->toBe(3);
    Http::assertNothingSent();
});

it('rolls back the domain transition when durable notification preparation fails', function (): void {
    DB::statement('ALTER TABLE notification_logs ADD CONSTRAINT delivery_fixture_reject CHECK (attempts > 0)');

    try {
        expect(fn () => deliveryOpen($this->team, $this->monitor))->toThrow(QueryException::class)
            ->and(DB::table('incidents')->count())->toBe(0)
            ->and(DB::table('incident_updates')->count())->toBe(0)
            ->and(DB::table('incident_notification_events')->count())->toBe(0);
    } finally {
        DB::statement('ALTER TABLE notification_logs DROP CONSTRAINT delivery_fixture_reject');
    }
});

it('does not send a second recovery summary when the previous episode was already summarized and the reopening coalesces', function (): void {
    $incident = deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun(deliveryRows($this->team)->sole());
    deliveryTransition($this->team, $incident, 'resolve');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    deliveryRun(deliveryRows($this->team)->last());
    deliveryTransition($this->team, $incident, 'reopen');
    deliveryTransition($this->team, $incident, 'resolve');

    expect(deliveryRows($this->team))->toHaveCount(3)
        ->and(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Suppressed);
    Http::assertSentCount(2);
});

it('contains unexpected provider errors and restores tenant context in a persistent worker before its next attempt', function (): void {
    deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(5));
    $row = deliveryRows($this->team)->sole();
    $other = Team::factory()->create();
    $this->providerResponses = static fn () => throw new Error('private provider token and private payload');
    $job = new DeliverIncidentNotification($this->team->id, $row->id, (string) Str::uuid());

    app(TenantContext::class)->run($other->id, function () use ($job, $other): void {
        expect(fn () => $job->middleware()[0]->handle($job, fn () => $job->handle(app(DeliverNotification::class))))
            ->toThrow(RuntimeException::class, 'The notification delivery worker encountered an internal error.');
        expect(app(TenantContext::class)->requireTeamId())->toBe($other->id);
    });

    expect(deliveryRows($this->team)->sole()->response)->toBe('The delivery worker encountered an internal error.')
        ->and(deliveryRows($this->team)->sole()->status)->toBe(DeliveryStatus::Pending);
    $this->providerResponses = Http::sequence()->pushStatus(204);
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
    $next = new DeliverIncidentNotification($this->team->id, $row->id, (string) Str::uuid());
    app(TenantContext::class)->run($other->id, function () use ($next, $other): void {
        $next->middleware()[0]->handle($next, fn () => $next->handle(app(DeliverNotification::class)));
        expect(app(TenantContext::class)->requireTeamId())->toBe($other->id);
    });
    expect(deliveryRows($this->team)->sole()->status)->toBe(DeliveryStatus::Sent)
        ->and(deliveryRows($this->team)->sole()->attempts)->toBe(2);
});

/**
 * @param Team $team Owning tenant.
 * @param list<array{after_minutes: int, channel_ids: list<int>}> $steps Frozen schedule to install before the transition.
 */
function deliverySteps(Team $team, array $steps): void
{
    app(TenantContext::class)->run($team->id, fn () => EscalationPolicy::query()->update(['steps_json' => $steps]));
}

/**
 * @param Team $team Owning tenant.
 * @param Monitor $monitor Monitor to silence.
 * @param string $start UTC start inclusive.
 * @param string $end UTC end exclusive.
 * @return MaintenanceWindow Synthetic persisted window; no real scheduler or provider is used.
 */
function deliveryMaintenance(Team $team, Monitor $monitor, string $start, string $end): MaintenanceWindow
{
    return app(TenantContext::class)->run($team->id, function () use ($monitor, $start, $end): MaintenanceWindow {
        $window = new MaintenanceWindow();
        $window->forceFill([
            'monitor_id' => $monitor->id,
            'starts_at' => CarbonImmutable::parse($start, 'UTC'),
            'ends_at' => CarbonImmutable::parse($end, 'UTC'),
            'reason' => 'Synthetic notification maintenance.',
        ])->save();

        return $window;
    });
}

it('delivers each frozen escalation exactly at its absolute delay with a distinct step identity', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id, $this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 10, 'channel_ids' => [$this->channel->id]],
    ]);
    deliveryOpen($this->team, $this->monitor);
    $rows = deliveryRows($this->team);

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('step_index')->all())->toBe([0, 1, 2])
        ->and($rows->pluck('kind')->all())->toBe([NotificationKind::Opened, NotificationKind::Escalated, NotificationKind::Escalated])
        ->and($rows->pluck('message_uuid')->unique())->toHaveCount(3)
        ->and($rows->pluck('dedupe_key')->unique())->toHaveCount(3);
    CarbonImmutable::setTestNow('2026-10-29 10:00:05');
    deliveryRun($rows[0]);
    CarbonImmutable::setTestNow('2026-10-29 10:04:59');
    deliveryRun($rows[1]);
    Http::assertSentCount(1);
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    deliveryRun($rows[1]);
    deliveryRun($rows[1]);
    Http::assertSentCount(2);
    CarbonImmutable::setTestNow('2026-10-29 10:09:59');
    deliveryRun($rows[2]);
    Http::assertSentCount(2);
    CarbonImmutable::setTestNow('2026-10-29 10:10:00');
    deliveryRun($rows[2]);
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request['event'] === 'incident.escalated'
        && $request['data']['step_index'] === 1 && $request['occurred_at'] === '2026-10-29T10:05:00+00:00');
    expect(deliveryRows($this->team)->pluck('attempts')->all())->toBe([1, 1, 1]);
});

it('freezes future steps across policy edits deletion and duplicate event replay', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    $incident = deliveryOpen($this->team, $this->monitor);
    $snapshot = deliveryRows($this->team)->map(fn (NotificationLog $row): array => [$row->dedupe_key, $row->message_uuid, $row->message()->payload()])->all();
    app(TenantContext::class)->run($this->team->id, function (): void {
        $extra = NotificationChannel::factory()->active()->create();
        EscalationPolicy::query()->update(['steps_json' => [['after_minutes' => 1, 'channel_ids' => [$extra->id]]]]);
        EscalationPolicy::query()->delete();
    });
    app(PrepareIncidentNotifications::class)->record(new IncidentNotificationRequested($this->team->id, $incident->id, $this->monitor->id, 0, 'incident.opened'));

    expect(deliveryRows($this->team)->map(fn (NotificationLog $row): array => [$row->dedupe_key, $row->message_uuid, $row->message()->payload()])->all())->toBe($snapshot);
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    deliveryRun(deliveryRows($this->team)->last());
    Http::assertSentCount(1);
});

it('cancels every waiting escalation at recovery and sends one summary per actually notified channel', function (): void {
    $extra = app(TenantContext::class)->run($this->team->id, fn () => NotificationChannel::factory()->webhook()->active()->create());
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id, $extra->id]],
        ['after_minutes' => 10, 'channel_ids' => [$this->channel->id]],
    ]);
    $incident = deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow('2026-10-29 10:00:05');
    deliveryRun(deliveryRows($this->team)->firstOrFail());
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');

    foreach (deliveryRows($this->team)->where('step_index', 1) as $row) {
        deliveryRun($row);
    }

    deliveryTransition($this->team, $incident, 'resolve');
    $rows = deliveryRows($this->team);
    expect($rows->where('kind', NotificationKind::Resolved))->toHaveCount(2)
        ->and($rows->where('step_index', 2)->sole()->status)->toBe(DeliveryStatus::Suppressed);
    CarbonImmutable::setTestNow('2026-10-29 10:15:00');

    foreach ($rows as $row) {
        deliveryRun($row);
        deliveryRun($row);
    }

    Http::assertSentCount(5);
    expect(deliveryRows($this->team)->where('kind', NotificationKind::Resolved)->pluck('status')->all())->toBe([DeliveryStatus::Sent, DeliveryStatus::Sent]);
});

it('cancels an escalation even at its exact due instant when recovery commits first', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    $incident = deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->last();
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    deliveryTransition($this->team, $incident, 'resolve');
    deliveryRun($row);

    expect(deliveryRows($this->team)->pluck('status')->all())->toBe([DeliveryStatus::Suppressed, DeliveryStatus::Suppressed]);
    Http::assertNothingSent();
});

it('restarts absolute escalation delays on reopening and rejects old cycle jobs', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    $incident = deliveryOpen($this->team, $this->monitor);
    $old = deliveryRows($this->team)->last();
    CarbonImmutable::setTestNow('2026-10-29 10:03:00');
    deliveryTransition($this->team, $incident, 'resolve');
    CarbonImmutable::setTestNow('2026-10-29 10:04:00');
    deliveryTransition($this->team, $incident, 'reopen');
    $escalation = deliveryRows($this->team)->last();

    expect($escalation->cycle)->toBe(1)
        ->and($escalation->available_at?->toIso8601String())->toBe('2026-10-29T10:09:00+00:00')
        ->and($escalation->message()->data['cycle'])->toBe(1);
    CarbonImmutable::setTestNow('2026-10-29 10:08:59');
    deliveryRun($old);
    deliveryRun($escalation);
    Http::assertNothingSent();
    CarbonImmutable::setTestNow('2026-10-29 10:09:00');
    deliveryRun($escalation);
    Http::assertSentCount(1);
});

it('retries escalations with stable bytes without delaying the next absolute step', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 6, 'channel_ids' => [$this->channel->id]],
    ]);
    deliveryOpen($this->team, $this->monitor);
    $rows = deliveryRows($this->team);
    $this->providerResponses = Http::sequence()->pushStatus(503)->pushStatus(204)->pushStatus(204);
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    deliveryRun($rows[1]);
    CarbonImmutable::setTestNow('2026-10-29 10:05:30');
    deliveryRun($rows[1]);
    CarbonImmutable::setTestNow('2026-10-29 10:06:00');
    deliveryRun($rows[2]);
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

    expect($requests)->toHaveCount(3)
        ->and($requests[0]->body())->toBe($requests[1]->body())
        ->and($requests[0]->header('X-Signalbox-Id'))->toBe($requests[1]->header('X-Signalbox-Id'))
        ->and($requests[2]['occurred_at'])->toBe('2026-10-29T10:06:00+00:00')
        ->and(deliveryRows($this->team)[1]->attempts)->toBe(2);
});

it('recovers due escalation publications after the original broker submission was lost', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    deliveryOpen($this->team, $this->monitor);
    CarbonImmutable::setTestNow('2026-10-29 10:00:05');
    deliveryRun(deliveryRows($this->team)->firstOrFail());
    expect(app(PublishNotificationDeliveries::class)->run()['published'])->toBe(0);
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    expect(app(PublishNotificationDeliveries::class)->run()['published'])->toBe(1);
    $row = deliveryRows($this->team)->last();
    expect($this->publisher->sent[0]['delivery_id'])->toBe($row->id);
    deliveryRun($row);
    expect(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Sent);
});

it('uses half-open maintenance boundaries at admission for openings escalations and summaries', function (string $kind, string $at, bool $suppressed): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    $incident = deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->firstOrFail();

    if ($kind === 'escalated') {
        $row = deliveryRows($this->team)->last();
    }

    if ($kind === 'resolved') {
        CarbonImmutable::setTestNow('2026-10-29 10:00:05');
        deliveryRun($row);
        deliveryTransition($this->team, $incident, 'resolve');
        $row = deliveryRows($this->team)->last();
    }

    deliveryMaintenance($this->team, $this->monitor, '2026-10-29 10:05:01', '2026-10-29 10:06:00');
    CarbonImmutable::setTestNow($at);
    deliveryRun($row);
    $fresh = deliveryRows($this->team)->find($row->id);

    expect($fresh->status)->toBe($suppressed ? DeliveryStatus::Suppressed : DeliveryStatus::Sent)
        ->and($fresh->attempts)->toBe($suppressed ? 0 : 1);
    Http::assertSentCount(($kind === 'resolved' ? 1 : 0) + ($suppressed ? 0 : 1));
})->with(['opened', 'escalated', 'resolved'])->with([
    'before start' => ['2026-10-29 10:05:00', false],
    'at start' => ['2026-10-29 10:05:01', true],
    'inside' => ['2026-10-29 10:05:59', true],
    'at end' => ['2026-10-29 10:06:00', false],
]);

it('suppresses an escalation retry if maintenance starts after its failed provider attempt', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->last();
    $this->providerResponses = Http::sequence()->pushStatus(503)->pushStatus(204);
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    deliveryRun($row);
    deliveryMaintenance($this->team, $this->monitor, '2026-10-29 10:05:10', '2026-10-29 10:06:00');
    CarbonImmutable::setTestNow('2026-10-29 10:05:30');
    deliveryRun($row);
    CarbonImmutable::setTestNow('2026-10-29 10:06:00');
    deliveryRun($row);

    expect(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Suppressed)
        ->and(deliveryRows($this->team)->last()->attempts)->toBe(1);
    Http::assertSentCount(1);
});

it('does not revive a silenced escalation but still sends future steps outside maintenance', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 10, 'channel_ids' => [$this->channel->id]],
    ]);
    deliveryOpen($this->team, $this->monitor);
    deliveryMaintenance($this->team, $this->monitor, '2026-10-29 10:04:00', '2026-10-29 10:06:00');
    $rows = deliveryRows($this->team);
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    deliveryRun($rows[1]);
    CarbonImmutable::setTestNow('2026-10-29 10:10:00');
    deliveryRun($rows[1]);
    deliveryRun($rows[2]);

    Http::assertSentCount(1);
    expect(deliveryRows($this->team)[1]->status)->toBe(DeliveryStatus::Suppressed)
        ->and(deliveryRows($this->team)[2]->status)->toBe(DeliveryStatus::Sent);
});

it('records real acceptance for an escalation admitted before maintenance and waits before its summary', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    $incident = deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->last();
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    $token = (string) Str::uuid();
    $ledger = app(NotificationDeliveryLedger::class);
    expect($ledger->acquire($this->team->id, $row->id, $token))->not->toBeNull();
    deliveryTransition($this->team, $incident, 'resolve');
    $summary = deliveryRows($this->team)->last();
    CarbonImmutable::setTestNow('2026-10-29 10:05:05');
    deliveryRun($summary);
    Http::assertNothingSent();
    deliveryMaintenance($this->team, $this->monitor, '2026-10-29 10:05:05', '2026-10-29 10:06:00');
    expect($ledger->complete($this->team->id, $row->id, $token, new ChannelDeliveryReceipt()))->toBeTrue();
    deliveryRun($summary);

    expect(deliveryRows($this->team)->find($row->id)->status)->toBe(DeliveryStatus::Sent)
        ->and(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Suppressed);
    Http::assertNothingSent();
});

it('suppresses an expired escalation lease during maintenance and fences its late acknowledgement', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    deliveryOpen($this->team, $this->monitor);
    $row = deliveryRows($this->team)->last();
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    $token = (string) Str::uuid();
    $ledger = app(NotificationDeliveryLedger::class);
    expect($ledger->acquire($this->team->id, $row->id, $token))->not->toBeNull();
    deliveryMaintenance($this->team, $this->monitor, '2026-10-29 10:06:00', '2026-10-29 10:07:00');
    CarbonImmutable::setTestNow('2026-10-29 10:06:15');
    deliveryRun($row);

    expect(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Suppressed)
        ->and(deliveryRows($this->team)->last()->attempts)->toBe(1)
        ->and($ledger->complete($this->team->id, $row->id, $token, new ChannelDeliveryReceipt()))->toBeFalse();
    Http::assertNothingSent();
});

it('keeps maintenance and delayed escalation jobs inside the correct monitor and tenant', function (): void {
    deliverySteps($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$this->channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$this->channel->id]],
    ]);
    deliveryOpen($this->team, $this->monitor);
    $other = Team::factory()->create();
    $foreign = app(TenantContext::class)->run($other->id, fn (): Monitor => Monitor::factory()->create());
    $local = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create());
    deliveryMaintenance($other, $foreign, '2026-10-29 10:00:00', '2026-10-29 11:00:00');
    deliveryMaintenance($this->team, $local, '2026-10-29 10:00:00', '2026-10-29 11:00:00');
    CarbonImmutable::setTestNow('2026-10-29 10:05:00');
    $row = deliveryRows($this->team)->last();
    $ledger = app(NotificationDeliveryLedger::class);
    expect($ledger->acquire($other->id, $row->id, (string) Str::uuid()))->toBeNull();
    deliveryRun($row);

    expect(deliveryRows($this->team)->last()->status)->toBe(DeliveryStatus::Sent);
    Http::assertSentCount(1);
});
