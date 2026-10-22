<?php

declare(strict_types=1);

/** Normalize explicit simulated execution regions without allowing duplicates or an empty set. */

namespace App\Data\Monitoring;

use App\Enums\Monitoring\MonitorRegion;
use InvalidArgumentException;

/** Persist canonical EU-then-US order so equivalent configurations have the same representation. */
final readonly class MonitorRegions
{
    /** @var list<MonitorRegion> Nonempty canonical execution regions. */
    public array $values;

    /**
     * @param array<array-key, mixed> $regions Selected regions, defaulting to EU only.
     * @throws InvalidArgumentException When selections are empty, duplicated or not region enum values.
     */
    public function __construct(array $regions = [MonitorRegion::Eu])
    {
        $selected = [];

        foreach ($regions as $region) {
            if (!$region instanceof MonitorRegion || isset($selected[$region->value])) {
                throw new InvalidArgumentException('Monitor regions must be distinct supported enum values.');
            }

            $selected[$region->value] = $region;
        }

        if ($selected === [] || !array_is_list($regions)) {
            throw new InvalidArgumentException('Monitor regions must be a nonempty list.');
        }

        ksort($selected);
        $this->values = array_values($selected);
    }

    /**
     * @param array<array-key, mixed> $values Stored region identifiers.
     * @return self Validated canonical region selection.
     * @throws InvalidArgumentException When identifiers are malformed or unsupported.
     */
    public static function fromValues(array $values): self
    {
        if (!array_is_list($values)) {
            throw new InvalidArgumentException('Stored regions must be a list.');
        }

        $regions = [];

        foreach ($values as $value) {
            if (!is_string($value) || ($region = MonitorRegion::tryFrom($value)) === null) {
                throw new InvalidArgumentException('Stored regions contain an unsupported identifier.');
            }

            $regions[] = $region;
        }

        return new self($regions);
    }

    /** @return list<string> Stable scalar representation for JSON persistence and deliberate projections. */
    public function toValues(): array
    {
        return array_map(static fn (MonitorRegion $region): string => $region->value, $this->values);
    }
}
