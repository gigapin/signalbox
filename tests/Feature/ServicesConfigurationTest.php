<?php

/** Verify package boundaries without external services, persisted accounts or network requests. */

use App\Actions\EnsureStripeTestCredentials;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\DatabaseEngine;

it(
    'denies anonymous Horizon dashboard and API access in every environment',
    /** @param string $environment Runtime name that must never bypass operator authorization. */
    function (string $environment): void {
        $this->app->instance('env', $environment);
        $this->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
    },
)->with(['local', 'staging', 'production']);

it(
    'only grants the Horizon gate to verified explicitly listed operators',
    /** Exercise the allowlist, empty-list default and email verification requirement without a database. */
    function (): void {
        /** @var User $operator Synthetic authenticated identity; no persisted account or tenant role is implied. */
        $operator = new User(['email' => 'operator@signalbox.example']);
        $operator->forceFill(['id' => 1, 'email_verified_at' => now()]);
        config(['horizon.allowed_emails' => []]);
        expect(Gate::forUser($operator)->allows('viewHorizon'))->toBeFalse();
        config(['horizon.allowed_emails' => ['operator@signalbox.example']]);
        expect(Gate::forUser($operator)->allows('viewHorizon'))->toBeTrue();
        $this->actingAs($operator)->get('/horizon')->assertOk()->assertSee('Horizon');
        $operator->email_verified_at = null;
        expect(Gate::forUser($operator)->allows('viewHorizon'))->toBeFalse();
        $this->actingAs($operator)->get('/horizon')->assertForbidden();
    },
);

it(
    'keeps check workers regionally isolated with a single attempt and safe timeout',
    /** Validate the effective Horizon provisioning plan rather than only the raw configuration shape. */
    function (): void {
        /** @var ProvisioningPlan $plan Effective supervisor settings after Horizon merges defaults. */
        $plan = ProvisioningPlan::get('test-master');
        /** @var string $supervisor Independent worker group name. */
        /** @var string $queue Assigned queue name. */
        foreach (['default' => 'default', 'checks-eu' => 'checks:eu', 'checks-us' => 'checks:us'] as $supervisor => $queue) {
            /** @var \Laravel\Horizon\SupervisorOptions $options Effective settings for one worker group. */
            $options = $plan->optionsFor('*', $supervisor);
            expect($options->queue)->toBe($queue)
                ->and($options->maxProcesses)->toBe(1)
                ->and($options->maxTries)->toBe(1)
                ->and($options->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
        }
    },
);

it(
    'selects PostgreSQL-backed Scout search without an external search client',
    /** Resolve the real search adapter; database queries are checked separately in the opt-in suite. */
    function (): void {
        expect(app(EngineManager::class)->engine())->toBeInstanceOf(DatabaseEngine::class);
    },
);

it(
    'keeps unfinished billing and broadcast authorization endpoints unregistered',
    /** Prevent package discovery from exposing unscoped billing or unsigned webhook handling. */
    function (): void {
        expect(Route::has('cashier.webhook'))->toBeFalse()
            ->and(Route::has('cashier.payment'))->toBeFalse();
        $this->postJson('/stripe/webhook', ['type' => 'customer.subscription.deleted'])->assertNotFound();
        $this->get('/stripe/payment/example')->assertNotFound();
        $this->postJson('/broadcasting/auth')->assertNotFound();
    },
);

it(
    'accepts blank or test-only Stripe keys without contacting Stripe',
    /**
     * @param string $publishableKey Public test fixture or empty configuration.
     * @param string $secretKey Fake test credential.
     */
    function (string $publishableKey, string $secretKey): void {
        (new EnsureStripeTestCredentials())($publishableKey, $secretKey);
        expect(true)->toBeTrue();
    },
)->with([
    ['', ''],
    ['pk_test_public_fixture', 'sk_test_private_fixture'],
    ['', 'sk_test_private_fixture'],
]);

it(
    'rejects live or unknown Stripe key prefixes without including their values in errors',
    /**
     * @param string $publishableKey Invalid or test public key fixture.
     * @param string $secretKey Invalid or test secret fixture.
     */
    function (string $publishableKey, string $secretKey): void {
        try {
            (new EnsureStripeTestCredentials())($publishableKey, $secretKey);
            $this->fail('Invalid Stripe credentials were accepted.');
        } catch (LogicException $exception) {
            /** @var LogicException $exception Expected mode rejection containing only a configuration key name. */
            expect($exception->getMessage())->not->toContain('fixture');
        }
    },
)->with([
    ['pk_live_public_fixture', 'sk_test_private_fixture'],
    ['pk_test_public_fixture', 'sk_live_private_fixture'],
    ['invalid_fixture', ''],
    ['', 'invalid_fixture'],
]);
