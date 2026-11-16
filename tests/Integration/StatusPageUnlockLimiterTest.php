<?php

declare(strict_types=1);

/** Verify shared password admission, Redis-time boundaries and stale completion fencing without sleeps. */

use App\Exceptions\StatusPageUnlockRateLimited;
use App\Exceptions\StatusPageUnlockUnavailable;
use App\Models\StatusPage;
use App\Models\Team;
use App\Services\StatusPages\StatusPageUnlockLimiter;
use App\Services\StatusPages\StatusPageUnlocks;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;

pest()->group('docker');

beforeEach(function (): void {
    if (getenv('RUN_DOCKER_TESTS') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL and Redis.');
    }

    $this->withoutVite();
    Cache::flush();
    $this->team = Team::factory()->create();
    $this->page = app(TenantContext::class)->run($this->team->id, fn (): StatusPage => StatusPage::factory()->published()->create([
        'slug' => 'guarded-status', 'password_hash' => Hash::make('correct-password'),
    ]));
    $this->limiter = app(StatusPageUnlockLimiter::class);
    $this->redis = app(Factory::class)->connection('cache');
    $this->unlockKey = unlockGuardKey($this->page);
});

afterEach(function (): void {
    if (isset($this->redis, $this->unlockKey)) {
        $this->redis->command('del', [$this->unlockKey]);
    }
});

/** @return string Test-only address of the shared state; no plaintext, IP or hostname is included. */
function unlockGuardKey(StatusPage $page): string
{
    return 'status-page-unlock:'.$page->id.':'.hash('sha256', (string) $page->password_hash);
}

/** @return int Redis server clock in milliseconds; application time travel cannot expire shared state. */
function unlockGuardNow(Connection $redis): int
{
    $time = $redis->command('time');

    return (int) $time[0] * 1000 + intdiv((int) $time[1], 1000);
}

/** Expire only a test cooldown, preserving failure history and avoiding wall-clock sleeps. */
function expireUnlockCooldown(Connection $redis, string $key): void
{
    $redis->command('hset', [$key, 'blocked_until_ms', unlockGuardNow($redis) - 1]);
}

it('blocks distributed visitors after five failures without hashing or granting a session', function (): void {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
            ->post('/status/guarded-status/unlock', ['password' => 'wrong-password'])
            ->assertRedirect()->assertSessionHasErrors('password');
    }

    Hash::shouldReceive('check')->never();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
        ->postJson('/status/guarded-status/unlock', ['password' => 'correct-password'])
        ->assertTooManyRequests()->assertHeader('Retry-After', '1')
        ->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('correct-password');
    expect(session()->get('status_page_unlocks.'.$this->page->id))->toBeNull();
    $this->get('/status/guarded-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Unlock'));
});

it('increases waiting time caps it and never extends it on denied requests', function (): void {
    foreach ([0, 0, 0, 0, 1, 2, 4, 8, 16, 32, 60, 60] as $index => $delay) {
        if ($index >= 5) {
            expireUnlockCooldown($this->redis, $this->unlockKey);
        }
        $token = $this->limiter->reserve($this->page);
        $this->limiter->finish($this->page, $token, false);
        expect((int) $this->redis->command('hget', [$this->unlockKey, 'failures']))->toBe(min($index + 1, 11));
        $remaining = (int) $this->redis->command('hget', [$this->unlockKey, 'blocked_until_ms']) - unlockGuardNow($this->redis);
        expect($remaining)->toBeLessThanOrEqual($delay * 1000)->toBeGreaterThan($delay * 1000 - 500);
        if ($delay === 0) {
            continue;
        }

        $state = $this->redis->command('hgetall', [$this->unlockKey]);
        $this->redis->command('expire', [$this->unlockKey, 100]);
        try {
            $this->limiter->reserve($this->page);
            $this->fail('An active cooldown must refuse admission.');
        } catch (StatusPageUnlockRateLimited $error) {
            expect($error->retryAfter)->toBe($delay);
        }
        expect($this->redis->command('hgetall', [$this->unlockKey]))->toBe($state)
            ->and((int) $this->redis->command('ttl', [$this->unlockKey]))->toBeLessThanOrEqual(100);
    }
});

it('admits only one overlapping verifier even through independently resolved instances', function (): void {
    $token = $this->limiter->reserve($this->page);
    $other = new StatusPageUnlockLimiter(app(Factory::class));
    expect(fn () => $other->reserve($this->page))->toThrow(StatusPageUnlockRateLimited::class);
    $this->limiter->finish($this->page, $token, false);
    $other->finish($this->page, $other->reserve($this->page), false);
    expect((int) $this->redis->command('hget', [$this->unlockKey, 'failures']))->toBe(2);
});

it('rejects stale success and failure completions without changing a replacement lease', function (bool $matched): void {
    $old = $this->limiter->reserve($this->page);
    $this->redis->command('hset', [$this->unlockKey, 'lease_until_ms', unlockGuardNow($this->redis) - 1]);
    $current = $this->limiter->reserve($this->page);
    $state = $this->redis->command('hgetall', [$this->unlockKey]);
    expect(fn () => $this->limiter->finish($this->page, $old, $matched))->toThrow(StatusPageUnlockRateLimited::class)
        ->and($this->redis->command('hgetall', [$this->unlockKey]))->toBe($state);
    $this->limiter->finish($this->page, $current, false);
    expect((int) $this->redis->command('hget', [$this->unlockKey, 'failures']))->toBe(2);
})->with([true, false]);

it('rejects an expired successful completion before another verifier arrives', function (): void {
    $token = $this->limiter->reserve($this->page);
    $this->redis->command('hset', [$this->unlockKey, 'lease_until_ms', unlockGuardNow($this->redis) - 1]);
    expect(fn () => $this->limiter->finish($this->page, $token, true))->toThrow(StatusPageUnlockRateLimited::class);
    expect($this->redis->command('hget', [$this->unlockKey, 'owner']))->toBe($token);
});

it('counts abandoned work toward cooldown instead of allowing repeated crash bypasses', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->limiter->reserve($this->page);
        $this->redis->command('hset', [$this->unlockKey, 'lease_until_ms', unlockGuardNow($this->redis) - 1]);
    }
    expect(fn () => $this->limiter->reserve($this->page))->toThrow(StatusPageUnlockRateLimited::class)
        ->and((int) $this->redis->command('hget', [$this->unlockKey, 'failures']))->toBe(5);
});

it('resets failures after accepted success and gives state a bounded idle lifetime', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->limiter->finish($this->page, $this->limiter->reserve($this->page), false);
    }
    expect((int) $this->redis->command('ttl', [$this->unlockKey]))->toBeGreaterThan(3590)->toBeLessThanOrEqual(3600);
    expireUnlockCooldown($this->redis, $this->unlockKey);
    $this->post('/status/guarded-status/unlock', ['password' => 'correct-password'])->assertRedirect();
    expect((int) $this->redis->command('exists', [$this->unlockKey]))->toBe(0);
    $this->get('/status/guarded-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Public'));
});

it('keeps counters across renamed addresses but separates page identities and password versions', function (): void {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->limiter->finish($this->page, $this->limiter->reserve($this->page), false);
    }
    $renamed = clone $this->page;
    $renamed->slug = 'renamed-status';
    $renamed->custom_domain = 'status.example.test';
    expect(fn () => $this->limiter->reserve($renamed))->toThrow(StatusPageUnlockRateLimited::class);
    foreach (['id', 'password_hash'] as $attribute) {
        $separate = clone $this->page;
        $separate->$attribute = $attribute === 'id' ? $this->page->id + 1 : Hash::make('replacement-password');
        $this->limiter->finish($separate, $this->limiter->reserve($separate), true);
    }
    expect((int) $this->redis->command('hget', [$this->unlockKey, 'failures']))->toBe(5);
});

it('does not grant a session when shared completion becomes unavailable', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('command')->once()->andReturn([1, 0]);
    $connection->shouldReceive('command')->once()->andThrow(new RuntimeException('private backend diagnostics'));
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->with('cache')->twice()->andReturn($connection);
    $unlocks = new StatusPageUnlocks(app(\App\Services\Security\PurposeKeys::class), new StatusPageUnlockLimiter($factory));
    expect(fn () => $unlocks->attempt(app('session.store'), $this->page, 'correct-password'))->toThrow(StatusPageUnlockUnavailable::class)
        ->and($unlocks->allows(app('session.store'), $this->page))->toBeFalse();
});

it('fails closed with a generic retryable response if Redis admission is unavailable', function (): void {
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->with('cache')->andThrow(new RuntimeException('private backend diagnostics'));
    app()->instance(Factory::class, $factory);
    Hash::shouldReceive('check')->never();
    $this->postJson('/status/guarded-status/unlock', ['password' => 'correct-password'])
        ->assertServiceUnavailable()->assertHeader('Retry-After', '1')
        ->assertDontSee('private backend diagnostics')->assertDontSee('correct-password');
    expect(session()->get('status_page_unlocks.'.$this->page->id))->toBeNull();
});

it('preserves legacy password access without weakening the new password minimum', function (): void {
    $this->page->password_hash = Hash::make('old-pass');
    app(TenantContext::class)->run($this->team->id, fn (): bool => $this->page->save());
    $this->post('/status/guarded-status/unlock', ['password' => 'old-pass'])->assertRedirect()->assertSessionHasNoErrors();
    $this->get('/status/guarded-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Public'));
});

it('never grants access when a correct check finishes after its lease', function (): void {
    Hash::shouldReceive('check')->once()->andReturnUsing(function (): bool {
        $this->redis->command('hset', [$this->unlockKey, 'lease_until_ms', unlockGuardNow($this->redis) - 1]);

        return true;
    });
    $this->postJson('/status/guarded-status/unlock', ['password' => 'correct-password'])->assertTooManyRequests();
    expect(session()->get('status_page_unlocks.'.$this->page->id))->toBeNull();
});

it('refuses malformed shared state without performing password work', function (): void {
    $this->redis->command('hset', [$this->unlockKey, 'failures', 'not-an-integer']);
    Hash::shouldReceive('check')->never();
    $this->postJson('/status/guarded-status/unlock', ['password' => 'correct-password'])->assertServiceUnavailable();
    expect(session()->get('status_page_unlocks.'.$this->page->id))->toBeNull();
});

it('keeps existing unlocked reads available during a global cooldown', function (): void {
    $this->post('/status/guarded-status/unlock', ['password' => 'correct-password'])->assertRedirect();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->limiter->finish($this->page, $this->limiter->reserve($this->page), false);
    }
    $this->get('/status/guarded-status')->assertInertia(fn (AssertableInertia $page) => $page->component('StatusPages/Public'));
});
