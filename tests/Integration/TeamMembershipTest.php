<?php

/** Verify invitations, membership permissions, ownership and selection against isolated PostgreSQL. */

use App\Actions\Identity\AcceptTeamInvitation;
use App\Actions\Identity\IssueTeamInvitation;
use App\Actions\Identity\ManageTeamMembers;
use App\Actions\Identity\SwitchTeam;
use App\Enums\Identity\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Identity\InvitationLinks;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthFixture;
use Tests\Support\InvitationFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    Cache::flush();
    Notification::fake();
});

it('sends a normalized hashed invitation with exact seven-day validity after commit', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));

    $team = Team::factory()->create();
    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/invitations", ['email' => ' INVITEE@EXAMPLE.TEST ', 'role' => 'member', 'team_id' => 999])
        ->assertRedirect("/teams/{$team->id}");

    $invitation = TeamInvitation::query()->sole();
    expect($invitation->email)->toBe('invitee@example.test')->and($invitation->team_id)->toBe($team->id)
        ->and($invitation->expires_at->getTimestamp() - now()->getTimestamp())->toBe(7 * 86400)
        ->and($invitation->toArray())->not->toHaveKey('token_hash');
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $mail, array $channels, object $recipient) use ($invitation): bool {
        return $recipient->routes['mail'] === 'invitee@example.test' && !str_contains($mail->toMail($recipient)->actionUrl, $invitation->token_hash);
    });
    $this->get("/teams/{$team->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('invitations', 1)->missing('invitations.0.token_hash')->missing('invitations.0.token'));
});

it('rejects duplicate pending invites and rotates old links on resend', function (): void {
    $team = Team::factory()->create();

    $fixture = InvitationFixture::issue($team);

    $oldHash = $fixture['invitation']->token_hash;
    $this->get($fixture['url'])->assertRedirect('/invitations/accept');
    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/invitations", ['email' => 'INVITEE@example.test', 'role' => 'viewer'])
        ->assertSessionHasErrors('email');
    $this->post("/teams/{$team->id}/invitations/{$fixture['invitation']->id}/resend")->assertRedirect("/teams/{$team->id}");
    expect(TeamInvitation::query()->count())->toBe(1)->and($fixture['invitation']->fresh()->token_hash)->not->toBe($oldHash)
        ->and(app(InvitationLinks::class)->resolve($fixture['invitation']->id, $oldHash))->toBeNull();
    $this->get('/invitations/accept')->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
});

it('accepts once with a verified matching identity and never upgrades an existing member', function (): void {
    $team = Team::factory()->create();

    $user = User::factory()->create();

    $fixture = InvitationFixture::issue($team, $user->email, TeamRole::Admin);
    $this->get($fixture['url']);
    expect($team->users()->count())->toBe(1);
    $this->actingAs($user)
        ->post('/invitations/accept', ['role' => 'owner', 'team_id' => 999])
        ->assertRedirect("/teams/{$team->id}");
    expect($user->resolveCurrentTeam()?->id)->toBe($team->id)->and($fixture['invitation']->fresh()->accepted_at)->not->toBeNull();
    app(ManageTeamMembers::class)->changeRole($team->owner, $team->id, $user->id, TeamRole::Viewer);
    $this->post('/invitations/accept')->assertRedirect("/teams/{$team->id}");
    expect(Membership::query()->where('team_id', $team->id)->where('user_id', $user->id)->sole()->role)->toBe(TeamRole::Viewer)->and($team->users()->count())->toBe(2);
    app(ManageTeamMembers::class)->remove($team->owner, $team->id, $user->id);
    $this->post('/invitations/accept')->assertSessionHasErrors('invitation');
    expect($user->resolveCurrentTeam())->toBeNull();
});

it('preserves preexisting membership roles on first acceptance', function (): void {
    $team = Team::factory()->create();

    $user = User::factory()->create();

    $fixture = InvitationFixture::issue($team, $user->email, TeamRole::Owner);
    $team->users()->attach($user->id, ['role' => 'viewer']);
    $this->get($fixture['url']);
    $this->actingAs($user)->post('/invitations/accept')->assertSessionHasNoErrors();
    expect(Membership::query()->where('team_id', $team->id)->where('user_id', $user->id)->sole()->role)->toBe(TeamRole::Viewer);
});

it('rejects expired revoked and wrong-email acceptance', function (string $state): void {
    $team = Team::factory()->create();

    $user = User::factory()->create();

    $fixture = InvitationFixture::issue($team, $user->email);
    $this->get($fixture['url']);

    if ($state === 'expired') {
        $this->travelTo($fixture['invitation']->expires_at);
    }

    if ($state === 'revoked') {
        app(IssueTeamInvitation::class)->revoke($team->owner, $team->id, $fixture['invitation']->id);
    }

    if ($state === 'wrong-email') {
        $user = User::factory()->create();
    }

    $this->actingAs($user)->post('/invitations/accept')->assertSessionHasErrors('invitation');
    expect($team->users()->count())->toBe(1);
})->with(['expired', 'revoked', 'wrong-email']);

it('retains verification and explicit acceptance for invited registrations without a personal team', function (): void {
    $team = Team::factory()->create();

    $fixture = InvitationFixture::issue($team);
    $this->get($fixture['url'])->assertRedirect('/invitations/accept');
    $this->get('/invitations/register')->assertInertia(fn (AssertableInertia $page) => $page->component('Teams/Register')->where('email', 'invitee@example.test'));
    $this->post('/invitations/register', [
        'name' => 'Invited User',
        'email' => 'invitee@example.test',
        'password' => 'secure-password-123',
        'password_confirmation' => 'secure-password-123',
        'team_name' => 'Injected',
        'role' => 'owner'
    ])->assertRedirect('/email/verify');

    $user = User::query()->where('email', 'invitee@example.test')->sole();
    expect($user->email_verified_at)->toBeNull()->and($user->current_team_id)->toBeNull()->and(Team::query()->count())->toBe(1)->and($team->users()->count())->toBe(1);
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->post('/invitations/accept')->assertRedirect('/email/verify');

    $verification = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($verification)->assertRedirect('/invitations/accept');
    expect($team->users()->count())->toBe(1);
    $this->post('/invitations/accept')->assertRedirect("/teams/{$team->id}");
    expect($team->users()->count())->toBe(2)->and(Team::query()->count())->toBe(1);
});

it('rejects invited registration without a matching pending capability', function (string $variant): void {
    $team = Team::factory()->create();

    $fixture = InvitationFixture::issue($team);

    if ($variant !== 'missing') {
        $this->get($fixture['url']);
    }

    if ($variant === 'expired') {
        $this->travelTo($fixture['invitation']->expires_at);
    }

    $this->post('/invitations/register', [
        'name' => 'Invited',
        'email' => $variant === 'wrong-email' ? 'another@example.test' : 'invitee@example.test',
        'password' => 'secure-password-123',
        'password_confirmation' => 'secure-password-123'
    ])->assertSessionHasErrors('invitation');
    expect(User::query()->count())->toBe(1);
})->with(['missing', 'expired', 'wrong-email']);

it('retains an invitation across confirmed second-factor login and explicit account switching', function (): void {
    $team = Team::factory()->create();

    $user = AuthFixture::enroll(User::factory()->create());

    $fixture = InvitationFixture::issue($team, $user->email);
    $this->get($fixture['url']);
    $this->get('/invitations/accept');
    $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
    $this->post('/two-factor-challenge', ['code' => AuthFixture::code($user)])->assertRedirect('/invitations/accept');
    $this->post('/invitations/switch-account')->assertRedirect('/login');
    $this->assertGuest();
    $this->get('/invitations/accept')->assertInertia(fn (AssertableInertia $page) => $page->where('invitation.email', $user->email)->where('auth.user', null));
    expect($team->users()->count())->toBe(1);
});

it('enforces the invitation hierarchy for every actor and target role', function (string $actorRole, string $targetRole, bool $allowed): void {
    $team = Team::factory()->create();

    $actor = User::factory()->create();
    $team->users()->attach($actor->id, ['role' => $actorRole]);

    $response = $this->actingAs($actor)->post("/teams/{$team->id}/invitations", ['email' => 'recipient@example.test', 'role' => $targetRole]);
    $allowed ? $response->assertRedirect("/teams/{$team->id}") : $response->assertForbidden();
    expect(TeamInvitation::query()->count())->toBe($allowed ? 1 : 0);
})->with([
    ['owner', 'owner', true], ['owner', 'admin', true], ['owner', 'member', true], ['owner', 'viewer', true],
    ['admin', 'owner', false], ['admin', 'admin', false], ['admin', 'member', true], ['admin', 'viewer', true],
    ['member', 'member', false], ['viewer', 'viewer', false],
]);

it('enforces both existing and requested roles during changes and removals', function (): void {
    $team = Team::factory()->create();

    $admin = User::factory()->create();

    $member = User::factory()->create();
    $team->users()->attach($admin->id, ['role' => 'admin']);
    $team->users()->attach($member->id, ['role' => 'member']);
    $this->actingAs($admin)
        ->patch("/teams/{$team->id}/members/{$member->id}", ['role' => 'viewer'])
        ->assertSessionHasNoErrors();
    $this->patch("/teams/{$team->id}/members/{$member->id}", ['role' => 'owner'])->assertForbidden();
    $this->patch("/teams/{$team->id}/members/{$admin->id}", ['role' => 'owner'])->assertForbidden();
    $this->delete("/teams/{$team->id}/members/{$team->owner_id}")->assertForbidden();
    $this->delete("/teams/{$team->id}/members/{$member->id}")->assertSessionHasNoErrors();
    expect($team->users()->whereKey($member->id)->exists())->toBeFalse();
});

it('protects the primary owner and permits explicit transfer to an existing owner only', function (): void {
    $team = Team::factory()->create();

    $successor = User::factory()->create();
    $team->users()->attach($successor->id, ['role' => 'owner']);
    $this->actingAs($team->owner)
        ->delete("/teams/{$team->id}/members/{$team->owner_id}")
        ->assertSessionHasErrors('member');
    $this->patch("/teams/{$team->id}/members/{$team->owner_id}", ['role' => 'admin'])->assertSessionHasErrors('member');
    $this->get("/teams/{$team->id}/ownership")->assertRedirect('/user/confirm-password');
    $this->post('/user/confirm-password', ['password' => 'password'])->assertRedirect("/teams/{$team->id}/ownership");
    $this->post("/teams/{$team->id}/ownership", ['user_id' => $team->owner_id])->assertSessionHasErrors('user_id');
    $this->post("/teams/{$team->id}/ownership", ['user_id' => $successor->id])->assertRedirect("/teams/{$team->id}");
    expect($team->fresh()->owner_id)->toBe($successor->id)->and(Membership::query()->where('team_id', $team->id)->where('role', 'owner')->count())->toBe(2);
    $this->delete("/teams/{$team->id}/members/{$team->owner_id}")->assertRedirect('/teams');
    expect($team->users()->count())->toBe(1);
});

it('rejects transfer by nonprimary owners and to nonowners with a fresh password', function (): void {
    $team = Team::factory()->create();

    $other = User::factory()->create();

    $viewer = User::factory()->create();
    $team->users()->attach($other->id, ['role' => 'owner']);
    $team->users()->attach($viewer->id, ['role' => 'viewer']);
    $this->actingAs($other)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post("/teams/{$team->id}/ownership", ['user_id' => $viewer->id])
        ->assertForbidden();
    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/ownership", ['user_id' => $viewer->id])
        ->assertSessionHasErrors('user_id');
    expect($team->fresh()->owner_id)->toBe($team->owner_id);
});

it('selects only accessible teams and clears the preference on departure', function (): void {
    $first = Team::factory()->create();

    $second = Team::factory()->create();

    $user = User::factory()->create();
    $first->users()->attach($user->id, ['role' => 'viewer']);
    $second->users()->attach($user->id, ['role' => 'member']);
    $this->actingAs($user)->post("/teams/{$first->id}/select")->assertRedirect('/account');
    expect($user->resolveCurrentTeam()?->id)->toBe($first->id);
    $this->delete("/teams/{$first->id}/members/{$user->id}")->assertRedirect('/teams');
    expect($user->fresh()->current_team_id)->toBeNull()->and($user->resolveCurrentTeam())->toBeNull();
    $this->post("/teams/{$first->id}/select")->assertNotFound();
    $this->get('/teams')->assertInertia(fn (AssertableInertia $page) => $page->has('teams', 1)->where('teams.0.id', $second->id)->where('currentTeamId', null));
});

it('denies direct foreign-team operations before exposing member or invitation data', function (string $method, string $suffix): void {
    $foreign = Team::factory()->create();

    $actor = User::factory()->create();
    $this->actingAs($actor)
        ->{$method}("/teams/{$foreign->id}{$suffix}", ['role' => 'member', 'email' => 'hidden@example.test', 'user_id' => $foreign->owner_id])
        ->assertNotFound();
})->with([
    ['get', ''],
    ['post', '/select'],
    ['post', '/invitations'],
    ['patch', '/members/1'],
    ['delete', '/members/1'],
    ['get', '/ownership'],
    ['post', '/invitations/1/resend'],
    ['delete', '/invitations/1']
]);

it('hides manager-only invitation data and rejects revoked memberships using stale actors', function (): void {
    $team = Team::factory()->create();

    $viewer = User::factory()->create();
    $team->users()->attach($viewer->id, ['role' => 'viewer']);
    InvitationFixture::issue($team);
    $this->actingAs($viewer)
        ->get("/teams/{$team->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->has('roles', 0)->has('invitations', 0));
    $viewer->load('teams');
    $team->users()->detach($viewer->id);
    $this->get("/teams/{$team->id}")->assertNotFound();
    $this->post("/teams/{$team->id}/invitations", ['email' => 'another@example.test', 'role' => 'member'])->assertNotFound();
});

it('rolls back invitation writes without notifications and reports delivery failures without losing persistence', function (): void {
    $team = Team::factory()->create();
    DB::beginTransaction();
    app(IssueTeamInvitation::class)($team->owner, $team->id, 'rollback@example.test', TeamRole::Member);
    DB::rollBack();
    expect(TeamInvitation::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('reports post-commit mail failures without losing the saved invitation', function (): void {
    $team = Team::factory()->create();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('synthetic transport failure'));
    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/invitations", ['email' => 'retry@example.test', 'role' => 'member'])
        ->assertSessionHasErrors('email');
    expect(TeamInvitation::query()->sole()->email)->toBe('retry@example.test');
});

it('reverses invitation schema without removing teams or identities', function (): void {
    $team = Team::factory()->create();
    InvitationFixture::issue($team);

    $migration = require base_path('database/migrations/2026_10_21_000005_create_team_invitations_table.php');
    $migration->down();
    expect(Schema::hasTable('team_invitations'))->toBeFalse()->and(Team::query()->count())->toBe(1)->and(User::query()->count())->toBe(1);
    $migration->up();
    expect(TeamInvitation::query()->count())->toBe(0);
});

it('serializes competing acceptance ownership and preference transitions across PostgreSQL sessions', function (string $scenario): void {
    config(['database.connections.membership_probe' => config('database.connections.pgsql')]);

    $probe = DB::connection('membership_probe');

    $ids = [];

    $suffix = (string) \Illuminate\Support\Str::uuid();

    foreach (['owner', 'other', 'invitee'] as $label) {
        $ids[] = $probe->table('users')->insertGetId([
            'name' => $label,
            'email' => $label.'-'.$suffix.'@example.test',
            'email_verified_at' => now(),
            'password' => 'unused-test-hash'
        ]);
    }

    $teamId = $probe->table('teams')->insertGetId([
        'name' => 'Contention fixture',
        'slug' => 'contention-'.$suffix,
        'owner_id' => $ids[0],
        'plan' => 'free',
        'timezone' => 'UTC',
        'trial_started_at' => now(),
        'trial_ends_at' => now()->addDays(14)
    ]);
    $probe->table('team_user')->insert([
        ['team_id' => $teamId, 'user_id' => $ids[0], 'role' => 'owner'],
        ['team_id' => $teamId, 'user_id' => $ids[1], 'role' => 'owner']
    ]);

    $hash = hash('sha256', 'synthetic-contention-token');

    $invitationId = $probe->table('team_invitations')->insertGetId([
        'team_id' => $teamId,
        'email' => 'invitee-'.$suffix.'@example.test',
        'role' => 'member',
        'token_hash' => $hash,
        'expires_at' => now()->addDays(7)
    ]);

    $owner = User::query()->findOrFail($ids[0]);

    $other = User::query()->findOrFail($ids[1]);

    $invitee = User::query()->findOrFail($ids[2]);
    DB::beginTransaction();

    try {
        match ($scenario) {
            'acceptance' => app(AcceptTeamInvitation::class)($invitee, $invitationId, $hash),
            'role-transfer' => app(ManageTeamMembers::class)->changeRole($owner, $teamId, $other->id, TeamRole::Admin),
            'switch-remove' => app(SwitchTeam::class)($other, $teamId),
            'remove-switch' => app(ManageTeamMembers::class)->remove($owner, $teamId, $other->id),
            'transfer-remove' => app(ManageTeamMembers::class)->transfer($owner, $teamId, $other->id),
        };
        $probe->statement("SET lock_timeout = '100ms'");
        DB::setDefaultConnection('membership_probe');

        try {
            match ($scenario) {
                'acceptance' => app(AcceptTeamInvitation::class)($invitee, $invitationId, $hash),
                'role-transfer' => app(ManageTeamMembers::class)->transfer($owner, $teamId, $other->id),
                'switch-remove', 'transfer-remove' => app(ManageTeamMembers::class)->remove($owner, $teamId, $other->id),
                'remove-switch' => app(SwitchTeam::class)($other, $teamId),
            };
            $this->fail('The competing operation must wait for the aggregate lock.');
        } catch (QueryException $exception) {
            // A bounded database timeout proves actual cross-session contention without sleeping.
            expect($exception->errorInfo[0])->toBe('55P03');
        } finally {
            DB::setDefaultConnection('pgsql');
        }
    } finally {
        DB::setDefaultConnection('pgsql');
        DB::rollBack();
        $probe->table('teams')->where('id', $teamId)->delete();
        $probe->table('users')->whereIn('id', $ids)->delete();
        DB::purge('membership_probe');
    }
})->with(['acceptance', 'role-transfer', 'switch-remove', 'remove-switch', 'transfer-remove']);

it('accepts immediately before expiry but rejects a forged bearer token without leaking metadata', function (): void {
    $team = Team::factory()->create();

    $user = User::factory()->create();

    $fixture = InvitationFixture::issue($team, $user->email);
    $this->get('/invitations/'.$fixture['invitation']->id.'/'.str_repeat('x', 64))
        ->assertRedirect('/invitations/accept')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('/invitations/accept')->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
    $this->travelTo($fixture['invitation']->expires_at->subSecond());
    $this->get($fixture['url'])
        ->assertSessionHas('team_invitation.hash', $fixture['invitation']->token_hash)
        ->assertSessionMissing('team_invitation.token');
    $this->actingAs($user)->post('/invitations/accept')->assertRedirect("/teams/{$team->id}");
});

it('restricts admin resend and revoke to manageable invitation roles and team-local IDs', function (): void {
    $team = Team::factory()->create();

    $admin = User::factory()->create();
    $team->users()->attach($admin->id, ['role' => 'admin']);

    $privileged = InvitationFixture::issue($team, 'owner-invite@example.test', TeamRole::Owner);

    $foreign = Team::factory()->create();

    $foreignInvite = InvitationFixture::issue($foreign);
    $this->actingAs($admin)
        ->post("/teams/{$team->id}/invitations/{$privileged['invitation']->id}/resend")
        ->assertForbidden();
    $this->delete("/teams/{$team->id}/invitations/{$privileged['invitation']->id}")->assertForbidden();
    $this->post("/teams/{$team->id}/invitations/{$foreignInvite['invitation']->id}/resend")->assertNotFound();
    $this->delete("/teams/{$team->id}/invitations/{$foreignInvite['invitation']->id}")->assertNotFound();
    $this->get("/teams/{$team->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('invitations', 0));
});

it('renews an expired invitation without duplicating its team-email lifecycle', function (): void {
    $team = Team::factory()->create();

    $fixture = InvitationFixture::issue($team);
    $this->travelTo($fixture['invitation']->expires_at);
    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/invitations", ['email' => 'invitee@example.test', 'role' => 'viewer'])
        ->assertRedirect("/teams/{$team->id}");
    expect(TeamInvitation::query()->count())->toBe(1)->and($fixture['invitation']->fresh()->role)->toBe(TeamRole::Viewer)
        ->and($fixture['invitation']->fresh()->token_hash)->not->toBe($fixture['invitation']->token_hash)
        ->and($fixture['invitation']->fresh()->expires_at->getTimestamp() - now()->getTimestamp())->toBe(7 * 86400);
});

it('rejects malformed invitation inputs without sending mail', function (array $input, string $field): void {
    $team = Team::factory()->create();
    $this->actingAs($team->owner)->post("/teams/{$team->id}/invitations", $input)->assertSessionHasErrors($field);
    expect(TeamInvitation::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    [['email' => 'invalid', 'role' => 'member'], 'email'],
    [['email' => 'valid@example.test', 'role' => 'superadmin'], 'role']
]);

it('rolls back accepted state membership and preference together when persistence fails', function (): void {
    $team = Team::factory()->create();

    $user = User::factory()->create();

    $fixture = InvitationFixture::issue($team, $user->email);
    DB::statement('ALTER TABLE users ADD CONSTRAINT fixture_forbid_selection CHECK (id <> '.$user->id.' OR current_team_id IS NULL)');

    try {
        expect(fn () => app(AcceptTeamInvitation::class)($user, $fixture['invitation']->id, $fixture['invitation']->token_hash))->toThrow(QueryException::class);
        expect($team->users()->count())->toBe(1)->and($fixture['invitation']->fresh()->accepted_at)->toBeNull()->and($user->fresh()->current_team_id)->toBeNull();
    } finally {
        DB::statement('ALTER TABLE users DROP CONSTRAINT fixture_forbid_selection');
    }
});

it('requires a fresh password on transfer POSTs and rejects stale ownership return destinations', function (): void {
    $team = Team::factory()->create();

    $successor = User::factory()->create();
    $team->users()->attach($successor->id, ['role' => 'owner']);
    $this->actingAs($team->owner)
        ->post("/teams/{$team->id}/ownership", ['user_id' => $successor->id])
        ->assertRedirect('/user/confirm-password');
    expect($team->fresh()->owner_id)->toBe($team->owner_id);
    $this->get("/teams/{$team->id}/ownership")->assertRedirect('/user/confirm-password');
    app(ManageTeamMembers::class)->transfer($team->owner, $team->id, $successor->id);
    $this->withSession(['url.intended' => 'https://untrusted.example/'])
        ->post('/user/confirm-password', ['password' => 'password'])
        ->assertRedirect('/account/security');
});
