<?php

declare(strict_types=1);

/** Verify the shared public-name filter independently of DNS and address authorization. */

use App\Services\Security\PublicHostnamePolicy;

it('rejects single labels and every reserved suffix on label boundaries', function (string $name): void {
    $policy = new PublicHostnamePolicy();

    expect($policy->allows($name))->toBeFalse()
        ->and(fn () => $policy->assertAllowed($name))->toThrow(App\Exceptions\OutboundDestinationRejected::class);
})->with(['postgres', 'redis', 'localhost', 'local', 'internal', 'test', 'invalid', 'example', 'onion',
    'db.localhost', 'db.local', 'db.internal', 'db.test', 'db.invalid', 'db.example', 'db.onion', 'deep.db.internal']);

it('accepts public names including underscore questions and suffix lookalikes', function (string $name): void {
    expect((new PublicHostnamePolicy())->allows($name))->toBeTrue();
})->with(['example.com', '_dmarc.example.com', '_https._tcp.example.com', 'xn--bcher-kva.example.com', 'notinternal.com', 'internal.example.com']);
