<?php

declare(strict_types=1);

/** Publish committed outbox rows without holding database locks across broker I/O. */

namespace App\Services\Monitoring\Scheduling;

use App\Contracts\Monitoring\CheckWorkPublisher;
use App\Data\Monitoring\SchedulingPass;
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
        return $this->runPass()->processed;
    }

    /** @return SchedulingPass Confirmed publications and executions whose lease transition failed and will be retried. */
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
            ->where('state', 'pending')
            ->where('available_at', '<=', $now)
            ->where('expires_at', '>', $now)
            ->chunkById((int) config('monitor-scheduling.chunk_size'), function ($rows) use (&$count, &$failed): void {
                $groups = [];
                $capacity = app(\App\Services\Monitoring\Checks\CheckRuntimeLimits::class)->batchSize();

                foreach ($rows as $row) {
                    try {
                        $work = $this->ledger->leasePublication($row->team_id, $row->id);
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::error('Monitor execution lease failed; continuing with the next execution.', [
                            'team_id' => $row->team_id,
                            'execution_id' => $row->id,
                            'exception' => $exception::class,
                        ]);

                        continue;
                    }

                    if ($work === null) {
                        continue;
                    }

                    $key = $work->team_id.':'.$work->region;
                    $batch = $groups[$key] ?? [];

                    if (count($batch) >= $capacity || collect($batch)->contains('monitor_id', $work->monitor_id)) {
                        $count += $this->send($batch);
                        $batch = [];
                    }

                    $batch[] = $work;
                    $groups[$key] = $batch;
                }

                foreach ($groups as $batch) {
                    $count += $this->send($batch);
                }
            });

        return new SchedulingPass($count, $failed);
    }

    /**
     * @param list<CheckExecution> $batch Leased rows sharing team and region with distinct monitors.
     * @return int Confirmed row acknowledgements.
     */
    private function send(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        $first = $batch[0];
        $ids = [];

        foreach ($batch as $work) {
            $ids[$work->id] = $work->monitor_id;
        }

        try {
            $this->publisher->publishBatch($first->team_id, $ids, $first->region);
            $count = 0;

            foreach ($batch as $work) {
                $count += (int) $this->ledger->acknowledge($work);
            }

            return $count;
        } catch (Throwable) {
            // Broker exceptions can contain credentials; retain leases and log only safe identifiers.
            Log::warning('Monitor execution batch publication unconfirmed.', ['execution_ids' => array_keys($ids), 'team_id' => $first->team_id]);

            return 0;
        }
    }
}
