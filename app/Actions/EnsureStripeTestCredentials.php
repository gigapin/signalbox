<?php

/** Validate the project's test-only Stripe boundary without contacting Stripe or logging credentials. */

namespace App\Actions;

use LogicException;

/** Reject credentials outside the supported test-mode prefixes before the application can construct a Stripe client. */
final class EnsureStripeTestCredentials
{
    /**
     * Allow an unconfigured checkout or test-mode keys, including a partially configured environment.
     *
     * @param string $publishableKey Browser key; an empty string disables browser Stripe integration.
     * @param string $secretKey Server key; an empty string leaves Stripe API calls unconfigured.
     * @throws LogicException When either supplied credential is outside Stripe test mode.
     */
    public function __invoke(string $publishableKey, string $secretKey): void
    {
        if ($publishableKey !== '' && ! str_starts_with($publishableKey, 'pk_test_')) {
            throw new LogicException('STRIPE_KEY must be a Stripe test publishable key.');
        }

        if ($secretKey !== '' && ! str_starts_with($secretKey, 'sk_test_')) {
            throw new LogicException('STRIPE_SECRET must be a Stripe test secret key.');
        }
    }
}
