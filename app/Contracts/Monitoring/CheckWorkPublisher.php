<?php

declare(strict_types=1);

/** Isolate broker delivery from the PostgreSQL execution outbox. */

namespace App\Contracts\Monitoring;

/** Publication may succeed before throwing; consumers must tolerate duplicate envelopes. */
interface CheckWorkPublisher
{
    /**
     * @param int $teamId Trusted tenant identity from the committed outbox.
     * @param int $executionId Persisted regional slot identity, without model serialization.
     * @param string $region Validated simulated region, eu or us.
     * @throws \Throwable When broker submission cannot be confirmed.
     */
    public function publish(int $teamId, int $executionId, string $region): void;
    /**
     * @param int $teamId Committed tenant boundary.
     * @param array<int, int> $executions Execution IDs mapped to distinct monitor IDs.
     * @param string $region Validated simulated regional queue.
     */
    public function publishBatch(int $teamId, array $executions, string $region): void;
}
