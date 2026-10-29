<?php

declare(strict_types=1);

/** Terminalize expired observations and abandoned acquisitions without retrying target traffic. */

namespace App\Services\Monitoring\Scheduling;

use App\Data\Monitoring\SchedulingPass;
use App\Models\CheckExecution;
use App\Models\Scopes\TeamScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        return $this->runPass()->processed;
    }

    /** @return SchedulingPass Recovered executions and executions whose recovery failed and will be retried. */
    public function runPass(): SchedulingPass
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return new SchedulingPass(0, 0);
        }

        $count = 0;
        $failed = 0;
        $now = CarbonImmutable::now('UTC');
        CheckExecution::withoutGlobalScope(TeamScope::class)
            ->select(['id', 'team_id'])
            ->where(function (Builder $query) use ($now): void {
                $query->where(fn (Builder $pending) => $pending->where('state', 'pending')->where('expires_at', '<=', $now))
                    ->orWhere(fn (Builder $running) => $running->where('state', 'running')->where('deadline_at', '<=', $now));
            })
            ->chunkById((int) config('monitor-scheduling.chunk_size'), function ($rows) use (&$count, &$failed): void {
                foreach ($rows as $row) {
                    try {
                        $count += (int) $this->ledger->recover($row->team_id, $row->id);
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::error('Monitor execution recovery failed; continuing with the next execution.', [
                            'team_id' => $row->team_id,
                            'execution_id' => $row->id,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        return new SchedulingPass($count, $failed);
    }
}
