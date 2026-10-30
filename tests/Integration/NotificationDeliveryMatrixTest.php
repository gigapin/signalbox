<?php

declare(strict_types=1);

/** Verify notification acceptance across real lifecycle events, serialized fake queue envelopes and provider adapters. */

use App\Actions\Notifications\ManageNotificationChannels;
use App\Contracts\Notifications\NotificationWorkPublisher;
use App\Contracts\Security\HostResolver;
use App\Data\Notifications\QuietHours;
use App\Enums\Monitoring\CheckError;
use App\Enums\Notifications\DeliveryStatus;
use App\Enums\Notifications\NotificationKind;
use App\Events\Incidents\IncidentOpened;
use App\Jobs\DeliverIncidentNotification;
use App\Mail\ChannelNotificationMail;
use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Services\Incidents\IncidentLifecycle;
use App\Services\Notifications\RedisNotificationWorkPublisher;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\FakeHostResolver;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:00', 'UTC'));
    Queue::fake()->serializeAndRestore();
    Mail::fake();
    Notification::fake();
    $this->team = Team::factory()->create();
    $this->monitor = app(TenantContext::class)->run(
        $this->team->id,
        fn (): Monitor => Monitor::factory()->create(['name' => 'Synthetic acceptance monitor']),
    );
    $this->baseline = DB::transactionLevel();
    $this->providerStatus = 204;
    $this->resolver = new FakeHostResolver([
        'hooks.example.test' => ['93.184.216.34'],
        'hooks.slack.com' => ['93.184.216.34'],
        'api.telegram.org' => ['93.184.216.34'],
    ]);
    app()->instance(HostResolver::class, $this->resolver);
    Http::fake(function (Request $request) {
        expect(DB::transactionLevel())->toBe($this->baseline);

        if ($this->providerStatus >= 400) {
            return Http::response('private provider response must not enter delivery logs', $this->providerStatus);
        }

        return match (parse_url($request->url(), PHP_URL_HOST)) {
            'hooks.slack.com' => Http::response('ok', 200),
            'api.telegram.org' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
            default => Http::response('', 204),
        };
    });
});

afterEach(function (): void {
    $this->travelBack();
});

/**
 * @param Team $team Explicit owner of the new recipient.
 * @param string $family Supported adapter family.
 * @return NotificationChannel Synthetic active recipient; SMS state intentionally exercises the stub's fail-closed behavior.
 */
function matrixChannel(Team $team, string $family): NotificationChannel
{
    return app(TenantContext::class)->run($team->id, function () use ($family): NotificationChannel {
        $factory = NotificationChannel::factory();
        $factory = match ($family) {
            'email' => $factory,
            'slack' => $factory->slack(),
            'telegram' => $factory->telegram(),
            'webhook' => $factory->webhook(),
            'sms' => $factory->sms(),
            default => throw new LogicException('Unknown acceptance-test adapter.'),
        };

        return $factory->active()->create();
    });
}

/**
 * @param Team $team Owning tenant.
 * @param list<array{after_minutes: int, channel_ids: list<int>}> $steps Absolute schedule to freeze on opening.
 */
function matrixPolicy(Team $team, array $steps): void
{
    app(TenantContext::class)->run($team->id, fn () => EscalationPolicy::factory()->create(['is_default' => true, 'steps_json' => $steps]));
}

/**
 * @param Team $team Owning tenant of every locked resource.
 * @param Monitor $monitor Monitor whose incident transitions.
 * @param Incident|null $incident Null opens a new incident; otherwise resolves or reopens this incident.
 * @param bool $reopen Whether an existing incident reopens instead of resolving.
 * @return Incident Current incident; real internal and after-commit events remain enabled.
 */
function matrixTransition(Team $team, Monitor $monitor, ?Incident $incident = null, bool $reopen = false): Incident
{
    return app(TenantContext::class)->run($team->id, fn (): Incident => DB::transaction(function () use ($team, $monitor, $incident, $reopen): Incident {
        Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
        $locked = Monitor::query()->whereKey($monitor->id)->lockForUpdate()->firstOrFail();
        $lifecycle = app(IncidentLifecycle::class);
        $now = CarbonImmutable::now('UTC');

        if ($incident === null) {
            return $lifecycle->open($locked, $now->subMinute(), CheckError::UnexpectedStatus, $now);
        }

        $current = Incident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();

        if ($reopen) {
            $lifecycle->reopen($current, $now);
        } else {
            $lifecycle->resolve($current, null, 'Synthetic private recovery note.', $now);
        }

        return $current;
    }));
}

/**
 * @param Team $team Explicit tenant whose logs are inspected.
 * @return Collection<int, NotificationLog> Persisted deliveries ordered by preparation identity.
 */
function matrixRows(Team $team): Collection
{
    return app(TenantContext::class)->run($team->id, fn (): Collection => NotificationLog::query()->orderBy('id')->get());
}

/**
 * @param NotificationLog $row Prepared identity published through the production Redis adapter into QueueFake.
 * @return DeliverIncidentNotification Latest serialized/restored queue envelope for that identity.
 * @throws LogicException When the real event/publisher chain failed to submit the row.
 */
function matrixJob(NotificationLog $row): DeliverIncidentNotification
{
    $job = Queue::pushed(
        DeliverIncidentNotification::class,
        fn (DeliverIncidentNotification $job): bool => $job->teamId === $row->team_id && $job->deliveryId === $row->id,
    )->last();

    if (!$job instanceof DeliverIncidentNotification) {
        throw new LogicException('The lifecycle did not publish the prepared notification.');
    }

    return $job;
}

/**
 * @param DeliverIncidentNotification $job Broker envelope; QueueFake does not execute it automatically or implement wall-clock delays.
 * Execute the real middleware/handler pipeline; the ledger remains the authority for due-time and attempt admission.
 */
function matrixExecute(DeliverIncidentNotification $job): void
{
    app(Pipeline::class)
        ->send($job)
        ->through($job->middleware())
        ->then(fn (DeliverIncidentNotification $current) => app()->call([$current, 'handle']));
}

it('publishes and executes one opening escalation and recovery per recipient despite duplicate queue envelopes', function (string $family): void {
    expect(app(NotificationWorkPublisher::class))->toBeInstanceOf(RedisNotificationWorkPublisher::class);
    $channel = matrixChannel($this->team, $family);
    matrixPolicy($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$channel->id]],
    ]);
    $incident = matrixTransition($this->team, $this->monitor);
    $opening = matrixRows($this->team)->firstOrFail();
    $escalation = matrixRows($this->team)->last();
    Queue::assertPushedOn('notifications', DeliverIncidentNotification::class);
    Queue::assertPushed(DeliverIncidentNotification::class, 2);
    matrixExecute(matrixJob($opening));
    matrixExecute(matrixJob($escalation));
    Http::assertNothingSent();
    Mail::assertNothingSent();
    $this->travel(5)->seconds();
    matrixExecute(matrixJob($opening));
    event(new IncidentOpened($this->team->id, $incident->id, $this->monitor->id));
    matrixExecute(matrixJob($opening));
    matrixExecute(matrixJob($escalation));
    expect(matrixRows($this->team)->firstOrFail()->attempts)->toBe(1)
        ->and(matrixRows($this->team)->last()->attempts)->toBe(0);
    $this->travel(5)->minutes();
    expect(CarbonImmutable::now()->toIso8601String())->toBe('2026-10-30T10:05:05+00:00');
    matrixExecute(matrixJob($escalation));
    matrixExecute(matrixJob($escalation));
    matrixTransition($this->team, $this->monitor, $incident);
    $summary = matrixRows($this->team)->last();
    expect($summary->kind)->toBe(NotificationKind::Resolved);
    $this->travel(5)->seconds();
    matrixExecute(matrixJob($summary));
    matrixExecute(matrixJob($summary));

    expect(matrixRows($this->team)->pluck('status')->all())->toBe([DeliveryStatus::Sent, DeliveryStatus::Sent, DeliveryStatus::Sent])
        ->and(matrixRows($this->team)->pluck('attempts')->all())->toBe([1, 1, 1])
        ->and(matrixRows($this->team)->pluck('message_uuid')->unique())->toHaveCount(3);
    Notification::assertNothingSent();

    if ($family === 'email') {
        Mail::assertSent(ChannelNotificationMail::class, 3);
        Http::assertNothingSent();
    } else {
        Http::assertSentCount(3);
        Mail::assertNothingSent();
    }
})->with(['email', 'slack', 'telegram', 'webhook']);

it('retries provider outages with fresh queue fences and stable recipient messages', function (string $family): void {
    $channel = matrixChannel($this->team, $family);
    matrixPolicy($this->team, [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]);
    matrixTransition($this->team, $this->monitor);
    $row = matrixRows($this->team)->sole();
    $first = matrixJob($row);
    $this->providerStatus = 503;
    $this->travel(5)->seconds();
    matrixExecute($first);
    expect(matrixRows($this->team)->sole()->status)->toBe(DeliveryStatus::Pending)
        ->and(matrixRows($this->team)->sole()->attempts)->toBe(1)
        ->and(matrixRows($this->team)->sole()->response)->not->toContain('private provider');
    $this->travel(29)->seconds();
    $this->artisan('notifications:dispatch')->expectsOutput('Published 0 notification deliveries; 0 publication failures.')->assertSuccessful();
    $this->travel(1)->seconds();
    $this->artisan('notifications:dispatch')->expectsOutput('Published 1 notification deliveries; 0 publication failures.')->assertSuccessful();
    $retry = matrixJob($row);
    expect($retry->token)->not->toBe($first->token);
    $this->providerStatus = 204;
    matrixExecute($retry);
    matrixExecute($first);
    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

    expect(matrixRows($this->team)->sole()->status)->toBe(DeliveryStatus::Sent)
        ->and(matrixRows($this->team)->sole()->attempts)->toBe(2)
        ->and($requests)->toHaveCount(2)
        ->and($requests[0]->body())->toBe($requests[1]->body());

    if ($family === 'webhook') {
        foreach ($requests as $request) {
            $timestamp = $request->header('X-Signalbox-Timestamp')[0];
            $signature = hash_hmac('sha256', $timestamp.'.'.$request->body(), $channel->config_json['signing_secret']);
            expect($request->header('X-Signalbox-Id'))->toBe([$row->message_uuid])
                ->and($request->header('X-Signalbox-Signature'))->toBe(['v1='.$signature])
                ->and(hash_hmac('sha256', $timestamp.'.'.$request->body().' ', $channel->config_json['signing_secret']))->not->toBe($signature);
        }

        expect($requests[0]->header('X-Signalbox-Timestamp'))->not->toBe($requests[1]->header('X-Signalbox-Timestamp'));
    }
})->with(['slack', 'telegram', 'webhook']);

it('exhausts a provider outage without creating a recovery summary for an unnotified recipient', function (string $family): void {
    $channel = matrixChannel($this->team, $family);
    matrixPolicy($this->team, [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]);
    $incident = matrixTransition($this->team, $this->monitor);
    $row = matrixRows($this->team)->sole();
    $this->providerStatus = 503;
    $this->travel(5)->seconds();

    foreach ([30, 120, 0] as $delay) {
        matrixExecute(matrixJob($row));
        $this->travel($delay)->seconds();
        $this->artisan('notifications:dispatch')->assertSuccessful();
    }

    matrixTransition($this->team, $this->monitor, $incident);
    expect(matrixRows($this->team))->toHaveCount(1)
        ->and(matrixRows($this->team)->sole()->status)->toBe(DeliveryStatus::Failed)
        ->and(matrixRows($this->team)->sole()->attempts)->toBe(3);
    Http::assertSentCount(3);
})->with(['slack', 'telegram', 'webhook']);

it('persists a safe SMTP failure and retries the same snapshot through a fresh queue envelope', function (): void {
    $channel = matrixChannel($this->team, 'email');
    matrixPolicy($this->team, [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]);
    matrixTransition($this->team, $this->monitor);
    $row = matrixRows($this->team)->sole();
    $mailer = Mockery::mock(\Illuminate\Mail\Mailer::class);
    Mail::shouldReceive('mailer')->twice()->with('smtp')->andReturn($mailer);
    $mailer->shouldReceive('to')->twice()->with($channel->config_json['address'])->andReturnSelf();
    $messages = [];
    $baseline = $this->baseline;
    $mailer->shouldReceive('send')->twice()->andReturnUsing(function (ChannelNotificationMail $mail) use (&$messages, $baseline): void {
        expect(DB::transactionLevel())->toBe($baseline);
        $messages[] = $mail->message->payload();

        if (count($messages) === 1) {
            throw new TransportException('private SMTP password and server payload');
        }
    });
    $this->travel(5)->seconds();
    matrixExecute(matrixJob($row));
    expect(matrixRows($this->team)->sole()->status)->toBe(DeliveryStatus::Pending)
        ->and(matrixRows($this->team)->sole()->response)->toBe('The email provider could not accept the notification.');
    $this->travel(30)->seconds();
    $this->artisan('notifications:dispatch')->assertSuccessful();
    matrixExecute(matrixJob($row));

    expect(matrixRows($this->team)->sole()->status)->toBe(DeliveryStatus::Sent)
        ->and(matrixRows($this->team)->sole()->attempts)->toBe(2)
        ->and($messages)->toHaveCount(2)
        ->and($messages[0])->toBe($messages[1]);
    Http::assertNothingSent();
});

it('combines quiet hours and maintenance at a retry boundary without catch-up or duplicate summaries', function (string $quietStart, string $quietEnd, string $maintenanceStart, string $maintenanceEnd, ?string $reason): void {
    $channel = matrixChannel($this->team, 'webhook');
    matrixPolicy($this->team, [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]);
    $incident = matrixTransition($this->team, $this->monitor);
    $row = matrixRows($this->team)->sole();
    $this->providerStatus = 503;
    $this->travel(5)->seconds();
    matrixExecute(matrixJob($row));
    app(ManageNotificationChannels::class)->setQuietHours($this->team->owner, $this->team->id, $channel->id, new QuietHours($quietStart, $quietEnd, 'UTC'));
    app(TenantContext::class)->run($this->team->id, function () use ($maintenanceStart, $maintenanceEnd): void {
        $window = new MaintenanceWindow();
        $window->forceFill([
            'monitor_id' => $this->monitor->id,
            'starts_at' => CarbonImmutable::parse('2026-10-30 '.$maintenanceStart, 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-10-30 '.$maintenanceEnd, 'UTC'),
            'reason' => 'Synthetic acceptance maintenance.',
        ])->save();
    });
    $this->travel(55)->seconds();
    $this->providerStatus = 204;
    $this->artisan('notifications:dispatch')->assertSuccessful();
    matrixExecute(matrixJob($row));
    $this->travel(1)->minutes();
    matrixExecute(matrixJob($row));
    matrixTransition($this->team, $this->monitor, $incident);
    $opening = matrixRows($this->team)->firstOrFail();

    expect($opening->status)->toBe($reason === null ? DeliveryStatus::Sent : DeliveryStatus::Suppressed)
        ->and($opening->attempts)->toBe($reason === null ? 2 : 1)
        ->and($opening->response)->toBe($reason ?? 'Accepted by the provider.')
        ->and($opening->message_uuid)->toBe($row->message_uuid)
        ->and(matrixRows($this->team)->where('kind', NotificationKind::Resolved))->toHaveCount($reason === null ? 1 : 0);
    Http::assertSentCount($reason === null ? 2 : 1);
})->with([
    'quiet end equals maintenance start' => ['10:00', '10:01', '10:01:00', '10:02:00', 'The monitor is under planned maintenance.'],
    'maintenance end equals quiet start' => ['10:01', '10:02', '10:00:05', '10:01:00', 'The channel is within its configured quiet hours.'],
    'overlap keeps maintenance priority' => ['10:00', '10:02', '10:00:05', '10:02:00', 'The monitor is under planned maintenance.'],
    'both ends allow the retry' => ['10:00', '10:01', '10:00:05', '10:01:00', null],
]);

it('keeps a declared SMS stub suppressed across escalation and recovery through the job pipeline', function (): void {
    $channel = matrixChannel($this->team, 'sms');
    matrixPolicy($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$channel->id]],
    ]);
    $incident = matrixTransition($this->team, $this->monitor);
    $rows = matrixRows($this->team);
    $this->travel(5)->seconds();
    matrixExecute(matrixJob($rows[0]));
    $this->travel(5)->minutes();
    matrixExecute(matrixJob($rows[1]));
    matrixTransition($this->team, $this->monitor, $incident);

    expect(matrixRows($this->team))->toHaveCount(2)
        ->and(matrixRows($this->team)->pluck('status')->all())->toBe([DeliveryStatus::Suppressed, DeliveryStatus::Suppressed])
        ->and(matrixRows($this->team)->pluck('sent_at')->all())->toBe([null, null])
        ->and(matrixRows($this->team)->pluck('attempts')->all())->toBe([1, 1]);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('restores worker context between interleaved tenant envelopes and rejects a forged tenant delivery pair', function (): void {
    $channel = matrixChannel($this->team, 'webhook');
    matrixPolicy($this->team, [['after_minutes' => 0, 'channel_ids' => [$channel->id]]]);
    matrixTransition($this->team, $this->monitor);
    $local = matrixRows($this->team)->sole();
    $other = Team::factory()->create();
    $foreign = matrixChannel($other, 'webhook');
    $monitor = app(TenantContext::class)->run($other->id, function () use ($foreign): Monitor {
        $foreign->forceFill(['config_json' => [...$foreign->config_json, 'url' => 'https://hooks.example.test/tenant-b']])->save();

        return Monitor::factory()->create();
    });
    matrixPolicy($other, [['after_minutes' => 0, 'channel_ids' => [$foreign->id]]]);
    $incident = matrixTransition($other, $monitor);
    $remote = matrixRows($other)->sole();
    $this->travel(5)->seconds();
    $jobs = [
        new DeliverIncidentNotification($other->id, $local->id, (string) Str::uuid()),
        matrixJob($local),
        matrixJob($remote),
        matrixJob($local),
    ];
    app(TenantContext::class)->run($other->id, function () use ($jobs, $other): void {
        foreach ($jobs as $job) {
            matrixExecute($job);
            expect(app(TenantContext::class)->requireTeamId())->toBe($other->id);
        }
    });

    expect(matrixRows($this->team)->sole()->status)->toBe(DeliveryStatus::Sent)
        ->and(matrixRows($other)->sole()->status)->toBe(DeliveryStatus::Sent);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.example.test/tenant-b'
        && $request['data']['incident_id'] === $incident->id
        && $request->header('X-Signalbox-Id') === [$remote->message_uuid]);
});

it('coalesces obsolete queued cycles while keeping one final recovery summary after reopening', function (): void {
    $channel = matrixChannel($this->team, 'webhook');
    matrixPolicy($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$channel->id]],
    ]);
    $incident = matrixTransition($this->team, $this->monitor);
    $first = matrixRows($this->team)->firstOrFail();
    $oldEscalation = matrixRows($this->team)->last();
    $this->travel(5)->seconds();
    matrixExecute(matrixJob($first));
    matrixTransition($this->team, $this->monitor, $incident);
    $obsoleteSummary = matrixJob(matrixRows($this->team)->last());
    $this->travel(1)->seconds();
    matrixTransition($this->team, $this->monitor, $incident, true);
    $reopened = matrixRows($this->team)->where('cycle', 1)->firstOrFail();
    expect($reopened->message()->event)->toBe('incident.reopened')
        ->and(matrixRows($this->team)->last()->available_at?->toIso8601String())->toBe('2026-10-30T10:05:06+00:00');
    $this->travel(5)->seconds();
    matrixExecute($obsoleteSummary);
    matrixExecute(matrixJob($oldEscalation));
    matrixExecute(matrixJob($reopened));
    matrixTransition($this->team, $this->monitor, $incident);
    $this->travel(5)->seconds();
    $summary = matrixRows($this->team)->last();
    matrixExecute(matrixJob($summary));

    foreach (matrixRows($this->team) as $row) {
        matrixExecute(matrixJob($row));
    }

    expect(matrixRows($this->team)->pluck('status')->all())->toBe([
        DeliveryStatus::Sent,
        DeliveryStatus::Suppressed,
        DeliveryStatus::Suppressed,
        DeliveryStatus::Sent,
        DeliveryStatus::Suppressed,
        DeliveryStatus::Sent,
    ])->and(matrixRows($this->team)->where('kind', NotificationKind::Resolved)->where('status', DeliveryStatus::Sent))->toHaveCount(1)
        ->and(DB::table('incident_notification_events')->where('team_id', $this->team->id)->count())->toBe(4);
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'Synthetic private recovery note.'));
});

it('keeps simultaneous incidents on different monitors independent even when they share one recipient', function (): void {
    $channel = matrixChannel($this->team, 'webhook');
    matrixPolicy($this->team, [
        ['after_minutes' => 0, 'channel_ids' => [$channel->id]],
        ['after_minutes' => 5, 'channel_ids' => [$channel->id]],
    ]);
    $monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create());
    $first = matrixTransition($this->team, $this->monitor);
    $second = matrixTransition($this->team, $monitor);
    $this->travel(5)->seconds();

    foreach (matrixRows($this->team)->where('kind', NotificationKind::Opened) as $row) {
        matrixExecute(matrixJob($row));
    }

    matrixTransition($this->team, $this->monitor, $first);
    $this->travel(5)->seconds();
    matrixExecute(matrixJob(matrixRows($this->team)->last()));
    $this->travel(5)->minutes();

    foreach (matrixRows($this->team) as $row) {
        matrixExecute(matrixJob($row));
    }

    $rows = matrixRows($this->team);
    expect($rows->where('incident_id', $first->id)->pluck('status')->all())->toBe([DeliveryStatus::Sent, DeliveryStatus::Suppressed, DeliveryStatus::Sent])
        ->and($rows->where('incident_id', $second->id)->pluck('status')->all())->toBe([DeliveryStatus::Sent, DeliveryStatus::Sent])
        ->and($rows->where('status', DeliveryStatus::Sent)->pluck('message_uuid')->unique())->toHaveCount(4);
    Http::assertSentCount(4);
});
