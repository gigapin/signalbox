<?php

/** Prevent integration migrations and fixtures from reaching development services. */

namespace Tests\Support;

use LogicException;

/** Validate configuration before connecting and validate server identity before any schema mutation. */
final class TestDatabaseGuard
{
    /** @var string DATABASE Dedicated disposable PostgreSQL database required by the integration suite. */
    public const string DATABASE = 'signalbox_test';

    /** @var string USERNAME Dedicated test account, never the development application's account. */
    public const string USERNAME = 'signalbox_test';

    /**
     * Reject cached state, connection URLs and development service destinations before opening a connection.
     *
     * @param string $environment Laravel environment, which must be testing.
     * @param bool $cached Whether Laravel loaded a compiled configuration file.
     * @param array{driver: string, database: string, username: string, host: string, url: mixed, redis_host: string, redis_url: mixed} $settings Effective connection settings.
     * @throws LogicException When integration execution could access application infrastructure.
     */
    public static function assertConfiguration(string $environment, bool $cached, array $settings): void
    {
        if ($environment !== 'testing' || $cached) {
            throw new LogicException('Integration tests require uncached testing configuration.');
        }

        if ($settings['driver'] !== 'pgsql'
            || $settings['database'] !== self::DATABASE
            || $settings['username'] !== self::USERNAME
            || ! in_array($settings['url'], [null, ''], true)
            || ! in_array($settings['redis_url'], [null, ''], true)
            || ! in_array($settings['host'], ['postgres-test', '127.0.0.1', 'localhost'], true)
            || ! in_array($settings['redis_host'], ['redis-test', '127.0.0.1', 'localhost'], true)) {
            throw new LogicException('Use the isolated php-test service or dedicated CI test services.');
        }
    }

    /**
     * Confirm the connected PostgreSQL server actually selected the dedicated test database and role.
     *
     * @param string $database Database name reported by current_database().
     * @param string $username Role name reported by current_user.
     * @throws LogicException When server identity differs from the declared disposable test identity.
     */
    public static function assertIdentity(string $database, string $username): void
    {
        if ($database !== self::DATABASE || $username !== self::USERNAME) {
            throw new LogicException('Connected PostgreSQL identity is not the dedicated test database and role.');
        }
    }
}
