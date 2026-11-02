<?php

declare(strict_types=1);

/** Run an independent PostgreSQL participant for subscription, publication and delivery admission races. */

use App\Actions\Incidents\ManageIncidents;
use App\Models\StatusPage;
use App\Models\Team;
use App\Services\StatusPages\StatusEmailLedger;
use App\Services\StatusPages\StatusSubscriptions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
DB::statement("SET application_name = 'signalbox-status-email-probe'");
DB::statement("SET lock_timeout = '8s'");
CarbonImmutable::setTestNow(CarbonImmutable::parse((string) getenv('STATUS_EMAIL_PROBE_NOW')));
$mode = $argv[1];
$teamId = (int) $argv[2];
$targetId = (int) $argv[3];

if ($mode === 'subscribe') {
    app(TenantContext::class)->run($teamId, function () use ($targetId): void {
        $page = StatusPage::query()->findOrFail($targetId);
        app(StatusSubscriptions::class)->subscribe($page, app('session.store'), 'race@example.test');
    });
} elseif ($mode === 'publish') {
    app(ManageIncidents::class)->publish(Team::query()->findOrFail($teamId)->owner, $teamId, $targetId);
} else {
    $ledger = app(StatusEmailLedger::class);
    $token = (string) Str::uuid();
    $attempt = $ledger->acquire($teamId, $targetId, $token);

    if ($attempt !== null) {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Email delivery was admitted inside a transaction.');
        }

        file_put_contents($argv[4], "accepted\n", FILE_APPEND | LOCK_EX);
        // Keep the accepted provider call in flight until the duplicate participant has returned.
        DB::select('SELECT pg_advisory_lock(178804, ?)', [$targetId]);
        DB::select('SELECT pg_advisory_unlock(178804, ?)', [$targetId]);
        $ledger->finish($teamId, $targetId, $token, true);
    }
}

fwrite(STDOUT, json_encode(['completed' => true], JSON_THROW_ON_ERROR));
