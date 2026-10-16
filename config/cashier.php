<?php

/** Prepare Stripe test credentials without exposing billing routes or attaching billing to users. */

use Laravel\Cashier\Console\WebhookCommand;
use Laravel\Cashier\Invoices\DompdfInvoiceRenderer;

return [
    // The service provider rejects any non-empty key without the required test-mode prefix.
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'path' => 'stripe',
    // No webhook is registered in M0; signed, idempotent handling belongs to M9.
    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => 300,
        'events' => WebhookCommand::DEFAULT_EVENTS,
    ],
    'currency' => env('CASHIER_CURRENCY', 'eur'),
    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en'),
    'payment_notification' => false,
    // Preserve the package renderer binding; remote invoice assets stay disabled.
    'invoices' => ['renderer' => DompdfInvoiceRenderer::class, 'options' => ['paper' => 'A4', 'remote_enabled' => false]],
    'logger' => null,
];
