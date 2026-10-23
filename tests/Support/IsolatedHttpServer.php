<?php

declare(strict_types=1);

/** Run the image's real HTTP server against the isolated test services and drive it like a browser. */

namespace Tests\Support;

use App\Http\Middleware\HandleInertiaRequests;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The server process only sees committed rows, so callers seed through a separate connection and clean up
 * explicitly. It reuses the Dockerfile CMD so the tested start command is the shipped one. Diagnostics expose
 * only the server's console output, never response bodies or cookies.
 */
final class IsolatedHttpServer
{
    /**
     * @param Process $process Running `artisan serve` process.
     * @param string $address Loopback host and port, e.g. 127.0.0.1:40123.
     */
    private function __construct(
        private readonly Process $process,
        public readonly string $address,
    ) {
    }

    /**
     * @param int $workers PHP built-in server workers; more than one lets requests run in parallel.
     * @return self Server that has printed its ready line.
     * @throws RuntimeException When the server does not become ready within ten seconds.
     */
    public static function start(int $workers = 1): self
    {
        $dockerfile = (string) file_get_contents(base_path('docker/php/Dockerfile'));

        preg_match('/^CMD (.+)$/m', $dockerfile, $matches);

        $command = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $address = self::freeLoopbackAddress();

        $command[] = '--host=127.0.0.1';
        $command[] = '--port='.substr($address, strrpos($address, ':') + 1);

        $process = new Process($command, base_path(), self::environment($address, $workers));
        $process->setTimeout(60);
        $process->start();

        $ready = $process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'Server running on'));

        if (!$ready) {
            throw new RuntimeException('Isolated HTTP server did not start: '.$process->getOutput().$process->getErrorOutput());
        }

        return new self($process, $address);
    }

    /**
     * @param CookieJar $cookies Browser cookie state for this client.
     * @param HandlerStack|null $handler Custom transport, e.g. a curl multi handler ticked while polling.
     * @return Client Client that never throws on HTTP errors and never follows redirects.
     */
    public function client(CookieJar $cookies, ?HandlerStack $handler = null): Client
    {
        $options = [
            'base_uri' => 'http://'.$this->address,
            'cookies' => $cookies,
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 10,
        ];

        if ($handler !== null) {
            $options['handler'] = $handler;
        }

        return new Client($options);
    }

    /** @return string Asset version the server expects in X-Inertia-Version on GET requests. */
    public static function inertiaVersion(): string
    {
        return app(HandleInertiaRequests::class)->version(Request::create('/login')) ?? '';
    }

    /**
     * @param CookieJar $cookies Session whose XSRF cookie authorizes the request.
     * @param string|null $referer Page the browser is on; `back()` redirects there.
     * @return array<string, string> Headers an Inertia browser visit sends.
     */
    public function inertiaHeaders(CookieJar $cookies, ?string $referer = null): array
    {
        $headers = [
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia' => 'true',
            'X-Inertia-Version' => self::inertiaVersion(),
        ];

        $token = $cookies->getCookieByName('XSRF-TOKEN')?->getValue();

        if ($token !== null) {
            $headers['X-XSRF-TOKEN'] = rawurldecode($token);
        }

        if ($referer !== null) {
            $headers['Referer'] = 'http://'.$this->address.$referer;
        }

        return $headers;
    }

    /**
     * Sign in through the real login form; the returned jar can be cloned to model several tabs of one session.
     *
     * @param string $email Synthetic verified account.
     * @param string $password Plain fixture password, never a real credential.
     * @return CookieJar Authenticated session cookies.
     * @throws RuntimeException When the login is refused.
     */
    public function login(string $email, string $password): CookieJar
    {
        $cookies = new CookieJar();
        $client = $this->client($cookies);

        $client->get('/login', ['headers' => $this->inertiaHeaders($cookies)]);

        $response = $client->post('/login', [
            'headers' => ['Accept' => 'application/json', 'X-XSRF-TOKEN' => rawurldecode((string) $cookies->getCookieByName('XSRF-TOKEN')?->getValue())],
            'json' => ['email' => $email, 'password' => $password],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Fixture login failed with HTTP '.$response->getStatusCode().'.');
        }

        return $cookies;
    }

    /**
     * @param ResponseInterface $response Inertia JSON page response.
     * @return array<string, mixed> Decoded page object (component, props, url, version).
     */
    public static function page(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return string Console output for failure diagnostics; response bodies and cookies are never included. */
    public function diagnostics(): string
    {
        return $this->process->getOutput().$this->process->getErrorOutput();
    }

    /** Stop the server and its workers, waiting at most two seconds. */
    public function stop(): void
    {
        $this->process->stop(2);
    }

    /** @return string Loopback host and an unused port, released immediately for the server to bind. */
    private static function freeLoopbackAddress(): string
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');

        if ($listener === false) {
            throw new RuntimeException('No free loopback port.');
        }

        $address = (string) stream_socket_get_name($listener, false);
        fclose($listener);

        return $address;
    }

    /**
     * Only isolated test services are reachable: the guarded test database, array cache and mail, sync queue.
     *
     * @param string $address Address the server binds, used as APP_URL.
     * @param int $workers Built-in server worker count.
     * @return array<string, string> Environment overriding the container defaults.
     */
    private static function environment(string $address, int $workers): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            // The test process shares the key so it can read rows the server encrypted.
            'APP_KEY' => (string) config('app.key'),
            'APP_URL' => 'http://'.$address,
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => TestDatabaseGuard::DATABASE,
            'DB_USERNAME' => TestDatabaseGuard::USERNAME,
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'DB_URL' => '',
            'SESSION_DRIVER' => 'cookie',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            // Only synthetic test requests reach this process; stderr makes HTTP failures diagnosable in CI.
            'LOG_CHANNEL' => 'stderr',
            'PHP_CLI_SERVER_WORKERS' => (string) $workers,
        ];
    }
}
