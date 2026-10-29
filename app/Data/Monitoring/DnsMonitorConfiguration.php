<?php

declare(strict_types=1);

/** Describe expected DNS records without selecting or contacting a resolver. */

namespace App\Data\Monitoring;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\MonitorType;
use InvalidArgumentException;

/** Preserve record values verbatim; semantic normalization belongs to the DNS checker contract. */
final readonly class DnsMonitorConfiguration implements MonitorConfiguration
{
    /** @var list<non-empty-string> Structurally validated expected records. */
    public array $expectedValues;

    /**
     * @param string $name Nonempty DNS query name.
     * @param DnsRecordType $recordType Record family to compare.
     * @param array<array-key, mixed> $expectedValues Nonempty list of nonempty expected record values.
     * @param string|null $resolver Optional resolver destination; null delegates selection to future protected infrastructure.
     * @throws InvalidArgumentException When the structure is incomplete or malformed.
     */
    public function __construct(
        public string $name,
        public DnsRecordType $recordType,
        array $expectedValues,
        public ?string $resolver = null,
    ) {
        if (trim($name) === '' || !array_is_list($expectedValues) || $expectedValues === [] || ($resolver !== null && trim($resolver) === '')) {
            throw new InvalidArgumentException('DNS configuration requires a name, expected values and an optional nonempty resolver.');
        }

        $validated = [];

        foreach ($expectedValues as $value) {
            if (!is_string($value) || $value === '') {
                throw new InvalidArgumentException('DNS expected values must be nonempty strings.');
            }

            $validated[] = $value;
        }

        $this->expectedValues = $validated;
    }

    /** @return MonitorType DNS observation identity. */
    public function type(): MonitorType
    {
        return MonitorType::Dns;
    }
}
