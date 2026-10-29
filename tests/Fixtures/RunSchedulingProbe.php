<?php

declare(strict_types=1);

/** Execute a scheduling race participant against explicitly guarded, committed test fixtures. */

use App\Services\Monitoring\Scheduling\ExecutionLedger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
DB::statement("SET application_name = 'signalbox-scheduling-probe'");
DB::statement("SET lock_timeout = '8s'");
config(['monitor-scheduling.enabled' => true]);
CarbonImmutable::setTestNow(CarbonImmutable::parse((string) getenv('SCHEDULING_PROBE_NOW')));

$mode = $argv[1];
$teamId = (int) ($argv[2] ?? 0);
$workId = (int) ($argv[3] ?? 0);
$result = match ($mode) {
    'dispatch' => Artisan::call('monitors:dispatch'),
    'partition' => app(App\Services\Monitoring\Results\ResultPartitions::class)->ensure(CarbonImmutable::parse('2031-01-01T00:00:00Z')),
    'recover' => app(ExecutionLedger::class)->recover($teamId, $workId),
    'fail' => app(ExecutionLedger::class)->failDelivery($teamId, [$workId], '00000000-0000-4000-8000-000000000001', 1),
    'acquire' => app(ExecutionLedger::class)->acquire($teamId, $workId) !== null,
    'publish' => app(ExecutionLedger::class)->leasePublication($teamId, $workId) !== null,
    default => throw new InvalidArgumentException('Unknown scheduling probe mode.'),
};
fwrite(STDOUT, json_encode(['result' => $result], JSON_THROW_ON_ERROR));
