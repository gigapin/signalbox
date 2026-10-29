<?php

declare(strict_types=1);

/** Exercise escalation policies, the team default and monitor overrides on PostgreSQL. */

use App\Actions\Notifications\AssignEscalationPolicy;
use App\Actions\Notifications\ManageEscalationPolicies;
use App\Exceptions\EscalationPolicyRejected;
use App\Models\EscalationPolicy;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;
use App\Services\Notifications\EscalationPolicyResolver;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->team = Team::factory()->create();
    $this->owner = $this->team->owner;
    [$this->email, $this->slack] = app(TenantContext::class)->run($this->team->id, fn (): array => [
        NotificationChannel::factory()->active()->create(),
        NotificationChannel::factory()->slack()->create(),
    ]);
});

/**
 * @param Team $team Team receiving the member.
 * @param string $role Persisted membership role.
 * @return User New member with that role.
 */
function epMember(Team $team, string $role): User
{
    $user = User::factory()->create();
    $team->users()->attach($user->id, ['role' => $role]);

    return $user;
}

/**
 * @param int ...$channels Channel identities of the first step.
 * @return list<array{after_minutes: int, channel_ids: list<int>}> Immediate step followed by a 15-minute escalation to the same channels.
 */
function epSteps(int ...$channels): array
{
    return [
        ['after_minutes' => 0, 'channel_ids' => array_values($channels)],
        ['after_minutes' => 15, 'channel_ids' => array_values($channels)],
    ];
}

/**
 * @param Team $team Owning team.
 * @return list<array{string, bool}> Policy names and default flags in name order.
 */
function epDefaults(Team $team): array
{
    return app(TenantContext::class)->run($team->id, fn (): array => EscalationPolicy::query()
        ->orderBy('name')
        ->get()
        ->map(static fn (EscalationPolicy $policy): array => [$policy->name, $policy->is_default])
        ->all());
}

it('stores ordered steps that reference inactive channels too', function (): void {
    $policy = app(ManageEscalationPolicies::class)->create($this->owner, $this->team->id, ' Primary ', [
        ['after_minutes' => 0, 'channel_ids' => [$this->email->id, $this->email->id]],
        ['after_minutes' => 30, 'channel_ids' => [$this->slack->id]],
    ], false);

    expect($policy->name)->toBe('Primary')
        ->and($policy->steps_json)->toBe([
            ['after_minutes' => 0, 'channel_ids' => [$this->email->id]],
            ['after_minutes' => 30, 'channel_ids' => [$this->slack->id]],
        ])
        ->and($policy->channelIds())->toBe([$this->email->id, $this->slack->id]);
});

it('rejects malformed steps, foreign channels and duplicate names', function (array $steps, string $message): void {
    $foreign = Team::factory()->create();
    $foreignChannel = app(TenantContext::class)->run($foreign->id, fn (): NotificationChannel => NotificationChannel::factory()->create());
    $resolved = array_map(fn (array $step): array => [
        'after_minutes' => $step['after_minutes'],
        'channel_ids' => array_map(fn (string|int $id): int => match ($id) {
            'email' => $this->email->id,
            'foreign' => $foreignChannel->id,
            default => (int) $id,
        }, $step['channel_ids']),
    ], $steps);

    expect(fn () => app(ManageEscalationPolicies::class)->create($this->owner, $this->team->id, 'Broken', $resolved, false))
        ->toThrow(EscalationPolicyRejected::class, $message)
        ->and(DB::table('escalation_policies')->count())->toBe(0);
})->with([
    'no steps' => [[], 'between 1 and 5 steps'],
    'too many steps' => [array_map(static fn (int $minute): array => ['after_minutes' => $minute, 'channel_ids' => ['email']], range(0, 5)), 'between 1 and 5 steps'],
    'equal delays' => [[['after_minutes' => 5, 'channel_ids' => ['email']], ['after_minutes' => 5, 'channel_ids' => ['email']]], 'Step 2 must wait longer'],
    'decreasing delays' => [[['after_minutes' => 10, 'channel_ids' => ['email']], ['after_minutes' => 5, 'channel_ids' => ['email']]], 'Step 2 must wait longer'],
    'delay beyond a day' => [[['after_minutes' => 1441, 'channel_ids' => ['email']]], 'between 0 and 1440 minutes'],
    'empty step' => [[['after_minutes' => 0, 'channel_ids' => []]], 'between 1 and 10 channels'],
    'foreign channel' => [[['after_minutes' => 0, 'channel_ids' => ['foreign']]], 'existing channels of this team'],
    'missing channel' => [[['after_minutes' => 0, 'channel_ids' => [999_999]]], 'existing channels of this team'],
]);

it('refuses a second policy with the same name ignoring case', function (): void {
    $actions = app(ManageEscalationPolicies::class);
    $actions->create($this->owner, $this->team->id, 'Primary', epSteps($this->email->id), false);

    expect(fn () => $actions->create($this->owner, $this->team->id, 'PRIMARY', epSteps($this->email->id), false))
        ->toThrow(EscalationPolicyRejected::class, 'already uses this name');
});

it('keeps exactly one default and lets a default be cleared', function (): void {
    $actions = app(ManageEscalationPolicies::class);
    $first = $actions->create($this->owner, $this->team->id, 'Alpha', epSteps($this->email->id), true);
    $actions->create($this->owner, $this->team->id, 'Beta', epSteps($this->email->id), true);

    expect(epDefaults($this->team))->toBe([['Alpha', false], ['Beta', true]]);

    $actions->update($this->owner, $this->team->id, $first->id, 'Alpha', epSteps($this->slack->id), true);
    expect(epDefaults($this->team))->toBe([['Alpha', true], ['Beta', false]]);

    $actions->update($this->owner, $this->team->id, $first->id, 'Alpha', epSteps($this->slack->id), false);
    expect(epDefaults($this->team))->toBe([['Alpha', false], ['Beta', false]]);
});

it('lets only owners and admins change policies', function (string $role, bool $allowed): void {
    $actor = epMember($this->team, $role);
    $create = fn (): EscalationPolicy => app(ManageEscalationPolicies::class)->create($actor, $this->team->id, 'Primary', epSteps($this->email->id), false);

    if ($allowed) {
        expect($create()->exists)->toBeTrue();

        return;
    }

    expect($create)->toThrow(AuthorizationException::class);
})->with([
    'admin' => ['admin', true],
    'member' => ['member', false],
    'viewer' => ['viewer', false],
]);

it('resolves the monitor override, then the team default, then nothing', function (): void {
    $actions = app(ManageEscalationPolicies::class);
    $monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create());
    $resolve = fn (): ?string => app(TenantContext::class)->run($this->team->id, fn (): ?string => app(EscalationPolicyResolver::class)
        ->forMonitor(Monitor::query()->findOrFail($monitor->id))?->name);

    expect($resolve())->toBeNull();

    $default = $actions->create($this->owner, $this->team->id, 'Default', epSteps($this->email->id), true);
    expect($resolve())->toBe('Default');

    $override = $actions->create($this->owner, $this->team->id, 'Database team', epSteps($this->slack->id), false);
    app(AssignEscalationPolicy::class)->assign($this->owner, $this->team->id, $monitor->id, $override->id);
    expect($resolve())->toBe('Database team');

    // Deleting the override falls back to the default through the foreign key.
    $actions->delete($this->owner, $this->team->id, $override->id);
    expect(DB::table('monitors')->where('id', $monitor->id)->value('escalation_policy_id'))->toBeNull()
        ->and($resolve())->toBe('Default');

    $actions->delete($this->owner, $this->team->id, $default->id);
    expect($resolve())->toBeNull();
});

it('lets monitor managers choose a policy without touching execution state', function (string $role, bool $allowed): void {
    $policy = app(ManageEscalationPolicies::class)->create($this->owner, $this->team->id, 'Primary', epSteps($this->email->id), false);
    $monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create());
    $before = DB::table('monitors')->where('id', $monitor->id)->first(['execution_revision', 'next_check_at', 'is_paused']);
    $actor = epMember($this->team, $role);
    $assign = fn (): Monitor => app(AssignEscalationPolicy::class)->assign($actor, $this->team->id, $monitor->id, $policy->id);

    if (!$allowed) {
        expect($assign)->toThrow(AuthorizationException::class)
            ->and(DB::table('monitors')->where('id', $monitor->id)->value('escalation_policy_id'))->toBeNull();

        return;
    }

    expect($assign()->escalation_policy_id)->toBe($policy->id)
        ->and(DB::table('monitors')->where('id', $monitor->id)->first(['execution_revision', 'next_check_at', 'is_paused']))->toEqual($before);

    app(AssignEscalationPolicy::class)->assign($actor, $this->team->id, $monitor->id, null);
    expect(DB::table('monitors')->where('id', $monitor->id)->value('escalation_policy_id'))->toBeNull();
})->with([
    'member' => ['member', true],
    'viewer' => ['viewer', false],
]);

it('refuses another team policy for a monitor', function (): void {
    $foreign = Team::factory()->create();
    $foreignPolicy = app(TenantContext::class)->run($foreign->id, fn (): EscalationPolicy => EscalationPolicy::factory()->create());
    $monitor = app(TenantContext::class)->run($this->team->id, fn (): Monitor => Monitor::factory()->create());

    expect(fn () => app(AssignEscalationPolicy::class)->assign($this->owner, $this->team->id, $monitor->id, $foreignPolicy->id))
        ->toThrow(EscalationPolicyRejected::class, 'Choose an escalation policy of this team.')
        // The composite key rejects the same cross-team reference even when written directly.
        ->and(fn () => DB::transaction(fn () => DB::table('monitors')->where('id', $monitor->id)->update(['escalation_policy_id' => $foreignPolicy->id])))
        ->toThrow(QueryException::class);
});

it('enforces policy invariants in the database', function (): void {
    $policy = app(TenantContext::class)->run($this->team->id, fn (): EscalationPolicy => EscalationPolicy::factory()->default()->create());
    $other = app(TenantContext::class)->run($this->team->id, fn (): EscalationPolicy => EscalationPolicy::factory()->create());

    foreach ([
        fn () => DB::table('escalation_policies')->where('id', $other->id)->update(['is_default' => true]),
        fn () => DB::table('escalation_policies')->where('id', $other->id)->update(['steps_json' => '[]']),
        fn () => DB::table('escalation_policies')->where('id', $other->id)->update(['steps_json' => '{}']),
        fn () => DB::table('escalation_policies')->where('id', $other->id)->update(['name' => strtoupper($policy->name)]),
    ] as $statement) {
        expect(fn () => DB::transaction($statement))->toThrow(QueryException::class);
    }
});
