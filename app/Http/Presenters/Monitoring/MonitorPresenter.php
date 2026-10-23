<?php

declare(strict_types=1);

/** Build deliberate Inertia projections of monitors; models are never serialized directly. */

namespace App\Http\Presenters\Monitoring;

use App\Enums\Monitoring\DnsRecordType;
use App\Enums\Monitoring\HttpMethod;
use App\Enums\Monitoring\KeywordMode;
use App\Enums\Monitoring\MonitorOperationalState;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\Monitoring\MonitorType;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorConfigurationProjection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Summaries read only non-configuration columns, so listing never decrypts URLs, headers or bodies.
 * Details take type-specific settings exclusively from MonitorConfigurationProjection.
 * Keys are camelCase like other page projections; the nested `configuration` keeps the snake_case
 * input field names so edit forms can prefill the non-secret values directly.
 */
final readonly class MonitorPresenter
{
    /** Suggested cadence for new monitors, in seconds, before applying the plan minimum. */
    private const int DEFAULT_INTERVAL_SECONDS = 300;

    /** Suggested execution budget for new monitors, in milliseconds. */
    private const int DEFAULT_TIMEOUT_MS = 5000;

    /** @param MonitorConfigurationProjection $configuration Only allowed configuration projection. */
    public function __construct(private MonitorConfigurationProjection $configuration)
    {
    }

    /**
     * @param Monitor $monitor Authorized monitor of the active team.
     * @return array{id: int, name: string, type: string|null, lastStatus: string, state: string, intervalSeconds: int, regions: list<string>, lastCheckedAt: string|null}
     *         Row fields without any configuration value.
     */
    public function summary(Monitor $monitor): array
    {
        return [
            'id' => $monitor->id,
            'name' => $monitor->name,
            'type' => $monitor->type?->value,
            'lastStatus' => $monitor->last_status->value,
            'state' => MonitorOperationalState::of($monitor)->value,
            'intervalSeconds' => $monitor->interval_seconds,
            'regions' => $monitor->regions_json->toValues(),
            'lastCheckedAt' => self::instant($monitor->last_checked_at),
        ];
    }

    /**
     * @param Monitor $monitor Authorized monitor of the active team; its configuration is decrypted in memory only.
     * @param bool $withinQuota Whether the monitor's deterministic position fits the current plan quota.
     * @return array<string, mixed> Summary plus timing, scheduling instants and the safe configuration projection.
     */
    public function detail(Monitor $monitor, bool $withinQuota): array
    {
        return [
            ...$this->summary($monitor),
            'timeoutMs' => $monitor->timeout_ms,
            'confirmationThreshold' => $monitor->confirmation_threshold,
            'nextCheckAt' => self::instant($monitor->next_check_at),
            'createdAt' => self::instant($monitor->created_at),
            'withinQuota' => $withinQuota,
            'configuration' => $this->configuration->project($monitor->configuration()),
        ];
    }

    /**
     * @param LengthAwarePaginator<int, Monitor> $page Tenant-filtered result page.
     * @return array{data: list<array<string, mixed>>, currentPage: int, lastPage: int, perPage: int, total: int, from: int|null, to: int|null}
     *         Rows and counters only; the client builds links from its own filters so engine-specific query
     *         parameters never reach page URLs.
     */
    public function page(LengthAwarePaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $monitor) {
            $rows[] = $this->summary($monitor);
        }

        return [
            'data' => $rows,
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'perPage' => $page->perPage(),
            'total' => $page->total(),
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
        ];
    }

    /**
     * Initial form values matching the validator's defaults, with the cadence raised to the plan minimum.
     *
     * @param int $minimumIntervalSeconds Effective plan's fastest cadence, in seconds.
     * @return array<string, mixed> Common and per-type starting values using input field names.
     */
    public static function defaults(int $minimumIntervalSeconds): array
    {
        return [
            'name' => '',
            'type' => MonitorType::Http->value,
            'interval_seconds' => max(self::DEFAULT_INTERVAL_SECONDS, $minimumIntervalSeconds),
            'timeout_ms' => self::DEFAULT_TIMEOUT_MS,
            'confirmation_threshold' => 3,
            'regions' => [MonitorRegion::Eu->value],
            'method' => HttpMethod::GET->value,
            'expected_status' => 200,
            'follow_redirects' => false,
            'keyword_mode' => KeywordMode::Present->value,
            'tls_port' => 443,
            'dns_record_type' => DnsRecordType::A->value,
            'heartbeat_grace_seconds' => 0,
        ];
    }

    /** @return array<string, list<string>> Enum values offered by filters and forms, so React copies none. */
    public static function options(): array
    {
        return [
            'types' => self::values(MonitorType::cases()),
            'statuses' => self::values(MonitorStatus::cases()),
            'states' => self::values(MonitorOperationalState::cases()),
            'regions' => self::values(MonitorRegion::cases()),
            'methods' => self::values(HttpMethod::cases()),
            'keywordModes' => self::values(KeywordMode::cases()),
            'dnsRecordTypes' => self::values(DnsRecordType::cases()),
        ];
    }

    /**
     * @param list<\BackedEnum> $cases Enum cases in declaration order.
     * @return list<string> Their string values.
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }

    /**
     * @param CarbonImmutable|null $instant Stored UTC instant.
     * @return string|null ISO-8601 UTC representation; the client formats it in the viewer's locale.
     */
    private static function instant(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->toIso8601String();
    }
}
