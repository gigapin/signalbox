<?php

declare(strict_types=1);

/** Carry a complete validated monitor definition without persistence or implicit serialization. */

namespace App\Data\Monitoring;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorConfigurationMapper;
use BackedEnum;

/** Keep configuration-bearing values inside an authorized unit of work; never log this object. */
final readonly class MonitorInput
{
    /**
     * @param string $name Validated presentation label.
     * @param int $intervalSeconds Validated cadence in seconds, without plan enforcement.
     * @param int $timeoutMs Validated timeout in milliseconds.
     * @param int $confirmationThreshold Positive regional failure threshold.
     * @param MonitorRegions $regions Canonical simulated execution regions.
     * @param MonitorConfiguration $configuration Complete type-specific settings, possibly containing secrets.
     */
    public function __construct(
        public string $name,
        public int $intervalSeconds,
        public int $timeoutMs,
        public int $confirmationThreshold,
        public MonitorRegions $regions,
        public MonitorConfiguration $configuration,
    ) {
    }

    /**
     * @param Monitor $monitor Authorized fresh record; callers performing mutations must hold their domain lock.
     * @return self Snapshot for validating a patch, not evidence that the record remains unchanged later.
     */
    public static function fromMonitor(Monitor $monitor): self
    {
        return new self(
            $monitor->name,
            $monitor->interval_seconds,
            $monitor->timeout_ms,
            $monitor->confirmation_threshold,
            $monitor->regions_json,
            $monitor->configuration(),
        );
    }

    /** @return array<string, mixed> Internal validation candidate; must never be merged into the original request or response. */
    public function candidate(): array
    {
        $attributes = MonitorConfigurationMapper::attributes($this->configuration);
        $attributes['headers'] = $attributes['headers_json'];
        unset($attributes['headers_json']);

        foreach ($attributes as $key => $value) {
            if ($value instanceof BackedEnum) {
                $attributes[$key] = $value->value;
            }
        }

        return [
            ...$attributes,
            'name' => $this->name,
            'interval_seconds' => $this->intervalSeconds,
            'timeout_ms' => $this->timeoutMs,
            'confirmation_threshold' => $this->confirmationThreshold,
            'regions' => $this->regions->toValues(),
        ];
    }
}
