<?php

declare(strict_types=1);

/** Run bounded real Redis workers with controlled transports, failures and tenant-isolation observations. */

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
$mode = $argv[2];
$targetAttempts = 0;

if (in_array($mode, ['pipeline', 'loopback'], true)) {
    $origin = (string) getenv('CHECK_WORKER_ORIGIN');
    $app->instance(HostResolver::class, new Tests\Support\FakeHostResolver([
        'example.invalid' => ['93.184.216.34'],
        'pinned.checker.test' => ['127.0.0.1'],
    ]));
    Illuminate\Support\Facades\Http::preventStrayRequests();

    if ($mode === 'pipeline') {
        Illuminate\Support\Facades\Http::fake(static function ($request) use (&$targetAttempts) {
            $targetAttempts++;
            if (preg_match('~/team/([0-9]+)/~', $request->url(), $matches) === 1
                && app(App\Services\Tenancy\TenantContext::class)->requireTeamId() !== (int) $matches[1]) {
                throw new LogicException('The worker leaked another tenant into target acquisition.');
            }

            if (str_contains($request->url(), '/infrastructure')) {
                throw new RuntimeException('Synthetic private worker failure.');
            }

            return Illuminate\Support\Facades\Http::response('healthy');
        });
    } else {
        if (preg_match('~\Ahttp://pinned\.checker\.test:[0-9]+\z~D', $origin) !== 1) {
            throw new InvalidArgumentException('The worker probe requires its explicit loopback origin.');
        }

        config(['outbound-security.allowed_cidrs' => ['127.0.0.1/32']]);
        Illuminate\Support\Facades\Http::allowStrayRequests([$origin.'/*']);
    }

    $app->make('events')->listen([
        Illuminate\Queue\Events\JobProcessed::class,
        Illuminate\Queue\Events\JobExceptionOccurred::class,
    ], static function (object $event) use (&$targetAttempts): void {
        try {
            app(App\Services\Tenancy\TenantContext::class)->requireTeamId();
            $clean = false;
        } catch (App\Exceptions\MissingTenantContext) {
            $clean = true;
        }

        fwrite(STDOUT, json_encode([
            'event' => class_basename($event),
            'uuid' => $event->job->payload()['uuid'],
            'context_clean' => $clean,
            'pid' => getmypid(),
            'target_attempts' => $targetAttempts,
        ], JSON_THROW_ON_ERROR)."\n");
        fflush(STDOUT);
    });
} else {
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
}

$app->make('events')->listen(Illuminate\Queue\Events\JobExceptionOccurred::class, static function (Illuminate\Queue\Events\JobExceptionOccurred $event): void {
    fwrite(STDERR, get_class($event->exception).': '.$event->exception->getMessage()."\n");
});
$app->make('queue.worker')->daemon('redis', $argv[1], new WorkerOptions(sleep: 0, maxTries: 1, force: true, stopWhenEmpty: true, maxJobs: (int) ($argv[3] ?? 1)));
