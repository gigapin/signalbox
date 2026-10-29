<?php

declare(strict_types=1);

/** Build deliberate Inertia projections of incidents and their timelines; models are never serialized. */

namespace App\Http\Presenters\Incidents;

use App\Enums\Incidents\IncidentListState;
use App\Enums\Incidents\IncidentSeverity;
use App\Enums\Incidents\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * People appear by display name only, never by email or identifier. A timeline entry without an author is a
 * system entry or one whose account was deleted; both are projected as `author: null`.
 */
final readonly class IncidentPresenter
{
    /**
     * @param Incident $incident Incident of the active team with its monitor identity loaded.
     * @return array{id: int, monitor: array{id: int, name: string}, status: string, severity: string, cause: string, startedAt: string, confirmedAt: string, resolvedAt: string|null, durationSeconds: int|null, acknowledged: bool, reopenedCount: int}
     *         Row fields shared by lists and the detail page.
     */
    public function summary(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'monitor' => ['id' => $incident->monitor->id, 'name' => $incident->monitor->name],
            'status' => $incident->status->value,
            'severity' => $incident->severity->value,
            'cause' => $incident->cause->value,
            'startedAt' => self::instant($incident->started_at),
            'confirmedAt' => self::instant($incident->confirmed_at),
            'resolvedAt' => self::optionalInstant($incident->resolved_at),
            'durationSeconds' => $incident->duration_seconds,
            'acknowledged' => $incident->acknowledged_at !== null,
            'reopenedCount' => $incident->reopened_count,
        ];
    }

    /**
     * @param Incident $incident Incident of the active team with monitor, responsible user and timeline loaded.
     * @return array<string, mixed> Summary plus responsibility, declared cause, reopening and the oldest-first timeline.
     */
    public function detail(Incident $incident): array
    {
        $timeline = [];

        foreach ($incident->updates as $update) {
            $timeline[] = $this->entry($update);
        }

        return [
            ...$this->summary($incident),
            'acknowledgedAt' => self::optionalInstant($incident->acknowledged_at),
            'responsible' => $incident->acknowledgedBy?->name,
            'rootCauseNote' => $incident->root_cause_note,
            'lastReopenedAt' => self::optionalInstant($incident->last_reopened_at),
            'timeline' => $timeline,
        ];
    }

    /**
     * @param IncidentUpdate $update Timeline entry with its author loaded.
     * @return array{id: int, status: string, message: string, author: string|null, isPublic: bool, publishedAt: string|null, createdAt: string|null}
     *         Entry fields; `author` is null for system entries and deleted accounts.
     */
    public function entry(IncidentUpdate $update): array
    {
        return [
            'id' => $update->id,
            'status' => $update->status->value,
            'message' => $update->message,
            'author' => $update->author?->name,
            'isPublic' => $update->is_public,
            'publishedAt' => self::optionalInstant($update->published_at),
            'createdAt' => self::optionalInstant($update->created_at),
        ];
    }

    /**
     * @param LengthAwarePaginator<int, Incident> $page Tenant-filtered result page.
     * @return array{data: list<array<string, mixed>>, currentPage: int, lastPage: int, perPage: int, total: int, from: int|null, to: int|null}
     *         Rows and counters only; the client builds links from its own filters.
     */
    public function page(LengthAwarePaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $incident) {
            $rows[] = $this->summary($incident);
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
     * An open incident may keep its status, move to another open status or be resolved; a resolved incident
     * only accepts notes, because reopening is automatic.
     *
     * @param Incident $incident Incident receiving the next timeline entry.
     * @return list<string> Status values a new entry may carry, in lifecycle order.
     */
    public function postableStatuses(Incident $incident): array
    {
        if (!$incident->status->isOpen()) {
            return [IncidentStatus::Resolved->value];
        }

        return self::values(IncidentStatus::cases());
    }

    /** @return array<string, list<string>> Enum values offered by filters and forms, so React copies none. */
    public static function options(): array
    {
        return [
            'states' => self::values(IncidentListState::cases()),
            'severities' => self::values(IncidentSeverity::cases()),
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
     * @param CarbonImmutable $instant Stored UTC instant.
     * @return string ISO-8601 UTC representation; the client formats it in the viewer's locale.
     */
    private static function instant(CarbonImmutable $instant): string
    {
        return $instant->utc()->toIso8601String();
    }

    /**
     * @param CarbonImmutable|null $instant Stored UTC instant, when set.
     * @return string|null ISO-8601 UTC representation, or null.
     */
    private static function optionalInstant(?CarbonImmutable $instant): ?string
    {
        return $instant?->utc()->toIso8601String();
    }
}
