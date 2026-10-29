<?php

declare(strict_types=1);

/** Execute one incident race participant against explicitly guarded, committed test fixtures. */

use App\Actions\Incidents\ManageIncidents;
use App\Data\Monitoring\Checks\CheckContext;
use App\Data\Monitoring\Checks\CheckOutcome;
use App\Enums\Incidents\IncidentStatus;
use App\Enums\Monitoring\CheckError;
use App\Enums\Monitoring\MonitorRegion;
use App\Enums\Monitoring\MonitorStatus;
use App\Models\User;
use App\Services\Monitoring\Scheduling\ExecutionLedger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
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
DB::statement("SET application_name = 'signalbox-incident-probe'");
DB::statement("SET lock_timeout = '8s'");
config(['monitor-scheduling.enabled' => true]);
CarbonImmutable::setTestNow(CarbonImmutable::parse((string) getenv('INCIDENT_PROBE_NOW')));

/**
 * Acquire one regional execution and complete it with a genuine observation, as a regional worker would.
 *
 * @param int $teamId Committed owner.
 * @param int $workId Pending execution of that team.
 * @param MonitorStatus $status Observed target status; failures report an unexpected HTTP status.
 * @return bool Whether this participant acquired and completed the work.
 */
$observe = static function (int $teamId, int $workId, MonitorStatus $status): bool {
    $ledger = app(ExecutionLedger::class);
    $acquired = $ledger->acquire($teamId, $workId);

    if ($acquired === null) {
        return false;
    }

    $down = $status === MonitorStatus::Down;
    $context = new CheckContext($acquired->team_id, $acquired->monitor_id, $acquired->id, MonitorRegion::from($acquired->region), $acquired->slot_at);

    return $ledger->finish($acquired, false, new CheckOutcome(
        $context,
        $status,
        CarbonImmutable::now('UTC'),
        10.0,
        $down ? 503 : 200,
        $down ? CheckError::UnexpectedStatus : null,
    ));
};

$mode = $argv[1];
$teamId = (int) ($argv[2] ?? 0);
$targetId = (int) ($argv[3] ?? 0);
$actorId = (int) ($argv[4] ?? 0);
$result = match ($mode) {
    'down' => $observe($teamId, $targetId, MonitorStatus::Down),
    'up' => $observe($teamId, $targetId, MonitorStatus::Up),
    'resolve' => app(ManageIncidents::class)->postUpdate(User::query()->findOrFail($actorId), $teamId, $targetId, IncidentStatus::Resolved, 'Resolved manually during the race.')->id,
    default => throw new InvalidArgumentException('Unknown incident probe mode.'),
};
fwrite(STDOUT, json_encode(['result' => $result], JSON_THROW_ON_ERROR));
