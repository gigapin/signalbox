<?php

declare(strict_types=1);

/** Create bounded real worker subprocesses with exclusively guarded test-service coordinates. */

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;

/** Worker fixtures share Redis cache locks but never inherit development database or queue settings. */
final class EngineWorkerProbe
{
    /**
     * @param string $queue Synthetic Redis queue owned and cleaned by its test.
     * @param string $mode Controlled fake pipeline or real loopback transport.
     * @param int $jobs Maximum deliveries processed by this persistent worker.
     * @param string $origin Explicit synthetic loopback origin, empty for network-free mode.
     * @return Process Unstarted process using the same worker fixture as timeout and failure tests.
     */
    public static function make(string $queue, string $mode, int $jobs = 1, string $origin = ''): Process
    {
        return new Process([
            PHP_BINARY, base_path('tests/Fixtures/RunCheckWorkerProbe.php'), $queue, $mode, (string) $jobs,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'APP_NAME' => 'Signalbox',
            'APP_KEY' => (string) config('app.key'),
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => '5432',
            'DB_DATABASE' => TestDatabaseGuard::DATABASE,
            'DB_USERNAME' => TestDatabaseGuard::USERNAME,
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'DB_URL' => '',
            'REDIS_HOST' => (string) config('database.redis.default.host'),
            'REDIS_PORT' => '6379',
            'REDIS_URL' => '',
            'REDIS_PASSWORD' => 'null',
            'REDIS_PREFIX' => 'signalbox_test:',
            'REDIS_DB' => '0',
            'REDIS_CACHE_DB' => '1',
            'CACHE_STORE' => 'redis',
            'CACHE_PREFIX' => 'signalbox_engine_probe',
            'LOG_CHANNEL' => 'null',
            'CHECK_WORKER_NOW' => CarbonImmutable::now('UTC')->toIso8601String(),
            'CHECK_WORKER_ORIGIN' => $origin,
        ], timeout: 15);
    }
}
