<?php

use App\Http\Controllers\HealthController as H;
use Illuminate\Support\Facades\Route;

/*
| PLAT-12: unauthenticated, tenant-independent health and status endpoints (see ResolveTenant, which lets exactly these paths
| through on any host). They expose component states only, never a firm's name or data.
*/
Route::middleware('throttle:120,1,health')->group(function () {
    Route::get('/health/live', [H::class, 'live']);
    Route::get('/health/ready', [H::class, 'ready']);
    Route::get('/status.json', [H::class, 'json']);
    Route::get('/status', [H::class, 'page']);
});
