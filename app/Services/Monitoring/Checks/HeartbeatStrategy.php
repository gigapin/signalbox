<?php

declare(strict_types=1);

/** Evaluate rolling heartbeat deadlines without network I/O, persistence or incident side effects. */

namespace App\Services\Monitoring\Checks;

use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\Checks\HeartbeatSnapshot;
use App\Data\Monitoring\HeartbeatMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorStatus;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** The scheduled reference instant determines health; actual evaluation time remains independently observable. */
final class HeartbeatStrategy
{
    /**
     * @param CheckInput $input Admitted heartbeat configuration and scheduled scalar context.
     * @param HeartbeatSnapshot $snapshot Last receipt visible at that slot, or the arming baseline.
     * @param int $intervalSeconds Positive monitor cadence in seconds.
     * @return CheckOutcome Down at or after the deadline, with no invented network latency or HTTP code.
     * @throws InvalidArgumentException When configuration, ownership or reference ordering is inconsistent.
     */
    public function evaluate(CheckInput $input, HeartbeatSnapshot $snapshot, int $intervalSeconds): CheckOutcome
    {
        $configuration = $input->configuration;
        $context = $input->context;

        if (!$configuration instanceof HeartbeatMonitorConfiguration || $intervalSeconds < 1
            || $snapshot->teamId !== $context->teamId || $snapshot->monitorId !== $context->monitorId
            || $snapshot->startedAt->isAfter($context->referenceAt)
            || $snapshot->receivedAt?->isAfter($context->referenceAt)) {
            throw new InvalidArgumentException('Heartbeat evaluation requires a local snapshot at the reference slot.');
        }

        $deadline = ($snapshot->receivedAt ?? $snapshot->startedAt)->addSeconds($intervalSeconds + $configuration->graceSeconds);
        $missed = $context->referenceAt->greaterThanOrEqualTo($deadline);

        return new CheckOutcome($context, $missed ? MonitorStatus::Down : MonitorStatus::Up, CarbonImmutable::now('UTC'), null, null, $missed ? CheckError::HeartbeatMissed : null);
    }
}
