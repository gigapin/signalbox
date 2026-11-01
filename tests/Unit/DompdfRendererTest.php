<?php

declare(strict_types=1);

/** Verify that the PDF adapter renders with the network and embedded code switched off. */

use App\Services\Reports\DompdfRenderer;

it('keeps every capability that could turn markup into a request or into code off', function (): void {
    $options = DompdfRenderer::options();

    expect($options->getIsRemoteEnabled())->toBeFalse()
        ->and($options->getIsPhpEnabled())->toBeFalse()
        ->and($options->getIsJavascriptEnabled())->toBeFalse()
        ->and(array_keys($options->getAllowedProtocols()))->toBe(['file://']);
});

it('produces a PDF document, also with non-ASCII text', function (): void {
    $pdf = (new DompdfRenderer())->render('<!DOCTYPE html><html><body><p>Città Ελληνικά</p></body></html>');

    expect(str_starts_with($pdf, '%PDF-'))->toBeTrue()
        ->and(strlen($pdf))->toBeGreaterThan(500);
});

it('still renders when markup refers to a remote image, without fetching it', function (): void {
    // An unroutable address: a fetch attempt would stall the test long before its end.
    $started = microtime(true);
    $pdf = (new DompdfRenderer())->render('<!DOCTYPE html><html><body><img src="http://10.255.255.1/x.png"></body></html>');

    expect(str_starts_with($pdf, '%PDF-'))->toBeTrue()
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});
