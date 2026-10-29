<?php

declare(strict_types=1);

/** Exercise notification pages and forms through HTTP, checking that secrets never reach pages or the session. */

use App\Models\EscalationPolicy;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\NotificationLog;
use App\Models\Team;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\RecordingVerificationSender;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-29 09:00:00', 'UTC'));
    $this->sender = RecordingVerificationSender::bind();
    $this->team = Team::factory()->create(['name' => 'Pager team']);
    $this->owner = $this->team->owner;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param Team $team Team receiving the member.
 * @param string $role Persisted membership role.
 * @return User New member with that role.
 */
function npMember(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->users()->attach($user->id, ['role' => $role]);

    return $user;
}

it('projects masked channels to members and editable non-secret values to managers', function (): void {
    [$telegram, $slack] = app(TenantContext::class)->run($this->team->id, fn (): array => [
        NotificationChannel::factory()->telegram()->active()->create(['name' => 'Ops chat']),
        NotificationChannel::factory()->slack()->create(['name' => 'Ops Slack']),
    ]);
    $token = $telegram->config_json['bot_token'];
    $webhook = $slack->config_json['webhook_url'];
    $member = npMember($this->team, 'member');

    $this->actingAs($member)->get("/teams/{$this->team->id}/notifications")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertDontSee($token, false)
        ->assertDontSee($webhook, false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Notifications/Index')
            ->where('channels.0.name', 'Ops chat')
            ->where('channels.0.hint', 'Chat …7890')
            ->where('channels.0.isActive', true)
            ->where('channels.0.editable', null)
            ->where('channels.1.name', 'Ops Slack')
            ->where('channels.1.hint', 'hooks.slack.com/services/…'.substr($webhook, -4))
            ->where('unnotifiedMonitors', 0)
            ->where('can.manage', false));

    $this->actingAs($this->owner)->get("/teams/{$this->team->id}/notifications")
        ->assertDontSee($token, false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channels.0.editable', ['chat_id' => '-1001234567890'])
            ->where('channels.1.editable', [])
            ->where('can.manage', true)
            ->where('limits.codeLength', 6));
});

it('reports configuration errors per field without flashing submitted secrets', function (): void {
    $response = $this->actingAs($this->owner)
        ->from("/teams/{$this->team->id}/notifications")
        ->post("/teams/{$this->team->id}/notification-channels", [
            'type' => 'telegram',
            'name' => 'Ops chat',
            'config' => ['bot_token' => 'leaked-secret-value', 'chat_id' => 'bad chat'],
        ]);

    $response->assertRedirect("/teams/{$this->team->id}/notifications")
        ->assertSessionHasErrors(['config.bot_token', 'config.chat_id']);
    expect(session()->getOldInput())->not->toHaveKey('config')
        ->and(json_encode(session()->all()))->not->toContain('leaked-secret-value')
        ->and(DB::table('notification_channels')->count())->toBe(0);
});

it('translates state refusals to the channel key and never flashes a submitted code', function (): void {
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->create());

    $this->actingAs($this->owner)
        ->from("/teams/{$this->team->id}/notifications")
        ->post("/teams/{$this->team->id}/notification-channels/{$channel->id}/verification/confirm", ['code' => '654321'])
        ->assertSessionHasErrors(['channel' => 'No valid code is pending. Request a new verification code.']);

    expect(session()->getOldInput())->not->toHaveKey('code');

    $this->actingAs($this->owner)
        ->patch("/teams/{$this->team->id}/notification-channels/{$channel->id}/activation", ['active' => true])
        ->assertSessionHasErrors(['channel' => 'Verify the channel before activating it.']);
});

it('runs the verification flow over HTTP', function (): void {
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->create());

    $this->actingAs($this->owner)
        ->post("/teams/{$this->team->id}/notification-channels/{$channel->id}/verification")
        ->assertSessionHas('status', 'channel-code-sent');

    $this->actingAs($this->owner)->get("/teams/{$this->team->id}/notifications")
        ->assertDontSee($this->sender->lastCode(), false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channels.0.verificationPending', true)
            ->where('channels.0.verificationExpiresAt', '2026-10-29T09:15:00+00:00'));

    $this->actingAs($this->owner)
        ->post("/teams/{$this->team->id}/notification-channels/{$channel->id}/verification/confirm", ['code' => $this->sender->lastCode()])
        ->assertSessionHas('status', 'channel-verified');

    expect(DB::table('notification_channels')->where('id', $channel->id)->value('is_active'))->toBeTrue();
});

it('validates policy shape before the domain rules and reports domain refusals on the policy key', function (): void {
    $channel = app(TenantContext::class)->run($this->team->id, fn (): NotificationChannel => NotificationChannel::factory()->create());

    $this->actingAs($this->owner)
        ->post("/teams/{$this->team->id}/escalation-policies", [
            'name' => 'Primary',
            'is_default' => false,
            'steps' => [['after_minutes' => -1, 'channel_ids' => ['first']]],
        ])
        ->assertSessionHasErrors(['steps.0.after_minutes', 'steps.0.channel_ids.0']);

    $this->actingAs($this->owner)
        ->post("/teams/{$this->team->id}/escalation-policies", [
            'name' => 'Primary',
            'is_default' => false,
            'steps' => [
                ['after_minutes' => 10, 'channel_ids' => [$channel->id]],
                ['after_minutes' => 5, 'channel_ids' => [$channel->id]],
            ],
        ])
        ->assertSessionHasErrors(['policy' => 'Step 2 must wait longer than the step before it.']);

    expect(DB::table('escalation_policies')->count())->toBe(0);
});

it('shows the effective policy on the monitor page and counts unnotified monitors', function (): void {
    [$monitor, $other, $policy] = app(TenantContext::class)->run($this->team->id, fn (): array => [
        Monitor::factory()->create(['name' => 'API']),
        Monitor::factory()->create(['name' => 'Web']),
        EscalationPolicy::factory()->create(['name' => 'Database team']),
    ]);

    $this->actingAs($this->owner)->get("/teams/{$this->team->id}/notifications")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('unnotifiedMonitors', 2));

    $this->actingAs(npMember($this->team, 'member'))
        ->patch("/teams/{$this->team->id}/monitors/{$monitor->id}/escalation-policy", ['escalation_policy_id' => $policy->id])
        ->assertSessionHas('status', 'monitor-escalation-saved');

    $this->actingAs($this->owner)->get("/teams/{$this->team->id}/monitors/{$monitor->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('escalation.policyId', $policy->id)
            ->where('escalation.effective', ['id' => $policy->id, 'name' => 'Database team', 'source' => 'override'])
            ->has('escalation.options', 1));

    $this->actingAs($this->owner)->get("/teams/{$this->team->id}/monitors/{$other->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('escalation.policyId', null)
            ->where('escalation.effective', null));

    $this->actingAs($this->owner)->get("/teams/{$this->team->id}/notifications")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('unnotifiedMonitors', 1)
            ->where('policies.0.monitorCount', 1));
});

it('lists an incident deliveries without dedupe keys and keeps rows of deleted channels', function (): void {
    [$incident, $channel] = app(TenantContext::class)->run($this->team->id, function (): array {
        $monitor = Monitor::factory()->create();
        $incident = Incident::factory()->create(['monitor_id' => $monitor->id]);
        $channel = NotificationChannel::factory()->create(['name' => 'Pager email']);
        NotificationLog::factory()->create(['incident_id' => $incident->id, 'channel_id' => $channel->id, 'dedupe_key' => 'secret-dedupe-key']);

        return [$incident, $channel];
    });

    $this->actingAs($this->owner)->get("/teams/{$this->team->id}/incidents/{$incident->id}")
        ->assertDontSee('secret-dedupe-key', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('deliveries', 1)
            ->where('deliveries.0.channelName', 'Pager email')
            ->where('deliveries.0.status', 'sent')
            ->missing('deliveries.0.dedupeKey'));

    $this->actingAs($this->owner)->delete("/teams/{$this->team->id}/notification-channels/{$channel->id}");

    $this->actingAs($this->owner)->get("/teams/{$this->team->id}/incidents/{$incident->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('deliveries.0.channelName', null)
            ->where('deliveries.0.channelType', 'email'));
});
