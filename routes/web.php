<?php

/**
 * Register public browser routes for the initial application shell.
 */

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
