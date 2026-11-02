<?php

declare(strict_types=1);

/** Verify one committed claim in an independent PostgreSQL session with DNS entirely faked. */

use App\Actions\StatusPages\ManageStatusDomains;
use App\Contracts\Monitoring\DnsTransport;
use App\Models\StatusPage;
use App\Models\Team;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Query\Query;
use Tests\Support\FakeDnsTransport;
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
DB::statement("SET application_name = 'signalbox-status-domain-probe'");
DB::statement("SET lock_timeout = '8s'");
CarbonImmutable::setTestNow(CarbonImmutable::parse((string) getenv('STATUS_DOMAIN_PROBE_NOW')));
$teamId = (int) $argv[1];
$pageId = (int) $argv[2];
$page = app(TenantContext::class)->run($teamId, fn (): StatusPage => StatusPage::query()->findOrFail($pageId));
$name = '_signalbox.'.$page->custom_domain.'.';
$dns = new FakeDnsTransport();
$dns->answers[$name.'|'.Message::TYPE_TXT] = Message::createResponseWithAnswersForQuery(new Query($name, Message::TYPE_TXT, Message::CLASS_IN), [
    new Record($name, Message::TYPE_TXT, Message::CLASS_IN, 60, ['signalbox-verification='.$page->domain_challenge]),
]);
$dns->beforeReply = static function (): void {
    if (DB::transactionLevel() !== 0) {
        throw new LogicException('DNS ownership proof was requested inside a transaction.');
    }
};
$app->instance(DnsTransport::class, $dns);
$accepted = app(ManageStatusDomains::class)->verify(Team::query()->findOrFail($teamId)->owner, $teamId, $pageId);
fwrite(STDOUT, json_encode(['accepted' => $accepted], JSON_THROW_ON_ERROR));
