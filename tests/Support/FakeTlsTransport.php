<?php

declare(strict_types=1);

/** Replace verified TLS acquisition with deterministic expiry metadata or safe transport failures. */

namespace Tests\Support;

use App\Contracts\Monitoring\TlsTransport;
use App\Data\Security\AuthorizedDestination;
use Carbon\CarbonImmutable;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

use Throwable;

/** Captured pins and remaining budgets verify authorization and deadline propagation without sockets. */
final class FakeTlsTransport implements TlsTransport
{
    /** @var list<AuthorizedDestination> Authorized destinations observed so far. */
    public array $destinations = [];
    /** @var list<float> Remaining TCP/TLS budgets in milliseconds. */
    public array $budgets = [];
    /** @var Throwable|null Optional deterministic failure. */
    public ?Throwable $failure = null;

    /**
     * @param AuthorizedDestination $destination Authorized endpoint and fresh pins.
     * @param float $timeoutMs Remaining budget.
     * @param LoopInterface $loop Owning batch loop, unused for immediate fake completion.
     * @return PromiseInterface<CarbonImmutable> Synthetic verified expiry or configured failure.
     */
    public function inspect(AuthorizedDestination $destination, float $timeoutMs, LoopInterface $loop): PromiseInterface
    {
        $this->destinations[] = $destination;
        $this->budgets[] = $timeoutMs;

        return $this->failure === null ? resolve(CarbonImmutable::now('UTC')->addDays(90)) : reject($this->failure);
    }
}
