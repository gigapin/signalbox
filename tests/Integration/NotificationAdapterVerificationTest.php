<?php

declare(strict_types=1);

/** Verify real provider adapters through authorized HTTP and persisted verification state on isolated PostgreSQL. */

use App\Contracts\Security\HostResolver;
use App\Mail\ChannelNotificationMail;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeHostResolver;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 09:00:00', 'UTC'));
    $this->team = Team::factory()->create();
    app()->instance(HostResolver::class, new FakeHostResolver([
        'hooks.slack.com' => ['93.184.216.34'],
        'api.telegram.org' => ['93.184.216.35'],
        'hooks.example.test' => ['93.184.216.36'],
    ]));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Owning tenant.
 * @param string $family Configured family.
 * @return NotificationChannel Unverified synthetic channel owned by the given team.
 */
function verificationAdapterChannel(Team $team, string $family): NotificationChannel
{
    return app(TenantContext::class)->run($team->id, function () use ($family): NotificationChannel {
        $factory = NotificationChannel::factory();
        $factory = match ($family) {
            'email' => $factory,
            'slack' => $factory->slack(),
            'telegram' => $factory->telegram(),
            'webhook' => $factory->webhook(),
            'sms' => $factory->sms(),
        };

        return $factory->create();
    });
}

/**
 * @param string $family Configured family.
 * @return string Plaintext code read from the intended fake recipient transport, never application state.
 */
function verificationAdapterCode(string $family): string
{
    if ($family === 'email') {
        $mail = Mail::sent(ChannelNotificationMail::class)->sole();

        return $mail->message->data['code'];
    }

    $request = Http::recorded()->sole()[0];

    if ($family === 'webhook') {
        return $request['data']['code'];
    }

    preg_match('/ is (\d{6})\./', $request['text'], $matches);

    return $matches[1];
}

it('delivers and confirms the code through the real sender without flashing secrets', function (string $family): void {
    Mail::fake();
    Http::fake([
        'hooks.slack.com/*' => Http::response('ok'),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        'hooks.example.test/*' => Http::response('', 204),
    ]);
    $channel = verificationAdapterChannel($this->team, $family);
    $url = "/teams/{$this->team->id}/notification-channels/{$channel->id}";
    $initialTransactions = DB::transactionLevel();

    if ($family !== 'email') {
        Http::beforeSending(function (Request $request, array $options) use ($initialTransactions, $channel): void {
            expect(DB::transactionLevel())->toBe($initialTransactions)
                ->and(DB::table('notification_channels')->where('id', $channel->id)->value('verification_code_hash'))->not->toBeNull();
        });
    }

    $this->actingAs($this->team->owner)->post($url.'/verification')->assertSessionHas('status', 'channel-code-sent');
    $code = verificationAdapterCode($family);
    expect($code)->toMatch('/^\d{6}$/')
        ->and(DB::table('notification_channels')->where('id', $channel->id)->value('is_active'))->toBeFalse()
        ->and(json_encode(session()->all()))->not->toContain($code);

    $this->get("/teams/{$this->team->id}/notifications")->assertOk()->assertDontSee($code, false);
    $this->post($url.'/verification/confirm', ['code' => $code])->assertSessionHas('status', 'channel-verified');

    $state = DB::table('notification_channels')->where('id', $channel->id)->first();
    expect($state->is_active)->toBeTrue()
        ->and($state->verified_at)->not->toBeNull()
        ->and($state->verification_code_hash)->toBeNull()
        ->and(DB::table('notification_logs')->count())->toBe(0);
})->with(['email', 'slack', 'telegram', 'webhook']);

it('withdraws failed provider codes, keeps safe errors and frees the resend cooldown', function (string $family): void {
    $channel = verificationAdapterChannel($this->team, $family);
    $sequence = Http::sequence()->push('private-body 654321', 503)->push($family === 'slack' ? 'ok' : ['ok' => true], 200);
    Http::fake(['*' => $sequence]);
    $url = "/teams/{$this->team->id}/notification-channels/{$channel->id}/verification";

    $this->actingAs($this->team->owner)->post($url)
        ->assertSessionHasErrors(['channel' => 'The verification message could not be sent: The notification provider refused the request (HTTP 503).']);

    $state = DB::table('notification_channels')->where('id', $channel->id)->first();
    expect($state->verification_code_hash)->toBeNull()
        ->and($state->verification_sent_at)->toBeNull()
        ->and($state->is_active)->toBeFalse()
        ->and(json_encode(session()->all()))->not->toContain('private-body', '654321');

    $this->post($url)->assertSessionHas('status', 'channel-code-sent');
    Http::assertSentCount(2);
})->with(['slack', 'telegram', 'webhook']);

it('records an SMS stub attempt but leaves no pending code or verified destination', function (): void {
    Http::fake();
    Mail::fake();
    $channel = verificationAdapterChannel($this->team, 'sms');
    $url = "/teams/{$this->team->id}/notification-channels/{$channel->id}";

    $this->actingAs($this->team->owner)->post($url.'/verification')
        ->assertSessionHasErrors(['channel' => 'The verification message could not be sent: SMS is a recording-only stub and cannot verify a destination.']);
    $this->patch($url.'/activation', ['active' => true])->assertSessionHasErrors('channel');

    $state = DB::table('notification_channels')->where('id', $channel->id)->first();
    expect($state->verification_code_hash)->toBeNull()
        ->and($state->verification_sent_at)->toBeNull()
        ->and($state->verified_at)->toBeNull()
        ->and($state->is_active)->toBeFalse();
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

it('blocks foreign channels and read-only members before the adapters run', function (string $role): void {
    Http::fake();
    Mail::fake();
    $foreignTeam = Team::factory()->create();
    $foreignChannel = verificationAdapterChannel($foreignTeam, 'slack');
    $local = verificationAdapterChannel($this->team, 'slack');
    $member = User::factory()->create();
    $this->team->users()->attach($member->id, ['role' => $role]);

    $this->actingAs($this->team->owner)->post("/teams/{$this->team->id}/notification-channels/{$foreignChannel->id}/verification")->assertNotFound();
    $this->actingAs($member)->post("/teams/{$this->team->id}/notification-channels/{$local->id}/verification")->assertForbidden();

    expect(DB::table('notification_channels')->whereNotNull('verification_code_hash')->count())->toBe(0);
    Http::assertNothingSent();
    Mail::assertNothingSent();
})->with(['member', 'viewer']);
