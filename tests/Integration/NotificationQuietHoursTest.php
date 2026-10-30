<?php

declare(strict_types=1);

/** Exercise authorized quiet-hour configuration, projections and PostgreSQL constraints without provider network I/O. */

use App\Actions\Notifications\ManageNotificationChannels;
use App\Data\Notifications\QuietHours;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Notifications\ChannelDestinationFingerprint;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\RecordingVerificationSender;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 22:30:00', 'UTC'));
    $this->sender = RecordingVerificationSender::bind();
    $this->team = Team::factory()->create();
    $this->channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->active()->create());
    $this->path = '/teams/'.$this->team->id.'/notification-channels/'.$this->channel->id.'/quiet-hours';
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Explicit owning tenant.
 * @param int $id Team-local channel identity.
 * @return NotificationChannel Current channel from that tenant.
 */
function quietChannel(Team $team, int $id): NotificationChannel
{
    return app(TenantContext::class)->run($team->id, fn (): NotificationChannel => NotificationChannel::query()->findOrFail($id));
}

it('persists changes idempotently and clears quiet hours without changing verification or destination', function (): void {
    $original = quietChannel($this->team, $this->channel->id)->getAttributes();
    $fingerprint = app(ChannelDestinationFingerprint::class)->for($this->channel->configuration());
    $payload = ['quiet_hours' => ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Rome']];

    foreach (range(1, 2) as $pass) {
        $this->actingAs($this->team->owner)->from('/teams/'.$this->team->id.'/notifications')
            ->patch($this->path, $payload)->assertRedirect('/teams/'.$this->team->id.'/notifications')->assertSessionHasNoErrors();
    }

    $fresh = quietChannel($this->team, $this->channel->id);
    expect($fresh->quietHours()?->contains(CarbonImmutable::now()))->toBeTrue()
        ->and($fresh->is_active)->toBeTrue()
        ->and($fresh->getRawOriginal('verified_at'))->toBe($original['verified_at'])
        ->and($fresh->getRawOriginal('config_json'))->toBe($original['config_json'])
        ->and(app(ChannelDestinationFingerprint::class)->for($fresh->configuration()))->toBe($fingerprint);
    $this->actingAs($this->team->owner)->patch($this->path, ['quiet_hours' => null])->assertSessionHasNoErrors();
    expect(quietChannel($this->team, $this->channel->id)->quietHours())->toBeNull();
});

it('rejects malformed partial empty equal-boundary and non-IANA intervals', function (array $payload): void {
    $this->actingAs($this->team->owner)->patch($this->path, $payload)->assertSessionHasErrors();
    expect(quietChannel($this->team, $this->channel->id)->quietHours())->toBeNull();
})->with([
    'omitted' => [[]],
    'empty' => [['quiet_hours' => []]],
    'missing end' => [['quiet_hours' => ['start' => '22:00', 'timezone' => 'UTC']]],
    'missing zone' => [['quiet_hours' => ['start' => '22:00', 'end' => '07:00']]],
    'malformed time' => [['quiet_hours' => ['start' => '24:00', 'end' => '07:00', 'timezone' => 'UTC']]],
    'equal' => [['quiet_hours' => ['start' => '22:00', 'end' => '22:00', 'timezone' => 'UTC']]],
    'offset' => [['quiet_hours' => ['start' => '22:00', 'end' => '07:00', 'timezone' => '+02:00']]],
    'unknown' => [['quiet_hours' => ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Mars/Olympus']]],
    'extra key' => [['quiet_hours' => ['start' => '22:00', 'end' => '07:00', 'timezone' => 'UTC', 'bypass' => true]]],
]);

it('projects the daily interval to all members while keeping controls manager-only', function (): void {
    app(ManageNotificationChannels::class)->setQuietHours($this->team->owner, $this->team->id, $this->channel->id, new QuietHours('22:00', '07:00', 'Europe/Rome'));
    $this->actingAs($this->team->owner)->get('/teams/'.$this->team->id.'/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channels.0.quietHours', ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Rome'])
            ->where('can.manage', true));
    $viewer = User::factory()->create();
    $this->team->users()->attach($viewer->id, ['role' => 'viewer']);
    $this->actingAs($viewer)->get('/teams/'.$this->team->id.'/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channels.0.quietHours', ['start' => '22:00', 'end' => '07:00', 'timezone' => 'Europe/Rome'])
            ->where('can.manage', false));
});

it('keeps verification codes available during quiet hours and retains a pending code when the rule changes', function (): void {
    $action = app(ManageNotificationChannels::class);
    $action->setQuietHours($this->team->owner, $this->team->id, $this->channel->id, new QuietHours('22:00', '07:00', 'UTC'));
    $action->sendVerification($this->team->owner, $this->team->id, $this->channel->id);
    $digest = quietChannel($this->team, $this->channel->id)->verification_code_hash;
    $action->setQuietHours($this->team->owner, $this->team->id, $this->channel->id, null);

    expect($this->sender->sent)->toHaveCount(1)
        ->and(quietChannel($this->team, $this->channel->id)->verification_code_hash)->toBe($digest);
});

it('rejects incomplete and invalid stored intervals at the PostgreSQL boundary', function (array $attributes): void {
    expect(fn () => DB::transaction(fn (): int => DB::table('notification_channels')->where('id', $this->channel->id)->update($attributes)))->toThrow(QueryException::class);
})->with([
    [['quiet_hours_start' => '22:00']],
    [['quiet_hours_start' => '22:00', 'quiet_hours_end' => '22:00', 'quiet_hours_timezone' => 'UTC']],
    [['quiet_hours_start' => '24:00', 'quiet_hours_end' => '07:00', 'quiet_hours_timezone' => 'UTC']],
    [['quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:00', 'quiet_hours_timezone' => '']],
]);
