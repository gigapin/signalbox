<?php

declare(strict_types=1);

/** Exercise notification channel creation, verification, activation and deletion on PostgreSQL. */

use App\Actions\Notifications\ManageEscalationPolicies;
use App\Actions\Notifications\ManageNotificationChannels;
use App\Enums\Notifications\ChannelType;
use App\Exceptions\ChannelConfigurationInvalid;
use App\Exceptions\NotificationChannelRejected;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingVerificationSender;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 09:00:00', 'UTC'));
    $this->sender = RecordingVerificationSender::bind();
    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return ManageNotificationChannels Action resolved after the recording sender is bound. */
function ncActions(): ManageNotificationChannels
{
    return app(ManageNotificationChannels::class);
}

/**
 * @param Team $team Team receiving the member.
 * @param string $role Persisted membership role.
 * @return User New member with that role.
 */
function ncMember(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->users()->attach($user->id, ['role' => $role]);

    return $user;
}

/**
 * @param Team $team Owning team.
 * @param int $channelId Channel to reload.
 * @return NotificationChannel Current persisted state.
 */
function ncFresh(Team $team, int $channelId): NotificationChannel
{
    return app(TenantContext::class)->run($team->id, fn (): NotificationChannel => NotificationChannel::query()->findOrFail($channelId));
}

/**
 * @param User $actor Owner or admin.
 * @param Team $team Owning team.
 * @return NotificationChannel Email channel created through the action.
 */
function ncEmail(User $actor, Team $team): NotificationChannel
{
    return ncActions()->create($actor, $team->id, ChannelType::Email, ' On-call ', ['address' => 'oncall@example.test']);
}

it('creates inactive unverified channels with encrypted configuration', function (): void {
    $channel = ncEmail($this->owner, $this->team);
    $raw = DB::table('notification_channels')->where('id', $channel->id)->value('config_json');

    expect($channel->name)->toBe('On-call')
        ->and($channel->is_active)->toBeFalse()
        ->and($channel->verified_at)->toBeNull()
        ->and($channel->created_by)->toBe($this->owner->id)
        ->and($raw)->not->toContain('oncall@example.test')
        ->and(ncFresh($this->team, $channel->id)->config_json)->toBe(['address' => 'oncall@example.test'])
        ->and($channel->toArray())->not->toHaveKeys(['config_json', 'verification_code_hash']);
});

it('lets only owners and admins create channels', function (string $role, bool $allowed): void {
    $actor = ncMember($this->team, $role);
    $create = fn (): NotificationChannel => ncEmail($actor, $this->team);

    if ($allowed) {
        expect($create()->exists)->toBeTrue();

        return;
    }

    expect($create)->toThrow(AuthorizationException::class)
        ->and(DB::table('notification_channels')->count())->toBe(0);
})->with([
    'admin' => ['admin', true],
    'member' => ['member', false],
    'viewer' => ['viewer', false],
]);

it('refuses channels beyond the team limit and invalid labels or destinations', function (): void {
    config(['notifications.channels_per_team' => 1]);
    ncEmail($this->owner, $this->team);

    expect(fn () => ncEmail($this->owner, $this->team))->toThrow(NotificationChannelRejected::class, 'at most 1 notification channels')
        ->and(fn () => ncActions()->create($this->owner, $this->team->id, ChannelType::Email, '   ', ['address' => 'a@example.test']))->toThrow(NotificationChannelRejected::class)
        ->and(fn () => ncActions()->create($this->owner, $this->team->id, ChannelType::Email, 'Ops', ['address' => 'nope']))->toThrow(ChannelConfigurationInvalid::class);
});

it('verifies and activates a channel with the code it received', function (): void {
    $channel = ncEmail($this->owner, $this->team);
    ncActions()->sendVerification($this->owner, $this->team->id, $channel->id);
    $pending = ncFresh($this->team, $channel->id);
    $code = $this->sender->lastCode();

    expect($this->sender->sent)->toHaveCount(1)
        ->and($this->sender->sent[0]['name'])->toBe('On-call')
        ->and($code)->toMatch('/^\d{6}$/')
        ->and($pending->verification_code_hash)->not->toContain($code)
        ->and($pending->verification_expires_at?->toIso8601String())->toBe('2026-10-29T09:15:00+00:00')
        ->and($pending->is_active)->toBeFalse();

    $verified = ncActions()->confirmVerification($this->owner, $this->team->id, $channel->id, $code);

    expect($verified->is_active)->toBeTrue()
        ->and($verified->verified_at?->toIso8601String())->toBe('2026-10-29T09:00:00+00:00')
        ->and(ncFresh($this->team, $channel->id)->verification_code_hash)->toBeNull();
});

it('counts wrong codes durably and discards the code after the last allowed mistake', function (): void {
    $channel = ncEmail($this->owner, $this->team);
    ncActions()->sendVerification($this->owner, $this->team->id, $channel->id);
    $code = $this->sender->lastCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 4) as $attempt) {
        expect(fn () => ncActions()->confirmVerification($this->owner, $this->team->id, $channel->id, $wrong))
            ->toThrow(NotificationChannelRejected::class, 'The code is not correct.');
        expect(ncFresh($this->team, $channel->id)->verification_attempts)->toBe($attempt);
    }

    expect(fn () => ncActions()->confirmVerification($this->owner, $this->team->id, $channel->id, $wrong))
        ->toThrow(NotificationChannelRejected::class, 'Too many wrong codes')
        ->and(ncFresh($this->team, $channel->id)->verification_code_hash)->toBeNull()
        ->and(fn () => ncActions()->confirmVerification($this->owner, $this->team->id, $channel->id, $code))
        ->toThrow(NotificationChannelRejected::class, 'No valid code is pending')
        ->and(ncFresh($this->team, $channel->id)->is_active)->toBeFalse();
});

it('rejects a code after its expiry instant', function (): void {
    $channel = ncEmail($this->owner, $this->team);
    ncActions()->sendVerification($this->owner, $this->team->id, $channel->id);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 09:15:00', 'UTC'));

    expect(fn () => ncActions()->confirmVerification($this->owner, $this->team->id, $channel->id, $this->sender->lastCode()))
        ->toThrow(NotificationChannelRejected::class, 'No valid code is pending');
});

it('throttles verification messages and invalidates the previous code on resend', function (): void {
    $channel = ncEmail($this->owner, $this->team);
    ncActions()->sendVerification($this->owner, $this->team->id, $channel->id);
    $first = $this->sender->lastCode();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 09:00:59', 'UTC'));

    expect(fn () => ncActions()->sendVerification($this->owner, $this->team->id, $channel->id))
        ->toThrow(NotificationChannelRejected::class, 'Try again in 1 seconds');

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 09:01:00', 'UTC'));
    ncActions()->sendVerification($this->owner, $this->team->id, $channel->id);
    $second = $this->sender->lastCode();

    expect($this->sender->sent)->toHaveCount(2);

    if ($first !== $second) {
        expect(fn () => ncActions()->confirmVerification($this->owner, $this->team->id, $channel->id, $first))
            ->toThrow(NotificationChannelRejected::class, 'The code is not correct.');
    }

    expect(ncActions()->confirmVerification($this->owner, $this->team->id, $channel->id, $second)->is_active)->toBeTrue();
});

it('withdraws an undelivered code and frees the cooldown', function (): void {
    $channel = ncEmail($this->owner, $this->team);
    $this->sender->failWith = 'Provider timed out.';

    expect(fn () => ncActions()->sendVerification($this->owner, $this->team->id, $channel->id))
        ->toThrow(NotificationChannelRejected::class, 'The verification message could not be sent: Provider timed out.');

    $state = ncFresh($this->team, $channel->id);
    expect($state->verification_code_hash)->toBeNull()
        ->and($state->verification_sent_at)->toBeNull();

    $this->sender->failWith = null;
    ncActions()->sendVerification($this->owner, $this->team->id, $channel->id);
    expect($this->sender->sent)->toHaveCount(1);
});

it('reports that delivery is unavailable while no adapter is bound', function (): void {
    app()->forgetInstance(\App\Contracts\Notifications\ChannelVerificationSender::class);
    app()->bind(\App\Contracts\Notifications\ChannelVerificationSender::class, \App\Services\Notifications\UnavailableChannelVerificationSender::class);
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->slack()->create());

    expect(fn () => ncActions()->sendVerification($this->owner, $this->team->id, $channel->id))
        ->toThrow(NotificationChannelRejected::class, 'Delivery to Slack channels is not available yet.')
        ->and(ncFresh($this->team, $channel->id)->verification_code_hash)->toBeNull();
});

it('keeps verification on label-only changes and resets it when the destination changes', function (): void {
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->telegram()->active()->create());
    $token = $channel->config_json['bot_token'];

    $renamed = ncActions()->update($this->owner, $this->team->id, $channel->id, 'Renamed', ['bot_token' => '', 'chat_id' => '-1001234567890']);
    expect($renamed->name)->toBe('Renamed')
        ->and($renamed->is_active)->toBeTrue()
        ->and($renamed->verified_at)->not->toBeNull();

    $moved = ncActions()->update($this->owner, $this->team->id, $channel->id, 'Renamed', ['chat_id' => '-1009999999999']);
    $state = ncFresh($this->team, $channel->id);

    expect($moved->is_active)->toBeFalse()
        ->and($state->verified_at)->toBeNull()
        ->and($state->config_json)->toBe(['bot_token' => $token, 'chat_id' => '-1009999999999']);
});

it('activates only verified channels and pauses them on request', function (): void {
    $unverified = ncEmail($this->owner, $this->team);
    $verified = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->active()->create());

    expect(fn () => ncActions()->setActive($this->owner, $this->team->id, $unverified->id, true))
        ->toThrow(NotificationChannelRejected::class, 'Verify the channel before activating it.')
        ->and(ncActions()->setActive($this->owner, $this->team->id, $verified->id, false)->is_active)->toBeFalse()
        ->and(ncActions()->setActive($this->owner, $this->team->id, $verified->id, true)->is_active)->toBeTrue();
});

it('rotates the webhook signing secret without discarding verification', function (): void {
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->webhook()->active()->create());
    $previous = $channel->config_json['signing_secret'];
    $email = ncEmail($this->owner, $this->team);

    $secret = ncActions()->rotateSigningSecret($this->owner, $this->team->id, $channel->id);
    $state = ncFresh($this->team, $channel->id);

    expect($secret)->toMatch('/^[0-9a-f]{64}$/')
        ->and($secret)->not->toBe($previous)
        ->and($state->config_json['signing_secret'])->toBe($secret)
        ->and($state->is_active)->toBeTrue()
        ->and(fn () => ncActions()->rotateSigningSecret($this->owner, $this->team->id, $email->id))
        ->toThrow(NotificationChannelRejected::class, 'Only webhook channels');
});

it('refuses to delete a channel a policy uses and keeps delivery history after deletion', function (): void {
    $used = ncEmail($this->owner, $this->team);
    $free = ncEmail($this->owner, $this->team);
    app(ManageEscalationPolicies::class)->create($this->owner, $this->team->id, 'Primary', [['after_minutes' => 0, 'channel_ids' => [$used->id]]], false);
    $log = app(TenantContext::class)->run($this->team->id, function () use ($free): NotificationLog {
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);

        return NotificationLog::factory()->create(['incident_id' => $incident->id, 'channel_id' => $free->id]);
    });

    expect(fn () => ncActions()->delete($this->owner, $this->team->id, $used->id))
        ->toThrow(NotificationChannelRejected::class, 'escalation policies first: Primary.');

    ncActions()->delete($this->owner, $this->team->id, $free->id);

    expect(DB::table('notification_channels')->where('id', $free->id)->exists())->toBeFalse()
        ->and(DB::table('notification_logs')->where('id', $log->id)->value('channel_id'))->toBeNull()
        ->and(DB::table('notification_logs')->where('id', $log->id)->value('channel_type'))->toBe('email');
});

it('never reaches another team channel and re-checks the role under lock', function (): void {
    $foreign = Team::factory()->create();
    $channel = app(TenantContext::class)->run($foreign->id, fn (): NotificationChannel => NotificationChannel::factory()->create());
    $local = ncEmail($this->owner, $this->team);
    $admin = ncMember($this->team, 'admin');
    $this->team->users()->updateExistingPivot($admin->id, ['role' => 'member']);

    expect(fn () => ncActions()->sendVerification($this->owner, $this->team->id, $channel->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ncActions()->delete($this->owner, $this->team->id, $channel->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ncActions()->delete($admin, $this->team->id, $local->id))->toThrow(AuthorizationException::class)
        ->and($this->sender->sent)->toBe([]);
});

it('enforces channel state invariants in the database', function (): void {
    $channel = ncEmail($this->owner, $this->team);

    // Each statement runs in its own savepoint so one violation does not abort the next check.
    foreach ([['is_active' => true], ['verification_code_hash' => 'x'], ['type' => 'pager'], ['name' => ' ']] as $change) {
        expect(fn () => DB::transaction(fn () => DB::table('notification_channels')->where('id', $channel->id)->update($change)))
            ->toThrow(QueryException::class);
    }

    expect(ncFresh($this->team, $channel->id)->is_active)->toBeFalse();
});
