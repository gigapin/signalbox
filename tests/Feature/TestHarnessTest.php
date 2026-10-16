<?php

/** Verify that ordinary feature tests cannot make accidental outbound HTTP calls. */

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

it(
    'rejects an unfaked HTTP client request before network access',
    /** Exercise the shared bootstrap barrier without contacting the reserved invalid hostname. */
    function (): void {
        expect(
            /** Attempt a request that the shared test application must refuse immediately. */
            fn () => Http::get('https://unexpected.signalbox.invalid'),
        )->toThrow(StrayRequestException::class);
    },
);
