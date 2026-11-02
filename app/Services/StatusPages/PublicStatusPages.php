<?php

declare(strict_types=1);

/** Resolve the status pages reachable at a public address and project what visitors may see. */

namespace App\Services\StatusPages;

use App\Enums\Incidents\IncidentStatus;
use App\Enums\StatusPages\PublicHealth;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\Scopes\TeamScope;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Public slug discovery, alongside StatusDomainRegistry's verified-host discovery. Drafts are indistinguishable
 * from unknown addresses; monitor names, maintenance reasons and every identity stay out of the projection.
 */
final readonly class PublicStatusPages
{
    /**
     * @param TenantContext $context Explicit tenant boundary for the page's content.
     * @param PublicHealthRules $health Current state rules.
     * @param PublicStatusHistory $history Daily availability from rollups.
     * @param PublicIncidentFeed $incidents Explicitly published incident entries.
     * @param StatusPageCache $cache Shared store of the projection.
     */
    public function __construct(
        private TenantContext $context,
        private PublicHealthRules $health,
        private PublicStatusHistory $history,
        private PublicIncidentFeed $incidents,
        private StatusPageCache $cache,
    ) {
    }

    /**
     * @param string $slug Public address segment.
     * @return StatusPage|null Published page at that address; null for a draft or an unknown address.
     */
    public function find(string $slug): ?StatusPage
    {
        return StatusPage::withoutGlobalScope(TeamScope::class)
            ->where('slug', $slug)
            ->where('is_public', true)
            ->first();
    }

    /**
     * Callers check visibility and the password on the live page first: the stored content does not depend on the visitor.
     *
     * @param StatusPage $page Published page the visitor may read.
     * @return array<string, mixed> Visitor projection: texts, theme, overall state, components with their history, and published incidents.
     */
    public function present(StatusPage $page): array
    {
        return $this->cache->remember($page->team_id, $page->id, fn (): array => $this->calculate($page));
    }

    /**
     * @param StatusPage $page Published page.
     * @return array<string, mixed> Visitor projection calculated now.
     */
    private function calculate(StatusPage $page): array
    {
        return $this->context->run($page->team_id, function () use ($page): array {
            $now = CarbonImmutable::now('UTC');
            $components = StatusPageComponent::query()
                ->where('status_page_id', $page->id)
                ->orderBy('position')
                ->with(['monitors' => static fn ($query) => $query->select([
                    'monitors.id',
                    'monitors.team_id',
                    'monitors.is_paused',
                    'monitors.is_plan_suspended',
                    'monitors.last_status',
                    'monitors.last_checked_at',
                    'monitors.interval_seconds',
                    'monitors.heartbeat_grace_seconds',
                ])])
                ->get();

            $monitorIds = array_values($components->flatMap(static fn (StatusPageComponent $component) => $component->monitors->map(static fn (Monitor $monitor): int => $monitor->id))->all());
            $openIncidents = $this->monitorsWithOpenIncident($monitorIds);
            $inMaintenance = $this->monitorsInMaintenance($monitorIds, $now);
            $history = $this->history->read($page, $now);

            $rows = [];
            $componentByMonitor = [];

            foreach ($components as $component) {
                $states = array_values($component->monitors->map(fn (Monitor $monitor) => $this->health->monitor(
                    $monitor,
                    isset($openIncidents[$monitor->id]),
                    isset($inMaintenance[$monitor->id]),
                    $now,
                ))->all());

                foreach ($component->monitors as $monitor) {
                    $componentByMonitor[$monitor->id] = $component->name;
                }

                $rows[] = [
                    'name' => $component->name,
                    'status' => $this->health->group($states)->value,
                    ...($history[$component->id] ?? $this->history->empty($now)),
                ];
            }

            return [
                'title' => $page->title,
                'description' => $page->description,
                'theme' => $page->theme()->toArray(),
                'status' => $this->overall($rows),
                'components' => $rows,
                'incidents' => $this->incidents->read($componentByMonitor, $now),
            ];
        });
    }

    /**
     * @param list<array{status: string}> $rows Projected states of the components.
     * @return string Overall state; a page without components says it is unknown, never operational.
     */
    private function overall(array $rows): string
    {
        return $this->health->group(array_map(static fn (array $row): PublicHealth => PublicHealth::from($row['status']), $rows))->value;
    }

    /**
     * @param list<int> $monitorIds Monitors of the page.
     * @return array<int, true> Monitors with an unresolved incident.
     */
    private function monitorsWithOpenIncident(array $monitorIds): array
    {
        return array_fill_keys(
            Incident::query()->whereIn('monitor_id', $monitorIds)->where('status', '!=', IncidentStatus::Resolved->value)->pluck('monitor_id')->all(),
            true,
        );
    }

    /**
     * @param list<int> $monitorIds Monitors of the page.
     * @param CarbonImmutable $now Current instant.
     * @return array<int, true> Monitors whose half-open maintenance window contains the instant.
     */
    private function monitorsInMaintenance(array $monitorIds, CarbonImmutable $now): array
    {
        $instant = $now->format('Y-m-d\TH:i:s.u\Z');

        return array_fill_keys(
            MaintenanceWindow::query()
                ->whereIn('monitor_id', $monitorIds)
                ->where('starts_at', '<=', $instant)
                ->where('ends_at', '>', $instant)
                ->pluck('monitor_id')
                ->all(),
            true,
        );
    }
}
