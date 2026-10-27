<?php

declare(strict_types=1);

/** Create deterministic UTC monthly partitions before taking any execution or monitor row locks. */

namespace App\Services\Monitoring\Results;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** PostgreSQL advisory coordination serializes concurrent creators; no retention or dropping is performed here. */
final class ResultPartitions
{
    /** @return int Number of current and future months ensured, including already-existing partitions. */
    public function prepare(): int
    {
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        foreach (range(0, 2) as $offset) {
            $this->ensure($month->addMonths($offset));
        }

        return 3;
    }

    /**
     * @param CarbonImmutable $slot Scheduled reference selecting the immutable partition month.
     * @return string Internally generated, validated partition identifier.
     * @throws InvalidArgumentException When the year is outside the supported identifier range.
     * @throws LogicException When an existing relation has an unexpected parent or bounds.
     */
    public function ensure(CarbonImmutable $slot): string
    {
        $month = $slot->utc()->startOfMonth();

        if ($month->year < 1 || $month->year > 9999) {
            throw new InvalidArgumentException('Unsupported result partition year.');
        }

        $name = 'check_results_'.$month->format('Y_m');
        $from = $month->format('Y-m-d H:i:sP');
        $to = $month->addMonth()->format('Y-m-d H:i:sP');

        // Existing months, the common case for every ledger transition, need neither locks nor DDL.
        if (DB::selectOne('SELECT to_regclass(?) AS relation', [$name])?->relation !== null) {
            $this->assertBounds($name, $from, $to);

            return $name;
        }

        DB::transaction(function () use ($name, $from, $to): void {
            // SET LOCAL outlives a released savepoint, so the caller's timeout is restored before returning.
            $previous = (string) DB::scalar("SELECT current_setting('lock_timeout')");
            DB::statement("SET LOCAL lock_timeout = '2s'");
            DB::select('SELECT pg_advisory_xact_lock(1936287589, hashtext(?))', [$name]);
            // The fast-path miss may be cached across the advisory wait; IF NOT EXISTS rechecks under the schema lock.
            // Identifiers and bounds are derived solely from the validated UTC month, never from request text.
            DB::statement("CREATE TABLE IF NOT EXISTS {$name} PARTITION OF check_results FOR VALUES FROM ('{$from}') TO ('{$to}')");
            $this->assertBounds($name, $from, $to);
            DB::select("SELECT set_config('lock_timeout', ?, true)", [$previous]);
        });

        return $name;
    }

    /**
     * @param string $name Internally generated partition identifier.
     * @param string $from Inclusive UTC lower bound.
     * @param string $to Exclusive UTC upper bound.
     * @throws LogicException When the relation is not a check_results partition with exactly these month bounds.
     */
    private function assertBounds(string $name, string $from, string $to): void
    {
        $partition = DB::selectOne(<<<'SQL'
            SELECT pg_get_expr(child.relpartbound, child.oid) AS bounds
            FROM pg_inherits inheritance
            JOIN pg_class child ON child.oid = inheritance.inhrelid
            WHERE inheritance.inhparent = 'check_results'::regclass AND child.oid = to_regclass(?)
            SQL, [$name]);

        if ($partition === null || !is_string($partition->bounds)
            || !str_contains($partition->bounds, substr($from, 0, 10))
            || !str_contains($partition->bounds, substr($to, 0, 10))) {
            throw new LogicException('Unexpected result partition definition.');
        }
    }
}
