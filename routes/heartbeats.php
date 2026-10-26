<?php

declare(strict_types=1);

/** Declare the dedicated bearer-only ingress outside the session, Inertia and CSRF web stack. */

use App\Http\Controllers\Monitoring\HeartbeatController;
use App\Http\Middleware\LimitHeartbeatIngress;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

Route::post('/heartbeats', HeartbeatController::class)
    ->middleware([NoStoreAuthentication::class, LimitHeartbeatIngress::class])
    ->name('heartbeats.receive');
