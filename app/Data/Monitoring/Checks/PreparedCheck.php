<?php

declare(strict_types=1);

/** Keep a private admitted observation snapshot exclusively inside the running worker. */

namespace App\Data\Monitoring\Checks;

use App\Models\CheckExecution;

/** No model or decrypted input from this object may be serialized into a queue payload. */
final readonly class PreparedCheck
{
    /**
     * @param CheckExecution $execution Acquired immutable fence and identity.
     * @param CheckInput $input Typed private configuration captured under admission locks.
     * @param int $intervalSeconds Captured cadence for heartbeat evaluation.
     * @param HeartbeatSnapshot|null $heartbeat Slot-consistent heartbeat state, absent for network checks.
     */
    public function __construct(
        public CheckExecution $execution,
        public CheckInput $input,
        public int $intervalSeconds,
        public ?HeartbeatSnapshot $heartbeat,
    ) {
    }
}
