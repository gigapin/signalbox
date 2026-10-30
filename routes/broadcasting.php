<?php

/** Expose only CSRF-protected session authorization for the application's known private team channel. */

use App\Http\Controllers\Broadcasting\BroadcastAuthorizationController;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

Route::post('/broadcasting/auth', BroadcastAuthorizationController::class)
    ->middleware([NoStoreAuthentication::class, 'auth:web', 'verified'])
    ->name('broadcasting.auth');
