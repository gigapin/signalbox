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
}
