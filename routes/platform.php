<?php

use App\Http\Controllers\BillingWebhookController;
use App\Http\Controllers\PlatformBillingController;
use App\Http\Controllers\PlatformConsoleController as C;
use App\Http\Controllers\PlatformPlansController;
use App\Http\Controllers\PlatformStatusController;
use App\Http\Controllers\PlatformSupportController as PS;
use Illuminate\Support\Facades\Route;

/*
| PLAT-7: platform-staff console. Mounted at /api/platform with `platform.host`: it answers only on PLATFORM_ADMIN_HOST in multi
| mode and is a 404 on every tenant host. Authentication is the staff bearer token, never a tenant session.
*/
Route::middleware('platform.host')->group(function () {
    // PLAT-4: provider payment callbacks. Authenticity = Stripe signature / secret URL token, not a staff session.
    Route::post('/webhooks/stripe', [BillingWebhookController::class, 'stripe'])->middleware('throttle:300,1,webhook-stripe');
    Route::post('/webhooks/mpesa/{token}', [BillingWebhookController::class, 'mpesa'])->middleware('throttle:300,1,webhook-mpesa');
    // Daraja refuses callback URLs containing "mpesa"/"safaricom": the URLs registered by this app use these neutral paths
    Route::post('/webhooks/c2b/{token}', [BillingWebhookController::class, 'mpesa'])->middleware('throttle:300,1,webhook-c2b');
    Route::post('/webhooks/stk/{token}/{number}', [BillingWebhookController::class, 'mpesaStk'])->middleware('throttle:300,1,webhook-stk');
    Route::get('/pay/{number}', [BillingWebhookController::class, 'pay'])->middleware('throttle:30,1,billing-pay');   // signed link in billing emails
    Route::get('/autopay/{subscription}', [BillingWebhookController::class, 'autopay'])->whereNumber('subscription')->middleware('throttle:30,1,billing-autopay');   // save a card
    Route::post('/login', [C::class, 'login'])->middleware('throttle:10,1,platform-login');
    // TEN-7: fleet migration dashboard. The page holds no data; it reads /fleet with the staff token the operator enters.
    Route::get('/dashboard', [C::class, 'dashboard']);
    Route::post('/login/mfa', [C::class, 'loginMfa'])->middleware('throttle:10,1,platform-login-mfa');   // SEC-12

    Route::middleware('platform.auth:view')->group(function () {
        Route::post('/logout', [C::class, 'logout']);
        Route::get('/me', [C::class, 'me']);
        Route::patch('/me', [C::class, 'updateMe'])->middleware('throttle:20,1,platform-account');
        Route::post('/me/password', [C::class, 'changePassword'])->middleware('throttle:5,1,platform-password');
        Route::get('/mfa', [C::class, 'mfaStatus']);                                  // SEC-12
        Route::post('/mfa/setup', [C::class, 'mfaSetup'])->middleware('throttle:10,1,platform-mfa-setup');
        Route::post('/mfa/enable', [C::class, 'mfaEnable'])->middleware('throttle:10,1,platform-mfa-enable');
        Route::post('/mfa/disable', [C::class, 'mfaDisable'])->middleware('throttle:10,1,platform-mfa-disable');
        Route::get('/fleet', [C::class, 'fleet']);
        Route::get('/cells', [C::class, 'cells']);   // TEN-4
        Route::get('/tenants', [C::class, 'tenants']);
        Route::get('/tenants/{slug}', [C::class, 'tenant']);
        Route::get('/audit', [C::class, 'audit']);
        Route::get('/health', [PlatformStatusController::class, 'health']);   // PLAT-12
        Route::get('/sla', [PlatformStatusController::class, 'sla']);
        Route::get('/incidents', [PlatformStatusController::class, 'incidents']);
        Route::get('/tenants/{slug}/integrations', [C::class, 'integrations']);
        Route::get('/tenants/{slug}/flags', [C::class, 'flags']);   // PLAT-3
        Route::get('/tenants/{slug}/billing', [PlatformBillingController::class, 'show']);   // PLAT-4
        Route::get('/billing/invoices/{number}', [PlatformBillingController::class, 'invoice']);
        Route::get('/billing/funnel', [PlatformBillingController::class, 'funnel']);   // PLAT-13
        Route::get('/billing/subscriptions', [PlatformBillingController::class, 'subscriptions']);
        Route::get('/billing/invoices', [PlatformBillingController::class, 'invoices']);
        Route::get('/plans', [PlatformPlansController::class, 'index']);
    });

    Route::middleware('platform.auth:provision')->post('/tenants', [C::class, 'provision']);

    Route::middleware('platform.auth:operate')->group(function () {
        Route::post('/incidents', [PlatformStatusController::class, 'open']);   // PLAT-12
        Route::post('/incidents/{id}/updates', [PlatformStatusController::class, 'update']);
        Route::post('/tenants/{slug}/suspend', [C::class, 'suspend']);
        Route::post('/tenants/{slug}/resume', [C::class, 'resume']);
        Route::post('/tenants/{slug}/migrate', [C::class, 'migrate']);
        Route::post('/tenants/{slug}/backup', [C::class, 'backup']);
        Route::put('/tenants/{slug}/integrations/{provider}', [C::class, 'setIntegration']);
        Route::put('/tenants/{slug}/flags', [C::class, 'setFlags']);   // PLAT-3
        Route::post('/plans', [PlatformPlansController::class, 'store']);
        Route::put('/plans/{code}', [PlatformPlansController::class, 'update']);
        Route::post('/plans/{code}/archive', [PlatformPlansController::class, 'archive']);
        Route::post('/plans/{code}/restore', [PlatformPlansController::class, 'restore']);
        Route::post('/tenants/{slug}/subscription', [PlatformBillingController::class, 'subscribe']);   // PLAT-4
        Route::post('/tenants/{slug}/subscription/plan', [PlatformBillingController::class, 'changePlan']);
        Route::post('/tenants/{slug}/subscription/cancel', [PlatformBillingController::class, 'cancel']);
        Route::post('/tenants/{slug}/invoices', [PlatformBillingController::class, 'charge']);   // one-off invoice
        Route::post('/billing/run', [PlatformBillingController::class, 'runNow']);   // generate what is due now
        Route::post('/billing/invoices/{number}/payments', [PlatformBillingController::class, 'pay']);
        Route::post('/billing/invoices/{number}/mpesa', [PlatformBillingController::class, 'mpesa']);   // STK push to the firm's phone
        Route::delete('/tenants/{slug}/autopay', [PlatformBillingController::class, 'disableAutopay']);   // remove the saved card
    });

    // PLAT-8: support access. The firm's policy and approval, not the staff role, decide whether a session can happen.
    Route::middleware('platform.auth:impersonate')->group(function () {
        Route::get('/tenants/{slug}/support-sessions', [PS::class, 'index']);
        Route::post('/tenants/{slug}/support-sessions', [PS::class, 'request']);
        Route::post('/tenants/{slug}/support-sessions/{id}/start', [PS::class, 'start']);
        Route::post('/tenants/{slug}/support-sessions/{id}/end', [PS::class, 'end']);
    });

    Route::middleware('platform.auth:destructive')->group(function () {
        Route::delete('/plans/{code}', [PlatformPlansController::class, 'destroy']);
        Route::post('/tenants/{slug}/eject', [C::class, 'eject']);   // PLAT-9
        Route::post('/tenants/{slug}/offboard', [C::class, 'offboard']);
        Route::post('/tenants/{slug}/purge', [C::class, 'purge']);
        Route::get('/tenants/{slug}/final-export', [C::class, 'finalExport']);   // TEN-9: the offboarded firm's data, to hand over
        Route::post('/tenants/{slug}/legal-hold', [C::class, 'legalHold']);
    });
});
