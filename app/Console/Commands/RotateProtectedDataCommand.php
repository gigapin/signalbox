<?php

declare(strict_types=1);

/** Provide an explicit, resumable operator migration and a non-mutating key-retirement preflight. */

namespace App\Console\Commands;

use App\Services\Security\RotateProtectedData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Never reads keys from arguments or prints exception diagnostics, plaintext, addresses or URL capabilities. */
final class RotateProtectedDataCommand extends Command
{
    /** @var string CLI accepts only operational mode and bounded scan size, never root secrets. */
    protected $signature = 'security:rotate-data {--check : Report required work without changing stored data} {--batch=100 : Identities per scan chunk, from 1 through 1000}';

    /** @var string Human-readable purpose shown by Artisan help. */
    protected $description = 'Rewrap protected data and migrate digests before retiring configured historical keys';

    /**
     * @param RotateProtectedData $rotation Typed domain migration with ownership and write locks.
     * @return int Zero when migration/preflight has no outstanding retirement prerequisite; one otherwise.
     */
    public function handle(RotateProtectedData $rotation): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if (!is_int($batch)) {
            $this->error('The batch size must be between 1 and 1000.');

            return self::FAILURE;
        }
        $check = $this->option('check') === true;
        try {
            $connection = DB::connection();
            if ($connection->scalar('SELECT pg_try_advisory_lock(186974, 11)') !== true) {
                $this->error('Another protected data migration is already running.');

                return self::FAILURE;
            }
            try {
                $result = $rotation->run($check, $batch);
            } finally {
                $connection->select('SELECT pg_advisory_unlock(186974, 11)');
            }
        } catch (Throwable) {
            $this->error('Protected data migration stopped safely. Retain all configured keys and consult the rotation runbook.');

            return self::FAILURE;
        }
        $this->line('Changes '.($check ? 'required' : 'applied').': '.$result['changes']);
        $this->line('Live verification codes requiring historical keys: '.$result['pending_codes']);
        $this->line('This command does not validate elapsed URL/cookie lifetimes, backups or deployment quiescence.');

        return $result['pending_codes'] > 0 || ($check && $result['changes'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
