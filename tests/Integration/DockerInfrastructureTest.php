<?php

/**
 * Exercise real local PostgreSQL and Redis only when explicitly requested in Docker.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it(
    'uses PostgreSQL 16 and rolls back transactional writes',
    /** Verify the required database engine and transactional behavior without persistent schema changes. */
    function (): void {
        expect(DB::getDriverName())->toBe('pgsql');
        expect((int) DB::scalar('SHOW server_version_num'))->toBeGreaterThanOrEqual(160000)->toBeLessThan(170000);

        DB::beginTransaction();

        try {
            DB::statement('CREATE TEMPORARY TABLE signalbox_docker_probe (id integer PRIMARY KEY) ON COMMIT DROP');
            DB::insert('INSERT INTO signalbox_docker_probe (id) VALUES (?)', [1]);
            expect((int) DB::scalar('SELECT count(*) FROM signalbox_docker_probe'))->toBe(1);
        } finally {
            DB::rollBack();
        }

        expect(DB::scalar("SELECT to_regclass('pg_temp.signalbox_docker_probe')"))->toBeNull();
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires the explicitly enabled local Docker infrastructure.');

it(
    'shares Redis cache and prevents concurrent lock ownership',
    /** Verify cache round trips and lock exclusion using unique disposable keys. */
    function (): void {
        /** @var string $key Unique probe namespace that cannot overwrite application data. */
        $key = 'docker-probe:'.bin2hex(random_bytes(12));
        /** @var \Illuminate\Contracts\Cache\Repository $cache Explicit Redis store despite in-memory PHPUnit defaults. */
        $cache = Cache::store('redis');
        /** @var \Illuminate\Contracts\Cache\Lock $lock First contender owning the shared lock for at most ten seconds. */
        $lock = Cache::store('redis')->lock($key.':lock', 10);

        try {
            $cache->put($key, 'ready', 10);
            expect($cache->get($key))->toBe('ready');
            expect($lock->get())->toBeTrue();
            expect(Cache::store('redis')->lock($key.':lock', 10)->get())->toBeFalse();
        } finally {
            $lock->release();
            $cache->forget($key);
        }
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires the explicitly enabled local Docker infrastructure.');

it(
    'reserves and deletes a Redis queue message without touching worker queues',
    /** Verify transport persistence; actual domain job processing belongs to later milestones. */
    function (): void {
        /** @var string $queue Disposable queue that no application worker consumes. */
        $queue = 'docker-probe:'.bin2hex(random_bytes(12));
        /** @var \Illuminate\Queue\RedisQueue $connection Real Redis transport selected independently of PHPUnit defaults. */
        $connection = Queue::connection('redis');

        try {
            $connection->pushRaw('{"id":"docker-probe","attempts":0,"displayName":"Docker transport probe","job":"probe","data":{}}', $queue);
            expect($connection->size($queue))->toBe(1);
            /** @var \Illuminate\Contracts\Queue\Job|null $job Reserved transport message; never executed as a domain job. */
            $job = $connection->pop($queue);
            expect($job)->not->toBeNull();
            $job?->delete();
            expect($connection->size($queue))->toBe(0);
        } finally {
            $connection->clear($queue);
        }
    },
)->group('docker')->skip(getenv('RUN_DOCKER_TESTS') !== '1', 'Requires the explicitly enabled local Docker infrastructure.');
