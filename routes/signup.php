<?php

use App\Http\Controllers\SignupController;
use Illuminate\Support\Facades\Route;

/*
| PLAT-13: self-service trial signup. Answers only on TENANCY_SIGNUP_HOST in multi mode (`signup.host`; 404 everywhere
| else). Never touches a firm: the request runs fail-closed and the provisioning job creates the firm.
*/
Route::middleware('signup.host')->group(function () {
    Route::get('/signup', [SignupController::class, 'page']);
    Route::get('/signup/verify', [SignupController::class, 'page']);

    Route::middleware('api')->prefix('api/signup')->group(function () {
        Route::get('/slug', [SignupController::class, 'slug'])->middleware('throttle:60,1,signup-slug');
        Route::post('/', [SignupController::class, 'store'])->middleware('throttle:5,60,signup-store');
        Route::post('/verify', [SignupController::class, 'verify'])->middleware('throttle:20,1,signup-verify');
        Route::get('/status', [SignupController::class, 'status'])->middleware('throttle:120,1,signup-status');
    });
});
