<?php

/** Verify identity aggregates, membership isolation and PostgreSQL constraints on disposable services. */

use App\Actions\Identity\CreateTeam;
use App\Enums\Billing\Plan;
use App\Enums\Identity\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

// Identity integration fixtures require the same guarded, isolated PostgreSQL lifecycle as all domain tests.
pest()->group('docker');
beforeEach(/** Skip domain database fixtures before any query when integration opt-in is absent. */ function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires the isolated php-test PostgreSQL service.');
    }
});

it('creates an atomic owner aggregate with exact UTC trial history and no implicit authorization', /** Verify production creation defaults and protected persistence attributes. */ function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:30:00', 'UTC'));
    /** @var User $owner Persisted identity without an implicit factory team. */
    $owner = User::factory()->create();
    /** @var Team $team Aggregate created through the actual domain boundary. */
    $team = app(CreateTeam::class)($owner, '  Example team  ', 'example-team');

    expect($team->name)->toBe('Example team')
        ->and($team->timezone)->toBe('UTC')
        ->and($team->plan)->toBe(Plan::Free)
        ->and($team->trial_started_at->toIso8601String())->toBe('2026-10-20T12:30:00+00:00')
        ->and($team->trial_ends_at->getTimestamp() - $team->trial_started_at->getTimestamp())->toBe(14 * 86400)
        ->and($team->created_at?->equalTo($team->trial_started_at))->toBeTrue()
        ->and($team->owner->is($owner))->toBeTrue()
        ->and($owner->resolveCurrentTeam()?->is($team))->toBeTrue()
        ->and($owner->teams()->first()?->pivot->role)->toBe(TeamRole::Owner)
        ->and($owner->ownedTeams()->count())->toBe(1);
    expect($owner->isFillable('current_team_id'))->toBeFalse()
        ->and($team->isFillable('owner_id'))->toBeFalse()
        ->and($team->isFillable('plan'))->toBeFalse()
        ->and($team->isFillable('trial_started_at'))->toBeFalse();
});

it('preserves the original selection across repeated creation from a stale user model', /** Read selection under the owner lock instead of trusting caller snapshots. */ function (): void {
    /** @var User $owner Caller intentionally retains an identity with a null preference. */
    $owner = User::factory()->create();
    /** @var Team $first First team selected by production creation. */
    $first = app(CreateTeam::class)($owner, 'First', 'first');
    /** @var Team $second Second team must not overwrite the first selection. */
    $second = app(CreateTeam::class)($owner, 'Second', 'second', 'Europe/Rome');

    expect($owner->current_team_id)->toBeNull()
        ->and($owner->resolveCurrentTeam()?->id)->toBe($first->id)
        ->and($second->timezone)->toBe('Europe/Rome')
        ->and($owner->teams()->count())->toBe(2);
});

it('rejects invalid creation input without any partial records', /** @param string $field Invalid input key. @param string $value Invalid input value. */ function (string $field, string $value): void {
    /** @var User $owner Synthetic actor with no initial selection. */
    $owner = User::factory()->create();
    /** @var array{name: string, slug: string, timezone: string} $input Otherwise valid domain input. */
    $input = ['name' => 'Team', 'slug' => 'valid-team', 'timezone' => 'UTC'];
    $input[$field] = $value;
    expect(fn () => app(CreateTeam::class)($owner, ...$input))->toThrow(ValidationException::class);
    expect(Team::query()->count())->toBe(0)
        ->and(Membership::query()->count())->toBe(0)
        ->and($owner->fresh()?->current_team_id)->toBeNull();
})->with([
    ['name', '   '], ['name', str_repeat('a', 256)], ['slug', 'UpperCase'],
    ['slug', '-leading'], ['slug', 'trailing-'], ['slug', 'space slug'],
    ['slug', str_repeat('a', 256)], ['timezone', 'Mars/Olympus'],
]);

it('rolls back the whole aggregate when membership persistence fails', /** Force a real PostgreSQL constraint failure after the team insert. */ function (): void {
    /** @var User $owner Actor whose preference must remain unchanged after rollback. */
    $owner = User::factory()->create();
    DB::statement("ALTER TABLE team_user ADD CONSTRAINT fixture_reject_owner CHECK (role <> 'owner')");
    try {
        expect(fn () => app(CreateTeam::class)($owner, 'Rollback', 'rollback'))->toThrow(QueryException::class);
        expect(Team::query()->count())->toBe(0)
            ->and(Membership::query()->count())->toBe(0)
            ->and($owner->fresh()?->current_team_id)->toBeNull();
    } finally {
        DB::statement('ALTER TABLE team_user DROP CONSTRAINT fixture_reject_owner');
    }
});

it('rejects duplicate slugs without changing the second owner or the existing aggregate', /** Exercise the database uniqueness boundary through production orchestration. */ function (): void {
    /** @var Team $existing First persisted aggregate. */
    $existing = app(CreateTeam::class)(User::factory()->create(), 'Existing', 'same-slug');
    /** @var User $other Independent actor whose attempted creation must roll back. */
    $other = User::factory()->create();
    expect(fn () => app(CreateTeam::class)($other, 'Duplicate', 'same-slug'))->toThrow(QueryException::class);
    expect(Team::query()->count())->toBe(1)
        ->and($existing->users()->count())->toBe(1)
        ->and($other->fresh()?->current_team_id)->toBeNull();
});

it('resolves membership afresh and never falls back to unrelated or another accessible team', /** Revoked selections and direct foreign IDs cannot resolve private team identity. */ function (): void {
    /** @var User $user Identity that will retain stale relationship data intentionally. */
    $user = User::factory()->create();
    /** @var Team $selected Team originally selected by creation. */
    $selected = app(CreateTeam::class)($user, 'Selected', 'selected');
    /** @var Team $other Accessible but never automatically selected team. */
    $other = app(CreateTeam::class)($user, 'Other', 'other');
    /** @var Team $foreign Team with an entirely different owner. */
    $foreign = Team::factory()->create();
    $user->load('teams');
    $selected->users()->detach($user->id);
    expect($user->resolveCurrentTeam())->toBeNull();
    $user->current_team_id = $foreign->id;
    $user->save();
    expect($user->resolveCurrentTeam())->toBeNull();
    $user->current_team_id = $other->id;
    $user->save();
    expect($user->resolveCurrentTeam()?->id)->toBe($other->id)
        ->and($user->teams()->whereKey($foreign->id)->exists())->toBeFalse();
});

it('supports multiple owners and typed membership relations in both directions', /** Check factory states and the distinction between primary and additional owners. */ function (): void {
    /** @var Team $team Coherent fixture including its primary owner. */
    $team = Team::factory()->create();
    /** @var string $state Factory state matching a supported role value. */
    foreach (['owner', 'admin', 'member', 'viewer'] as $state) {
        /** @var Membership $membership Additional assignment without replacing primary ownership. */
        $membership = Membership::factory()->for($team)->{$state}()->create();
        expect($membership->role)->toBe(TeamRole::from($state))
            ->and($membership->team->is($team))->toBeTrue()
            ->and($membership->user->teams()->whereKey($team->id)->first()?->pivot->role)->toBe(TeamRole::from($state));
    }
    expect($team->users()->wherePivot('role', TeamRole::Owner->value)->count())->toBe(2)
        ->and($team->users()->whereKey($team->owner_id)->first()?->pivot->role)->toBe(TeamRole::Owner);
});

it('enforces persisted role plan membership and reference constraints', /** @param string $scenario Invalid database operation isolated by a nested transaction savepoint. */ function (string $scenario): void {
    /** @var Team $team Valid starting aggregate whose rows survive the rejected operation. */
    $team = Team::factory()->create();
    expect(fn () => DB::transaction(/** Apply one intentionally invalid persistence operation. */ function () use ($scenario, $team): void {
        match ($scenario) {
            'role' => DB::table('team_user')->where('team_id', $team->id)->update(['role' => 'superuser']),
            'plan' => DB::table('teams')->where('id', $team->id)->update(['plan' => 'unknown']),
            'pair' => DB::table('team_user')->insert(['team_id' => $team->id, 'user_id' => $team->owner_id, 'role' => 'viewer']),
            'member_user' => DB::table('team_user')->insert(['team_id' => $team->id, 'user_id' => 9223372036854775807, 'role' => 'viewer']),
            'member_team' => DB::table('team_user')->insert(['team_id' => 9223372036854775807, 'user_id' => $team->owner_id, 'role' => 'viewer']),
            'owner' => DB::table('teams')->where('id', $team->id)->update(['owner_id' => 9223372036854775807]),
            'selection' => DB::table('users')->where('id', $team->owner_id)->update(['current_team_id' => 9223372036854775807]),
            'trial' => DB::table('teams')->where('id', $team->id)->update(['trial_ends_at' => $team->trial_started_at]),
            'delete_owner' => $team->owner->delete(),
        };
    }))->toThrow(QueryException::class);
    expect($team->fresh())->not->toBeNull();
})->with(['role', 'plan', 'pair', 'member_user', 'member_team', 'owner', 'selection', 'trial', 'delete_owner']);

it('cascades memberships and clears selections when a team is deleted', /** Deletion never removes the unrelated user identities. */ function (): void {
    /** @var User $owner Primary owner whose preference references the deleted team. */
    $owner = User::factory()->create();
    /** @var Team $team Aggregate to delete. */
    $team = app(CreateTeam::class)($owner, 'Disposable', 'disposable');
    Membership::factory()->for($team)->viewer()->create();
    $team->delete();
    expect(Membership::query()->count())->toBe(0)
        ->and($owner->fresh()?->current_team_id)->toBeNull()
        ->and($owner->resolveCurrentTeam())->toBeNull()
        ->and(User::query()->count())->toBe(2);
});

it('removes only the deleted non-owner membership', /** A deleted ordinary identity must not remove its team or other members. */ function (): void {
    /** @var Membership $member Disposable ordinary team participant. */
    $member = Membership::factory()->create();
    $member->user->delete();
    expect($member->team->users()->count())->toBe(1)
        ->and(Membership::query()->where('user_id', $member->user_id)->exists())->toBeFalse();
});

it('builds explicit plan fixtures with immutable timestamps and no implicit user teams', /** Check factory compatibility and all persisted plan enum variants. */ function (): void {
    /** @var User $user Factory identity independent of any team. */
    $user = User::factory()->create();
    expect($user->current_team_id)->toBeNull()->and($user->teams)->toHaveCount(0);
    /** @var string $state Explicit plan state applied to a separate coherent aggregate. */
    foreach (['free', 'pro', 'business'] as $state) {
        /** @var Team $team Factory aggregate with its original trial history. */
        $team = Team::factory()->{$state}()->create();
        expect($team->fresh()?->plan)->toBe(Plan::from($state))
            ->and($team->trial_started_at)->toBeInstanceOf(CarbonImmutable::class)
            ->and($team->trial_ends_at->getTimestamp() - $team->trial_started_at->getTimestamp())->toBe(14 * 86400);
    }
});

it('seeds two synthetic teams repeatably without resetting credentials selections or trials', /** Run the public seeding entrypoint twice against isolated fixtures. */ function (): void {
    $this->seed(DatabaseSeeder::class);
    /** @var User $owner Known fixture identity used to verify stable credentials and selection. */
    $owner = User::query()->where('email', 'owner@signalbox.test')->firstOrFail();
    /** @var string $password Original credential hash retained across repeat seeding. */
    $password = $owner->password;
    /** @var array<int, mixed> $trials Original persisted starts, proving seeding cannot renew trials. */
    $trials = Team::query()->pluck('trial_started_at', 'id')->all();
    $this->travel(15)->days();
    $this->seed(DatabaseSeeder::class);
    expect(User::query()->count())->toBe(4)
        ->and(Team::query()->count())->toBe(2)
        ->and(Membership::query()->count())->toBe(8)
        ->and(Team::query()->pluck('trial_started_at', 'id')->all())->toEqual($trials)
        ->and($owner->fresh()?->password)->toBe($password)
        ->and(Hash::check('password', $password))->toBeTrue()
        ->and($owner->fresh()?->current_team_id)->toBe($owner->current_team_id)
        ->and(Membership::query()->pluck('role')->map(/** @param TeamRole $role Persisted fixture role. @return string Enum storage value. */ fn (TeamRole $role): string => $role->value)->unique()->sort()->values()->all())->toBe(['admin', 'member', 'owner', 'viewer']);
});

it('refuses synthetic seeding in production before writing any account', /** Verify the environment guard even when Artisan force is used by a caller. */ function (): void {
    app()->instance('env', 'production');
    try {
        expect(fn () => $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->run())->toThrow(LogicException::class);
        expect(User::query()->count())->toBe(0)->and(Team::query()->count())->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
});

it('reverses identity and dependent migrations without changing baseline identities', function (): void {
    $user = User::factory()->create();
    $files = [
        'database/migrations/2026_10_20_000001_create_teams_table.php',
        'database/migrations/2026_10_20_000002_create_team_user_table.php',
        'database/migrations/2026_10_20_000003_add_current_team_to_users.php',
        'database/migrations/2026_10_21_000005_create_team_invitations_table.php',
        'database/migrations/2026_10_22_000006_create_monitors_table.php',
        // Plan overrides reference teams, so they are removed before teams and restored after them.
        'database/migrations/2026_10_23_000009_create_team_plan_overrides_table.php',
        // Execution ownership references monitors and teams and must be reversed first.
        'database/migrations/2026_10_23_000010_create_check_executions_table.php',
        'database/migrations/2026_10_26_000011_create_monitor_heartbeats.php',
        'database/migrations/2026_10_26_000012_create_partitioned_check_results.php',
        // Incidents reference monitors and users through foreign keys and are reversed before them.
        'database/migrations/2026_10_28_000013_create_incidents_tables.php',
        'database/migrations/2026_10_28_000014_create_monitor_region_sequences.php',
        'database/migrations/2026_10_28_000015_create_maintenance_windows.php',
        'database/migrations/2026_10_28_000016_create_monitor_pause_periods.php',
        // Channels, policies and delivery logs reference teams and incidents; the override references policies.
        'database/migrations/2026_10_29_000017_create_notification_tables.php',
        'database/migrations/2026_10_29_000018_add_escalation_policy_to_monitors.php',
        'database/migrations/2026_10_29_000019_add_notification_delivery_outbox.php',
        'database/migrations/2026_10_30_000020_add_channel_quiet_hours.php',
        // Rollups reference monitors and are reversed before them.
        'database/migrations/2026_10_30_000021_create_check_rollups.php',
    ];
    foreach (array_reverse($files) as $file) {
        (require base_path($file))->down();
    }
    expect(User::query()->findOrFail($user->id)->email)->toBe($user->email);
    foreach ($files as $file) {
        (require base_path($file))->up();
    }
    expect($user->fresh()?->current_team_id)->toBeNull()
        ->and($user->fresh()?->password)->toBe($user->password)
        ->and(Team::query()->count())->toBe(0)
        ->and(Membership::query()->count())->toBe(0);
});


it('serializes competing team creations with an actual PostgreSQL owner row lock', /** Use two independent sessions to exercise creation contention without parallel test databases. */ function (): void {
    config(['database.connections.identity_probe' => config('database.connections.pgsql')]);
    /** @var \Illuminate\Database\Connection $probe Independent session outside RefreshDatabase's outer transaction. */
    $probe = DB::connection('identity_probe');
    /** @var User $owner Committed fixture visible to both competing sessions. */
    $owner = User::factory()->make();
    $owner->setConnection('identity_probe');
    $owner->save();
    $owner->setConnection('pgsql');
    DB::beginTransaction();
    try {
        app(CreateTeam::class)($owner, 'Uncommitted', 'uncommitted-race');
        $probe->statement("SET lock_timeout = '100ms'");
        DB::setDefaultConnection('identity_probe');
        try {
            app(CreateTeam::class)($owner->setConnection('identity_probe'), 'Competing', 'competing-race');
            $this->fail('Competing creation must wait for the owner lock.');
        } catch (QueryException $exception) {
            /** @var QueryException $exception Database failure carrying the PostgreSQL SQLSTATE. */
            // PostgreSQL lock_not_available proves contention at the shared owner row.
            expect($exception->errorInfo[0])->toBe('55P03');
        } finally {
            DB::setDefaultConnection('pgsql');
        }
        expect($probe->table('teams')->where('owner_id', $owner->id)->count())->toBe(0)
            ->and($probe->table('users')->where('id', $owner->id)->value('current_team_id'))->toBeNull();
    } finally {
        DB::setDefaultConnection('pgsql');
        DB::rollBack();
        $probe->table('users')->where('id', $owner->id)->delete();
        DB::purge('identity_probe');
    }
});
