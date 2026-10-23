<?php

declare(strict_types=1);

/** Terminalize expired observations and abandoned acquisitions without retrying target traffic. */

namespace App\Services\Monitoring\Scheduling;

use App\Models\CheckExecution;
use App\Models\Scopes\TeamScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Recheck every discovered candidate under its own tenant-scoped row lock. */
final readonly class RecoverMonitorChecks
{
    /** @param ExecutionLedger $ledger Single owner of terminal transitions. */
    public function __construct(private ExecutionLedger $ledger)
    {
    }

    /** @return int Recovered execution count; pending publication recovery belongs to the publisher. */
    public function run(): int
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return 0;
        }

        $count = 0;
        $now = CarbonImmutable::now('UTC');
        CheckExecution::withoutGlobalScope(TeamScope::class)
            ->select(['id', 'team_id'])
            ->where(function (Builder $query) use ($now): void {
                $query->where(fn (Builder $pending) => $pending->where('state', 'pending')->where('expires_at', '<=', $now))
                    ->orWhere(fn (Builder $running) => $running->where('state', 'running')->where('deadline_at', '<=', $now));
            })
            ->chunkById((int) config('monitor-scheduling.chunk_size'), function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    $count += (int) $this->ledger->recover($row->team_id, $row->id);
                }
            });

        return $count;
    }
}
