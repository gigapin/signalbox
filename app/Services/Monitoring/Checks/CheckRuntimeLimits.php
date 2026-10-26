<?php

declare(strict_types=1);

/** Validate the complete runner timing envelope against checker budgets and Redis visibility. */

namespace App\Services\Monitoring\Checks;

use LogicException;

/** A small batch bounds sequential DNS authorization and redirect rounds without reducing monitor timeout options. */
final class CheckRuntimeLimits
{
    /**
     * @return int Validated regional batch capacity, never exceeding the implemented two-observation envelope.
     * @throws LogicException When configuration cannot fit inside the worker, fence or Redis deadlines.
     */
    public function batchSize(): int
    {
        $batch = config('monitor-scheduling.batch_size');
        $timeout = config('monitor-input.timeout_max_ms');
        $dns = config('monitor-checks.resolution_timeout_ms');
        $redirects = config('outbound-security.max_redirects');
        $visibility = config('queue.connections.redis.retry_after');

        if (!is_int($batch) || $batch < 1 || $batch > 2 || !is_int($timeout) || $timeout < 1
            || !is_int($dns) || $dns < 1 || !is_int($redirects) || $redirects < 0
            || !is_int($visibility) || $visibility <= 55
            || $batch * ($timeout + ($redirects + 1) * $dns) > 37000) {
            throw new LogicException('Checker budgets do not fit the regional worker timing envelope.');
        }

        return $batch;
    }
}
