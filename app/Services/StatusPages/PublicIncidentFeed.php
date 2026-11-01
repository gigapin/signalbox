<?php

declare(strict_types=1);

/** List what a status page publishes about incidents: only entries someone explicitly published. */

namespace App\Services\StatusPages;

use App\Models\Incident;
use App\Models\IncidentUpdate;
use Carbon\CarbonImmutable;

/**
 * An incident appears only once it has a published entry, and its status is the one of its latest published entry, so a
 * private change of status reveals nothing. Authors, the declared cause, the error class and monitor names stay out.
 */
final readonly class PublicIncidentFeed
{
    /** @var int Most incidents listed on a page, newest first. */
    private const int MAX_INCIDENTS = 25;

    /**
     * @param array<int, string> $componentByMonitor Public component name per monitor of the page.
     * @param CarbonImmutable $now Current instant.
     * @return list<array{component: string, status: string, updates: list<array{status: string, message: string, publishedAt: string}>}> Incidents, newest publication first, with their entries newest first.
     */
    public function read(array $componentByMonitor, CarbonImmutable $now): array
    {
        if ($componentByMonitor === []) {
            return [];
        }

        $updates = IncidentUpdate::query()
            ->where('is_public', true)
            ->where('published_at', '>=', $now->subDays(PublicStatusHistory::DAYS)->utc()->format('Y-m-d\TH:i:s\Z'))
            ->whereIn('incident_id', Incident::query()->whereIn('monitor_id', array_keys($componentByMonitor))->select('id'))
            ->with('incident:id,monitor_id')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();

        $feed = [];

        foreach ($updates->groupBy('incident_id')->take(self::MAX_INCIDENTS) as $entries) {
            $latest = $entries->first();

            if (!$latest instanceof IncidentUpdate) {
                continue;
            }

            $feed[] = [
                'component' => $componentByMonitor[$latest->incident->monitor_id],
                'status' => $latest->status->value,
                'updates' => array_values($entries->map(static fn (IncidentUpdate $update): array => [
                    'status' => $update->status->value,
                    'message' => $update->message,
                    'publishedAt' => (string) $update->published_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                ])->all()),
            ];
        }

        return $feed;
    }
}
