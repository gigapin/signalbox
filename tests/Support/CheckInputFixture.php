<?php

declare(strict_types=1);

/** Build private checker inputs with synthetic scalar identities and reserved destinations. */

namespace Tests\Support;

use App\Contracts\Monitoring\MonitorConfiguration;
use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\HttpMonitorConfiguration;
use App\Enums\Monitoring\MonitorRegion;
use Carbon\CarbonImmutable;

/** Fixtures do not create tenants, query the database or authorize network access. */
final class CheckInputFixture
{
    /**
     * @param int $id Distinct synthetic execution/monitor identity.
     * @param MonitorConfiguration|null $configuration Explicit variant, defaulting to a synthetic HTTP target.
     * @param int $timeoutMs Observation network budget.
     * @param int $teamId Synthetic admitted tenant identity.
     * @return CheckInput Private input for pure or HTTP-faked tests.
     */
    public static function make(int $id = 1, ?MonitorConfiguration $configuration = null, int $timeoutMs = 1000, int $teamId = 1): CheckInput
    {
        return new CheckInput(
            new CheckContext($teamId, $id, $id, MonitorRegion::Eu, CarbonImmutable::parse('2026-10-23T12:00:00Z')),
            $configuration ?? new HttpMonitorConfiguration('https://checker.example.test/health'),
            $timeoutMs,
        );
    }
}
