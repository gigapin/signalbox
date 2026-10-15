<?php

/**
 * Serve HTTP requests through the Signalbox application container.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/** Record the request start time in seconds for framework timing. */
define('LARAVEL_START', microtime(true));

/** @var string $maintenance Optional framework-generated maintenance response path. */
$maintenance = __DIR__.'/../storage/framework/maintenance.php';

if (file_exists($maintenance)) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app Application container dispatching the incoming request. */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
