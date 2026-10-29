<?php

declare(strict_types=1);

/** Submit scalar delivery envelopes to the existing simulated regional Redis queues. */

namespace App\Services\Monitoring\Scheduling;

use App\Contracts\Monitoring\CheckWorkPublisher;
use App\Jobs\DeliverCheckExecution;
use Illuminate\Contracts\Queue\Factory;
use InvalidArgumentException;

/** Explicit Redis selection prevents a synchronous default connection from executing inline. */
final readonly class RedisCheckWorkPublisher implements CheckWorkPublisher
{
    /** @param Factory $queues Laravel queue connection resolver. */
    public function __construct(private Factory $queues)
    {
    }

    /**
     * @param int $teamId Committed tenant identity.
     * @param int $executionId Committed execution identity.
     * @param string $region Supported queue suffix.
     * @throws InvalidArgumentException When the requested queue is not a simulated region.
     */
    public function publish(int $teamId, int $executionId, string $region): void
    {
        if (!in_array($region, ['eu', 'us'], true)) {
            throw new InvalidArgumentException('Unsupported execution region.');
        }

        $this->queues->connection('redis')->push(new DeliverCheckExecution($teamId, $executionId), '', 'checks:'.$region);
    }
    /**
     * @param int $teamId Committed tenant boundary.
     * @param array<int, int> $executions Bounded execution-to-monitor map.
     * @param string $region Supported simulated region.
     */
    public function publishBatch(int $teamId, array $executions, string $region): void
    {
        if (!in_array($region, ['eu', 'us'], true) || $executions === []
            || count($executions) > app(\App\Services\Monitoring\Checks\CheckRuntimeLimits::class)->batchSize()
            || count(array_unique($executions)) !== count($executions)) {
            throw new InvalidArgumentException('Invalid regional batch.');
        }
        $this->queues->connection('redis')->push(new \App\Jobs\DeliverCheckBatch($teamId, $executions, $region), '', 'checks:'.$region);
    }
}
