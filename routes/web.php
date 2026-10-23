<?php

/**
 * Register the public entry point and explicit browser authentication surface.
 */

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

require __DIR__.'/auth.php';

require __DIR__.'/teams.php';

require __DIR__.'/monitors.php';

require __DIR__.'/admin.php';
