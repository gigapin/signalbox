<?php

/** Verify tenant query/write boundaries, HTTP binding, policies and worker lifetimes on PostgreSQL. */

use App\Enums\Identity\TeamRole;
use App\Exceptions\MissingTenantContext;
use App\Http\Middleware\EstablishTeamContext;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Policies\TeamPolicy;
use App\Services\Identity\InvitationLinks;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TenantProbeJob;
use Tests\Fixtures\TenantRecord;
use Tests\Support\InvitationFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL and Redis.');
    }

    $this->withoutVite();
    Notification::fake();
    // PostgreSQL temporary relations cannot reference permanent tables; production invitation FKs remain covered by M1.03.
    DB::statement('CREATE TEMPORARY TABLE signalbox_tenant_records (id bigserial PRIMARY KEY, team_id bigint NOT NULL, title text NOT NULL) ON COMMIT DROP');
});

it('requires explicit context for private queries and model writes', function (): void {
    expect(fn () => TenantRecord::query()->count())->toThrow(MissingTenantContext::class);
    expect(fn () => TenantRecord::query()->create(['title' => 'private']))->toThrow(MissingTenantContext::class);
    expect(fn () => TeamInvitation::query()->count())->toThrow(MissingTenantContext::class);
    expect(User::query()->count())->toBe(0)->and(Team::query()->count())->toBe(0);
});

it('isolates counts relations aggregates and bulk mutations with qualified predicates', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $context = app(TenantContext::class);
    $a = $context->run($alpha->id, fn () => TenantRecord::query()->create(['title' => 'alpha']));
    $b = $context->run($beta->id, fn () => TenantRecord::query()->create(['title' => 'beta']));

    $context->run($alpha->id, function () use ($alpha, $a, $b): void {
        expect(TenantRecord::query()->count())->toBe(1)
            ->and(TenantRecord::query()->max('id'))->toBe($a->id)
            ->and(TenantRecord::query()->whereHas('team', fn ($query) => $query->whereKey($alpha->id))->count())->toBe(1)
            ->and(TenantRecord::query()->with('team')->sole()->team->id)->toBe($alpha->id)
            ->and(TenantRecord::query()->join('teams', 'teams.id', '=', 'signalbox_tenant_records.team_id')->count())->toBe(1)
            ->and(TenantRecord::query()->whereKey($b->id)->update(['title' => 'forged']))->toBe(0)
            ->and(TenantRecord::query()->whereKey($b->id)->delete())->toBe(0);
        expect(fn () => TenantRecord::query()->findOrFail($b->id))->toThrow(ModelNotFoundException::class);
    });

    expect($context->run($beta->id, fn () => $b->fresh()->title))->toBe('beta');
    expect(fn () => $context->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('assigns ownership and rejects forged ownership reassignment and foreign model deletion', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $context = app(TenantContext::class);
    $record = $context->run($alpha->id, fn () => TenantRecord::query()->create(['title' => 'alpha']));
    expect($record->team_id)->toBe($alpha->id);

    $context->run($alpha->id, function () use ($record, $beta): void {
        expect(fn () => TenantRecord::query()->create(['team_id' => $beta->id, 'title' => 'forged']))->toThrow(LogicException::class);
        $record->team_id = $beta->id;
        expect(fn () => $record->save())->toThrow(LogicException::class);
    });
    $context->run($beta->id, function () use ($record, $beta): void {
        $record->team_id = $beta->id;
        expect(fn () => $record->save())->toThrow(LogicException::class);
        expect(fn () => $record->delete())->toThrow(LogicException::class);
    });
    expect($context->run($alpha->id, fn () => $record->fresh()->team_id))->toBe($alpha->id);
    expect(fn () => $record->delete())->toThrow(MissingTenantContext::class);
});

it('establishes the explicit route team before implicit binding and clears it on 404 or exception', function (): void {
    Route::middleware(['web', 'auth', 'verified', EstablishTeamContext::class])
        ->get('/_test/tenants/{team}/records/{record}', fn (string $team, TenantRecord $record) => response()->json(['title' => $record->title]))->whereNumber('team');
    Route::middleware(['web', 'auth', EstablishTeamContext::class])
        ->get('/_test/tenants/{team}/fail', fn () => throw new RuntimeException('synthetic HTTP failure'))->whereNumber('team');

    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $beta->users()->attach($alpha->owner_id, ['role' => 'viewer']);
    $alpha->owner->forceFill(['current_team_id' => $alpha->id])->save();
    $context = app(TenantContext::class);
    $a = $context->run($alpha->id, fn () => TenantRecord::query()->create(['title' => 'alpha']));
    $b = $context->run($beta->id, fn () => TenantRecord::query()->create(['title' => 'beta']));
    $this->actingAs($alpha->owner)->get("/_test/tenants/{$alpha->id}/records/{$a->id}")->assertOk()->assertJsonPath('title', 'alpha');
    $this->get("/_test/tenants/{$alpha->id}/records/{$b->id}")->assertNotFound();
    expect(fn () => $context->requireTeamId())->toThrow(MissingTenantContext::class);
    $this->get("/_test/tenants/{$alpha->id}/fail")->assertStatus(500);
    expect(fn () => $context->requireTeamId())->toThrow(MissingTenantContext::class);
    $this->get("/_test/tenants/{$beta->id}/records/{$b->id}")->assertOk()->assertJsonPath('title', 'beta');
    $beta->users()->detach($alpha->owner_id);
    $this->get("/_test/tenants/{$beta->id}/records/{$b->id}")->assertNotFound();
});

it('keeps capabilities available without membership while private invitation queries require context', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $fixture = InvitationFixture::issue($alpha);
    $context = app(TenantContext::class);
    expect(fn () => $context->requireTeamId())->toThrow(MissingTenantContext::class);
    expect(app(InvitationLinks::class)->resolve($fixture['invitation']->id, str_repeat('0', 64)))->toBeNull();
    $context->run($beta->id, function () use ($fixture): void {
        expect(TeamInvitation::query()->find($fixture['invitation']->id))->toBeNull();
    });
    $this->get($fixture['url'])->assertRedirect('/invitations/accept');
    $this->get('/invitations/accept')->assertOk();
    expect(fn () => $context->requireTeamId())->toThrow(MissingTenantContext::class);
});

it('applies fresh policies for all roles without treating preference as authority', function (string $role): void {
    $team = Team::factory()->create();
    $actor = User::factory()->create();
    $team->users()->attach($actor->id, ['role' => $role]);
    $policy = app(TeamPolicy::class);
    expect($actor->resolveCurrentTeam())->toBeNull()
        ->and($policy->view($actor, $team))->toBeTrue()
        ->and($policy->manageRole($actor, $team, TeamRole::Member))->toBe(in_array($role, ['owner', 'admin'], true))
        ->and($policy->manageRole($actor, $team, TeamRole::Admin))->toBe($role === 'owner')
        ->and($policy->removeMember($actor, $team, $actor))->toBeTrue()
        ->and($policy->transferOwnership($actor, $team))->toBeFalse();
    $this->actingAs($actor)->get("/teams/{$team->id}")->assertOk();
    $team->users()->detach($actor->id);
    expect(fn () => $policy->view($actor, $team))->toThrow(ModelNotFoundException::class);
    $this->get("/teams/{$team->id}")->assertNotFound();
})->with(['owner', 'admin', 'member', 'viewer']);

it('executes serialized jobs in one worker without leaking context after failures or foreign IDs', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $context = app(TenantContext::class);
    $a = $context->run($alpha->id, fn () => TenantRecord::query()->create(['title' => 'alpha']));
    $b = $context->run($beta->id, fn () => TenantRecord::query()->create(['title' => 'beta']));
    $queue = 'tenant-probe:'.bin2hex(random_bytes(12));
    $transport = Queue::connection('redis');
    $worker = app('queue.worker');
    $options = new WorkerOptions(sleep: 0, maxTries: 1);

    try {
        foreach ([
            new TenantProbeJob($alpha->id, $a->id, $queue.':a', true),
            new TenantProbeJob($alpha->id, $b->id, $queue.':foreign'),
            new TenantProbeJob($beta->id, $b->id, $queue.':b'),
        ] as $job) {
            $transport->push($job, '', $queue);
            $worker->runNextJob('redis', $queue, $options);
            expect(fn () => $context->requireTeamId())->toThrow(MissingTenantContext::class);
        }

        expect(Cache::get($queue.':a'))->toBe([$alpha->id, 'alpha'])
            ->and(Cache::get($queue.':a:failed'))->toBe(RuntimeException::class)
            ->and(Cache::has($queue.':foreign'))->toBeFalse()
            ->and(Cache::get($queue.':foreign:failed'))->toBe(ModelNotFoundException::class)
            ->and(Cache::get($queue.':b'))->toBe([$beta->id, 'beta'])
            ->and($transport->size($queue))->toBe(0);
    } finally {
        $transport->clear($queue);
    }
});

it('runs CLI work explicitly and resets the scoped binding between lifecycles', function (): void {
    $context = app(TenantContext::class);
    $team = Team::factory()->create();
    $context->run($team->id, fn () => TenantRecord::query()->create(['title' => 'CLI fixture']));
    Artisan::command('_test:tenant-count {team}', function (): void {
        $count = app(TenantContext::class)->run((int) $this->argument('team'), fn () => TenantRecord::query()->count());
        $this->line((string) $count);
    });
    expect(Artisan::call('_test:tenant-count', ['team' => $team->id]))->toBe(0);
    expect(trim(Artisan::output()))->toBe('1');
    expect(fn () => TenantRecord::query()->count())->toThrow(MissingTenantContext::class);
    app()->forgetScopedInstances();
    expect(app(TenantContext::class))->not->toBe($context);
    expect(fn () => app(TenantContext::class)->requireTeamId())->toThrow(MissingTenantContext::class);
});


it('keeps model reloads and restoration scoped instead of using Laravel unscoped defaults', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $context = app(TenantContext::class);
    $record = $context->run($alpha->id, fn () => TenantRecord::query()->create(['title' => 'alpha']));
    expect(fn () => $record->fresh())->toThrow(MissingTenantContext::class);
    expect(fn () => $record->refresh())->toThrow(MissingTenantContext::class);
    expect(fn () => $record->newQueryForRestoration($record->id)->first())->toThrow(MissingTenantContext::class);
    $context->run($beta->id, function () use ($record): void {
        expect($record->fresh())->toBeNull();
        expect(fn () => $record->refresh())->toThrow(ModelNotFoundException::class);
        expect(fn () => $record->refreshForUpdate())->toThrow(ModelNotFoundException::class);
        expect($record->newQueryForRestoration([$record->id])->count())->toBe(0);
    });
    $context->run($alpha->id, function () use ($record): void {
        expect($record->fresh()->title)->toBe('alpha');
        expect($record->refreshForUpdate())->toBe($record);
    });
});


it('guards model increment operations that bypass saving events', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $context = app(TenantContext::class);
    $record = $context->run($alpha->id, fn () => TenantRecord::query()->create(['title' => 'alpha']));
    expect(fn () => $record->increment('team_id'))->toThrow(MissingTenantContext::class);
    $record->team_id = $alpha->id;
    $context->run($alpha->id, function () use ($record): void {
        expect(fn () => $record->increment('team_id'))->toThrow(LogicException::class);
    });
    $context->run($beta->id, function () use ($record): void {
        expect(fn () => $record->increment('id'))->toThrow(LogicException::class);
    });
    expect($context->run($alpha->id, fn () => TenantRecord::query()->sole()->team_id))->toBe($alpha->id);
});
