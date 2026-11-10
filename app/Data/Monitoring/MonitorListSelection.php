<?php

declare(strict_types=1);

/** Normalize bounded list controls identically for direct requests and navigation return links. */

namespace App\Data\Monitoring;

use App\Enums\Monitoring\MonitorOperationalState;
use App\Enums\Monitoring\MonitorStatus;
use App\Enums\Monitoring\MonitorType;

/** Invalid controls are reported by name, never redirected or reflected as arbitrary values. */
final readonly class MonitorListSelection
{
    /**
     * @param MonitorListFilter $filter Accepted list selection.
     * @param list<string> $rejected Invalid query keys in declaration order.
     */
    private function __construct(public MonitorListFilter $filter, public array $rejected)
    {
    }

    /**
     * @param array<array-key, mixed> $input Raw query parameters; unknown keys are ignored.
     * @return self Bounded canonical selection without redirect-loop validation.
     */
    public static function fromInput(array $input): self
    {
        $rejected = [];
        $query = $input['q'] ?? '';
        if (!is_string($query) || mb_strlen(trim($query)) > MonitorListFilter::QUERY_MAX_LENGTH) {
            $rejected[] = 'q';
            $query = '';
        }
        $type = self::enum($input, 'type', MonitorType::class, $rejected);
        $status = self::enum($input, 'status', MonitorStatus::class, $rejected);
        $state = self::enum($input, 'state', MonitorOperationalState::class, $rejected);
        $page = $input['page'] ?? null;
        $number = 1;
        if ($page !== null) {
            if (is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/D', $page) === 1) {
                $number = (int) $page;
            } else {
                $rejected[] = 'page';
            }
        }

        return new self(new MonitorListFilter(trim($query), $type, $status, $state, $number), $rejected);
    }

    /**
     * @template T of MonitorType|MonitorStatus|MonitorOperationalState
     * @param array<array-key, mixed> $input Raw controls.
     * @param string $key Filter key.
     * @param class-string<T> $enum Supported vocabulary.
     * @param list<string> $rejected Output names of invalid controls.
     * @return T|null Accepted case or null for absent/invalid input.
     */
    private static function enum(array $input, string $key, string $enum, array &$rejected): MonitorType|MonitorStatus|MonitorOperationalState|null
    {
        $value = $input[$key] ?? null;
        $case = is_string($value) ? $enum::tryFrom($value) : null;
        if ($value !== null && $case === null) {
            $rejected[] = $key;
        }

        return $case;
    }
}
