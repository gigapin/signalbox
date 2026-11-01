<?php

declare(strict_types=1);

/** Keep what a status page publishes in the shared cache, and drop it when something it shows changes. */

namespace App\Services\StatusPages;

use App\Events\Incidents\IncidentOpened;
use App\Events\Incidents\IncidentReopened;
use App\Events\Incidents\IncidentResolved;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keys carry the team and page identities, never the address, so a page keeps nothing when it moves and two pages never
 * meet. Each page has a generation in its key: invalidating raises it, so a calculation already running when the change
 * commits stores under the old generation and is never read. Content only: visibility and the password are always
 * checked on the live page before this is read, and nothing about the visitor is stored here. The cache is optional:
 * if it fails, pages are calculated on each visit and the duration below bounds any invalidation that was missed.
 */
final readonly class StatusPageCache
{
    /** @var int Seconds a calculation lasts, which bounds how late checks and time-based changes appear. */
    public const int TTL_SECONDS = 60;

    /** @var int Seconds a generation lasts once unused; far above the entry lifetime, so restarting from 1 cannot revive an entry. */
    private const int GENERATION_TTL_SECONDS = 86_400;

    /** @param Repository $cache Application cache store. */
    public function __construct(private Repository $cache)
    {
    }

    /**
     * @param int $teamId Owning team.
     * @param int $pageId Published page.
     * @param Closure(): array<string, mixed> $calculate Builds the visitor projection.
     * @return array<string, mixed> Stored projection, or a new one.
     */
    public function remember(int $teamId, int $pageId, Closure $calculate): array
    {
        try {
            $key = $this->key($teamId, $pageId, $this->generation($teamId, $pageId));
            $stored = $this->cache->get($key);
        } catch (Throwable $exception) {
            $this->report('read', $exception);

            return $calculate();
        }

        if (is_array($stored)) {
            return $stored;
        }

        $value = $calculate();

        try {
            $this->cache->put($key, $value, self::TTL_SECONDS);
        } catch (Throwable $exception) {
            $this->report('write', $exception);
        }

        return $value;
    }

    /**
     * Takes effect after the surrounding transaction commits, so a reader cannot store the old content under the new generation.
     *
     * @param int $teamId Owning team.
     * @param int $pageId Page whose content changed.
     */
    public function forgetPage(int $teamId, int $pageId): void
    {
        DB::afterCommit(fn () => $this->raise($teamId, $pageId));
    }

    /**
     * Call it before deleting the monitor: afterwards the pages showing it can no longer be found.
     *
     * @param int $teamId Owning team.
     * @param int $monitorId Monitor whose public state changed.
     */
    public function forgetForMonitor(int $teamId, int $monitorId): void
    {
        // The page identities are read now, inside the change that may be about to remove the link.
        $pageIds = DB::table('status_page_component_monitors')
            ->where('team_id', $teamId)
            ->where('monitor_id', $monitorId)
            ->distinct()
            ->pluck('status_page_id');

        foreach ($pageIds as $pageId) {
            $this->forgetPage($teamId, (int) $pageId);
        }
    }

    /**
     * Registered for the incident events, which are dispatched after their transaction commits.
     *
     * @param IncidentOpened|IncidentReopened|IncidentResolved $event Change of a monitor's public state.
     */
    public function onIncident(IncidentOpened|IncidentReopened|IncidentResolved $event): void
    {
        try {
            $this->forgetForMonitor($event->teamId, $event->monitorId);
        } catch (Throwable $exception) {
            // Incident handling must not depend on a public page: the entry expires by itself.
            $this->report('invalidation', $exception);
        }
    }

    /**
     * A failure never undoes the committed change: the entry simply expires by itself.
     *
     * @param int $teamId Owning team.
     * @param int $pageId Page to invalidate.
     */
    private function raise(int $teamId, int $pageId): void
    {
        try {
            $this->generation($teamId, $pageId);
            $this->cache->increment($this->generationKey($teamId, $pageId));
        } catch (Throwable $exception) {
            $this->report('invalidation', $exception);
        }
    }

    /**
     * @param int $teamId Owning team.
     * @param int $pageId Page identity.
     * @return int Current generation, created at 1 when absent.
     */
    private function generation(int $teamId, int $pageId): int
    {
        $key = $this->generationKey($teamId, $pageId);

        // Add never overwrites, so concurrent first readers agree on the value.
        $this->cache->add($key, 1, self::GENERATION_TTL_SECONDS);

        return (int) $this->cache->get($key, 1);
    }

    /**
     * @param int $teamId Owning team.
     * @param int $pageId Page identity.
     * @param int $generation Current generation.
     * @return string Key of the stored projection.
     */
    private function key(int $teamId, int $pageId, int $generation): string
    {
        return "status-pages:{$teamId}:{$pageId}:content:{$generation}";
    }

    /**
     * @param int $teamId Owning team.
     * @param int $pageId Page identity.
     * @return string Key of the page's generation.
     */
    private function generationKey(int $teamId, int $pageId): string
    {
        return "status-pages:{$teamId}:{$pageId}:generation";
    }

    /**
     * @param string $operation Failed operation.
     * @param Throwable $exception Failure; only its class is logged, as a message could carry connection details.
     */
    private function report(string $operation, Throwable $exception): void
    {
        Log::warning('Status page cache unavailable.', ['operation' => $operation, 'exception' => $exception::class]);
    }
}
