<?php

declare(strict_types=1);

/** Exercise status page changes, monitor selection, plan limits and password storage on isolated PostgreSQL. */

use App\Actions\StatusPages\ManageStatusPages;
use App\Data\StatusPages\StatusPageInput;
use App\Data\StatusPages\StatusPageTheme;
use App\Enums\StatusPages\StatusPageAccent;
use App\Enums\StatusPages\StatusPageThemeMode;
use App\Exceptions\StatusPageRejected;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CommittedTeamFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->withoutVite();
    $this->actions = app(ManageStatusPages::class);
});

/**
 * @param Team $team Owning team.
 * @param string $name Monitor name.
 * @return Monitor Monitor of that team.
 */
function statusPageMonitor(Team $team, string $name = 'internal-monitor'): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->create(['name' => $name]));
}

/**
 * @param array<string, mixed> $overrides Replaced input fields.
 * @return StatusPageInput Valid draft without components unless overridden.
 */
function statusPageInput(array $overrides = []): StatusPageInput
{
    $values = [
        'slug' => 'acme-status',
        'title' => 'Acme status',
        'description' => null,
        'theme' => new StatusPageTheme(StatusPageThemeMode::System, StatusPageAccent::Emerald),
        'isPublic' => false,
        'password' => null,
        'removePassword' => false,
        'components' => [],
        ...$overrides,
    ];

    return new StatusPageInput(...$values);
}

/**
 * @param Team $team Owning team.
 * @param int $id Page identity.
 * @return StatusPage Fresh persisted page.
 */
function reloadStatusPage(Team $team, int $id): StatusPage
{
    return app(TenantContext::class)->run($team->id, fn (): StatusPage => StatusPage::query()->findOrFail($id));
}

/**
 * @param Team $team Owning team.
 * @param int $id Page identity.
 * @return list<array{string, list<int>}> Component names with sorted monitor identities, in display order.
 */
function statusPageComponents(Team $team, int $id): array
{
    return app(TenantContext::class)->run($team->id, fn (): array => StatusPageComponent::query()
        ->where('status_page_id', $id)
        ->orderBy('position')
        ->with('monitors')
        ->get()
        ->map(static fn (StatusPageComponent $component): array => [
            $component->name,
            $component->monitors->pluck('id')->sort()->values()->all(),
        ])
        ->all());
}

it('creates a draft page with ordered components through the web route', function (): void {
    $team = Team::factory()->create();
    $api = statusPageMonitor($team, 'api-eu');
    $web = statusPageMonitor($team, 'web-eu');

    $this->actingAs($team->owner)->from("/teams/{$team->id}/status-pages")
        ->post("/teams/{$team->id}/status-pages", [
            'slug' => 'acme-status',
            'title' => ' Acme status ',
            'description' => '',
            'theme_mode' => 'dark',
            'theme_accent' => 'violet',
            'is_public' => false,
            'components' => [
                ['name' => 'Website', 'monitor_ids' => [$web->id]],
                ['name' => 'API', 'monitor_ids' => [$api->id]],
            ],
            'team_id' => 999,
            'created_by' => 999,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'status-page-created');

    $page = app(TenantContext::class)->run($team->id, fn (): StatusPage => StatusPage::query()->sole());

    expect($page->team_id)->toBe($team->id)
        ->and($page->created_by)->toBe($team->owner_id)
        ->and($page->title)->toBe('Acme status')
        ->and($page->description)->toBeNull()
        ->and($page->is_public)->toBeFalse()
        ->and($page->password_hash)->toBeNull()
        ->and($page->theme_json)->toBe(['mode' => 'dark', 'accent' => 'violet'])
        ->and(statusPageComponents($team, $page->id))->toBe([['Website', [$web->id]], ['API', [$api->id]]]);
});

it('replaces components and their order on each save', function (): void {
    $team = Team::factory()->create();
    $first = statusPageMonitor($team, 'first');
    $second = statusPageMonitor($team, 'second');

    $page = $this->actions->create($team->owner, $team->id, statusPageInput([
        'components' => [
            ['name' => 'One', 'monitor_ids' => [$first->id]],
            ['name' => 'Two', 'monitor_ids' => [$second->id]],
        ],
    ]));

    $this->actions->update($team->owner, $team->id, $page->id, statusPageInput([
        'components' => [
            ['name' => 'Two', 'monitor_ids' => [$second->id, $second->id]],
            ['name' => 'One', 'monitor_ids' => [$first->id]],
        ],
    ]));

    expect(statusPageComponents($team, $page->id))->toBe([['Two', [$second->id]], ['One', [$first->id]]]);

    $this->actions->update($team->owner, $team->id, $page->id, statusPageInput());

    expect(statusPageComponents($team, $page->id))->toBe([]);
});

it('refuses a monitor of another team exactly like one that does not exist', function (): void {
    $team = Team::factory()->create();
    $foreign = statusPageMonitor(Team::factory()->create());

    $message = static function (int $monitorId) use ($team): string {
        try {
            app(ManageStatusPages::class)->create($team->owner, $team->id, statusPageInput([
                'components' => [['name' => 'API', 'monitor_ids' => [$monitorId]]],
            ]));
        } catch (StatusPageRejected $exception) {
            return $exception->getMessage();
        }

        throw new LogicException('The foreign selection was accepted.');
    };

    expect($message($foreign->id))->toBe($message(999_999_999));
    expect(app(TenantContext::class)->run($team->id, fn (): int => StatusPage::query()->count()))->toBe(0);
});

it('makes the database reject a foreign monitor and a monitor listed twice on a page', function (): void {
    $team = Team::factory()->create();
    $own = statusPageMonitor($team);
    $foreign = statusPageMonitor(Team::factory()->create());

    $page = $this->actions->create($team->owner, $team->id, statusPageInput([
        'components' => [
            ['name' => 'One', 'monitor_ids' => [$own->id]],
        ],
    ]));

    $component = app(TenantContext::class)->run($team->id, fn (): StatusPageComponent => StatusPageComponent::query()->sole());
    $other = app(TenantContext::class)->run($team->id, function () use ($page): StatusPageComponent {
        $created = new StatusPageComponent();
        $created->forceFill(['status_page_id' => $page->id, 'name' => 'Two', 'position' => 1])->save();

        return $created;
    });

    $insert = fn (int $componentId, int $monitorId) => DB::transaction(fn () => DB::table('status_page_component_monitors')->insert([
        'status_page_component_id' => $componentId,
        'status_page_id' => $page->id,
        'team_id' => $team->id,
        'monitor_id' => $monitorId,
    ]));

    expect(fn () => $insert($component->id, $foreign->id))->toThrow(QueryException::class, 'status_page_component_monitors_monitor_fk')
        ->and(fn () => $insert($other->id, $own->id))->toThrow(QueryException::class, 'status_page_component_monitors_once_per_page');
});

it('refuses invalid components', function (array $components, string $message): void {
    $team = Team::factory()->create();
    $monitor = statusPageMonitor($team);

    $resolved = array_map(static fn (array $component): array => [
        'name' => $component['name'],
        'monitor_ids' => array_map(static fn (string $id): int => $monitor->id, $component['monitor_ids']),
    ], $components);

    expect(fn () => $this->actions->create($team->owner, $team->id, statusPageInput(['components' => $resolved])))
        ->toThrow(StatusPageRejected::class, $message);
})->with([
    'blank name' => [[['name' => '  ', 'monitor_ids' => ['own']]], 'The name of component 1'],
    'long name' => [[['name' => str_repeat('x', 101), 'monitor_ids' => ['own']]], 'The name of component 1'],
    'no monitors' => [[['name' => 'API', 'monitor_ids' => []]], 'Component 1 must group'],
    'twice on the page' => [[['name' => 'One', 'monitor_ids' => ['own']], ['name' => 'Two', 'monitor_ids' => ['own']]], 'only one component'],
    'same name ignoring case' => [[['name' => 'API', 'monitor_ids' => ['own']], ['name' => 'api', 'monitor_ids' => ['own']]], 'cannot share the name'],
]);

it('refuses more components than a page allows', function (): void {
    $team = Team::factory()->create();
    $components = array_fill(0, ManageStatusPages::COMPONENTS_PER_PAGE + 1, ['name' => 'x', 'monitor_ids' => [1]]);

    expect(fn () => $this->actions->create($team->owner, $team->id, statusPageInput(['components' => $components])))
        ->toThrow(StatusPageRejected::class, 'at most 50 components');
});

it('removes a deleted monitor from its component', function (): void {
    $team = Team::factory()->create();
    $monitor = statusPageMonitor($team);

    $page = $this->actions->create($team->owner, $team->id, statusPageInput([
        'components' => [['name' => 'API', 'monitor_ids' => [$monitor->id]]],
    ]));

    app(TenantContext::class)->run($team->id, fn () => $monitor->delete());

    expect(statusPageComponents($team, $page->id))->toBe([['API', []]]);
});

it('keeps addresses unique across teams and valid in format', function (): void {
    $alpha = Team::factory()->create();
    $beta = Team::factory()->create();
    $page = $this->actions->create($alpha->owner, $alpha->id, statusPageInput());

    expect(fn () => $this->actions->create($beta->owner, $beta->id, statusPageInput()))
        ->toThrow(StatusPageRejected::class, 'already taken');

    // Saving a page under its own address is not a collision.
    $this->actions->update($alpha->owner, $alpha->id, $page->id, statusPageInput(['title' => 'Renamed']));

    expect(reloadStatusPage($alpha, $page->id)->title)->toBe('Renamed');

    foreach (['ab', 'Upper-case', '-leading', 'trailing-', 'with space', 'with/slash', str_repeat('a', 64), "line\nbreak"] as $slug) {
        expect(fn () => $this->actions->update($alpha->owner, $alpha->id, $page->id, statusPageInput(['slug' => $slug])))
            ->toThrow(StatusPageRejected::class, 'An address must be');
    }
});

it('reports an address taken by a concurrent team instead of failing', function (): void {
    $team = Team::factory()->create();
    $other = Team::factory()->create();

    // Runs after the check and before the insert, the only window the unique index has to settle.
    StatusPage::creating(static function () use ($other): void {
        DB::table('status_pages')->insert([
            'team_id' => $other->id,
            'slug' => 'acme-status',
            'title' => 'Concurrent',
            'theme_json' => '{"mode":"system","accent":"emerald"}',
        ]);
    });

    expect(fn () => $this->actions->create($team->owner, $team->id, statusPageInput()))
        ->toThrow(StatusPageRejected::class, 'already taken');
});

it('stores only a hash of the password and applies its changes', function (): void {
    $team = Team::factory()->create();
    $page = $this->actions->create($team->owner, $team->id, statusPageInput(['password' => 'first-password']));
    $hash = reloadStatusPage($team, $page->id)->password_hash;

    expect($hash)->not->toContain('first-password')
        ->and(Hash::check('first-password', (string) $hash))->toBeTrue();

    $this->actions->update($team->owner, $team->id, $page->id, statusPageInput());
    expect(reloadStatusPage($team, $page->id)->password_hash)->toBe($hash);

    $this->actions->update($team->owner, $team->id, $page->id, statusPageInput(['password' => 'second-password']));
    expect(Hash::check('second-password', (string) reloadStatusPage($team, $page->id)->password_hash))->toBeTrue();

    $this->actions->update($team->owner, $team->id, $page->id, statusPageInput(['removePassword' => true]));
    expect(reloadStatusPage($team, $page->id)->password_hash)->toBeNull();
});

it('refuses unusable passwords', function (array $fields, string $message): void {
    $team = Team::factory()->create();

    expect(fn () => $this->actions->create($team->owner, $team->id, statusPageInput($fields)))
        ->toThrow(StatusPageRejected::class, $message);
})->with([
    'too short' => [['password' => 'short'], 'at least 8'],
    'bcrypt would truncate it' => [['password' => str_repeat('è', 37)], 'at most 72 bytes'],
    'contradictory' => [['password' => 'long-enough', 'removePassword' => true], 'either a new password'],
]);

it('counts drafts against the plan limit and frees a slot on deletion', function (): void {
    $team = Team::factory()->trialEnded()->create();
    $page = $this->actions->create($team->owner, $team->id, statusPageInput());

    expect(fn () => $this->actions->create($team->owner, $team->id, statusPageInput(['slug' => 'second-page'])))
        ->toThrow(StatusPageRejected::class, 'Your plan allows 1 status pages');

    $this->actions->delete($team->owner, $team->id, $page->id);
    $this->actions->create($team->owner, $team->id, statusPageInput(['slug' => 'second-page']));

    expect(app(TenantContext::class)->run($team->id, fn (): int => StatusPage::query()->count()))->toBe(1);
});

it('serializes competing creations on the Team row before counting the plan limit', function (): void {
    $fixture = CommittedTeamFixture::freeTeam(0);
    $owner = $fixture->owner;

    try {
        DB::beginTransaction();

        try {
            // This session holds the Team row lock, as a competing creation would.
            DB::table('teams')->where('id', $fixture->teamId)->lockForUpdate()->first();
            $fixture->connection->statement("SET lock_timeout = '100ms'");

            try {
                $fixture->committed(fn () => $this->actions->create($owner, $fixture->teamId, statusPageInput()));
                $this->fail('The competing creation must wait for the Team lock.');
            } catch (QueryException $exception) {
                expect($exception->errorInfo[0])->toBe('55P03');
            }
        } finally {
            // Rolling back to the savepoint releases the row lock before cleanup.
            DB::rollBack();
        }

        $fixture->connection->statement('SET lock_timeout = 0');
        $fixture->committed(fn () => $this->actions->create($owner, $fixture->teamId, statusPageInput()));

        // The recount under the lock sees the page committed by the competitor.
        expect(fn () => $fixture->committed(fn () => $this->actions->create($owner, $fixture->teamId, statusPageInput(['slug' => 'second-page']))))
            ->toThrow(StatusPageRejected::class, 'Your plan allows 1')
            ->and($fixture->connection->table('status_pages')->where('team_id', $fixture->teamId)->count())->toBe(1);
    } finally {
        $fixture->cleanup();
    }
});

it('authorizes changes again inside the action', function (): void {
    $team = Team::factory()->create();
    $member = Membership::factory()->for($team)->member()->create()->user;
    $page = $this->actions->create($team->owner, $team->id, statusPageInput());

    expect(fn () => $this->actions->create($member, $team->id, statusPageInput(['slug' => 'other-page'])))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->actions->update($member, $team->id, $page->id, statusPageInput(['title' => 'Changed'])))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->actions->delete($member, $team->id, $page->id))->toThrow(AuthorizationException::class);
    expect(reloadStatusPage($team, $page->id)->title)->toBe('Acme status');
});

it('refuses unknown theme values and shapes before the action', function (array $change, string $key): void {
    $team = Team::factory()->create();

    $this->actingAs($team->owner)->from("/teams/{$team->id}/status-pages")
        ->post("/teams/{$team->id}/status-pages", [
            'slug' => 'acme-status',
            'title' => 'Acme',
            'theme_mode' => 'system',
            'theme_accent' => 'emerald',
            'is_public' => false,
            'components' => [],
            ...$change,
        ])
        ->assertSessionHasErrors($key);

    expect(app(TenantContext::class)->run($team->id, fn (): int => StatusPage::query()->count()))->toBe(0);
})->with([
    'css in the accent' => [['theme_accent' => 'red;background:url(x)'], 'theme_accent'],
    'unknown mode' => [['theme_mode' => 'neon'], 'theme_mode'],
    'address format' => [['slug' => 'Not Valid'], 'slug'],
    'missing components' => [['components' => null], 'components'],
    'component without monitors' => [['components' => [['name' => 'API', 'monitor_ids' => []]]], 'components.0.monitor_ids'],
    'short password' => [['password' => 'short'], 'password'],
]);

it('turns a domain refusal into an error on the page key', function (): void {
    $team = Team::factory()->create();
    $foreign = statusPageMonitor(Team::factory()->create());

    $this->actingAs($team->owner)->from("/teams/{$team->id}/status-pages")
        ->post("/teams/{$team->id}/status-pages", [
            'slug' => 'acme-status',
            'title' => 'Acme',
            'theme_mode' => 'system',
            'theme_accent' => 'emerald',
            'is_public' => true,
            'components' => [['name' => 'API', 'monitor_ids' => [$foreign->id]]],
        ])
        ->assertRedirect("/teams/{$team->id}/status-pages")
        ->assertSessionHasErrors('page');
});

it('never sends the password hash to the settings page and lists only local monitors', function (): void {
    $team = Team::factory()->create();
    $monitor = statusPageMonitor($team, 'local-monitor');
    statusPageMonitor(Team::factory()->create(), 'foreign-monitor');
    $page = $this->actions->create($team->owner, $team->id, statusPageInput([
        'password' => 'first-password',
        'components' => [['name' => 'API', 'monitor_ids' => [$monitor->id]]],
    ]));
    $hash = (string) reloadStatusPage($team, $page->id)->password_hash;

    $this->actingAs($team->owner)->get("/teams/{$team->id}/status-pages")
        ->assertOk()
        ->assertDontSee($hash, false)
        ->assertDontSee('foreign-monitor', false)
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('StatusPages/Index')
            ->where('pages.0.hasPassword', true)
            ->where('pages.0.path', '/status/acme-status')
            ->where('pages.0.components.0.monitors.0.name', 'local-monitor')
            ->where('monitors', [['id' => $monitor->id, 'name' => 'local-monitor']])
            ->where('limits.used', 1)
            ->where('limits.pages', 3)
            ->where('themeChoices.modes', ['system', 'light', 'dark']));
});
