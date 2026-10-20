<?php

/** Verify the actual image server command preserves container database overrides for HTTP requests. */

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\Process\Process;

it('preserves PostgreSQL container coordinates in the development HTTP server', /** Start the configured server against guarded test services without mutating development data. */ function (): void {
    /** @var string $dockerfile Source of the actual container default command under regression. */
    $dockerfile = file_get_contents(base_path('docker/php/Dockerfile'));
    /** @var array<int, string> $matches Captured JSON argument vector of the image command. */
    preg_match('/^CMD (.+)$/m', $dockerfile, $matches);
    /** @var list<string> $command Image arguments extended only with an ephemeral test bind address. */
    $command = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    /** @var resource $listener Temporary port reservation used to choose an unused loopback port. */
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    /** @var string $address Reserved loopback address and ephemeral port. */
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    /** @var string $port Numeric port selected by the operating system. */
    $port = substr($address, strrpos($address, ':') + 1);
    $command[] = '--host=127.0.0.1';
    $command[] = '--port='.$port;
    /** @var Process $server Real HTTP child whose database must remain the guarded test database. */
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
        'LOG_CHANNEL' => 'null',
        'PHP_CLI_SERVER_WORKERS' => '1',
    ]);
    $server->setTimeout(10);
    try {
        $server->start();
        $server->waitUntil(/**
         * @param string $type Process output channel.
         * @param string $output Incremental server readiness message.
         * @return bool Whether HTTP requests can start.
         */ static fn (string $type, string $output): bool => str_contains($output, 'Server running on'));
        /** @var CookieJar $cookies CSRF/session cookies kept only in memory, never written or printed. */
        $cookies = new CookieJar();
        /** @var Client $client Loopback HTTP transport targeting only the dedicated test server. */
        $client = new Client(['base_uri' => 'http://'.$address, 'cookies' => $cookies, 'http_errors' => false, 'timeout' => 5]);
        expect($client->get('/login')->getStatusCode())->toBe(200);
        /** @var string $csrf Encrypted cookie value required by the real HTTP CSRF middleware. */
        $csrf = rawurldecode($cookies->getCookieByName('XSRF-TOKEN')->getValue());
        /** @var \Psr\Http\Message\ResponseInterface $response Nonexistent-account lookup exercises PostgreSQL without creating any account. */
        $response = $client->post('/login', ['headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => $csrf], 'json' => ['email' => 'container-web-probe@example.invalid', 'password' => 'not-a-valid-password']]);
        expect($response->getStatusCode())->toBe(422)
            ->and(json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('errors.email');
    } finally {
        $server->stop(2);
    }
})->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires the isolated php-test PostgreSQL service.');
