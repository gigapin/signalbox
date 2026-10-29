<?php

/** Verify the actual image server command preserves container database overrides for HTTP requests. */

use GuzzleHttp\Cookie\CookieJar;
use Tests\Support\IsolatedHttpServer;

it('preserves PostgreSQL container coordinates in the development HTTP server', function (): void {
    $server = IsolatedHttpServer::start();

    try {
        $cookies = new CookieJar();
        $client = $server->client($cookies);

        $page = $client->get('/login', ['headers' => $server->inertiaHeaders($cookies)]);
        expect($page->getStatusCode())->toBe(200)
            ->and($page->getHeaderLine('X-Inertia'))->toBe('true')
            ->and(IsolatedHttpServer::page($page))->toHaveKey('component', 'Auth/Login');

        $csrf = rawurldecode($cookies->getCookieByName('XSRF-TOKEN')->getValue());

        $response = $client->post('/login', [
            'headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $csrf],
            'json' => ['email' => 'container-web-probe@example.invalid', 'password' => 'not-a-valid-password']
        ]);
        expect($response->getStatusCode())->toBe(422)
            ->and(json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('errors.email');
    } catch (Throwable $exception) {
        // Keep response bodies and cookies private; report only the isolated server's console diagnostics.
        throw new RuntimeException('Isolated HTTP probe failed. Server output: '.$server->diagnostics(), previous: $exception);
    } finally {
        $server->stop();
    }
})->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires the isolated php-test PostgreSQL service.');
