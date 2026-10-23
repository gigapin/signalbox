<?php

declare(strict_types=1);

/**
 * Drive every monitor type through the production pages and routes exactly as the React form submits it:
 * the payloads come from the contract fixture that tests/frontend/MonitorPayloadContract.test.ts pins to
 * buildPayload(). Also covers wrong input, per-type permissions and search isolation on isolated PostgreSQL.
 */

use App\Http\Presenters\Monitoring\MonitorPresenter;
use App\Models\Membership;
use App\Models\Monitor;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }
});

/** @var list<string> LIFECYCLE_SECRET_FIELDS HTTP-family fields that never return to the browser. */
const LIFECYCLE_SECRET_FIELDS = ['url', 'headers', 'body'];

/** @var list<string> LIFECYCLE_COMMON_FIELDS Fields projected outside `configuration`. */
const LIFECYCLE_COMMON_FIELDS = ['name', 'type', 'interval_seconds', 'timeout_ms', 'confirmation_threshold', 'regions'];

/** @return array{defaults: array<string, mixed>, cases: array<string, array{form: array<string, mixed>, create: array<string, mixed>, edit?: array<string, mixed>}>} Shared form contract. */
function formContract(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/monitor-form-payloads.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, array{array{form: array<string, mixed>, create: array<string, mixed>, edit?: array<string, mixed>}}> One dataset row per monitor type. */
function contractCases(): array
{
    return array_map(static fn (array $case): array => [$case], formContract()['cases']);
}

/**
 * Submit like Inertia's router: a JSON body with HTML Accept, so validation failures redirect back with
 * session errors instead of returning 422 JSON.
 *
 * @param string $method HTTP verb.
 * @param string $uri Production route path.
 * @param array<string, mixed> $payload Form payload.
 * @return TestResponse<Symfony\Component\HttpFoundation\Response> Redirect response.
 */
function inertiaSubmit(string $method, string $uri, array $payload = []): TestResponse
{
    return test()->json($method, $uri, $payload, [
        'Accept' => 'text/html, application/xhtml+xml',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia' => 'true',
    ]);
}

/**
 * @param array<string, mixed> $create Contract creation payload.
 * @return array<string, mixed> Non-secret type settings as the safe projection must show them; form strings
 *                              holding integers become integers, everything else is already canonical.
 */
function projectedSettings(array $create): array
{
    $settings = array_diff_key($create, array_flip([...LIFECYCLE_COMMON_FIELDS, ...LIFECYCLE_SECRET_FIELDS]));

    return array_map(
        static fn (mixed $value): mixed => is_string($value) && ctype_digit($value) ? (int) $value : $value,
        $settings,
    );
}

/**
 * @param Team $team Owning team.
 * @return int Persisted monitors of the team, read directly so no scope can hide one.
 */
function persistedMonitors(Team $team): int
{
    return DB::table('monitors')->where('team_id', $team->id)->count();
}

/**
 * @param TestResponse<Symfony\Component\HttpFoundation\Response> $response Redirect carrying session errors.
 * @param string $field Expected field, or its prefix for list items such as `regions.0`.
 */
function expectFieldError(TestResponse $response, string $field): void
{
    $response->assertSessionHasErrors();

    $errors = session('errors');
    $keys = $errors instanceof ViewErrorBag ? array_keys($errors->getBag('default')->toArray()) : [];
    $matching = array_filter($keys, static fn (string $key): bool => $key === $field || str_starts_with($key, $field.'.'));

    expect($matching)->not->toBeEmpty("Expected an error on {$field}, got: ".implode(', ', $keys));
}

/**
 * @param Team $team Owning team, established as tenant context for the read.
 * @param int $id Monitor identity.
 * @return Monitor Fresh persisted monitor with decrypted configuration.
 */
function tenantMonitor(Team $team, int $id): Monitor
{
    return app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::query()->findOrFail($id));
}

it('keeps the contract defaults equal to the server form defaults', function (): void {
    $team = Team::factory()->free()->trialEnded()->create();

    expect(MonitorPresenter::defaults(300))->toBe(formContract()['defaults']);

    $this->actingAs($team->owner)
        ->get("/teams/{$team->id}/monitors/create")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Monitors/Create')
            ->where('defaults', formContract()['defaults']));
});

it('runs the complete lifecycle of each type through the production pages and routes', function (array $case): void {
    $team = Team::factory()->create();
    $create = $case['create'];
    $type = $create['type'];
    $base = "/teams/{$team->id}/monitors";

    $this->actingAs($team->owner)->from("{$base}/create");
    $created = inertiaSubmit('POST', $base, $create)->assertSessionHasNoErrors();

    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::query()->sole());
    $created->assertRedirect("{$base}/{$monitor->id}")->assertSessionHas('status', 'monitor-created');

    if (array_key_exists('url', $create)) {
        // Secrets persist byte for byte; header names are stored case-normalized.
        expect($monitor->url)->toBe($create['url'])
            ->and($monitor->headers_json)->toBe(array_change_key_case($create['headers']))
            ->and($monitor->body)->toBe($create['body']);
    }

    foreach (['show' => 'Monitors/Show', 'edit' => 'Monitors/Edit'] as $page => $component) {
        $path = $page === 'show' ? "{$base}/{$monitor->id}" : "{$base}/{$monitor->id}/edit";

        $response = $this->get($path)->assertOk()->assertDontSee('contract-secret', false);
        $response->assertInertia(function (AssertableInertia $inertia) use ($component, $create, $type): void {
            $detail = $inertia->component($component)->toArray()['props']['monitor'];

            expect($detail['name'])->toBe($create['name'])
                ->and($detail['type'])->toBe($type)
                ->and($detail['intervalSeconds'])->toBe((int) $create['interval_seconds'])
                ->and($detail['timeoutMs'])->toBe((int) $create['timeout_ms'])
                ->and($detail['confirmationThreshold'])->toBe((int) $create['confirmation_threshold'])
                ->and($detail['regions'])->toBe($create['regions'])
                ->and(array_intersect_key($detail['configuration'], projectedSettings($create)))->toEqual(projectedSettings($create));
        });
    }

    $this->get("{$base}?".http_build_query(['q' => 'contract', 'type' => $type]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('monitors.total', 1)->where('monitors.data.0.id', $monitor->id));

    $this->from("{$base}/{$monitor->id}/edit");
    inertiaSubmit('PATCH', "{$base}/{$monitor->id}", [...($case['edit'] ?? $create), 'name' => 'Renamed contract'])
        ->assertSessionHasNoErrors()
        ->assertRedirect("{$base}/{$monitor->id}/edit")
        ->assertSessionHas('status', 'monitor-updated');

    $updated = tenantMonitor($team, $monitor->id);

    expect($updated->name)->toBe('Renamed contract')
        ->and($updated->url)->toBe($monitor->url)
        ->and($updated->headers_json)->toBe($monitor->headers_json)
        ->and($updated->body)->toBe($monitor->body);

    $this->from("{$base}/{$monitor->id}");
    inertiaSubmit('POST', "{$base}/{$monitor->id}/pause")->assertSessionHas('status', 'monitor-paused');
    expect(tenantMonitor($team, $monitor->id)->is_paused)->toBeTrue();

    inertiaSubmit('POST', "{$base}/{$monitor->id}/resume")->assertSessionHas('status', 'monitor-resumed');
    expect(tenantMonitor($team, $monitor->id)->is_paused)->toBeFalse();

    inertiaSubmit('DELETE', "{$base}/{$monitor->id}")
        ->assertRedirect($base)
        ->assertSessionHas('status', 'monitor-deleted');

    $this->get($base)->assertInertia(fn (AssertableInertia $page) => $page->where('monitors.total', 0)->where('filtered', false));
    $this->get("{$base}/{$monitor->id}")->assertNotFound();
})->with(contractCases());

it('refuses wrong creation input through the production route without persistence or echoed secrets', function (string $type, array $changes, string $field): void {
    $team = Team::factory()->create();
    $form = "/teams/{$team->id}/monitors/create";
    $payload = array_filter(
        [...formContract()['cases'][$type]['create'], ...$changes],
        static fn (mixed $value): bool => $value !== '__omit__',
    );

    $this->actingAs($team->owner)->from($form);
    $response = inertiaSubmit('POST', "/teams/{$team->id}/monitors", $payload)->assertRedirect($form);

    expectFieldError($response, $field);

    expect(persistedMonitors($team))->toBe(0)
        ->and(session()->getOldInput())->toBe([])
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('contract-secret');

})->with([
    'http without URL' => ['http', ['url' => '__omit__'], 'url'],
    'http with unsupported method' => ['http', ['method' => 'TRACE'], 'method'],
    'http to loopback' => ['http', ['url' => 'http://127.0.0.1/admin?token=contract-secret'], 'url'],
    'http to private network' => ['http', ['url' => 'http://10.0.0.8/'], 'url'],
    'http with oversized body' => ['http', ['body' => str_repeat('x', 65537)], 'body'],
    'http with invalid status' => ['http', ['expected_status' => '99'], 'expected_status'],
    'http with TLS field' => ['http', ['tls_host' => 'contract.example.invalid'], 'tls_host'],
    'keyword without keyword' => ['keyword', ['expected_keyword' => '__omit__'], 'expected_keyword'],
    'keyword with unknown mode' => ['keyword', ['keyword_mode' => 'contains'], 'keyword_mode'],
    'tls with port out of range' => ['tls', ['tls_port' => '70000'], 'tls_port'],
    'tls to local name' => ['tls', ['tls_host' => 'localhost'], 'tls_host'],
    'tls with negative warning' => ['tls', ['tls_expiry_warning_days' => '-1'], 'tls_expiry_warning_days'],
    'dns with unsupported record type' => ['dns', ['dns_record_type' => 'SRV'], 'dns_record_type'],
    'dns with private resolver' => ['dns', ['dns_resolver' => '10.0.0.53'], 'dns_resolver'],
    'dns without expected values' => ['dns', ['dns_expected_values' => []], 'dns_expected_values'],
    'heartbeat with negative grace' => ['heartbeat', ['heartbeat_grace_seconds' => '-1'], 'heartbeat_grace_seconds'],
    'heartbeat with HTTP field' => ['heartbeat', ['url' => 'https://contract.example.invalid/'], 'url'],
    'timeout above the bound' => ['tls', ['timeout_ms' => '10001'], 'timeout_ms'],
    'interval below the minimum' => ['dns', ['interval_seconds' => '29'], 'interval_seconds'],
    'threshold above the bound' => ['heartbeat', ['confirmation_threshold' => '11'], 'confirmation_threshold'],
    'unknown region' => ['keyword', ['regions' => ['ap']], 'regions'],
    'empty name' => ['http', ['name' => ''], 'name'],
    'unknown type' => ['http', ['type' => 'ping'], 'type'],
]);

it('refuses wrong edits through the production route and keeps the stored configuration', function (string $type, array $patch, string $field): void {
    $team = Team::factory()->create();
    $base = "/teams/{$team->id}/monitors";

    $this->actingAs($team->owner)->from("{$base}/create");
    inertiaSubmit('POST', $base, formContract()['cases'][$type]['create'])->assertSessionHasNoErrors();

    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::query()->sole());
    $before = DB::table('monitors')->where('id', $monitor->id)->first();

    $this->from("{$base}/{$monitor->id}/edit");
    $response = inertiaSubmit('PATCH', "{$base}/{$monitor->id}", $patch)->assertRedirect("{$base}/{$monitor->id}/edit");

    expectFieldError($response, $field);

    expect(DB::table('monitors')->where('id', $monitor->id)->first())->toEqual($before)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('contract-secret');
})->with([
    'type change without the new configuration' => ['http', ['type' => 'tls'], 'tls_host'],
    'secret-free edit with private URL' => ['keyword', ['url' => 'http://192.168.1.1/'], 'url'],
    'field of another type' => ['tls', ['dns_name' => 'contract.example.invalid'], 'dns_name'],
    'timeout below the bound' => ['heartbeat', ['timeout_ms' => '99'], 'timeout_ms'],
    'empty patch' => ['dns', [], 'monitor'],
]);

it('keeps viewers read-only for every type', function (array $case): void {
    $team = Team::factory()->create();
    $viewer = Membership::factory()->for($team)->viewer()->create()->user;
    $base = "/teams/{$team->id}/monitors";

    $this->actingAs($team->owner)->from("{$base}/create");
    inertiaSubmit('POST', $base, $case['create'])->assertSessionHasNoErrors();

    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::query()->sole());
    $before = DB::table('monitors')->where('team_id', $team->id)->get()->all();

    $this->actingAs($viewer);

    $this->get("{$base}/{$monitor->id}")->assertOk();
    $this->get("{$base}/create")->assertForbidden();
    $this->get("{$base}/{$monitor->id}/edit")->assertForbidden();
    inertiaSubmit('POST', $base, $case['create'])->assertForbidden();
    inertiaSubmit('PATCH', "{$base}/{$monitor->id}", [...($case['edit'] ?? $case['create']), 'name' => 'Viewer rename'])->assertForbidden();
    inertiaSubmit('POST', "{$base}/{$monitor->id}/pause")->assertForbidden();
    inertiaSubmit('DELETE', "{$base}/{$monitor->id}")->assertForbidden();

    expect(DB::table('monitors')->where('team_id', $team->id)->get()->all())->toEqual($before);
})->with(contractCases());

it('never lists another team’s monitors that share a searchable TLS host or DNS name', function (): void {
    $team = Team::factory()->create();
    $foreign = Team::factory()->create();
    $cases = formContract()['cases'];

    foreach ([$team, $foreign] as $owner) {
        foreach (['tls', 'dns'] as $type) {
            $this->actingAs($owner->owner)->from("/teams/{$owner->id}/monitors/create");
            inertiaSubmit('POST', "/teams/{$owner->id}/monitors", [...$cases[$type]['create'], 'name' => "{$type} of {$owner->id}"])
                ->assertSessionHasNoErrors();
        }
    }

    $this->actingAs($team->owner)
        ->get("/teams/{$team->id}/monitors?q=contract.example.invalid")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('monitors.total', 2)
            ->where('monitors.data', fn ($rows): bool => collect($rows)->pluck('name')->sort()->values()->all() === ["dns of {$team->id}", "tls of {$team->id}"]));

    $foreignId = app(TenantContext::class)->run($foreign->id, fn (): int => Monitor::query()->where('type', 'tls')->sole()->id);

    $this->get("/teams/{$team->id}/monitors/{$foreignId}")->assertNotFound();
    // A team the actor does not belong to is indistinguishable from a missing one.
    $this->get("/teams/{$foreign->id}/monitors")->assertNotFound();
});
