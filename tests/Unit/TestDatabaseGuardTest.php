<?php

/** Exercise destructive-test protection without booting Laravel or opening database connections. */

use Tests\Support\TestDatabaseGuard;

it(
    'accepts only explicit isolated test configuration',
    /** Verify the dedicated Docker identities and endpoints pass the pre-connection guard. */
    function (): void {
        TestDatabaseGuard::assertConfiguration('testing', false, [
            'driver' => 'pgsql', 'database' => 'signalbox_test', 'username' => 'signalbox_test',
            'host' => 'postgres-test', 'url' => null, 'redis_host' => 'redis-test', 'redis_url' => null,
        ]);
        TestDatabaseGuard::assertIdentity('signalbox_test', 'signalbox_test');
        expect(true)->toBeTrue();
    },
);

it(
    'rejects unsafe configuration before a connection can be opened',
    /**
     * @param string $environment Runtime environment under verification.
     * @param bool $cached Whether compiled application configuration was loaded.
     * @param array<string, mixed> $overrides Deliberately unsafe connection fields.
     */
    function (string $environment, bool $cached, array $overrides): void {
        /** @var array{driver: string, database: string, username: string, host: string, url: mixed, redis_host: string, redis_url: mixed} $settings Test settings with one unsafe case applied. */
        $settings = array_replace([
            'driver' => 'pgsql', 'database' => 'signalbox_test', 'username' => 'signalbox_test',
            'host' => 'postgres-test', 'url' => null, 'redis_host' => 'redis-test', 'redis_url' => null,
        ], $overrides);
        expect(
            /** Trigger only the pure pre-connection guard; no database manager is involved. */
            fn () => TestDatabaseGuard::assertConfiguration($environment, $cached, $settings),
        )->toThrow(LogicException::class);
    },
)->with([
    'production environment' => ['production', false, []],
    'cached configuration' => ['testing', true, []],
    'SQLite fallback' => ['testing', false, ['driver' => 'sqlite']],
    'application database' => ['testing', false, ['database' => 'signalbox']],
    'application role' => ['testing', false, ['username' => 'signalbox']],
    'application PostgreSQL service' => ['testing', false, ['host' => 'postgres']],
    'connection URL override' => ['testing', false, ['url' => 'pgsql://example.invalid/application']],
    'application Redis service' => ['testing', false, ['redis_host' => 'redis']],
    'Redis URL override' => ['testing', false, ['redis_url' => 'redis://example.invalid']],
]);

it(
    'rejects an unexpected connected server identity',
    /**
     * @param string $database Actual server database.
     * @param string $username Actual server role.
     */
    function (string $database, string $username): void {
        expect(
            /** Run the post-connection identity check against synthetic server responses. */
            fn () => TestDatabaseGuard::assertIdentity($database, $username),
        )->toThrow(LogicException::class);
    },
)->with([
    ['signalbox', 'signalbox_test'],
    ['signalbox_test', 'signalbox'],
]);
