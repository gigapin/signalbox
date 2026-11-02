<?php

declare(strict_types=1);

/** Exercise canonical configuration and text normalization without database or network access. */

use App\Models\StatusPage;
use App\Services\StatusPages\StatusPageDocuments;

it('normalizes document text and retains a configured deployment prefix and port', function (): void {
    config(['app.name' => "Signalbox\nCloud", 'app.url' => 'https://app.example.com:8443/prefix/']);
    $page = (new StatusPage())->forceFill(['title' => " API\nstatus ", 'description' => " State\t& history ", 'slug' => 'api-status']);
    $metadata = app(StatusPageDocuments::class)->page($page);

    expect($metadata->title)->toBe('API status | Signalbox Cloud')
        ->and($metadata->description)->toBe('State & history')
        ->and($metadata->canonicalUrl)->toBe('https://app.example.com:8443/prefix/status/api-status');
});

it('refuses invalid canonical configuration without falling back to a request host', function (string $url): void {
    config(['app.url' => $url]);
    $page = (new StatusPage())->forceFill(['title' => 'API', 'slug' => 'api-status']);

    expect(fn () => app(StatusPageDocuments::class)->page($page))->toThrow(LogicException::class);
})->with([
    'relative' => '/app',
    'non-HTTP' => 'ftp://app.example.com',
    'credentials' => 'https://user:pass@app.example.com',
    'query' => 'https://app.example.com?x=1',
    'fragment' => 'https://app.example.com#status',
    'markup' => 'https://app.example.com/"injected',
    'newline' => "https://app.example.com/\nvalue",
    'backslash' => 'https://app.example.com/\\value',
    'invalid port' => 'https://app.example.com:99999',
]);
