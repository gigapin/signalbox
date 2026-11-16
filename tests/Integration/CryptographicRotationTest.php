<?php

declare(strict_types=1);

/** Exercise legacy adoption, key retirement and retained unsubscribe capabilities on real PostgreSQL. */

use App\Actions\Notifications\ManageNotificationChannels;
use App\Data\Notifications\ChannelMessage;
use App\Enums\Notifications\DeliveryStatus;
use App\Enums\Notifications\NotificationKind;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\StatusPage;
use App\Models\StatusPageSubscriber;
use App\Models\Team;
use App\Services\Notifications\ChannelDestinationFingerprint;
use App\Services\Notifications\ChannelVerificationCodes;
use App\Services\Notifications\NotificationDeliveryLedger;
use App\Services\Security\PurposeKeys;
use App\Services\Security\RotateProtectedData;
use App\Services\StatusPages\StatusPageUnlocks;
use App\Services\StatusPages\StatusSubscriptionLinks;
use App\Services\StatusPages\StatusSubscriptions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL and the application crypto providers.');
    }
    $this->withoutVite();
    Cache::flush();
    $this->team = Team::factory()->create();
    $this->page = app(TenantContext::class)->run($this->team->id, fn (): StatusPage => StatusPage::factory()->published()->create(['slug' => 'rotation-status']));
    $this->root = (string) config('app.key');
    $this->legacy = new Encrypter(base64_decode(substr($this->root, 7), true), 'AES-256-CBC');
});

/**
 * @param Team $team Explicit owning tenant.
 * @param StatusPage $page Parent whose current password authorizes consent.
 * @param string $root Public test root in its original Laravel encoding.
 * @param Encrypter $legacy Original native cipher with no derived keys.
 * @param string $address Synthetic mailbox, canonicalized before this fixture is invoked.
 * @param string|null $passwordHash Optional obsolete hash to exercise non-renewal of stale consent.
 * @return StatusPageSubscriber Direct original-format fixture; normal writes already use derived keys.
 */
function rotationLegacySubscriber(Team $team, StatusPage $page, string $root, Encrypter $legacy, string $address = 'reader@example.test', ?string $passwordHash = null): StatusPageSubscriber
{
    $id = DB::table('status_page_subscribers')->insertGetId([
        'team_id' => $team->id, 'status_page_id' => $page->id, 'email' => $legacy->encryptString($address),
        'email_hash' => hash_hmac('sha256', $address, $root), 'generation' => (string) Str::uuid(),
        'access_hash' => hash_hmac('sha256', $passwordHash ?? $page->password_hash ?? '', $root),
        'requested_at' => now(), 'verification_expires_at' => now()->addHour(), 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return app(TenantContext::class)->run($team->id, fn (): StatusPageSubscriber => StatusPageSubscriber::query()->findOrFail($id));
}

/**
 * Replace only synthetic in-memory configuration and the already-resolved native cipher during a rotation test.
 *
 * @param string $root New current public test root; never a deployment secret.
 * @param list<string> $previous Explicit historical test roots.
 * @param bool $legacy Whether original-format proofs/ciphertext remain accepted.
 */
function rotationSwitchKey(string $root, array $previous = [], bool $legacy = false): void
{
    config(['app.key' => $root, 'app.previous_keys' => $previous, 'cryptography.allow_legacy' => $legacy]);
    Crypt::swap(app(PurposeKeys::class)->encrypter());
}

/**
 * @param Incident $incident Already open or resolved incident matching the delivery kind.
 * @param NotificationChannel $channel Verified destination inside the active tenant context.
 * @param NotificationKind $kind Controlled opening/resolution event.
 * @return NotificationLog Runnable synthetic delivery with the current destination key and no provider I/O.
 */
function rotationPendingDelivery(Incident $incident, NotificationChannel $channel, NotificationKind $kind): NotificationLog
{
    $message = new ChannelMessage((string) Str::uuid(), $kind === NotificationKind::Resolved ? 'incident.resolved' : 'incident.opened', CarbonImmutable::now('UTC'), 'Synthetic alert', 'Synthetic text');

    return NotificationLog::factory()->create([
        'incident_id' => $incident->id, 'channel_id' => $channel->id, 'kind' => $kind,
        'status' => DeliveryStatus::Pending, 'attempts' => 0, 'cycle' => $incident->reopened_count,
        'message_uuid' => $message->id, 'message_payload' => $message->payload(),
        'destination_hash' => app(ChannelDestinationFingerprint::class)->for($channel->configuration()),
        'available_at' => now(), 'sent_at' => null,
    ]);
}

it('keeps session proofs separate from consent even for the same protected page', function (): void {
    $page = $this->page;
    $page->password_hash = Hash::make('correct-password');
    $consent = app(StatusSubscriptionLinks::class)->accessHash($page);
    $session = app('session.store');
    $session->put('status_page_unlocks.'.$page->id, $consent);
    expect(app(StatusPageUnlocks::class)->allows($session, $page))->toBeFalse();
    $session->put('status_page_unlocks.'.$page->id, hash_hmac('sha256', $page->password_hash, $this->root));
    expect(app(StatusPageUnlocks::class)->allows($session, $page))->toBeTrue()
        ->and($session->get('status_page_unlocks.'.$page->id))->toStartWith('sb1:')->not->toBe($consent);
    $old = $session->get('status_page_unlocks.'.$page->id);
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root]);
    expect(app(StatusPageUnlocks::class)->allows($session, $page))->toBeTrue()
        ->and($session->get('status_page_unlocks.'.$page->id))->not->toBe($old);
});

it('finds legacy subscribers without duplicates or renewed consent and migrates idempotently', function (): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    app(StatusSubscriptions::class)->subscribe($this->page, app('session.store'), 'READER@example.test');
    expect(DB::table('status_page_subscribers')->count())->toBe(1);
    $before = DB::table('status_page_subscribers')->where('id', $subscriber->id)->first();
    $check = app(RotateProtectedData::class)->run(true, 1);
    expect($check['changes'])->toBeGreaterThan(0)
        ->and(DB::table('status_page_subscribers')->where('id', $subscriber->id)->first())->toEqual($before);
    app(RotateProtectedData::class)->run(false, 1);
    $after = DB::table('status_page_subscribers')->where('id', $subscriber->id)->first();
    expect($after->generation)->toBe($before->generation)->and($after->verified_at)->toBe($before->verified_at)
        ->and($after->email_hash)->toStartWith('sb1:')->and($after->access_hash)->toStartWith('sb1:')
        ->and(app(RotateProtectedData::class)->run(true, 1))->toBe(['changes' => 0, 'pending_codes' => 0]);
});

it('never renews consent invalidated by a password change while migrating', function (): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy, passwordHash: Hash::make('previous-password'));
    app(RotateProtectedData::class)->run(false, 100);
    $stored = DB::table('status_page_subscribers')->where('id', $subscriber->id)->first();
    expect($stored->access_hash)->toStartWith('retired:')
        ->and(app(StatusSubscriptionLinks::class)->allowsConsent($this->page, $stored->access_hash))->toBeFalse()
        ->and($stored->generation)->toBe($subscriber->generation)->and($stored->verified_at)->not->toBeNull()
        ->and(app(RotateProtectedData::class)->run(true, 100)['changes'])->toBe(0);
});

it('preserves exact original and previous derived unsubscribe links after removing all historical keys', function (bool $original): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    $paths = app(StatusSubscriptionLinks::class)->retirementPaths($subscriber);
    $oldPath = $paths[$original ? 1 : 0];
    $this->get($oldPath)->assertOk();
    expect(DB::table('status_page_subscribers')->where('id', $subscriber->id)->value('unsubscribed_at'))->toBeNull();
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root], true);
    app(RotateProtectedData::class)->run(false, 100);
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)));
    $this->get($oldPath)->assertOk();
    $this->post($oldPath)->assertOk();
    expect(DB::table('status_page_subscribers')->where('id', $subscriber->id)->value('unsubscribed_at'))->not->toBeNull();
    $this->post($oldPath)->assertOk();
    $this->get($oldPath.'&extra=1')->assertForbidden();
    $this->get(str_replace('/unsubscribe?', '/verify?', $oldPath))->assertForbidden();
    $this->get(str_replace('/'.$subscriber->generation.'/', '/'.Str::uuid().'/', $oldPath))->assertForbidden();
})->with([true, false]);

it('keeps verification codes valid across rotation and reports their retirement prerequisite', function (): void {
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->create());
    DB::table('notification_channels')->where('id', $channel->id)->update([
        'verification_code_hash' => hash_hmac('sha256', 'notification-channel-verification:'.$channel->id.':123456', $this->root),
        'verification_expires_at' => now()->addMinutes(10),
    ]);
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root], true);
    expect(app(RotateProtectedData::class)->run(false, 100)['pending_codes'])->toBe(1);
    $verified = app(ManageNotificationChannels::class)->confirmVerification($this->team->owner, $this->team->id, $channel->id, '123456');
    expect($verified->verified_at)->not->toBeNull()->and($verified->verification_code_hash)->toBeNull()
        ->and(app(RotateProtectedData::class)->run(true, 100)['pending_codes'])->toBe(0);
});

it('allows new verification codes through previous derived keys without enabling legacy', function (): void {
    $codes = app(ChannelVerificationCodes::class);
    $digest = $codes->digest(123, '123456');
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root]);
    expect($codes->matches($digest, 123, '123456'))->toBeTrue()
        ->and($codes->matches($digest, 124, '123456'))->toBeFalse()
        ->and($codes->matches($digest, 123, '000000'))->toBeFalse();
});

it('rewraps legacy model casts and Fortify serialization before retiring the old root', function (): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    $monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create());
    DB::table('monitors')->where('id', $monitor->id)->update([
        'url' => $this->legacy->encryptString('https://example.test/private'),
        'headers_json' => $this->legacy->encryptString('{"Authorization":"synthetic-secret"}'),
        'body' => $this->legacy->encryptString('synthetic body'),
    ]);
    DB::table('users')->where('id', $this->team->owner_id)->update([
        'two_factor_secret' => $this->legacy->encrypt('synthetic-authenticator'),
        'two_factor_recovery_codes' => $this->legacy->encrypt('["synthetic-recovery"]'),
    ]);
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->create());
    DB::table('notification_channels')->where('id', $channel->id)->update([
        'config_json' => $this->legacy->encryptString('{"address":"oncall@example.test"}'),
    ]);
    $log = app(TenantContext::class)->run($this->team->id, fn (): NotificationLog => NotificationLog::factory()->create(['channel_id' => $channel->id]));
    DB::table('notification_logs')->where('id', $log->id)->update([
        'message_payload' => $this->legacy->encryptString('{"subject":"synthetic alert"}'),
    ]);
    app(TenantContext::class)->run($this->team->id, fn () => app(StatusSubscriptions::class)->enqueue($subscriber, 'verification', null, ['subject' => 'Synthetic consent', 'text' => 'Synthetic body']));
    $delivery = DB::table('status_page_deliveries')->sole();
    DB::table('status_page_deliveries')->where('id', $delivery->id)->update([
        'payload' => $this->legacy->encryptString('{"subject":"Synthetic consent","text":"Synthetic body"}'),
    ]);
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root], true);
    app(RotateProtectedData::class)->run(false, 2);
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)));
    $fresh = app(TenantContext::class)->run($this->team->id, fn (): Monitor => $monitor->fresh());
    expect($fresh->url)->toBe('https://example.test/private')
        ->and($fresh->headers_json)->toBe(['Authorization' => 'synthetic-secret'])->and($fresh->body)->toBe('synthetic body')
        ->and(Fortify::currentEncrypter()->decrypt($this->team->owner->fresh()->two_factor_secret))->toBe('synthetic-authenticator')
        ->and($this->team->owner->fresh()->recoveryCodes())->toBe(['synthetic-recovery']);
    app(TenantContext::class)->run($this->team->id, function () use ($channel, $log): void {
        expect($channel->fresh()->config_json)->toBe(['address' => 'oncall@example.test'])
            ->and($log->fresh()->message_payload)->toBe(['subject' => 'synthetic alert']);
    });
    expect(Crypt::decryptString(DB::table('status_page_deliveries')->where('id', $delivery->id)->value('payload')))
        ->toBe('{"subject":"Synthetic consent","text":"Synthetic body"}');
    $freshSubscriber = app(TenantContext::class)->run($this->team->id, fn (): StatusPageSubscriber => $subscriber->fresh());
    app(StatusSubscriptions::class)->subscribe($this->page, app('session.store'), $freshSubscriber->email);
    expect(DB::table('status_page_subscribers')->count())->toBe(1)
        ->and(app(RotateProtectedData::class)->run(true, 100)['changes'])->toBe(0);
});

it('refuses an unavailable consent key instead of converting a potentially valid consent to stale', function (): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    $unknown = 'sb1:'.str_repeat('f', 16).':'.str_repeat('a', 64);
    DB::table('status_page_subscribers')->where('id', $subscriber->id)->update(['access_hash' => $unknown]);
    expect(fn () => app(RotateProtectedData::class)->run(false, 100))->toThrow(LogicException::class)
        ->and(DB::table('status_page_subscribers')->where('id', $subscriber->id)->value('access_hash'))->toBe($unknown);
});

it('rejects retained capabilities after resubscription and across tenant coordinates', function (): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    $path = app(StatusSubscriptionLinks::class)->retirementPaths($subscriber)[1];
    app(RotateProtectedData::class)->run(false, 100);
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)));
    DB::table('status_page_subscribers')->where('id', $subscriber->id)->update(['generation' => (string) Str::uuid()]);
    $this->post($path)->assertNotFound();
    $foreign = Team::factory()->create();
    $this->get(str_replace('/subscriptions/'.$this->team->id.'/', '/subscriptions/'.$foreign->id.'/', $path))->assertForbidden();
    expect(DB::table('status_page_subscribers')->where('id', $subscriber->id)->value('unsubscribed_at'))->toBeNull();
});

it('restores original encrypted rows and their original key configuration from a paired synthetic backup', function (): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    $backup = (array) DB::table('status_page_subscribers')->where('id', $subscriber->id)->first();
    $path = app(StatusSubscriptionLinks::class)->retirementPaths($subscriber)[1];
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root], true);
    app(RotateProtectedData::class)->run(false, 100);
    DB::table('status_page_subscribers')->where('id', $subscriber->id)->update($backup);
    rotationSwitchKey($this->root, [], true);
    $restored = app(TenantContext::class)->run($this->team->id, fn (): StatusPageSubscriber => $subscriber->fresh());
    expect($restored->email)->toBe('reader@example.test')->and($restored->generation)->toBe($subscriber->generation)
        ->and(app(StatusSubscriptionLinks::class)->allowsConsent($this->page, $restored->access_hash))->toBeTrue();
    $this->post($path)->assertOk();
});

it('rejects invalid batch sizes and makes check mode refuse outstanding retirement work', function (): void {
    rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    $this->artisan('security:rotate-data', ['--batch' => '0'])->assertFailed();
    $this->artisan('security:rotate-data', ['--batch' => '1001'])->assertFailed();
    $this->artisan('security:rotate-data', ['--check' => true])->assertFailed();
});

it('drops expired legacy codes without replacing them or issuing another message', function (): void {
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->create());
    DB::table('notification_channels')->where('id', $channel->id)->update([
        'verification_code_hash' => str_repeat('a', 64), 'verification_expires_at' => now()->subSecond(),
    ]);
    app(RotateProtectedData::class)->run(false, 100);
    expect(DB::table('notification_channels')->where('id', $channel->id)->value('verification_code_hash'))->toBeNull();
});

it('serializes operator runs through a shared PostgreSQL advisory lock', function (): void {
    config(['database.connections.crypto_probe' => config('database.connections.pgsql')]);
    $probe = DB::connection('crypto_probe');
    try {
        $probe->select('SELECT pg_advisory_lock(186974, 11)');
        $this->artisan('security:rotate-data', ['--check' => true])
            ->expectsOutputToContain('already running')->assertFailed();
    } finally {
        $probe->select('SELECT pg_advisory_unlock(186974, 11)');
        DB::purge('crypto_probe');
    }
    $this->artisan('security:rotate-data', ['--check' => true])->assertSuccessful();
});

it('keeps finite signed verification URLs valid through rotation and enforces their original expiry', function (): void {
    config(['cryptography.allow_legacy' => false]);
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $this->team->owner_id, 'hash' => sha1($this->team->owner->email),
    ]);
    $request = \Illuminate\Http\Request::create($url);
    rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root]);
    expect(\Illuminate\Support\Facades\URL::hasValidSignature($request))->toBeTrue();
    $this->travel(61)->minutes();
    expect(\Illuminate\Support\Facades\URL::hasValidSignature($request))->toBeFalse();
});

it('admits a resolution prepared under the new key after an opening sent under the previous key', function (): void {
    app(TenantContext::class)->run($this->team->id, function (): void {
        $channel = NotificationChannel::factory()->active()->create();
        $incident = Incident::factory()->resolved()->create();
        $opening = NotificationLog::factory()->create([
            'incident_id' => $incident->id, 'channel_id' => $channel->id, 'cycle' => 0,
            'destination_hash' => app(ChannelDestinationFingerprint::class)->for($channel->configuration()),
        ]);
        rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root]);
        $channel = $channel->fresh();
        $resolution = rotationPendingDelivery($incident, $channel, NotificationKind::Resolved);
        expect($resolution->destination_hash)->not->toBe($opening->destination_hash)
            ->and(app(NotificationDeliveryLedger::class)->acquire($this->team->id, $resolution->id, (string) Str::uuid()))->not->toBeNull();
    });
});

it('coalesces equivalent deferred notifications across key versions', function (): void {
    app(TenantContext::class)->run($this->team->id, function (): void {
        $channel = NotificationChannel::factory()->active()->create();
        $incident = Incident::factory()->create();
        $first = rotationPendingDelivery($incident, $channel, NotificationKind::Opened);
        $first->forceFill(['quiet_hours_deferred_until' => now()->subMinute()])->save();
        rotationSwitchKey('base64:'.base64_encode(str_repeat('b', 32)), [$this->root]);
        $second = rotationPendingDelivery($incident, $channel->fresh(), NotificationKind::Opened);
        expect(app(NotificationDeliveryLedger::class)->acquire($this->team->id, $first->id, (string) Str::uuid()))->not->toBeNull()
            ->and($second->fresh()->status)->toBe(DeliveryStatus::Suppressed);
    });
});

it('refuses corrupt ciphertext without printing protected input and resumes after repair', function (): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    DB::table('status_page_subscribers')->where('id', $subscriber->id)->update(['email' => 'private-corrupt-ciphertext']);
    $this->artisan('security:rotate-data')->expectsOutputToContain('stopped safely')->assertFailed();
    DB::table('status_page_subscribers')->where('id', $subscriber->id)->update(['email' => $this->legacy->encryptString('reader@example.test')]);
    $this->artisan('security:rotate-data')->assertSuccessful();
    $this->artisan('security:rotate-data', ['--check' => true])->expectsOutputToContain('Changes required: 0')->assertSuccessful();
});

it('refuses a changed subscriber identity instead of silently merging or granting consent', function (): void {
    $subscriber = rotationLegacySubscriber($this->team, $this->page, $this->root, $this->legacy);
    DB::table('status_page_subscribers')->where('id', $subscriber->id)->update(['email_hash' => str_repeat('0', 64)]);
    expect(fn () => app(RotateProtectedData::class)->run(false, 100))->toThrow(LogicException::class);
    expect(DB::table('status_page_subscribers')->where('id', $subscriber->id)->value('email_hash'))->toBe(str_repeat('0', 64));
});

it('binds migrated notification destinations to their original configuration', function (): void {
    app(TenantContext::class)->run($this->team->id, function (): void {
        $channel = NotificationChannel::factory()->create(['config_json' => ['address' => 'oncall@example.test']]);
        $legacy = hash_hmac('sha256', 'email'.serialize(['address' => 'oncall@example.test']), $this->root);
        $valid = NotificationLog::factory()->create(['channel_id' => $channel->id, 'destination_hash' => $legacy]);
        $stale = NotificationLog::factory()->create(['channel_id' => $channel->id, 'destination_hash' => str_repeat('f', 64)]);
        app(RotateProtectedData::class)->run(false, 100);
        expect($valid->fresh()->destination_hash)->toBe(app(ChannelDestinationFingerprint::class)->for($channel->configuration()))
            ->and($stale->fresh()->destination_hash)->toStartWith('retired:');
    });
});
