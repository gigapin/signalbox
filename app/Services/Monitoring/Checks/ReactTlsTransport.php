<?php

declare(strict_types=1);

/** Establish one pinned, verified TLS connection without sending application data. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\TlsTransport;
use App\Data\Security\AuthorizedDestination;
use App\Enums\Monitoring\CheckError;
use App\Exceptions\CheckTransportFailed;
use Carbon\CarbonImmutable;
use LogicException;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\Timer\timeout;

use React\Promise\Timer\TimeoutException;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\SecureConnector;
use RuntimeException;
use Throwable;

/** The original peer name is retained for SNI and verification while the socket uses a literal IP. */
final class ReactTlsTransport implements TlsTransport
{
    /**
     * @param string|null $caFile Optional infrastructure-owned trust bundle; null uses PHP's system trust store.
     * @throws LogicException When an explicitly injected trust bundle is unavailable.
     */
    public function __construct(private readonly ?string $caFile = null)
    {
        if ($caFile !== null && (!is_file($caFile) || !is_readable($caFile))) {
            throw new LogicException('The TLS trust bundle is unavailable.');
        }
    }

    /**
     * @param AuthorizedDestination $destination Authorized original hostname and fresh address pins.
     * @param float $timeoutMs Remaining cumulative TCP/TLS budget in milliseconds.
     * @param LoopInterface $loop Batch-owned event loop.
     * @return PromiseInterface<CarbonImmutable> Verified certificate expiry in UTC.
     */
    public function inspect(AuthorizedDestination $destination, float $timeoutMs, LoopInterface $loop): PromiseInterface
    {
        if ($timeoutMs <= 0) {
            return reject(new CheckTransportFailed(CheckError::Timeout));
        }

        $address = $destination->addresses[0] ?? throw new LogicException('TLS requires an authorized address pin.');
        $endpoint = (str_contains($address, ':') ? '['.$address.']' : $address).':'.$destination->port;
        $secure = new SecureConnector(new PinnedTcpConnector($loop), $loop, [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $destination->host,
            'SNI_enabled' => !$destination->isIpLiteral(),
            'capture_peer_cert' => true,
            ...($this->caFile === null ? [] : ['cafile' => $this->caFile]),
        ]);

        return timeout($secure->connect('tls://'.$endpoint.'?hostname='.rawurlencode($destination->host)), $timeoutMs / 1000, $loop)
            ->then(static function (ConnectionInterface $connection): CarbonImmutable {
                try {
                    if (!$connection instanceof Connection || !is_resource($connection->stream)) {
                        throw new LogicException('TLS adapter did not expose its certificate stream.');
                    }

                    $options = stream_context_get_options($connection->stream);
                    $certificate = $options['ssl']['peer_certificate'] ?? null;

                    if (!$certificate instanceof \OpenSSLCertificate) {
                        throw new CheckTransportFailed(CheckError::Tls);
                    }

                    $parsed = openssl_x509_parse($certificate);
                    $expires = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

                    if (!is_int($expires)) {
                        throw new CheckTransportFailed(CheckError::Tls);
                    }

                    return CarbonImmutable::createFromTimestampUTC($expires);
                } finally {
                    $connection->close();
                }
            }, static function (Throwable $error): never {
                if ($error instanceof TimeoutException) {
                    throw new CheckTransportFailed(CheckError::Timeout);
                }

                if ($error instanceof RuntimeException) {
                    $previous = $error->getPrevious();
                    throw new CheckTransportFailed($previous instanceof CheckTransportFailed ? $previous->reason : CheckError::Tls);
                }

                throw $error;
            });
    }
}
