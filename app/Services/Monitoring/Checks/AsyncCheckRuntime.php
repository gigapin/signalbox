<?php

declare(strict_types=1);

/** Bridge synchronous application entrypoints to a bounded, private event loop. */

namespace App\Services\Monitoring\Checks;

use App\Data\Monitoring\Checks\AsyncCheckCompletion;
use Closure;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use Throwable;

/** No global loop or outstanding work may escape a checker invocation. */
final class AsyncCheckRuntime
{
    /**
     * @template TResult of array<array-key, mixed>|object
     * @param Closure(LoopInterface): PromiseInterface<TResult> $start Cancellable operation started on the supplied loop.
     * @return TResult Completed operation result.
     * @throws Throwable When setup, transport or programming failures reject the operation.
     */
    public function await(Closure $start): mixed
    {
        $loop = new StreamSelectLoop();
        $pending = $start($loop);
        $completion = new AsyncCheckCompletion($pending);

        try {
            // Drain cancellation callbacks and the DNS TCP adapter's short idle-close timer as well as active work.
            $loop->run();

            return $completion->result();
        } finally {
            $pending->cancel();
            $loop->stop();
        }
    }
}
