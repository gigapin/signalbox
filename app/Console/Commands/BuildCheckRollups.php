<?php

declare(strict_types=1);

/** Expose incremental rollup progress and explicit rebuilds without placing domain rules in the console command. */

namespace App\Console\Commands;

use App\Exceptions\RollupSourceUnavailable;
use App\Models\Monitor;
use App\Models\Scopes\TeamScope;
use App\Services\Metrics\Rollups\BuildCheckRollups as Operation;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

/** Without options every monitor advances; together, the three options rebuild one monitor's range from raw results. */
final class BuildCheckRollups extends Command
{
    /** @var string Stable operational entry point. */
    protected $signature = 'metrics:rollups
        {--monitor= : Monitor identity to rebuild}
        {--from= : Inclusive UTC instant of the rebuild}
        {--to= : Exclusive UTC instant of the rebuild}';

    /** @var string Purpose displayed by artisan list. */
    protected $description = 'Advance hourly and daily check rollups, or rebuild one monitor range';

    /**
     * @param Operation $operation Cohesive rollup operation.
     * @return int Success when all work completed; failure when an item failed or the rebuild was refused.
     */
    public function handle(Operation $operation): int
    {
        $options = array_filter([$this->option('monitor'), $this->option('from'), $this->option('to')], static fn (mixed $option): bool => $option !== null);

        if ($options === []) {
            return $this->advanceAll($operation);
        }

        if (count($options) !== 3) {
            $this->error('A rebuild requires --monitor, --from and --to together.');

            return self::FAILURE;
        }

        return $this->rebuild($operation);
    }

    /**
     * @param Operation $operation Cohesive rollup operation.
     * @return int Success, or failure when any monitor failed while the others progressed.
     */
    private function advanceAll(Operation $operation): int
    {
        $pass = $operation->runPass();

        $this->info('Hours written: '.$pass->processed);

        if ($pass->failed > 0) {
            // Item details are logged by identity; the scheduler sees a failed run while healthy items progressed.
            $this->error('Failed: '.$pass->failed);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param Operation $operation Cohesive rollup operation.
     * @return int Success, or failure for invalid options, an unknown monitor or a refused rebuild.
     */
    private function rebuild(Operation $operation): int
    {
        // Operator tooling has no tenant context: identity is discovered here and enforced by the operation.
        $monitor = Monitor::withoutGlobalScope(TeamScope::class)
            ->select(['id', 'team_id'])
            ->find((int) $this->option('monitor'));

        if ($monitor === null) {
            $this->error('Unknown monitor.');

            return self::FAILURE;
        }

        $from = $this->option('from');
        $to = $this->option('to');

        // An empty text would otherwise parse as the current instant.
        if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
            $this->error('--from and --to must be valid instants.');

            return self::FAILURE;
        }

        try {
            $hours = $operation->rebuild(
                $monitor->team_id,
                $monitor->id,
                CarbonImmutable::parse($from, 'UTC'),
                CarbonImmutable::parse($to, 'UTC'),
            );
        } catch (InvalidFormatException) {
            $this->error('--from and --to must be valid instants.');

            return self::FAILURE;
        } catch (RollupSourceUnavailable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Hours rewritten: '.$hours);

        return self::SUCCESS;
    }
}
