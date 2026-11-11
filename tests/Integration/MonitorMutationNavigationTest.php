<?php

declare(strict_types=1);

/** Verify mutation redirects, strict body validation and tenant-safe navigation on PostgreSQL. */

use App\Models\Monitor;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Tests\Support\MonitorInputFixture;

pest()->group('docker');
beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated php-test PostgreSQL.');
    }

    $this->freezeTime();
});

it('normalizes query-only navigation independently of each authorized mutation', function (string $operation, string $hint): void {
    $team = Team::factory()->create();
    $base = '/teams/'.$team->id.'/monitors';
    $foreignBase = '/teams/'.($team->id + 100).'/monitors';
    $return = match ($hint) {
        'valid' => $base.'?q=api&type=http&page=2&unknown=discarded',
        'external' => 'https://example.test'.$base,
        'foreign' => $foreignBase.'?q=private',
        'detail' => $base.'/123',
        'array' => [$base],
        'duplicate' => $base.'?q=api',
        'oversized' => $base.'?q='.str_repeat('a', 2100),
        default => null,
    };
    $query = $return === null ? '' : '?'.http_build_query(['returnTo' => $return]);
    if ($hint === 'duplicate') {
        $query .= '&returnTo='.rawurlencode($base.'?q=second');
    }
    $list = $hint === 'valid' ? $base.'?q=api&type=http&page=2' : $base;
    $context = $list === $base ? '' : '?'.http_build_query(['returnTo' => $list], '', '&', PHP_QUERY_RFC3986);
    $this->actingAs($team->owner)->from($base.'/create');

    if ($operation === 'create') {
        $response = $this->post($base.$query, MonitorInputFixture::http())->assertSessionHasNoErrors();
        $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::query()->sole());
        $response->assertRedirect($base.'/'.$monitor->id.$context)->assertSessionHas('status', 'monitor-created');

        return;
    }
    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->http()->create());
    if ($operation === 'update') {
        $this->patch($base.'/'.$monitor->id.$query, ['name' => 'Updated synthetic monitor'])
            ->assertSessionHasNoErrors()->assertRedirect($base.'/'.$monitor->id.$context)
            ->assertSessionHas('status', 'monitor-updated');

        expect(app(TenantContext::class)->run($team->id, fn (): string => $monitor->refresh()->name))->toBe('Updated synthetic monitor');

        return;
    }
    $this->delete($base.'/'.$monitor->id.$query)->assertRedirect($list)->assertSessionHas('status', 'monitor-deleted');

    expect(app(TenantContext::class)->run($team->id, fn (): int => Monitor::query()->count()))->toBe(0);
})->with(['create', 'update', 'delete'])->with(['valid', 'external', 'foreign', 'detail', 'array', 'duplicate', 'oversized', 'absent']);

it('rejects body navigation fields even when the query navigation hint is valid', function (bool $json): void {
    $team = Team::factory()->create();
    $base = '/teams/'.$team->id.'/monitors';
    $url = $base.'?'.http_build_query(['returnTo' => $base.'?q=api']);
    $payload = [...MonitorInputFixture::http(), 'returnTo' => '/private'];
    $this->actingAs($team->owner)->from($base.'/create');

    if ($json) {
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('returnTo');
    } else {
        $this->post($url, $payload)->assertRedirect($base.'/create')->assertSessionHasErrors('returnTo')->assertSessionHas('_old_input', []);
    }

    expect(app(TenantContext::class)->run($team->id, fn (): int => Monitor::query()->count()))->toBe(0);
})->with([false, true]);

it('keeps refusal on the editor without flashing private input or accepting an unsafe return', function (): void {
    $team = Team::factory()->create();
    $base = '/teams/'.$team->id.'/monitors';
    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::factory()->http()->create());
    $editor = $base.'/'.$monitor->id.'/edit';
    $this->actingAs($team->owner)->from($editor)
        ->patch($base.'/'.$monitor->id.'?returnTo=https%3A%2F%2Fevil.test', ['name' => '', 'headers' => ['Authorization' => 'private-secret']])
        ->assertRedirect($editor)->assertSessionHasErrors('name')->assertSessionHas('_old_input', []);
});


it('accepts JSON monitor input with query-only navigation without admitting it to the domain patch', function (): void {
    $team = Team::factory()->create();
    $base = '/teams/'.$team->id.'/monitors';
    $query = '?'.http_build_query(['returnTo' => $base.'?q=api&page=2']);
    $response = $this->actingAs($team->owner)->postJson($base.$query, MonitorInputFixture::http())->assertSessionHasNoErrors();
    $monitor = app(TenantContext::class)->run($team->id, fn (): Monitor => Monitor::query()->sole());
    $response->assertRedirect($base.'/'.$monitor->id.$query)->assertSessionHas('status', 'monitor-created');
});
