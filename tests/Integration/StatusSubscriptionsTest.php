<?php

declare(strict_types=1);

/** Verify public email consent, tenant isolation, publication snapshots and recoverable SMTP attempts on PostgreSQL. */

use App\Actions\Incidents\ManageIncidents;
use App\Actions\StatusPages\ManageStatusPages;
use App\Data\StatusPages\StatusPageInput;
use App\Data\StatusPages\StatusPageTheme;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Exceptions\MissingTenantContext;
use App\Jobs\DeliverStatusPageEmail;
use App\Mail\StatusPageMail;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageDelivery;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Services\StatusPages\StatusEmailLedger;
use App\Services\StatusPages\StatusSubscriptionLinks;
use App\Services\StatusPages\StatusSubscriptions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedTeamFixture;
use Tests\Support\TestDatabaseGuard;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-15T12:00:00Z'));
    Cache::flush();
    Mail::fake();
    $this->team = Team::factory()->create();
    $this->monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create(['name' => 'private-monitor-name']));
    $this->page = subscriptionPage($this->team, $this->monitor);
    $this->incident = app(TenantContext::class)->run($this->team->id, fn (): Incident => Incident::factory()->create(['monitor_id' => $this->monitor->id, 'root_cause_note' => 'private-cause-note']));
});

/**
 * @param Team $team Owning tenant.
 * @param Monitor $monitor Monitor selected for the public component.
 * @param string $slug Globally unique synthetic address.
 * @return StatusPage Published page created through the real action.
 */
function subscriptionPage(Team $team, Monitor $monitor, string $slug = 'email-status'): StatusPage
{
    return app(ManageStatusPages::class)->create($team->owner, $team->id, new StatusPageInput(
        $slug,
        'Public status title',
        null,
        new StatusPageTheme(StatusPageThemeMode::System, StatusPageAccent::Emerald),
        true,
        null,
        false,
        [['name' => 'Public API', 'monitor_ids' => [$monitor->id]]],
    ));
}

/**
 * @param string $email Synthetic mailbox.
 * @param bool $verified Whether to explicitly confirm mailbox consent.
 * @return StatusPageSubscriber Recipient loaded inside the current fixture's tenant.
 */
function subscriptionRecipient(string $email = 'reader@example.test', bool $verified = true): StatusPageSubscriber
{
    $test = test();
    app(StatusSubscriptions::class)->subscribe($test->page, app('session.store'), $email);
    $subscriber = app(TenantContext::class)->run($test->team->id, fn (): StatusPageSubscriber => StatusPageSubscriber::query()->orderByDesc('id')->firstOrFail());

    if ($verified) {
        app(StatusSubscriptions::class)->act($test->team->id, $test->page->id, $subscriber->id, $subscriber->generation, 'verify', true);
    }

    return app(TenantContext::class)->run($test->team->id, fn (): StatusPageSubscriber => $subscriber->fresh());
}

/** @return IncidentUpdate Newly published explicit message, with a private note left unpublished. */
function subscriptionPublication(): IncidentUpdate
{
    $test = test();
    $entry = app(ManageIncidents::class)->postUpdate($test->team->owner, $test->team->id, $test->incident->id, IncidentStatus::Investigating, 'Public update text');

    return app(ManageIncidents::class)->publish($test->team->owner, $test->team->id, $entry->id);
}

/** @return StatusPageDelivery The current fixture's newest public update intent. */
function subscriptionDelivery(): StatusPageDelivery
{
    return app(TenantContext::class)->run(test()->team->id, fn (): StatusPageDelivery => StatusPageDelivery::query()->where('kind', 'update')->orderByDesc('id')->firstOrFail());
}

/**
 * @param StatusPageSubscriber $subscriber Recipient whose lifecycle is inspected.
 * @return StatusPageSubscriber Fresh scoped lifecycle.
 */
function freshSubscription(StatusPageSubscriber $subscriber): StatusPageSubscriber
{
    return app(TenantContext::class)->run($subscriber->team_id, fn (): StatusPageSubscriber => $subscriber->fresh());
}

it('accepts anonymous subscription requests without disclosing existing consent or sending synchronously', function (): void {
    foreach (['Reader@Example.test', 'reader@example.test'] as $address) {
        $this->post('/status/email-status/subscribe', ['email' => $address])->assertRedirect('/status/email-status')
            ->assertSessionHas('status', 'status-subscription-requested');
    }

    expect(DB::table('status_page_subscribers')->count())->toBe(1)
        ->and(DB::table('status_page_deliveries')->count())->toBe(1);
    Mail::assertNothingSent();
    $this->get('/status/email-status')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('subscribePath', '/status/email-status/subscribe')->missing('subscribers'));
});

it('validates bounded mailbox input without network requests', function (mixed $email): void {
    $this->post('/status/email-status/subscribe', ['email' => $email])->assertSessionHasErrors('email');
    expect(DB::table('status_page_subscribers')->count())->toBe(0);
})->with([null, 'invalid', str_repeat('a', 255).'@example.test', "reader@example.test\r\nBcc: other@example.test"]);

it('requires live visibility and the current password for subscription requests', function (): void {
    $this->post('/status/unknown-status/subscribe', ['email' => 'a@example.test'])->assertNotFound();
    DB::table('status_pages')->where('id', $this->page->id)->update(['is_public' => false]);
    $this->post('/status/email-status/subscribe', ['email' => 'a@example.test'])->assertNotFound();
    DB::table('status_pages')->where('id', $this->page->id)->update(['is_public' => true, 'password_hash' => bcrypt('correct-password')]);
    $this->post('/status/email-status/subscribe', ['email' => 'a@example.test'])->assertForbidden();
    $this->post('/status/email-status/unlock', ['password' => 'correct-password'])->assertRedirect();
    $this->post('/status/email-status/subscribe', ['email' => 'a@example.test'])->assertRedirect();
    expect(DB::table('status_page_subscribers')->count())->toBe(1);
});

it('limits anonymous requests across page addresses by visitor IP', function (): void {
    foreach (range(1, 5) as $index) {
        $this->post('/status/email-status/subscribe', ['email' => 'reader'.$index.'@example.test'])->assertRedirect();
    }

    $this->post('/status/unknown-status/subscribe', ['email' => 'extra@example.test'])->assertTooManyRequests();
});

it('encrypts mailbox and capability content and hides them from model serialization and public props', function (): void {
    $subscriber = subscriptionRecipient(verified: false);
    $raw = DB::table('status_page_subscribers')->first();
    $delivery = DB::table('status_page_deliveries')->first();
    expect($raw->email)->not->toContain('reader@example.test')
        ->and($delivery->payload)->not->toContain($subscriber->generation)
        ->and($subscriber->toArray())->not->toHaveKeys(['email', 'email_hash', 'generation', 'access_hash']);
    $this->get('/status/email-status')->assertDontSee('reader@example.test', false)->assertDontSee($subscriber->generation, false);
    expect(fn () => StatusPageSubscriber::query()->count())->toThrow(MissingTenantContext::class)
        ->and(fn () => StatusPageDelivery::query()->count())->toThrow(MissingTenantContext::class);
});

it('uses the configured public origin for signed mail links even under an untrusted request host', function (): void {
    config(['app.url' => 'https://status.example.test']);
    URL::forceRootUrl('https://untrusted.example.test');
    $subscriber = subscriptionRecipient(verified: false);
    $link = app(StatusSubscriptionLinks::class)->action($subscriber, 'verify');
    expect($link)->toStartWith('https://status.example.test/status/subscriptions/')
        ->not->toContain('reader@example.test')->not->toContain('untrusted.example.test');
});

it('requires explicit POST consent and leaves GET links inert for email scanners', function (string $action): void {
    $subscriber = subscriptionRecipient(verified: $action === 'unsubscribe');
    $link = app(StatusSubscriptionLinks::class)->action($subscriber, $action);
    $response = $this->get($link)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('StatusPages/Subscription')->where('action', $action)->where('completed', false)->missing('email')->missing('page'));
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    expect($action === 'verify' ? freshSubscription($subscriber)->verified_at : freshSubscription($subscriber)->unsubscribed_at)->toBeNull();

    foreach (range(1, 2) as $ignored) {
        $this->post($link)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('completed', true));
    }

    expect($action === 'verify' ? freshSubscription($subscriber)->verified_at : freshSubscription($subscriber)->unsubscribed_at)->not->toBeNull();
})->with(['verify', 'unsubscribe']);

it('requires a valid web CSRF token for both anonymous consent mutations', function (): void {
    $subscriber = subscriptionRecipient(verified: false);
    $link = app(StatusSubscriptionLinks::class)->action($subscriber, 'verify');
    app()->instance('env', 'production');

    try {
        $this->post('/status/email-status/subscribe', ['email' => 'other@example.test'])->assertStatus(419);
        $this->post($link)->assertStatus(419);
        $token = Str::random(40);
        $this->withSession(['_token' => $token])->post($link, ['_token' => $token])->assertOk();
    } finally {
        app()->instance('env', 'testing');
    }
});

it('rejects tampered tenant page recipient generation and action coordinates before any mutation', function (string $coordinate): void {
    $subscriber = subscriptionRecipient(verified: false);
    $link = app(StatusSubscriptionLinks::class)->action($subscriber, 'verify');
    $parameters = [
        'teamId' => $this->team->id,
        'pageId' => $this->page->id,
        'subscriberId' => $subscriber->id,
        'generation' => $subscriber->generation,
        'action' => 'verify',
    ];
    $path = '/status/subscriptions/'.implode('/', $parameters);
    $parameters[$coordinate] = match ($coordinate) {
        'generation' => (string) Str::uuid(),
        'action' => 'unsubscribe',
        default => 999999,
    };
    $this->post(str_replace($path, '/status/subscriptions/'.implode('/', $parameters), $link))->assertForbidden();
    expect(freshSubscription($subscriber)->verified_at)->toBeNull();
})->with(['teamId', 'pageId', 'subscriberId', 'generation', 'action']);

it('expires verification exactly at its persisted one-hour deadline while unsubscribe stays available', function (): void {
    $subscriber = subscriptionRecipient(verified: false);
    $verify = app(StatusSubscriptionLinks::class)->action($subscriber, 'verify');
    $unsubscribe = app(StatusSubscriptionLinks::class)->action($subscriber, 'unsubscribe');
    $this->travel(3599)->seconds();
    $this->get($verify)->assertOk();
    $this->travel(1)->seconds();
    $this->post($verify)->assertNotFound();
    $this->travel(1)->seconds();
    $this->get($verify)->assertForbidden();
    $this->post($unsubscribe)->assertOk();
});

it('resends only after the mailbox cooldown and revokes old verification capabilities', function (): void {
    $subscriber = subscriptionRecipient(verified: false);
    $old = app(StatusSubscriptionLinks::class)->action($subscriber, 'verify');
    $this->travel(599)->seconds();
    subscriptionRecipient(verified: false);
    expect(freshSubscription($subscriber)->generation)->toBe($subscriber->generation);
    $this->travel(1)->seconds();
    $replacement = subscriptionRecipient(verified: false);
    expect($replacement->id)->toBe($subscriber->id)->and($replacement->generation)->not->toBe($subscriber->generation);
    $this->post($old)->assertNotFound();
    $this->post(app(StatusSubscriptionLinks::class)->action($replacement, 'verify'))->assertOk();
});

it('freezes only verified recipients at explicit publication and never sends private updates or backfills new subscribers', function (): void {
    $active = subscriptionRecipient();
    subscriptionRecipient('pending@example.test', false);
    app(ManageIncidents::class)->postUpdate($this->team->owner, $this->team->id, $this->incident->id, IncidentStatus::Investigating, 'PRIVATE NOTE');
    expect(DB::table('status_page_deliveries')->where('kind', 'update')->count())->toBe(0);

    $entry = subscriptionPublication();
    $delivery = subscriptionDelivery();
    expect($delivery->subscriber_id)->toBe($active->id)->and($delivery->incident_update_id)->toBe($entry->id);

    subscriptionRecipient('later@example.test');
    app(ManageIncidents::class)->publish($this->team->owner, $this->team->id, $entry->id);
    expect(DB::table('status_page_deliveries')->where('kind', 'update')->count())->toBe(1);
});

it('includes only published text and public labels in email and keeps stable identity across retry', function (): void {
    subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    $ledger = app(StatusEmailLedger::class);
    $first = $ledger->acquire($this->team->id, $delivery->id, (string) Str::uuid());
    expect($first->text)->toContain('Public status title', 'Public API', 'Public update text', 'Unsubscribe:')
        ->not->toContain('private-monitor-name', 'private-cause-note', $this->team->name);
    $mail = new StatusPageMail($first);
    expect($mail->render())->toContain('Public update text', 'Unsubscribe:')
        ->and($mail->headers()->messageId)->toBe($first->messageId.'@status.signalbox.invalid');
    $ledger->finish($this->team->id, $delivery->id, DB::table('status_page_deliveries')->where('id', $delivery->id)->value('claim_token'), false);
    $this->travel(30)->seconds();
    $second = $ledger->acquire($this->team->id, $delivery->id, (string) Str::uuid());
    expect($second->text)->toBe($first->text)->and($second->messageId)->toBe($first->messageId);
});

it('rolls publication and email intents back together', function (): void {
    subscriptionRecipient();
    $entry = app(ManageIncidents::class)->postUpdate($this->team->owner, $this->team->id, $this->incident->id, IncidentStatus::Investigating, 'Not committed');
    try {
        DB::transaction(function () use ($entry): void {
            app(ManageIncidents::class)->publish($this->team->owner, $this->team->id, $entry->id);
            expect(DB::table('status_page_deliveries')->where('kind', 'update')->count())->toBe(1);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('incident_updates')->where('id', $entry->id)->value('is_public'))->toBeFalse()
        ->and(DB::table('status_page_deliveries')->where('kind', 'update')->count())->toBe(0);
});

it('does not publish when preparing the durable audience fails', function (): void {
    subscriptionRecipient();
    $entry = app(ManageIncidents::class)->postUpdate($this->team->owner, $this->team->id, $this->incident->id, IncidentStatus::Investigating, 'Cannot publish');
    StatusPageDelivery::creating(static function (StatusPageDelivery $delivery): void {
        if ($delivery->kind === 'update') {
            throw new RuntimeException('preparation failed');
        }
    });
    expect(fn () => app(ManageIncidents::class)->publish($this->team->owner, $this->team->id, $entry->id))->toThrow(RuntimeException::class);
    expect(DB::table('incident_updates')->where('id', $entry->id)->value('is_public'))->toBeFalse();
});

it('cancels pending updates on unsubscribe and requires fresh opt-in after resubscription', function (): void {
    $subscriber = subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    $oldUnsubscribe = app(StatusSubscriptionLinks::class)->action($subscriber, 'unsubscribe');
    $this->post($oldUnsubscribe)->assertOk();
    expect(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('status'))->toBe('suppressed');
    $replacement = subscriptionRecipient(verified: false);
    expect($replacement->generation)->not->toBe($subscriber->generation)->and($replacement->verified_at)->toBeNull();
    $this->post($oldUnsubscribe)->assertNotFound();
    $this->post(app(StatusSubscriptionLinks::class)->action($replacement, 'verify'))->assertOk();
    expect(app(StatusEmailLedger::class)->acquire($this->team->id, $delivery->id, (string) Str::uuid()))->toBeNull();
});

it('rechecks live consent visibility password and monitor selection before each attempt', function (string $change): void {
    $subscriber = subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();

    match ($change) {
        'draft' => DB::table('status_pages')->where('id', $this->page->id)->update(['is_public' => false]),
        'password' => DB::table('status_pages')->where('id', $this->page->id)->update(['password_hash' => bcrypt('new-password')]),
        'selection' => DB::table('status_page_component_monitors')->where('status_page_id', $this->page->id)->delete(),
        'unverified' => DB::table('status_page_subscribers')->where('id', $subscriber->id)->update(['verified_at' => null]),
    };

    expect(app(StatusEmailLedger::class)->acquire($this->team->id, $delivery->id, (string) Str::uuid()))->toBeNull()
        ->and(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('status'))->toBe('suppressed');
})->with(['draft', 'password', 'selection', 'unverified']);

it('does not confirm a protected subscription after the password changes but still allows cancellation', function (): void {
    $subscriber = subscriptionRecipient(verified: false);
    DB::table('status_pages')->where('id', $this->page->id)->update(['password_hash' => bcrypt('new-password')]);
    $this->post(app(StatusSubscriptionLinks::class)->action($subscriber, 'verify'))->assertNotFound();
    $this->post(app(StatusSubscriptionLinks::class)->action($subscriber, 'unsubscribe'))->assertOk();
});

it('isolates identical mailboxes across pages and teams and rejects foreign job identities', function (): void {
    $subscriber = subscriptionRecipient();
    $otherTeam = Team::factory()->create();
    $otherMonitor = app(TenantContext::class)->run($otherTeam->id, fn (): Monitor => Monitor::factory()->create());
    $otherPage = subscriptionPage($otherTeam, $otherMonitor, 'other-status');
    app(StatusSubscriptions::class)->subscribe($otherPage, app('session.store'), 'reader@example.test');
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    expect(DB::table('status_page_subscribers')->count())->toBe(2)
        ->and(DB::table('status_page_deliveries')->where('kind', 'update')->count())->toBe(1);
    app(TenantContext::class)->run($otherTeam->id, function () use ($subscriber, $delivery, $otherTeam): void {
        expect(StatusPageSubscriber::query()->find($subscriber->id))->toBeNull()
            ->and(StatusPageDelivery::query()->find($delivery->id))->toBeNull()
            ->and(app(StatusEmailLedger::class)->acquire($otherTeam->id, $delivery->id, (string) Str::uuid()))->toBeNull();
        expect(app(TenantContext::class)->requireTeamId())->toBe($otherTeam->id);
    });
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('admits duplicate queue envelopes once and fences stale completions after lease recovery', function (): void {
    subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    $ledger = app(StatusEmailLedger::class);
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    expect($ledger->acquire($this->team->id, $delivery->id, $first))->not->toBeNull()
        ->and($ledger->acquire($this->team->id, $delivery->id, $second))->toBeNull();
    $this->travel(75)->seconds();
    expect($ledger->acquire($this->team->id, $delivery->id, $second))->toBeNull();
    $this->travel(30)->seconds();
    expect($ledger->acquire($this->team->id, $delivery->id, $second))->not->toBeNull();
    $ledger->finish($this->team->id, $delivery->id, $first, true);
    expect(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('status'))->toBe('pending');
    $ledger->finish($this->team->id, $delivery->id, $second, true);
    expect(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('status'))->toBe('sent');
});

it('bounds provider retries to three acquired attempts with 30 and 120 second backoff', function (): void {
    subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    $ledger = app(StatusEmailLedger::class);
    foreach ([30, 120, 0] as $index => $delay) {
        $token = (string) Str::uuid();
        expect($ledger->acquire($this->team->id, $delivery->id, $token))->not->toBeNull();
        $ledger->finish($this->team->id, $delivery->id, $token, false);
        expect(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('attempts'))->toBe($index + 1);
        expect($ledger->acquire($this->team->id, $delivery->id, (string) Str::uuid()))->toBeNull();
        $this->travel($delay)->seconds();
    }

    expect(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('status'))->toBe('failed');
});

it('recovers committed intents through scalar Redis jobs and sends using the explicit SMTP mailer', function (): void {
    Queue::fake();
    $subscriber = subscriptionRecipient(verified: false);
    $this->artisan('status-pages:dispatch-emails')->assertSuccessful();
    Queue::assertPushedOn('notifications', DeliverStatusPageEmail::class);
    $job = Queue::pushed(DeliverStatusPageEmail::class)->first();
    expect(serialize($job))->not->toContain('reader@example.test', $subscriber->generation);
    $job->handle(app(StatusEmailLedger::class));
    Mail::assertSent(StatusPageMail::class, fn (StatusPageMail $mail): bool => $mail->hasTo('reader@example.test')
        && $mail->mailer === 'smtp' && str_contains($mail->attempt->text, '/verify?'));
    expect(DB::table('status_page_deliveries')->where('id', $job->deliveryId)->value('status'))->toBe('sent');
    $job->handle(app(StatusEmailLedger::class));
    Mail::assertSentCount(1);
});

it('treats corrupt encrypted payloads as terminal without leaking exceptions', function (): void {
    subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    DB::table('status_page_deliveries')->where('id', $delivery->id)->update(['payload' => 'corrupt-secret-payload']);
    expect(app(StatusEmailLedger::class)->acquire($this->team->id, $delivery->id, (string) Str::uuid()))->toBeNull()
        ->and(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('status'))->toBe('failed');
});

it('preserves real acceptance of in-flight email after unsubscribe and cancels later attempts', function (): void {
    $subscriber = subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    $ledger = app(StatusEmailLedger::class);
    $token = (string) Str::uuid();
    $ledger->acquire($this->team->id, $delivery->id, $token);
    $this->post(app(StatusSubscriptionLinks::class)->action($subscriber, 'unsubscribe'))->assertOk();
    $ledger->finish($this->team->id, $delivery->id, $token, true);
    expect(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('status'))->toBe('sent');
});

it('sends independently for two subscribed pages showing the same monitor', function (): void {
    subscriptionRecipient();
    $original = $this->page;
    $this->page = subscriptionPage($this->team, $this->monitor, 'second-email-status');
    subscriptionRecipient();
    $this->page = $original;
    subscriptionPublication();
    expect(DB::table('status_page_subscribers')->count())->toBe(2)
        ->and(DB::table('status_page_deliveries')->where('kind', 'update')->count())->toBe(2);
});

it('suppresses obsolete verification intents after expiry confirmation or consent rotation', function (string $change): void {
    $subscriber = subscriptionRecipient(verified: false);
    $id = (int) DB::table('status_page_deliveries')->value('id');
    if ($change === 'expired') {
        $this->travel(1)->hours();
    } elseif ($change === 'confirmed') {
        $this->post(app(StatusSubscriptionLinks::class)->action($subscriber, 'verify'))->assertOk();
    } else {
        $this->travel(10)->minutes();
        subscriptionRecipient(verified: false);
    }
    expect(app(StatusEmailLedger::class)->acquire($this->team->id, $id, (string) Str::uuid()))->toBeNull()
        ->and(DB::table('status_page_deliveries')->where('id', $id)->value('status'))->toBe('suppressed');
})->with(['expired', 'confirmed', 'rotated']);

it('records a generic SMTP failure without leaking the provider exception and lets failed hooks schedule retry', function (): void {
    subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    Log::spy();
    Mail::shouldReceive('mailer')->with('smtp')->once()->andThrow(new RuntimeException('private provider failure reader@example.test'));
    $job = new DeliverStatusPageEmail($this->team->id, $delivery->id, (string) Str::uuid());
    $job->handle(app(StatusEmailLedger::class));
    expect(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('status'))->toBe('pending')
        ->and(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('attempts'))->toBe(1);
    Log::shouldNotHaveReceived('error');
    $this->travel(30)->seconds();
    $replacement = new DeliverStatusPageEmail($this->team->id, $delivery->id, (string) Str::uuid());
    app(StatusEmailLedger::class)->acquire($this->team->id, $delivery->id, $replacement->token);
    $replacement->failed(new RuntimeException('private timeout detail'));
    expect(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('attempts'))->toBe(2)
        ->and(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('claim_until'))->toBeNull();
});

it('continues recovering other deliveries when one Redis publication fails', function (): void {
    subscriptionRecipient('first@example.test', false);
    subscriptionRecipient('second@example.test', false);
    $connection = Mockery::mock(QueueConnection::class);
    $calls = 0;
    $connection->shouldReceive('push')->twice()->andReturnUsing(function () use (&$calls): int {
        if (++$calls === 1) {
            throw new RuntimeException('private broker detail');
        }
        return 1;
    });
    $factory = Mockery::mock(QueueFactory::class);
    $factory->shouldReceive('connection')->with('redis')->twice()->andReturn($connection);
    $this->app->instance(QueueFactory::class, $factory);
    Log::spy();
    $this->artisan('status-pages:dispatch-emails')->assertFailed();
    expect($calls)->toBe(2)->and(DB::table('status_page_deliveries')->sum('attempts'))->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(static fn (string $message, array $context): bool => array_keys($context) === ['team_id', 'delivery_id']);
});

it('enforces page and recipient ownership with composite PostgreSQL foreign keys', function (): void {
    subscriptionRecipient();
    subscriptionPublication();
    $delivery = subscriptionDelivery();
    $other = Team::factory()->create();
    expect(fn () => DB::transaction(fn () => DB::table('status_page_deliveries')->where('id', $delivery->id)->update(['team_id' => $other->id])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('status_page_subscribers')->where('id', $delivery->subscriber_id)->update(['team_id' => $other->id])))
        ->toThrow(QueryException::class);
});

/**
 * @param string $mode Subscribe, publish or deliver admission operation.
 * @param int $teamId Committed tenant.
 * @param int $targetId Page, timeline entry or delivery identity, respectively.
 * @param string $receipts Synthetic provider receipts path, never a real mailbox.
 * @return Process Bounded participant restricted to isolated test services.
 */
function statusEmailProbe(string $mode, int $teamId, int $targetId, string $receipts): Process
{
    return new Process([PHP_BINARY, base_path('tests/Fixtures/RunStatusEmailProbe.php'), $mode, (string) $teamId, (string) $targetId, $receipts], base_path(), [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'APP_DEBUG' => 'false',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) config('database.connections.pgsql.host'),
        'DB_PORT' => (string) config('database.connections.pgsql.port'),
        'DB_DATABASE' => TestDatabaseGuard::DATABASE,
        'DB_USERNAME' => TestDatabaseGuard::USERNAME,
        'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
        'DB_URL' => '',
        'REDIS_HOST' => (string) config('database.redis.default.host'),
        'REDIS_URL' => '',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'LOG_CHANNEL' => 'null',
        'STATUS_EMAIL_PROBE_NOW' => CarbonImmutable::now()->toIso8601String(),
    ], timeout: 15);
}

it('serializes real concurrent subscription publication and email admission in separate PostgreSQL sessions', function (string $mode): void {
    $fixture = CommittedTeamFixture::freeTeam(1);
    $children = [];
    $receipts = sys_get_temp_dir().'/signalbox-status-email-'.bin2hex(random_bytes(8));
    $target = null;

    try {
        $target = $fixture->committed(fn (): int => app(TenantContext::class)->run($fixture->teamId, function () use ($fixture, $mode): int {
            $monitor = Monitor::query()->firstOrFail();
            $page = subscriptionPage(Team::query()->findOrFail($fixture->teamId), $monitor, 'race-'.Str::lower(Str::random(12)));

            if ($mode === 'subscribe') {
                return $page->id;
            }

            app(StatusSubscriptions::class)->subscribe($page, app('session.store'), 'race@example.test');
            $subscriber = StatusPageSubscriber::query()->where('status_page_id', $page->id)->firstOrFail();
            app(StatusSubscriptions::class)->act($fixture->teamId, $page->id, $subscriber->id, $subscriber->generation, 'verify', true);
            $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);
            $entry = app(ManageIncidents::class)->postUpdate($fixture->owner, $fixture->teamId, $incident->id, IncidentStatus::Investigating, 'Concurrent public message');

            if ($mode === 'publish') {
                return $entry->id;
            }

            app(ManageIncidents::class)->publish($fixture->owner, $fixture->teamId, $entry->id);

            return StatusPageDelivery::query()->where('kind', 'update')->firstOrFail()->id;
        }));

        if ($mode === 'deliver') {
            $fixture->connection->select('SELECT pg_advisory_lock(178804, ?)', [$target]);
        }

        $fixture->connection->beginTransaction();
        $fixture->connection->table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();
        foreach (range(1, 2) as $ignored) {
            $child = statusEmailProbe($mode, $fixture->teamId, $target, $receipts);
            $child->start();
            $children[] = $child;
        }

        $deadline = microtime(true) + 5;
        do {
            $fixture->connection->select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) $fixture->connection->scalar("SELECT count(*) FROM pg_stat_activity WHERE application_name = 'signalbox-status-email-probe' AND wait_event_type = 'Lock'");
            if ($waiting === 2) {
                break;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($waiting)->toBe(2);
        $fixture->connection->commit();

        if ($mode === 'deliver') {
            $deadline = microtime(true) + 5;
            do {
                $accepted = is_file($receipts) ? file($receipts, FILE_IGNORE_NEW_LINES) : [];
                $completed = count(array_filter($children, fn (Process $child): bool => !$child->isRunning()));
                if (count($accepted) === 1 && $completed === 1) {
                    break;
                }

                usleep(10000);
            } while (microtime(true) < $deadline);
            expect($accepted)->toBe(['accepted'])->and($completed)->toBe(1);
            $fixture->connection->select('SELECT pg_advisory_unlock(178804, ?)', [$target]);
        }

        foreach ($children as $child) {
            $child->wait();
            expect($child->isSuccessful())->toBeTrue($child->getErrorOutput());
        }

        expect($fixture->connection->table('status_page_subscribers')->where('team_id', $fixture->teamId)->count())->toBe(1);
        $kind = $mode === 'subscribe' ? 'verification' : 'update';
        expect($fixture->connection->table('status_page_deliveries')->where('team_id', $fixture->teamId)->where('kind', $kind)->count())->toBe(1);
        if ($mode === 'deliver') {
            $row = $fixture->connection->table('status_page_deliveries')->where('id', $target)->first();
            expect($row->status)->toBe('sent')->and($row->attempts)->toBe(1);
        }
    } finally {
        if ($fixture->connection->transactionLevel() > 0) {
            $fixture->connection->rollBack();
        }
        if ($target !== null && $mode === 'deliver') {
            $fixture->connection->select('SELECT pg_advisory_unlock(178804, ?)', [$target]);
        }
        foreach ($children as $child) {
            if ($child->isRunning()) {
                $child->stop();
            }
        }
        if (is_file($receipts)) {
            unlink($receipts);
        }
        $fixture->cleanup();
    }
})->with(['subscribe', 'publish', 'deliver']);
