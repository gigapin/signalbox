<?php

declare(strict_types=1);

/**
 * Bound the transactional messages a tenant can send to recipients it has not proved, and keep its text out of them.
 *
 * Channel verification codes and team invitations both reach unverified addresses. These cases check that no
 * tenant-chosen text is rendered as links or markup, that per-minute route throttles and team-wide daily quotas
 * apply, and that deleting and recreating channels does not reset the daily count.
 */

use App\Actions\Notifications\ManageNotificationChannels;
use App\Enums\Identity\TeamQuota;
use App\Enums\Notifications\ChannelType;
use App\Exceptions\NotificationChannelRejected;
use App\Mail\ChannelNotificationMail;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\Support\RecordingVerificationSender;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    Cache::flush();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13 09:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** Label or team name a hostile tenant could choose to make a message look like its own content. */
const ABUSE_HOSTILE_TEXT = "[Claim your refund](https://attacker.example/r) **now**\nCall +1 555 0100";

/**
 * @param User $actor Owner or admin creating the channel.
 * @param Team $team Owning team.
 * @param string $label Channel label.
 * @return NotificationChannel Unverified email channel created through the production action.
 */
function abuseEmailChannel(User $actor, Team $team, string $label = 'Ops'): NotificationChannel
{
    return app(ManageNotificationChannels::class)->create(
        $actor,
        $team->id,
        ChannelType::Email,
        $label,
        ['address' => 'stranger@example.test'],
    );
}

/**
 * Run one create → send code → delete cycle, the abuse loop the per-channel cooldown cannot bound.
 *
 * @param Team $team Team sending the code.
 * @throws NotificationChannelRejected When the team reached its daily verification limit.
 */
function abuseVerificationCycle(Team $team): void
{
    $actions = app(ManageNotificationChannels::class);
    $channel = abuseEmailChannel($team->owner, $team);

    try {
        $actions->sendVerification($team->owner, $team->id, $channel->id);
    } finally {
        $actions->delete($team->owner, $team->id, $channel->id);
    }
}

/**
 * @param Team $team Team whose counter is read.
 * @param TeamQuota $quota Counter to read.
 * @return int Uses recorded in the current window.
 */
function abuseQuotaUsed(Team $team, TeamQuota $quota): int
{
    return (int) DB::table('team_daily_quotas')
        ->where('team_id', $team->id)
        ->where('quota', $quota->value)
        ->value('used');
}

/**
 * @param Team $team Team whose invitations are counted.
 * @return int Invitation rows of the team, whatever their state.
 */
function abuseInvitationCount(Team $team): int
{
    return app(TenantContext::class)->run(
        $team->id,
        fn (): int => TeamInvitation::query()->count(),
    );
}

it('sends only fixed text and the code to an unverified destination, never the channel label', function (): void {
    Mail::fake();
    $team = Team::factory()->create();
    $channel = abuseEmailChannel($team->owner, $team, '[Win](https://attacker.example) call 555');

    app(ManageNotificationChannels::class)->sendVerification($team->owner, $team->id, $channel->id);

    Mail::assertSent(ChannelNotificationMail::class, function (ChannelNotificationMail $mail): bool {
        $visible = $mail->message->subject.' '.$mail->message->text;

        return $mail->hasTo('stranger@example.test')
            && preg_match('/\b\d{6}\b/', $mail->message->text) === 1
            && !str_contains($visible, 'attacker.example')
            && !str_contains($visible, 'Win')
            && !str_contains($visible, '555');
    });
});

it('caps verification codes per team across deleted and recreated channels until 24 hours pass', function (): void {
    config(['notifications.verification_sends_per_team_per_day' => 3]);
    $sender = RecordingVerificationSender::bind();
    $team = Team::factory()->create();

    abuseVerificationCycle($team);
    abuseVerificationCycle($team);
    abuseVerificationCycle($team);

    expect(fn () => abuseVerificationCycle($team))
        ->toThrow(NotificationChannelRejected::class, 'sent 3 verification codes in the last 24 hours');

    $refused = abuseEmailChannel($team->owner, $team);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 08:59:59', 'UTC'));

    expect(fn () => app(ManageNotificationChannels::class)->sendVerification($team->owner, $team->id, $refused->id))
        ->toThrow(NotificationChannelRejected::class, 'Try again after 2026-11-14 09:00 UTC');

    $pending = app(TenantContext::class)->run(
        $team->id,
        fn (): NotificationChannel => NotificationChannel::query()->findOrFail($refused->id),
    );

    expect($sender->sent)->toHaveCount(3)
        ->and($pending->verification_code_hash)->toBeNull()
        ->and($pending->verification_sent_at)->toBeNull();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 09:00:00', 'UTC'));
    app(ManageNotificationChannels::class)->sendVerification($team->owner, $team->id, $refused->id);

    expect($sender->sent)->toHaveCount(4)
        ->and(abuseQuotaUsed($team, TeamQuota::ChannelVerification))->toBe(1);
});

it('keeps one team exhausted without limiting another team', function (): void {
    config(['notifications.verification_sends_per_team_per_day' => 1]);
    RecordingVerificationSender::bind();
    $exhausted = Team::factory()->create();
    $other = Team::factory()->create();

    abuseVerificationCycle($exhausted);

    expect(fn () => abuseVerificationCycle($exhausted))->toThrow(NotificationChannelRejected::class, 'verification codes');

    abuseVerificationCycle($other);

    expect(abuseQuotaUsed($other, TeamQuota::ChannelVerification))->toBe(1);
});

it('does not charge cooldown refusals and keeps failed deliveries counted', function (): void {
    config(['notifications.verification_sends_per_team_per_day' => 2]);
    $sender = RecordingVerificationSender::bind();
    $team = Team::factory()->create();
    $actions = app(ManageNotificationChannels::class);
    $channel = abuseEmailChannel($team->owner, $team);

    $actions->sendVerification($team->owner, $team->id, $channel->id);

    expect(fn () => $actions->sendVerification($team->owner, $team->id, $channel->id))
        ->toThrow(NotificationChannelRejected::class, 'sent recently')
        ->and(abuseQuotaUsed($team, TeamQuota::ChannelVerification))->toBe(1);

    $sender->failWith = 'Provider refused.';
    $failing = abuseEmailChannel($team->owner, $team);

    expect(fn () => $actions->sendVerification($team->owner, $team->id, $failing->id))
        ->toThrow(NotificationChannelRejected::class, 'could not be sent')
        ->and(abuseQuotaUsed($team, TeamQuota::ChannelVerification))->toBe(2);

    $sender->failWith = null;
    $last = abuseEmailChannel($team->owner, $team);

    expect(fn () => $actions->sendVerification($team->owner, $team->id, $last->id))
        ->toThrow(NotificationChannelRejected::class, 'verification codes in the last 24 hours');
});

it('throttles channel changes per user and team at twenty per minute', function (): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create(['owner_id' => $team->owner_id]);
    $this->actingAs($team->owner);

    foreach (range(1, 20) as $attempt) {
        $this->delete("/teams/{$team->id}/notification-channels/999999")->assertNotFound();
    }

    $this->delete("/teams/{$team->id}/notification-channels/999999")->assertTooManyRequests();
    $this->post("/teams/{$team->id}/notification-channels/999999/verification")->assertTooManyRequests();
    $this->delete("/teams/{$other->id}/notification-channels/999999")->assertNotFound();
});

it('renders a hostile legacy team name as literal single-line text in the invitation email', function (): void {
    config(['mail.default' => 'array']);
    $team = Team::factory()->create(['name' => ABUSE_HOSTILE_TEXT]);

    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/invitations", ['email' => 'stranger@example.test', 'role' => 'member'])
        ->assertRedirect("/teams/{$team->id}");

    $transport = Mail::mailer('array')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    $email = $transport->messages()->sole()->getOriginalMessage();
    expect($email)->toBeInstanceOf(Email::class);

    $html = html_entity_decode((string) $email->getHtmlBody());

    expect($html)->not->toContain('href="https://attacker.example')
        ->and($html)->not->toContain('<strong>now</strong>')
        ->and($html)->toContain('[Claim your refund](https://attacker.example/r) **now** Call +1 555 0100')
        ->and($email->getSubject())->toBe('Invitation to [Claim your refund](https://attacker.example/r) **now** Call +1 555 0100');
});

it('caps invitation emails per team, counting resends, until 24 hours pass', function (): void {
    config(['teams.invitations_per_team_per_day' => 2]);
    Notification::fake();
    $team = Team::factory()->create();
    $this->actingAs($team->owner);

    $this->post("/teams/{$team->id}/invitations", ['email' => 'first@example.test', 'role' => 'member'])
        ->assertSessionHasNoErrors();

    $invitation = app(TenantContext::class)->run($team->id, fn (): TeamInvitation => TeamInvitation::query()->sole());

    $this->post("/teams/{$team->id}/invitations/{$invitation->id}/resend")
        ->assertSessionHasNoErrors();

    $this->post("/teams/{$team->id}/invitations", ['email' => 'second@example.test', 'role' => 'member'])
        ->assertSessionHasErrors(['email' => 'This team sent 2 invitations in the last 24 hours. Try again after 2026-11-14 09:00 UTC.']);

    Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 2);
    expect(abuseInvitationCount($team))->toBe(1);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 09:00:00', 'UTC'));
    Cache::flush();

    $this->post("/teams/{$team->id}/invitations", ['email' => 'second@example.test', 'role' => 'member'])
        ->assertSessionHasNoErrors();

    expect(abuseInvitationCount($team))->toBe(2);
});

it('does not charge invitations refused for other reasons and keeps team counters apart', function (): void {
    config(['teams.invitations_per_team_per_day' => 1]);
    Notification::fake();
    $team = Team::factory()->create();
    $other = Team::factory()->create();

    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/invitations", ['email' => 'first@example.test', 'role' => 'member'])
        ->assertSessionHasNoErrors();

    $this->post("/teams/{$team->id}/invitations", ['email' => 'first@example.test', 'role' => 'member'])
        ->assertSessionHasErrors(['email' => 'A pending invitation already exists. Use resend to send a new link.']);

    expect(abuseQuotaUsed($team, TeamQuota::Invitation))->toBe(1);

    $this->post("/teams/{$team->id}/invitations", ['email' => 'second@example.test', 'role' => 'member'])
        ->assertSessionHasErrors('email');

    $this->actingAs($other->owner)
        ->post("/teams/{$other->id}/invitations", ['email' => 'second@example.test', 'role' => 'member'])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 2);
});

it('rejects team names that could carry links, addresses, markup or line breaks', function (string $teamName): void {
    $this->post('/register', [
        'name' => 'Owner',
        'team_name' => $teamName,
        'email' => 'owner@example.test',
        'password' => 'secure-password-123',
        'password_confirmation' => 'secure-password-123',
    ])->assertSessionHasErrors('team_name');

    expect(User::query()->count())->toBe(0)
        ->and(Team::query()->count())->toBe(0);
})->with([
    'evil.com',
    'https://attacker.example',
    'Reply to a@b.test',
    '[Refund](x)',
    "Line\nbreak",
    'Team_name',
    '**bold**',
    'Ops <b>',
    'Version 2.0',
]);

it('accepts ordinary team names', function (string $teamName): void {
    $this->post('/register', [
        'name' => 'Owner',
        'team_name' => $teamName,
        'email' => 'owner@example.test',
        'password' => 'secure-password-123',
        'password_confirmation' => 'secure-password-123',
    ])->assertRedirect('/email/verify')->assertSessionHasNoErrors();

    expect(Team::query()->sole()->name)->toBe($teamName);
})->with([
    'Acme Inc.',
    'Kub-Lang & Sons (EU)',
    'Café Zürich 2',
    'O’Brien, Ltd',
    "O'Brien Ops",
]);
