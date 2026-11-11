<?php

declare(strict_types=1);

/** Parse direct incident filters and return hints with the same tolerant bounded vocabulary. */

namespace App\Data\Incidents;

use App\Enums\Incidents\IncidentListState;
use App\Enums\Incidents\IncidentSeverity;

/** Invalid controls are reported by name without reflecting raw values or redirecting failed GET input. */
final readonly class IncidentListSelection
{
    /**
     * @param IncidentListFilter $filter Canonical selection independent of tenant identity.
     * @param list<string> $rejected Invalid field names in declaration order.
     */
    private function __construct(public IncidentListFilter $filter, public array $rejected)
    {
    }

    /**
     * @param array<array-key, mixed> $input Untrusted query values.
     * @return self Normalized state, severity and one-based bounded page.
     */
    public static function fromInput(array $input): self
    {
        $rejected = [];
        $state = self::enum($input, 'state', IncidentListState::class, $rejected);
        $severity = self::enum($input, 'severity', IncidentSeverity::class, $rejected);
        $page = $input['page'] ?? null;
        $number = 1;
        if ($page !== null) {
            if (is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/D', $page) === 1) {
                $number = (int) $page;
            } else {
                $rejected[] = 'page';
            }
        }

        return new self(new IncidentListFilter($state, $severity, $number), $rejected);
    }

    /**
     * @template T of IncidentListState|IncidentSeverity
     * @param array<array-key, mixed> $input Raw controls.
     * @param string $key Known control name.
     * @param class-string<T> $enum Supported filter enum.
     * @param list<string> $rejected Invalid output keys.
     * @return T|null Accepted enum or null for absent/invalid input.
     */
    private static function enum(array $input, string $key, string $enum, array &$rejected): IncidentListState|IncidentSeverity|null
    {
        $value = $input[$key] ?? null;
        $case = is_string($value) ? $enum::tryFrom($value) : null;
        if ($value !== null && $case === null) {
            $rejected[] = $key;
        }

        return $case;
    }
}
