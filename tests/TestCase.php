<?php

/** Bootstrap Laravel tests and protect real-service suites before RefreshDatabase can run migrations. */

namespace Tests;

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;
use Tests\Support\TestDatabaseGuard;

/** Provide HTTP helpers, reject stray HTTP client calls and guard opt-in infrastructure access. */
abstract class TestCase extends BaseTestCase
{
    /**
     * Verify effective configuration and read-only PostgreSQL identity before migration traits execute.
     *
     * @return Application Bootstrapped test application, ready for isolated fixtures.
     * @throws \LogicException When cached or development service settings are detected.
     */
    public function createApplication(): Application
    {
        /** @var Application $application Laravel instance with PHPUnit's forced test settings applied. */
        $application = parent::createApplication();
        if ($application->configurationIsCached()) {
            throw new \LogicException('Clear cached application configuration before running tests.');
        }
        $application->make(Factory::class)->preventStrayRequests();

        if (getenv('RUN_DOCKER_TESTS') === '1') {
            TestDatabaseGuard::assertConfiguration(
                (string) $application->environment(),
                $application->configurationIsCached(),
                [
                    'driver' => (string) config('database.default'),
                    'database' => (string) config('database.connections.pgsql.database'),
                    'username' => (string) config('database.connections.pgsql.username'),
                    'host' => (string) config('database.connections.pgsql.host'),
                    'url' => config('database.connections.pgsql.url'),
                    'redis_host' => (string) config('database.redis.default.host'),
                    'redis_url' => config('database.redis.default.url'),
                ],
            );
            /** @var \Illuminate\Database\Connection $connection Test connection used only for read-only identity checks at this point. */
            $connection = $application->make(DatabaseManager::class)->connection();
            TestDatabaseGuard::assertIdentity(
                (string) $connection->scalar('SELECT current_database()'),
                (string) $connection->scalar('SELECT current_user'),
            );
        }

        return $application;
    }
}
