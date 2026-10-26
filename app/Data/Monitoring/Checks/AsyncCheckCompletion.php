<?php

declare(strict_types=1);

/** Capture one typed asynchronous completion without unsafe local type assertions. */

namespace App\Data\Monitoring\Checks;

use LogicException;
use React\Promise\PromiseInterface;
use Throwable;

/** @template TResult of array<array-key, mixed>|object Completed checker collection or observation. */
final class AsyncCheckCompletion
{
    /** @var list<TResult> Empty while pending; a fulfilled promise supplies exactly one result. */
    private array $values = [];
    /** @var Throwable|null Rejection retained only until the synchronous caller observes it. */
    private ?Throwable $failure = null;

    /** @param PromiseInterface<TResult> $pending Cancellable operation whose typed completion is captured. */
    public function __construct(PromiseInterface $pending)
    {
        $pending->then($this->complete(...), $this->fail(...));
    }

    /**
     * @param TResult $value Fulfilled observation or collection.
     * @return void Store the completed value without changing its generic type.
     */
    private function complete(array|object $value): void
    {
        $this->values[] = $value;
    }

    /** @param Throwable $error Operation rejection to propagate after resource cleanup. */
    private function fail(Throwable $error): void
    {
        $this->failure = $error;
    }

    /**
     * @return TResult Typed completed result.
     * @throws Throwable When the operation rejected or lost its completion signal.
     */
    public function result(): array|object
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->values[0] ?? throw new LogicException('A checker operation lost its completion signal.');
    }
}
