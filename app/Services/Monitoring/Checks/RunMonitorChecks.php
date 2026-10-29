<?php

declare(strict_types=1);

/** Orchestrate admission, concurrent protocol strategies and fenced atomic observation persistence. */

namespace App\Services\Monitoring\Checks;

use App\Contracts\Monitoring\BatchCheckWorkConsumer;
use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckInput;
use App\Data\Monitoring\Checks\PreparedCheck;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorType;
use App\Models\CheckExecution;
use App\Models\Monitor;
use App\Models\MonitorHeartbeat;
use App\Models\Team;
use App\Services\Monitoring\MonitorConfigurationMapper;
use App\Services\Monitoring\Results\ResultPartitions;
use App\Services\Monitoring\Scheduling\ExecutionEligibility;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/** Network work starts only after every admission transaction has committed. */
final readonly class RunMonitorChecks implements BatchCheckWorkConsumer
{
    /**
     * @param ExecutionLedger $ledger Atomic acquisition and terminal result writer.
     * @param TenantContext $tenants Restored explicit worker scope.
     * @param ResultPartitions $partitions Prepare DDL before domain locks.
     * @param CheckRuntimeLimits $limits Validated worker timing envelope.
     * @param HeartbeatSnapshotReader $snapshots Historical receipt reader.
     * @param HeartbeatStrategy $heartbeat Pure heartbeat strategy.
     * @param HttpCheckCoordinator $http Concurrent HTTP and keyword transport.
     * @param NetworkCheckCoordinator $network Concurrent TLS and DNS transport.
     * @param ExecutionEligibility $eligibility Revalidate legacy acquired inputs under the same domain locks.
     */
    public function __construct(
        private ExecutionLedger $ledger,
        private TenantContext $tenants,
        private ResultPartitions $partitions,
        private CheckRuntimeLimits $limits,
        private HeartbeatSnapshotReader $snapshots,
        private HeartbeatStrategy $heartbeat,
        private HttpCheckCoordinator $http,
        private NetworkCheckCoordinator $network,
        private ExecutionEligibility $eligibility,
    ) {
    }

    /** @param CheckExecution $execution Legacy admitted envelope; capture is revalidated inside its existing fence. */
    public function consume(CheckExecution $execution): void
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return;
        }

        $this->limits->batchSize();
        $prepared = $this->tenants->run($execution->team_id, fn (): ?PreparedCheck => DB::transaction(function () use ($execution): ?PreparedCheck {
            $team = Team::query()->lockForUpdate()->find($execution->team_id);
            $monitor = Monitor::query()->lockForUpdate()->find($execution->monitor_id);
            $work = CheckExecution::query()->lockForUpdate()->find($execution->id);

            if ($work === null || $work->state !== 'running' || $work->acquisition_token !== $execution->acquisition_token) {
                return null;
            }

            if ($team === null || $monitor === null || $monitor->execution_revision !== $work->monitor_revision
                || $work->deadline_at === null || $work->deadline_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
                || !$this->eligibility->allows($team, $monitor, CarbonImmutable::now('UTC'))) {
                return null;
            }

            return $this->snapshot($work);
        }));

        if ($prepared !== null) {
            try {
                $this->tenants->run($execution->team_id, fn () => $this->execute([$prepared]));
            } catch (Throwable $exception) {
                $this->ledger->finish($execution, true);
                throw $exception;
            }
        } else {
            $this->ledger->finish($execution, true);
        }
    }

    /**
     * @param int $teamId Explicit tenant boundary.
     * @param array<int, int> $executions Execution IDs mapped to distinct monitor IDs.
     * @param string $region Expected regional identity.
     * @param string|null $uuid Broker ownership fence.
     * @param int|null $attempt Broker attempt fence.
     * @throws Throwable After durably failing owned work on infrastructure errors.
     */
    public function consumeBatch(int $teamId, array $executions, string $region, ?string $uuid, ?int $attempt): void
    {
        if (config('monitor-scheduling.enabled') !== true) {
            return;
        }

        if ($executions === [] || count($executions) > $this->limits->batchSize()
            || count(array_unique($executions)) !== count($executions) || !in_array($region, ['eu', 'us'], true)) {
            throw new InvalidArgumentException('Invalid regional batch envelope.');
        }

        $this->tenants->run($teamId, function () use ($teamId, $executions, $region, $uuid, $attempt): void {
            $prepared = [];

            try {
                foreach ($executions as $id => $monitorId) {
                    $identity = CheckExecution::query()->find($id);

                    if ($identity === null || $identity->monitor_id !== $monitorId || $identity->region !== $region) {
                        continue;
                    }

                    $this->partitions->ensure($identity->slot_at);
                    $snapshotFailure = null;
                    $item = DB::transaction(function () use ($teamId, $id, $uuid, $attempt, &$snapshotFailure): ?PreparedCheck {
                        $work = $this->ledger->acquire($teamId, $id, $uuid, $attempt);

                        if ($work === null) {
                            return null;
                        }

                        try {
                            return $this->snapshot($work);
                        } catch (Throwable $exception) {
                            // Commit a non-observation result before propagating private-input infrastructure failures.
                            $snapshotFailure = $exception;
                            $this->ledger->finish($work, true);

                            return null;
                        }
                    });

                    if ($snapshotFailure !== null) {
                        throw $snapshotFailure;
                    }

                    if ($item !== null) {
                        $prepared[] = $item;
                    }
                }

                $this->execute($prepared);
            } catch (Throwable $exception) {
                foreach ($prepared as $item) {
                    $this->ledger->finish($item->execution, true);
                }

                throw $exception;
            }
        });
    }

    /**
     * @param CheckExecution $work Execution locked in the admission transaction.
     * @return PreparedCheck Private immutable input.
     */
    private function snapshot(CheckExecution $work): PreparedCheck
    {
        $monitor = Monitor::query()->findOrFail($work->monitor_id);
        $context = new CheckContext($work->team_id, $work->monitor_id, $work->id, MonitorRegion::from($work->region), $work->slot_at);
        $input = new CheckInput($context, MonitorConfigurationMapper::configuration($monitor), $monitor->timeout_ms);
        $snapshot = null;

        if ($monitor->type === MonitorType::Heartbeat) {
            MonitorHeartbeat::query()->where('monitor_id', $monitor->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshots->read($context);
        }

        return new PreparedCheck($work, $input, $monitor->interval_seconds, $snapshot);
    }

    /** @param list<PreparedCheck> $prepared At most two observations with committed acquisition fences. */
    private function execute(array $prepared): void
    {
        $http = [];
        $network = [];
        $owners = [];

        foreach ($prepared as $item) {
            $owners[$item->execution->id] = $item->execution;

            if ($item->heartbeat !== null) {
                $this->ledger->finish($item->execution, false, $this->heartbeat->evaluate($item->input, $item->heartbeat, $item->intervalSeconds));
            } elseif (in_array($item->input->configuration->type(), [MonitorType::Http, MonitorType::Keyword], true)) {
                $http[] = $item->input;
            } else {
                $network[] = $item->input;
            }
        }

        foreach ([$http, $network] as $index => $inputs) {
            if ($inputs === []) {
                continue;
            }

            $outcomes = $index === 0 ? $this->http->run($inputs) : $this->network->run($inputs);

            foreach ($outcomes as $outcome) {
                $this->ledger->finish($owners[$outcome->context->executionId], false, $outcome);
            }
        }
    }
}
