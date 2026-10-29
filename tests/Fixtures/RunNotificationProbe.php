<?php

declare(strict_types=1);

/** Run one notification concurrency participant against guarded committed fixtures, with HTTP entirely faked. */

use App\Contracts\Security\HostResolver;
use App\Events\Incidents\IncidentNotificationRequested;
use App\Services\Notifications\DeliverNotification;
use App\Services\Notifications\PrepareIncidentNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\FakeHostResolver;
use Tests\Support\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::assertConfiguration(
    (string) $app->environment(),
    $app->configurationIsCached(),
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
TestDatabaseGuard::assertIdentity((string) DB::scalar('SELECT current_database()'), (string) DB::scalar('SELECT current_user'));
DB::statement("SET application_name = 'signalbox-notification-probe'");
DB::statement("SET lock_timeout = '8s'");
CarbonImmutable::setTestNow(CarbonImmutable::parse((string) getenv('NOTIFICATION_PROBE_NOW')));
$teamId = (int) $argv[2];
$targetId = (int) $argv[3];

if ($argv[1] === 'prepare') {
    $monitorId = (int) $argv[4];
    app(PrepareIncidentNotifications::class)->record(new IncidentNotificationRequested($teamId, $targetId, $monitorId, 0, 'incident.opened'));
} else {
    $receipts = $argv[4];
    app()->instance(HostResolver::class, new FakeHostResolver(['hooks.example.test' => ['93.184.216.34']]));
    Http::preventStrayRequests();
    Http::fake(function () use ($receipts, $targetId) {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('A provider request was made inside a transaction.');
        }

        file_put_contents($receipts, "accepted\n", FILE_APPEND | LOCK_EX);
        // The parent holds this real database barrier until the duplicate worker has returned without sending.
        DB::select('SELECT pg_advisory_lock(177503, ?)', [$targetId]);
        DB::select('SELECT pg_advisory_unlock(177503, ?)', [$targetId]);

        return Http::response('', 204);
    });
    app(DeliverNotification::class)->run($teamId, $targetId, (string) Str::uuid());
}

fwrite(STDOUT, json_encode(['completed' => true], JSON_THROW_ON_ERROR));
