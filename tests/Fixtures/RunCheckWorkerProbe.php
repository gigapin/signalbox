<?php

declare(strict_types=1);

/** Run one real Redis worker with controlled infrastructure failure, timeout or external termination. */

use App\Contracts\Security\HostResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::assertConfiguration((string) $app->environment(), $app->configurationIsCached(), [
    'driver' => (string) config('database.default'),
    'database' => (string) config('database.connections.pgsql.database'),
    'username' => (string) config('database.connections.pgsql.username'),
    'host' => (string) config('database.connections.pgsql.host'),
    'url' => config('database.connections.pgsql.url'),
    'redis_host' => (string) config('database.redis.default.host'),
    'redis_url' => config('database.redis.default.url'),
]);
TestDatabaseGuard::assertIdentity((string) DB::scalar('SELECT current_database()'), (string) DB::scalar('SELECT current_user'));
config(['monitor-scheduling.enabled' => true]);
CarbonImmutable::setTestNow((string) getenv('CHECK_WORKER_NOW'));
$app->instance(HostResolver::class, new class ($argv[2]) implements HostResolver {
    /** @param string $mode Controlled infrastructure failure mechanism. */
    public function __construct(private readonly string $mode)
    {
    }

    /**
     * @param string $host Ignored synthetic destination.
     * @param int|null $timeoutMs Ignored for deliberate worker failure.
     * @return list<string> Never returns a network address.
     */
    public function resolve(string $host, ?int $timeoutMs = null): array
    {
        fwrite(STDOUT, "ACQUIRED\n");
        fflush(STDOUT);
        if ($this->mode === 'exception') {
            throw new RuntimeException('Synthetic private worker exception.');
        }
        // The timeout alarm or parent SIGKILL interrupts this bounded, test-only stalled acquisition.
        sleep(8);
        throw new RuntimeException('The controlled worker was not interrupted.');
    }
});
$app->make('events')->listen(Illuminate\Queue\Events\JobExceptionOccurred::class, static function (Illuminate\Queue\Events\JobExceptionOccurred $event): void {
    fwrite(STDERR, get_class($event->exception).': '.$event->exception->getMessage()."\n");
});
$app->make('queue.worker')->daemon('redis', $argv[1], new WorkerOptions(sleep: 0, maxTries: 1, force: true, stopWhenEmpty: true, maxJobs: 1));
