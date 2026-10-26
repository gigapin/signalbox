<?php

declare(strict_types=1);

/** Preserve TCP-stage failure identity before the TLS adapter wraps vendor exceptions. */

namespace App\Services\Monitoring\Checks;

use App\Enums\Monitoring\CheckError;
use App\Exceptions\CheckTransportFailed;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\TcpConnector;
use RuntimeException;
use Throwable;

/** React's IP-only TCP connector prevents an accidental second DNS lookup after authorization. */
final readonly class PinnedTcpConnector implements ConnectorInterface
{
    /** @var TcpConnector IP-only asynchronous connector owned by the observation loop. */
    private TcpConnector $connector;

    /** @param LoopInterface $loop Batch-owned event loop. */
    public function __construct(LoopInterface $loop)
    {
        $this->connector = new TcpConnector($loop);
    }

    /**
     * @param string $uri Authorized literal IP endpoint, optionally carrying the original TLS hostname.
     * @return PromiseInterface<ConnectionInterface> Connected socket or a safe TCP-specific failure.
     * @throws Throwable When the connector encounters a programming error instead of a network failure.
     */
    public function connect($uri): PromiseInterface
    {
        return $this->connector->connect($uri)->catch(static function (Throwable $error): never {
            if ($error instanceof RuntimeException) {
                throw new CheckTransportFailed(CheckError::Connection);
            }

            throw $error;
        });
    }
}
