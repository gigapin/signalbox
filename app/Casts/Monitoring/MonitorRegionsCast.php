<?php

declare(strict_types=1);

/** Convert canonical region selections between PostgreSQL JSON and a typed immutable value. */

namespace App\Casts\Monitoring;

use App\Data\Monitoring\MonitorRegions;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<MonitorRegions, MonitorRegions> Persist only explicitly validated selections. */
final class MonitorRegionsCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * @param Model $model Owning monitor.
     * @param string $key Region attribute name.
     * @param mixed $value Raw JSON text supplied by PostgreSQL or model defaults.
     * @param array<string, mixed> $attributes Raw persisted attributes.
     * @return MonitorRegions Typed canonical selection.
     * @throws InvalidArgumentException When persisted regions are not a JSON list.
     * @throws \JsonException When persisted JSON is malformed.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): MonitorRegions
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Stored regions must be JSON text.');
        }

        $decoded = json_decode($value, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Stored regions must be a JSON list.');
        }

        return MonitorRegions::fromValues($decoded);
    }

    /**
     * @param Model $model Owning monitor.
     * @param string $key Region attribute name.
     * @param mixed $value Explicit region selection, never a loosely typed payload.
     * @param array<string, mixed> $attributes Current raw attributes.
     * @return string Canonical JSON representation.
     * @throws InvalidArgumentException When the supplied value is not a typed selection.
     * @throws \JsonException When encoding fails.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (!$value instanceof MonitorRegions) {
            throw new InvalidArgumentException('Assign regions using MonitorRegions.');
        }

        return json_encode($value->toValues(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param Model $model Owning monitor.
     * @param string $key Region attribute name.
     * @param mixed $value Cast value supplied by Eloquent during explicit model serialization.
     * @param array<string, mixed> $attributes Raw persisted attributes.
     * @return list<string> Stable scalar region identifiers, never an internal value object.
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (!$value instanceof MonitorRegions) {
            throw new InvalidArgumentException('Serialized regions require a typed selection.');
        }

        return $value->toValues();
    }

}
