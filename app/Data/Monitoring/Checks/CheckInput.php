<?php

declare(strict_types=1);

/** Carry trusted typed configuration and its bounded network budget to a checker. */

namespace App\Data\Monitoring\Checks;

use App\Contracts\Monitoring\MonitorConfiguration;
use InvalidArgumentException;

/** Private input must never be placed into queues, response payloads or logs. */
final readonly class CheckInput
{
    /**
     * @param CheckContext $context Admitted scalar execution identity.
     * @param MonitorConfiguration $configuration Validated private configuration, checked again by the resolver.
     * @param int $timeoutMs Network budget in milliseconds, shared by all HTTP hops.
     * @throws InvalidArgumentException When the budget is not positive; the coordinator applies the central operational bounds.
     */
    public function __construct(
        public CheckContext $context,
        #[\SensitiveParameter]
        public MonitorConfiguration $configuration,
        public int $timeoutMs,
    ) {
        if ($timeoutMs < 1) {
            throw new InvalidArgumentException('Check network budgets must be positive.');
        }
    }

    /** @return array<string, mixed> Safe debugger representation without configuration contents. */
    public function __debugInfo(): array
    {
        return ['context' => $this->context, 'timeout_ms' => $this->timeoutMs, 'configuration' => '[REDACTED]'];
    }
}
