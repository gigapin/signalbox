<?php

declare(strict_types=1);

/** Serve the real Laravel kernel only inside the isolated TLS admission proxy probe. */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
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

// This test-only sentinel detects credentials forwarded through the public proxy without requiring a tenant fixture.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/_probe/header') {
    http_response_code(isset($_SERVER['HTTP_SIGNALBOXTLSSECRET']) ? 403 : 204);

    return;
}

$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
