<?php

declare(strict_types=1);

/** Bound public-ingress lock waits without leaking timeout changes into a caller's outer transaction. */

namespace App\Services\Database;

use App\Exceptions\PublicIngressBusy;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** PostgreSQL savepoints restore failed attempts; successful nested calls restore their caller's setting explicitly. */
final class PublicIngressTransaction
{
    /** @var int Maximum wait per PostgreSQL lock acquisition, in milliseconds; not a request execution deadline. */
    public const int LOCK_TIMEOUT_MS = 250;

    /**
     * @template TResult
     * @param Closure(): TResult $operation Public database work with no provider calls or automatic retries.
     * @param-immediately-invoked-callable $operation
     * @return TResult Committed operation result, or a result still owned by the caller's outer transaction.
     * @throws PublicIngressBusy When PostgreSQL cannot obtain a lock within its effective bound.
     * @throws QueryException For other database failures, unchanged after transaction/savepoint rollback.
     * @throws \Throwable For domain errors, unchanged after transaction/savepoint rollback.
     */
    public function run(Closure $operation): mixed
    {
        $connection = DB::connection();

        try {
            return $connection->transaction(function () use ($connection, $operation): mixed {
                // pg_settings exposes lock_timeout in milliseconds, independently of SHOW's display unit.
                $previous = (int) $connection->scalar("SELECT setting FROM pg_settings WHERE name = 'lock_timeout'");
                $timeout = $previous === 0 ? self::LOCK_TIMEOUT_MS : min($previous, self::LOCK_TIMEOUT_MS);
                $connection->select("SELECT set_config('lock_timeout', ?, true)", [(string) $timeout]);
                $result = $operation();

                // A released savepoint does not undo SET LOCAL; restore before returning successfully.
                // On failure the transaction/savepoint rollback restores it, even after a SQL-aborted statement.
                $connection->select("SELECT set_config('lock_timeout', ?, true)", [(string) $previous]);

                return $result;
            });
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '55P03') {
                throw new PublicIngressBusy();
            }

            throw $error;
        }
    }
}
