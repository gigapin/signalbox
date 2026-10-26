<?php

declare(strict_types=1);

/** Prepare current and near-future UTC result partitions without deleting historical data. */

namespace App\Console\Commands;

use App\Services\Monitoring\Results\ResultPartitions;
use Illuminate\Console\Command;

/** Daily preparation complements the ledger's on-demand slot-month preparation. */
final class PrepareResultPartitions extends Command
{
    /** @var string Explicit operational entry point. */
    protected $signature = 'monitors:partitions';
    /** @var string Operator-facing maintenance purpose. */
    protected $description = 'Prepare current and next two UTC check-result partitions';

    /**
     * @param ResultPartitions $partitions Serialized idempotent partition maintenance.
     * @return int Success after partition preparation.
     */
    public function handle(ResultPartitions $partitions): int
    {
        $this->info('Prepared: '.$partitions->prepare());
        return self::SUCCESS;
    }
}
