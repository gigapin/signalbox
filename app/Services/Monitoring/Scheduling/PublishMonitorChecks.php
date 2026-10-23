<?php

declare(strict_types=1);

/** Publish committed outbox rows without holding database locks across broker I/O. */

namespace App\Services\Monitoring\Scheduling;

use App\Contracts\Monitoring\CheckWorkPublisher;
use App\Models\CheckExecution;
use App\Models\Scopes\TeamScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Expired leases and confirmed-but-unacquired messages become eligible for another delivery. */
final readonly class PublishMonitorChecks
{
    /**
     * @param ExecutionLedger $ledger Fenced publication transitions.
     * @param CheckWorkPublisher $publisher Replaceable broker adapter.
     */
    public function __construct(private ExecutionLedger $ledger, private CheckWorkPublisher $publisher)
    {
    }

    /** @return int Confirmed publications; individual broker failures retain their leases for recovery. */
    public function run(): int
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return 0;
        }

        $count = 0;
        $now = CarbonImmutable::now('UTC');
        CheckExecution::withoutGlobalScope(TeamScope::class)
            ->select(['id', 'team_id'])
            ->where('state', 'pending')
            ->where('available_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->chunkById((int) config('monitor-scheduling.chunk_size'), function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    $work = $this->ledger->leasePublication($row->team_id, $row->id);

                    if ($work === null) {
                        continue;
                    }

                    try {
                        $this->publisher->publish($work->team_id, $work->id, $work->region);
                        $count += (int) $this->ledger->acknowledge($work);
                    } catch (Throwable) {
                        // Broker exceptions can contain credentials; record only the safe work identity.
                        Log::warning('Monitor execution publication unconfirmed.', ['execution_id' => $work->id, 'team_id' => $work->team_id]);
                    }
                }
            });

        return $count;
    }
}
