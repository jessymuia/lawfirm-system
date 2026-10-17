<?php

use App\Http\Controllers\AppController;
use Illuminate\Support\Facades\Route;

// FUN-11: a client's invoice payment page (standalone; the link's secret is the authority)
Route::get('/pay/{token}', [\App\Http\Controllers\PayController::class, 'page'])->where('token', '[A-Za-z0-9]{40}');
// FUN-16: calendar subscription feeds (the secret link is the credential) and the calendar providers' return address
Route::get('/calendar/{token}.ics', [\App\Http\Controllers\CalendarController::class, 'ics'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:120,1,calendar-ics');
Route::get('/calendar/oauth/callback', [\App\Http\Controllers\CalendarController::class, 'callback'])->middleware('throttle:30,1,calendar-callback');
// FUN-14: a signer's signing page
Route::get('/sign/{token}', [\App\Http\Controllers\SignController::class, 'page'])->where('token', '[A-Za-z0-9]{48}');
// UI-16: arriving from another of the person's firms (a single-use, one-minute token is the credential)
Route::get('/switch/{token}', [\App\Http\Controllers\FirmSwitchController::class, 'redeem'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1,firm-switch');
// FUN-19: the web app manifest (per firm: it carries the firm's name)
Route::get('/manifest.webmanifest', [AppController::class, 'manifest']);
// FUN-10: the client portal (standalone page; clients sign in with a code texted to their phone)
Route::get('/portal', [\App\Http\Controllers\PortalController::class, 'page']);

Route::get('/{any}', [AppController::class, 'index'])
    // ENG-11: never serve the SPA shell for /api/*; unknown API paths must be a
    // JSON 404, not an HTML 200 that hides missing/removed routes.
    ->where('any', '^(?!api(/|$)).*$')
    ->name('login');
