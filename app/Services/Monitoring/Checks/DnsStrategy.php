<?php

declare(strict_types=1);

/** Compare complete normalized DNS sets independently of transport ordering and TTL. */

namespace App\Services\Monitoring\Checks;

use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Data\Monitoring\DnsMonitorConfiguration;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorStatus;
use App\Services\Monitoring\MonitorInputSyntax;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** TXT bytes remain exact; expected-value normalization reuses the existing input boundary. */
final readonly class DnsStrategy
{
    /** @param MonitorInputSyntax $syntax Shared expected-value normalization. */
    public function __construct(private MonitorInputSyntax $syntax)
    {
    }

    /**
     * @param CheckInput $input Admitted DNS configuration and scalar identity.
     * @param list<string> $values Complete canonical observed record set.
     * @param CarbonImmutable $observedAt Actual UTC completion instant.
     * @param float $latencyMs Measured acquisition milliseconds.
     * @return CheckOutcome Success only for exact set equality, ignoring duplicates and order.
     * @throws InvalidArgumentException When configuration or its expected values are invalid.
     */
    public function evaluate(CheckInput $input, array $values, CarbonImmutable $observedAt, float $latencyMs): CheckOutcome
    {
        $configuration = $input->configuration;

        if (!$configuration instanceof DnsMonitorConfiguration) {
            throw new InvalidArgumentException('DNS evaluation requires a DNS configuration.');
        }

        $expected = $this->syntax->dnsValues($configuration->recordType, $configuration->expectedValues);
        $actual = array_values(array_unique($values));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        $error = $actual === [] ? CheckError::DnsMissing : ($actual === $expected ? null : CheckError::DnsMismatch);

        return new CheckOutcome($input->context, $error === null ? MonitorStatus::Up : MonitorStatus::Down, $observedAt, $latencyMs, null, $error);
    }
}
