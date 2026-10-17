<?php

use App\Http\Controllers\PlatformConsoleController;
use Illuminate\Support\Facades\Route;

/*
| PLAT-7: the staff-facing browser console is separate from the firm's SPA.
| ResolveTenant admits this path only on PLATFORM_ADMIN_HOST; this route
| middleware independently enforces the configured host as well.
*/
// "/" is the console only on the platform host; anywhere else it is the firm app's own front page.
Route::get('/', [\App\Http\Controllers\AppController::class, 'home'])->name('platform.home');

Route::middleware('platform.host')->get('/console', [PlatformConsoleController::class, 'console'])
    ->name('platform.console');
