<?php

declare(strict_types=1);

/** Report what one partition removal pass did. */

namespace App\Data\Metrics;

/** A blocked month is an operational delay to expose, not an error in the data the product shows. */
final readonly class PartitionDropOutcome
{
    /**
     * @param list<string> $dropped Identifiers of the removed partitions.
     * @param array<string, array<string, int>> $blocked Reasons with counts, per expired month (YYYY-MM) that was kept.
     */
    public function __construct(
        public array $dropped,
        public array $blocked,
    ) {
    }
}
