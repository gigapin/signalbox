<?php

/** Verify the actual image server command preserves container database overrides for HTTP requests. */

use App\Http\Middleware\HandleInertiaRequests;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

it('preserves PostgreSQL container coordinates in the development HTTP server', function (): void {
    $dockerfile = file_get_contents(base_path('docker/php/Dockerfile'));

    preg_match('/^CMD (.+)$/m', $dockerfile, $matches);

    $command = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

    $listener = stream_socket_server('tcp://127.0.0.1:0');

    $address = stream_socket_get_name($listener, false);
    fclose($listener);

    $port = substr($address, strrpos($address, ':') + 1);
    $command[] = '--host=127.0.0.1';
    $command[] = '--port='.$port;

    $server = new Process($command, base_path(), [
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'APP_URL' => 'http://'.$address,
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) config('database.connections.pgsql.host'),
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'signalbox_test',
        'DB_USERNAME' => 'signalbox_test',
        'DB_PASSWORD' => 'signalbox_test',
        'DB_URL' => '',
        'SESSION_DRIVER' => 'cookie',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        // Only synthetic test requests reach this process; stderr makes HTTP failures diagnosable in CI.
        'LOG_CHANNEL' => 'stderr',
        'PHP_CLI_SERVER_WORKERS' => '1',
    ]);
    $server->setTimeout(10);

    try {
        $server->start();
        expect($server->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'Server running on')))->toBeTrue();

        $cookies = new CookieJar();

        $client = new Client([
            'base_uri' => 'http://'.$address,
            'cookies' => $cookies,
            'http_errors' => false,
            'timeout' => 5
        ]);

        $version = app(HandleInertiaRequests::class)->version(Request::create('/login')) ?? '';

        $page = $client->get('/login', [
            'headers' => ['Accept' => 'application/json', 'X-Inertia' => 'true', 'X-Inertia-Version' => $version]
        ]);
        expect($page->getStatusCode())->toBe(200)
            ->and($page->getHeaderLine('X-Inertia'))->toBe('true')
            ->and(json_decode((string) $page->getBody(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('component', 'Auth/Login');

        $csrf = rawurldecode($cookies->getCookieByName('XSRF-TOKEN')->getValue());

        $response = $client->post('/login', [
            'headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $csrf],
            'json' => ['email' => 'container-web-probe@example.invalid', 'password' => 'not-a-valid-password']
        ]);
        expect($response->getStatusCode())->toBe(422)
            ->and(json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('errors.email');
    } catch (Throwable $exception) {
        // Keep response bodies and cookies private; report only the isolated server's console diagnostics.
        throw new RuntimeException('Isolated HTTP probe failed. Server output: '.$server->getOutput().$server->getErrorOutput(), previous: $exception);
    } finally {
        $server->stop(2);
    }
})->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires the isolated php-test PostgreSQL service.');
