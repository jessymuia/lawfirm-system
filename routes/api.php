<?php

use App\Http\Controllers\APIController;
use App\Http\Controllers\AttorneyController;
use App\Http\Controllers\CasesController;
use App\Http\Controllers\EthicalScreenController;
use App\Http\Controllers\MatterAccessController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CourtController;
use App\Http\Controllers\CourtTypeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HearingController;
use App\Http\Controllers\HearingTypeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceItemController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RolesManagementController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TimeEntryController;
use App\Http\Controllers\TrustTransactionController;
use App\Http\Controllers\UserController;
use App\Constants\ModulePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
// SEC-14: browsers report Content-Security-Policy violations (unauthenticated by nature; throttled, size-limited)
Route::post('/csp-report', \App\Http\Controllers\CspReportController::class)->middleware('throttle:60,1,csp-report');
// UI-13: the firm's name, colour and logo for the sign-in page and the app shell (nothing private)
Route::get('/branding', [\App\Http\Controllers\BrandingController::class, 'show'])->middleware('throttle:120,1,branding');
Route::get('/branding/logo', [\App\Http\Controllers\BrandingController::class, 'logo'])->middleware('throttle:120,1,branding-logo');
Route::post('/login', [APIController::class, 'login']);
// SEC-12: second step of a login for a user with two-factor on (the challenge token is the credential; throttled).
Route::post('/login/mfa', [APIController::class, 'loginMfa'])->middleware('throttle:10,1,login-mfa');
// SEC-12: OpenID Connect single sign-on (public: the browser is redirected here by the identity provider; every step is state/nonce/PKCE bound).
Route::get('/sso/config', [\App\Http\Controllers\SsoController::class, 'publicConfig'])->middleware('throttle:30,1,sso-config');
Route::get('/sso/start', [\App\Http\Controllers\SsoController::class, 'start'])->middleware('throttle:20,1,sso-start');
Route::get('/sso/callback', [\App\Http\Controllers\SsoController::class, 'callback'])->middleware(['sso.session', 'throttle:20,1,sso-callback']);
// SEC-12: SAML 2.0. The IdP posts its signed response to the ACS (request id + relay state bound, single use); the metadata is public by design.
if (Route::getLastGroupPrefix() === 'api/v1') Route::post('/sso/saml/acs', [\App\Http\Controllers\SsoController::class, 'samlAcs'])->middleware(['sso.session', 'throttle:20,1,sso-saml-acs']);
if (Route::getLastGroupPrefix() === 'api/v1') Route::get('/sso/saml/metadata', [\App\Http\Controllers\SsoController::class, 'samlMetadata'])->middleware('throttle:30,1,sso-saml-metadata');
// SEC-12: SCIM 2.0 user provisioning for a firm's identity provider. Authenticated by a firm-issued bearer token (scim.token), users only.
if (Route::getLastGroupPrefix() === 'api/v1') Route::prefix('scim/v2')->middleware('scim.token')->controller(\App\Http\Controllers\ScimController::class)->group(function () {
    Route::get('/ServiceProviderConfig', 'serviceProviderConfig');
    Route::get('/ResourceTypes', 'resourceTypes');
    Route::get('/Schemas', 'schemas');
    Route::get('/Users', 'index');
    Route::post('/Users', 'store');
    Route::get('/Users/{id}', 'show')->whereNumber('id');
    Route::put('/Users/{id}', 'replace')->whereNumber('id');
    Route::patch('/Users/{id}', 'patch')->whereNumber('id');
    Route::delete('/Users/{id}', 'destroy')->whereNumber('id');
});
// FUN-11: a client paying an invoice online (public: the payment link's secret is the authority) and the providers' callbacks
Route::get('/pay/{token}', [\App\Http\Controllers\PayController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1,pay-show');
Route::post('/pay/{token}/mpesa', [\App\Http\Controllers\PayController::class, 'mpesa'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:6,1,pay-mpesa');
Route::post('/pay/{token}/card', [\App\Http\Controllers\PayController::class, 'card'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:6,1,pay-card');
Route::get('/pay/{token}/attempts/{attempt}', [\App\Http\Controllers\PayController::class, 'status'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('attempt')->middleware('throttle:120,1,pay-status');
Route::post('/pay/callback/mpesa/{secret}', [\App\Http\Controllers\PayController::class, 'mpesaCallback'])->where('secret', '[A-Za-z0-9]{40}')->middleware('throttle:300,1,pay-cb-mpesa');
Route::post('/pay/callback/stripe', [\App\Http\Controllers\PayController::class, 'stripeCallback'])->middleware('throttle:300,1,pay-cb-stripe');

// FUN-14: a signer reading and signing a document (public: the personal link's secret is the credential)
Route::prefix('sign/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->controller(\App\Http\Controllers\SignController::class)->group(function () {
    Route::get('/', 'show')->middleware('throttle:60,1,sign-show');
    Route::get('/document', 'document')->middleware('throttle:30,1,sign-document');
    Route::post('/', 'sign')->middleware('throttle:10,1,sign-sign');
    Route::post('/decline', 'decline')->middleware('throttle:10,1,sign-decline');
});

// FUN-15: inbound email for filing to matters (the secret in the URL is the credential; see docs/EMAIL_FILING.md)
Route::post('/inbound/email/{secret}', [\App\Http\Controllers\InboundEmailController::class, 'receive'])->where('secret', '[A-Za-z0-9_-]{32,128}')->middleware('throttle:600,1,inbound-email');

// FUN-10: the client portal (public sign-in by a texted code, then the portal session token; staff logins mean nothing here)
Route::prefix('portal')->middleware('entitled:client_portal')->controller(\App\Http\Controllers\PortalController::class)->group(function () {
    Route::post('/login', 'login')->middleware('throttle:5,10,portal-login');
    Route::post('/verify', 'verify')->middleware('throttle:10,10,portal-verify');
    Route::middleware('portal.session')->group(function () {
        Route::post('/logout', 'logout')->middleware('throttle:30,1,portal-logout');
        Route::get('/me', 'me')->middleware('throttle:120,1,portal-me');
        Route::get('/matters', 'matters')->middleware('throttle:120,1,portal-matters');
        Route::get('/matters/{case}', 'matter')->whereNumber('case')->middleware('throttle:120,1,portal-matter');
        Route::post('/matters/{case}/documents', 'upload')->whereNumber('case')->middleware('throttle:20,60,portal-upload');
        Route::post('/matters/{case}/messages', 'message')->whereNumber('case')->middleware('throttle:30,60,portal-message');
        Route::get('/documents/{share}', 'download')->whereNumber('share')->middleware('throttle:60,1,portal-download');
        Route::get('/invoices', 'invoices')->middleware('throttle:120,1,portal-invoices');
        Route::post('/invoices/{invoice}/pay', 'pay')->whereNumber('invoice')->middleware('throttle:10,10,portal-pay');
    });
});
// PLAT-11: the public, read-only API. Authenticated by a scoped API key (api.key), not a session; each route names its scope.
// Registered on /api/v1 only: new surface is never added to the deprecated unversioned alias.
if (Route::getLastGroupPrefix() === 'api/v1') Route::prefix('public')->group(function () {
    $P = \App\Http\Controllers\PublicApiController::class;
    Route::get('/clients', [$P, 'clients'])->middleware('api.key:clients:read');
    Route::get('/clients/{id}', [$P, 'client'])->middleware('api.key:clients:read');
    Route::get('/cases', [$P, 'cases'])->middleware('api.key:cases:read');
    Route::get('/cases/{id}', [$P, 'case'])->middleware('api.key:cases:read');
    Route::get('/hearings', [$P, 'hearings'])->middleware('api.key:hearings:read');
    Route::get('/hearings/{id}', [$P, 'hearing'])->middleware('api.key:hearings:read');
});
Route::post('/password-recovery', [APIController::class, 'passwordRecovery']);
Route::post('/password-reset', [APIController::class, 'reset_pass']);
// PLAT-8: platform support redeems a one-time code for a session (the code is the credential; throttled).
Route::post('/support/session', [\App\Http\Controllers\SupportAccessController::class, 'redeem'])->middleware('throttle:10,1,support-session');
Route::middleware(['auth:sanctum', 'support.session', 'authz.guard', 'authz:authenticated'])->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| ENG-11: deny-by-default authorization
|--------------------------------------------------------------------------
| Every route in this group MUST carry an `authz:*` declaration or it is
| refused with 403 (RequireAuthzDeclaration). Declare it one of three ways:
|
|   authz:permission,<perm>             a permission, enforced before the controller runs
|   authz:policy,<ability>,<Model>[,p]  the model's Policy (object-level rules), before the controller runs
|   authz:authenticated                 self-service endpoints for any signed-in user
*/
// SEC-12: sign-out and two-factor enrolment must stay reachable while the firm's policy blocks everything else.
Route::middleware(['auth:sanctum', 'support.session', 'authz.guard', 'authz:authenticated'])->group(function () {
    Route::post('/logout', [APIController::class, 'logout']);
    Route::get('/session', [APIController::class, 'session']);
    Route::get('/mfa', [\App\Http\Controllers\MfaController::class, 'status']);
    Route::post('/mfa/setup', [\App\Http\Controllers\MfaController::class, 'setup'])->middleware('throttle:10,1,mfa-setup');
    Route::post('/mfa/enable', [\App\Http\Controllers\MfaController::class, 'enable'])->middleware('throttle:10,1,mfa-enable');
    Route::post('/mfa/disable', [\App\Http\Controllers\MfaController::class, 'disable'])->middleware('throttle:10,1,mfa-disable');
    Route::post('/mfa/recovery-codes', [\App\Http\Controllers\MfaController::class, 'recoveryCodes'])->middleware('throttle:10,1,mfa-recovery-codes');
});

Route::middleware(['auth:sanctum', 'support.session', 'mfa.policy', 'authz.guard'])->group(function () {

    // ---- Self-service: any authenticated user ---------------------------
    Route::middleware('authz:authenticated')->group(function () {
        // PLAT-10: customer changelog and upgrade guides
        Route::get('/releases', [\App\Http\Controllers\ReleasesController::class, 'index']);
        Route::get('/releases/{version}', [\App\Http\Controllers\ReleasesController::class, 'show'])->where('version', '[0-9A-Za-z.+-]+');
        // FUN-19: the hearing-day view (my hearings today and tomorrow, my tasks due)
        Route::get('/today', [\App\Http\Controllers\TodayController::class, 'show']);
        // FUN-16: a person's own calendar feeds and Google / Microsoft connections
        Route::get('/calendar/feeds', [\App\Http\Controllers\CalendarController::class, 'feeds']);
        Route::post('/calendar/feeds', [\App\Http\Controllers\CalendarController::class, 'createFeed'])->middleware('throttle:10,1,calendar-feed');
        Route::delete('/calendar/feeds/{feed}', [\App\Http\Controllers\CalendarController::class, 'revokeFeed'])->whereNumber('feed');
        Route::get('/calendar/connections', [\App\Http\Controllers\CalendarController::class, 'connections']);
        Route::post('/calendar/connect/{provider}', [\App\Http\Controllers\CalendarController::class, 'connect'])->whereIn('provider', ['google', 'microsoft'])->middleware('throttle:10,1,calendar-connect');
        Route::post('/calendar/connections/{connection}/sync', [\App\Http\Controllers\CalendarController::class, 'sync'])->whereNumber('connection')->middleware('throttle:6,1,calendar-sync');
        Route::delete('/calendar/connections/{connection}', [\App\Http\Controllers\CalendarController::class, 'disconnect'])->whereNumber('connection');
        // UI-16: the person's firms, and moving to another of them
        Route::get('/firms', [\App\Http\Controllers\FirmSwitchController::class, 'index']);
        Route::post('/firms/{firm}/switch', [\App\Http\Controllers\FirmSwitchController::class, 'switch'])->where('firm', '[a-z0-9-]{1,63}')->middleware('throttle:20,1,firm-switch-issue');
        // FUN-13: UTBMS task, activity and expense codes for every form
        Route::get('/utbms', [\App\Http\Controllers\EBillingController::class, 'codes']);
        // FUN-18: office / practice group names for every form
        Route::get('/organisation/options', [\App\Http\Controllers\OrganisationController::class, 'options']);
        // PLAT-13: the "getting started" checklist
        Route::get('/onboarding', [\App\Http\Controllers\OnboardingController::class, 'show']);
        Route::post('/onboarding/dismiss', [\App\Http\Controllers\OnboardingController::class, 'dismiss']);
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
        // Staff id+name dropdown used by every case/task form (no other fields).
        Route::get('/advocates', [AttorneyController::class, 'getAdvocateUsers']);
        // PLAT-2: license state for the banner (any signed-in user).
        Route::get('/license', fn (\App\Licensing\LicenseManager $l) => response()->json($l->status()->toArray()));
        // PLAT-3: which flags are on for this firm (the SPA hides/shows by these; the server enforces them itself).
        Route::get('/flags', fn (\App\Flags\Flags $f) => response()->json(['data' => collect($f->all())->map->value]));
        // PLAT-1: what this firm's plan includes (for the SPA to hide/lock features).
        Route::get('/entitlements', function (\App\Entitlements\Entitlements $e, \App\Observability\UsageMetrics $usage) {
            $set = $e->set();

            return response()->json($set->toArray() + [
                'usage' => ['users' => $usage->current('users'), 'storage_bytes' => $usage->current('storage_bytes')],
            ]);
        });
    });

    // ---- Enforced at the route: permission per action -------------------
    // index -> list, store -> create, update -> update, destroy -> delete.
    // The scaffold create/show/edit actions are empty in these controllers, so
    // they are deliberately not routed at all.
    $permissionResource = function (string $name, string $controller, string $list, string $create, string $update, string $delete) {
        foreach ([
            [['index'], $list],
            [['store'], $create],
            [['update'], $update],
            [['destroy'], $delete],
        ] as [$actions, $permission]) {
            Route::resource($name, $controller)->only($actions)->middleware("authz:permission,{$permission}");
        }
    };

    // ENG-16: capped id+name lists for dropdowns (list endpoints themselves are always paginated)
    Route::get('/courttypes/options', [CourtTypeController::class, 'options'])->middleware('authz:permission,'.ModulePermissions::LIST_COURTS);
    Route::get('/hearingtypes/options', [HearingTypeController::class, 'options'])->middleware('authz:permission,'.ModulePermissions::LIST_HEARINGS);
    Route::get('/case-types/options', [\App\Http\Controllers\CaseTypeController::class, 'options'])->middleware('authz:permission,'.ModulePermissions::LIST_CASES);   // DATA-12
    Route::get('/expense-categories/options', [ExpenseCategoryController::class, 'options'])->middleware('authz:permission,'.ModulePermissions::LIST_EXPENSES);

    $permissionResource('courttypes', CourtTypeController::class,
        ModulePermissions::LIST_COURTS, ModulePermissions::CREATE_COURTS, ModulePermissions::UPDATE_COURTS, ModulePermissions::DELETE_COURTS);
    $permissionResource('courts', CourtController::class,
        ModulePermissions::LIST_COURTS, ModulePermissions::CREATE_COURTS, ModulePermissions::UPDATE_COURTS, ModulePermissions::DELETE_COURTS);
    $permissionResource('hearingtypes', HearingTypeController::class,
        ModulePermissions::LIST_HEARINGS, ModulePermissions::CREATE_HEARINGS, ModulePermissions::UPDATE_HEARINGS, ModulePermissions::DELETE_HEARINGS);
    $permissionResource('expense-categories', ExpenseCategoryController::class,
        ModulePermissions::LIST_EXPENSES, ModulePermissions::CREATE_EXPENSES, ModulePermissions::UPDATE_EXPENSES, ModulePermissions::DELETE_EXPENSES);

    // ---- Clients, cases, invoices: enforced at the route ---------------
    $P = ModulePermissions::class;
    $perm = fn (string $p) => "authz:permission,{$p}";

    Route::get('/clients', [ClientController::class, 'index'])->middleware($perm($P::LIST_CLIENTS));
    Route::get('/clientsDropDown', [ClientController::class, 'clientsDropDown'])->middleware($perm($P::LIST_CLIENTS));
    Route::get('/clients-check-phone', [ClientController::class, 'checkPhoneExists'])->middleware($perm($P::LIST_CLIENTS));
    Route::post('/clients', [ClientController::class, 'store'])->middleware($perm($P::CREATE_CLIENTS));
    Route::post('/clients/bulk-delete', [ClientController::class, 'destroyMany'])->middleware($perm($P::DELETE_CLIENTS));
    Route::post('/clients/{id}/restore', [ClientController::class, 'restore'])->middleware($perm($P::DELETE_CLIENTS));
    Route::get('/clients/{client}', [ClientController::class, 'show'])->middleware($perm($P::LIST_CLIENTS));
    Route::match(['put', 'patch'], '/clients/{client}', [ClientController::class, 'update'])->middleware($perm($P::UPDATE_CLIENTS));
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->middleware($perm($P::DELETE_CLIENTS));

    Route::get('/cases', [CasesController::class, 'index'])->middleware($perm($P::LIST_CASES));
    Route::get('/casesDropDown', [CasesController::class, 'dropDown'])->middleware($perm($P::LIST_CASES));
    Route::get('/cases-check-number', [CasesController::class, 'checkCaseNumberExists'])->middleware($perm($P::LIST_CASES));
    Route::post('/cases', [CasesController::class, 'store'])->middleware($perm($P::CREATE_CASES));
    Route::post('/cases/bulk-delete', [CasesController::class, 'destroyMany'])->middleware($perm($P::DELETE_CASES));
    Route::post('/cases/{id}/restore', [CasesController::class, 'restore'])->middleware($perm($P::DELETE_CASES));
    Route::get('/cases/{id}/activity', [CasesController::class, 'activity'])->middleware($perm($P::LIST_CASES));
    Route::put('/cases/{id}/status', [CasesController::class, 'transitionStatus'])->middleware($perm($P::UPDATE_CASES));
    Route::get('/cases/{case}', [CasesController::class, 'show'])->middleware($perm($P::LIST_CASES));
    // FUN-9: the matter's parties (adverse, co-party, related, opposing counsel) for conflict checks
    Route::get('/cases/{case}/parties', [\App\Http\Controllers\MatterPartyController::class, 'index'])->middleware($perm($P::LIST_CASES));
    Route::post('/cases/{case}/parties', [\App\Http\Controllers\MatterPartyController::class, 'store'])->middleware($perm($P::UPDATE_CASES));
    Route::put('/cases/{case}/parties/{party}', [\App\Http\Controllers\MatterPartyController::class, 'update'])->middleware($perm($P::UPDATE_CASES));
    Route::delete('/cases/{case}/parties/{party}', [\App\Http\Controllers\MatterPartyController::class, 'destroy'])->middleware($perm($P::UPDATE_CASES));

    // FUN-10: the firm's side of the client portal
    Route::middleware(['entitled:client_portal', $perm($P::MANAGE_CLIENT_PORTAL)])->controller(\App\Http\Controllers\ClientPortalController::class)->group(function () {
        Route::get('/clients/{client}/portal', 'access')->whereNumber('client');
        Route::put('/clients/{client}/portal', 'setAccess')->whereNumber('client')->middleware('throttle:30,1,portal-access');
        Route::get('/cases/{case}/portal', 'matter')->whereNumber('case');
        Route::post('/cases/{case}/portal/documents', 'share')->whereNumber('case');
        Route::delete('/cases/{case}/portal/documents/{share}', 'unshare')->whereNumber('case')->whereNumber('share');
        Route::post('/cases/{case}/portal/messages', 'message')->whereNumber('case')->middleware('throttle:60,1,portal-staff-message');
        Route::get('/client-portal/unread', 'unread');
    });

    // FUN-11: online payment links for an invoice
    Route::post('/invoices/{invoice}/payment-links', [\App\Http\Controllers\PaymentLinkController::class, 'store'])->whereNumber('invoice')->middleware([$perm($P::CREATE_PAYMENTS), 'throttle:30,1,payment-link']);
    Route::get('/invoices/{invoice}/payment-links', [\App\Http\Controllers\PaymentLinkController::class, 'index'])->whereNumber('invoice')->middleware($perm($P::LIST_PAYMENTS));
    Route::post('/invoices/{invoice}/pay/mpesa', [\App\Http\Controllers\PaymentLinkController::class, 'mpesa'])->whereNumber('invoice')->middleware([$perm($P::CREATE_PAYMENTS), 'throttle:10,1,staff-stk']);
    Route::post('/invoices/{invoice}/pay/card', [\App\Http\Controllers\PaymentLinkController::class, 'card'])->whereNumber('invoice')->middleware([$perm($P::CREATE_PAYMENTS), 'throttle:10,1,staff-card']);
    Route::get('/invoices/{invoice}/pay/attempts/{attempt}', [\App\Http\Controllers\PaymentLinkController::class, 'attempt'])->whereNumber('invoice')->whereNumber('attempt')->middleware($perm($P::LIST_PAYMENTS));
    Route::delete('/invoices/{invoice}/payment-links/{link}', [\App\Http\Controllers\PaymentLinkController::class, 'revoke'])->whereNumber('invoice')->whereNumber('link')->middleware($perm($P::CREATE_PAYMENTS));

    // UI-13: branding (logo, colour, letterhead)
    Route::get('/branding/settings', [\App\Http\Controllers\BrandingController::class, 'settings'])->middleware($perm($P::MANAGE_BRANDING));
    Route::put('/branding', [\App\Http\Controllers\BrandingController::class, 'update'])->middleware($perm($P::MANAGE_BRANDING));
    Route::post('/branding/logo', [\App\Http\Controllers\BrandingController::class, 'uploadLogo'])->middleware([$perm($P::MANAGE_BRANDING), 'throttle:20,1,branding-upload']);
    Route::delete('/branding/logo', [\App\Http\Controllers\BrandingController::class, 'removeLogo'])->middleware($perm($P::MANAGE_BRANDING));

    // DATA-14: currencies, exchange rates and tax rates
    Route::controller(\App\Http\Controllers\FinanceSettingsController::class)->prefix('finance')->group(function () use ($perm, $P) {
        Route::get('/settings', 'show')->middleware($perm($P::LIST_INVOICES));
        Route::put('/base-currency', 'baseCurrency')->middleware($perm($P::MANAGE_FINANCE_SETTINGS));
        Route::put('/numbering', 'numbering')->middleware($perm($P::MANAGE_FINANCE_SETTINGS));   // DATA-8
        Route::post('/currencies', 'currency')->middleware($perm($P::MANAGE_FINANCE_SETTINGS));
        Route::post('/exchange-rates', 'exchangeRate')->middleware($perm($P::MANAGE_FINANCE_SETTINGS));
        Route::delete('/exchange-rates/{id}', 'deleteExchangeRate')->whereNumber('id')->middleware($perm($P::MANAGE_FINANCE_SETTINGS));
        Route::post('/tax-rates', 'taxRate')->middleware($perm($P::MANAGE_FINANCE_SETTINGS));
        Route::put('/tax-rates/{id}', 'taxRate')->whereNumber('id')->middleware($perm($P::MANAGE_FINANCE_SETTINGS));
        Route::post('/tax-rates/{id}/archive', 'archiveTaxRate')->whereNumber('id')->middleware($perm($P::MANAGE_FINANCE_SETTINGS));
    });

    // FUN-17: partner reports (JSON, or CSV with ?format=csv)
    foreach (['summary', 'utilisation', 'realisation', 'wip', 'receivables', 'profitability'] as $report) {
        Route::get("/reports/{$report}", [\App\Http\Controllers\ReportsController::class, $report])->middleware([$perm($P::VIEW_PARTNER_REPORTS), "throttle:60,1,report-{$report}", 'replica']);   // ENG-23
    }

    // FUN-18: the firm's offices and practice groups
    Route::get('/organisation', [\App\Http\Controllers\OrganisationController::class, 'index'])->middleware($perm($P::MANAGE_ORGANISATION));
    Route::post('/organisation/offices', [\App\Http\Controllers\OrganisationController::class, 'storeOffice'])->middleware($perm($P::MANAGE_ORGANISATION));
    Route::put('/organisation/offices/{office}', [\App\Http\Controllers\OrganisationController::class, 'updateOffice'])->whereNumber('office')->middleware($perm($P::MANAGE_ORGANISATION));
    Route::delete('/organisation/offices/{office}', [\App\Http\Controllers\OrganisationController::class, 'destroyOffice'])->whereNumber('office')->middleware($perm($P::MANAGE_ORGANISATION));
    Route::post('/organisation/practice-groups', [\App\Http\Controllers\OrganisationController::class, 'storeGroup'])->middleware($perm($P::MANAGE_ORGANISATION));
    Route::put('/organisation/practice-groups/{group}', [\App\Http\Controllers\OrganisationController::class, 'updateGroup'])->whereNumber('group')->middleware($perm($P::MANAGE_ORGANISATION));
    Route::delete('/organisation/practice-groups/{group}', [\App\Http\Controllers\OrganisationController::class, 'destroyGroup'])->whereNumber('group')->middleware($perm($P::MANAGE_ORGANISATION));
    // FUN-14: document templates, documents made from them, and e-signature
    Route::get('/document-templates', [\App\Http\Controllers\DocumentTemplateController::class, 'index'])->middleware($perm($P::LIST_DOCUMENTS));
    Route::post('/document-templates', [\App\Http\Controllers\DocumentTemplateController::class, 'store'])->middleware($perm($P::MANAGE_DOCUMENT_TEMPLATES));
    Route::post('/document-templates/{template}', [\App\Http\Controllers\DocumentTemplateController::class, 'update'])->whereNumber('template')->middleware($perm($P::MANAGE_DOCUMENT_TEMPLATES));
    Route::delete('/document-templates/{template}', [\App\Http\Controllers\DocumentTemplateController::class, 'archive'])->whereNumber('template')->middleware($perm($P::MANAGE_DOCUMENT_TEMPLATES));
    Route::get('/document-templates/{template}/download', [\App\Http\Controllers\DocumentTemplateController::class, 'download'])->whereNumber('template')->middleware($perm($P::MANAGE_DOCUMENT_TEMPLATES));
    Route::post('/cases/{case}/documents/from-template', [\App\Http\Controllers\DocumentTemplateController::class, 'assemble'])->whereNumber('case')->middleware([$perm($P::CREATE_DOCUMENTS), 'throttle:60,1,document-assemble']);
    Route::get('/cases/{case}/signatures', [\App\Http\Controllers\SignatureController::class, 'index'])->whereNumber('case')->middleware($perm($P::LIST_DOCUMENTS));
    Route::post('/cases/{case}/signatures', [\App\Http\Controllers\SignatureController::class, 'store'])->whereNumber('case')->middleware([$perm($P::CREATE_DOCUMENTS), 'throttle:30,1,signature-send']);
    Route::post('/cases/{case}/signatures/{request}/remind', [\App\Http\Controllers\SignatureController::class, 'remind'])->whereNumber('case')->whereNumber('request')->middleware([$perm($P::CREATE_DOCUMENTS), 'throttle:10,1,signature-remind']);
    Route::post('/cases/{case}/signatures/{request}/cancel', [\App\Http\Controllers\SignatureController::class, 'cancel'])->whereNumber('case')->whereNumber('request')->middleware($perm($P::CREATE_DOCUMENTS));

    // FUN-15: email filed to matters
    Route::get('/cases/{case}/emails', [\App\Http\Controllers\MatterEmailController::class, 'index'])->whereNumber('case')->middleware($perm($P::LIST_DOCUMENTS));
    Route::get('/cases/{case}/emails/{email}', [\App\Http\Controllers\MatterEmailController::class, 'show'])->whereNumber('case')->whereNumber('email')->middleware($perm($P::LIST_DOCUMENTS));
    Route::get('/cases/{case}/emails/{email}/raw', [\App\Http\Controllers\MatterEmailController::class, 'raw'])->whereNumber('case')->whereNumber('email')->middleware($perm($P::VIEW_DOCUMENTS));
    Route::post('/cases/{case}/emails', [\App\Http\Controllers\MatterEmailController::class, 'upload'])->whereNumber('case')->middleware([$perm($P::CREATE_DOCUMENTS), 'throttle:60,1,email-upload']);
    Route::delete('/cases/{case}/emails/{email}', [\App\Http\Controllers\MatterEmailController::class, 'destroy'])->whereNumber('case')->whereNumber('email')->middleware($perm($P::DELETE_DOCUMENTS));
    Route::get('/email-filing/settings', [\App\Http\Controllers\MatterEmailController::class, 'settings'])->middleware($perm($P::LIST_DOCUMENTS));
    Route::put('/email-filing/settings', [\App\Http\Controllers\MatterEmailController::class, 'saveSettings'])->middleware($perm($P::MANAGE_ORGANISATION));

    // FUN-19: an outcome (and the next date) recorded from court
    Route::post('/hearings/{hearing}/outcome', [\App\Http\Controllers\TodayController::class, 'outcome'])->whereNumber('hearing')->middleware([$perm($P::UPDATE_HEARINGS), 'throttle:60,1,hearing-outcome']);

    // FUN-16: the court diary
    Route::get('/court-diary', [\App\Http\Controllers\CalendarController::class, 'diary'])->middleware($perm($P::LIST_HEARINGS));

    // FUN-13: the firm's LEDES identifiers, and an issued invoice as a LEDES 1998B e-bill
    Route::get('/ebilling/settings', [\App\Http\Controllers\EBillingController::class, 'settings'])->middleware($perm($P::LIST_INVOICES));
    Route::put('/ebilling/settings', [\App\Http\Controllers\EBillingController::class, 'saveSettings'])->middleware($perm($P::MANAGE_ORGANISATION));
    Route::get('/invoices/{invoice}/ledes/check', [\App\Http\Controllers\EBillingController::class, 'check'])->whereNumber('invoice')->middleware($perm($P::LIST_INVOICES));
    Route::get('/invoices/{invoice}/ledes', [\App\Http\Controllers\EBillingController::class, 'export'])->whereNumber('invoice')->middleware([$perm($P::LIST_INVOICES), 'throttle:30,1,ledes-export']);
    Route::post('/organisation/assign', [\App\Http\Controllers\OrganisationController::class, 'assign'])->middleware($perm($P::MANAGE_ORGANISATION));

    // FUN-9: conflict-of-interest checks
    Route::post('/conflicts/check', [\App\Http\Controllers\ConflictController::class, 'check'])->middleware([$perm($P::RUN_CONFLICT_CHECKS), 'throttle:60,1,conflict-check']);
    Route::get('/conflicts/policy', [\App\Http\Controllers\ConflictController::class, 'policy'])->middleware($perm($P::RUN_CONFLICT_CHECKS));
    Route::put('/conflicts/policy', [\App\Http\Controllers\ConflictController::class, 'updatePolicy'])->middleware($perm($P::MANAGE_CONFLICTS));
    Route::get('/conflicts', [\App\Http\Controllers\ConflictController::class, 'index'])->middleware($perm($P::MANAGE_CONFLICTS));
    Route::get('/conflicts/{id}', [\App\Http\Controllers\ConflictController::class, 'show'])->whereNumber('id')->middleware($perm($P::RUN_CONFLICT_CHECKS));
    Route::put('/conflicts/{id}/decision', [\App\Http\Controllers\ConflictController::class, 'decide'])->whereNumber('id')->middleware($perm($P::MANAGE_CONFLICTS));
    Route::match(['put', 'patch'], '/cases/{case}', [CasesController::class, 'update'])->middleware($perm($P::UPDATE_CASES));
    Route::delete('/cases/{case}', [CasesController::class, 'destroy'])->middleware($perm($P::DELETE_CASES));
    // SEC-10: who may see one matter. {case} binding is scoped, so a user cannot manage access to a matter they cannot see.
    Route::get('/cases/{case}/access', [MatterAccessController::class, 'index'])->middleware($perm($P::MANAGE_MATTER_ACCESS));
    Route::post('/cases/{case}/access', [MatterAccessController::class, 'store'])->middleware($perm($P::MANAGE_MATTER_ACCESS));
    Route::delete('/cases/{case}/access/{grant}', [MatterAccessController::class, 'destroy'])->middleware($perm($P::MANAGE_MATTER_ACCESS));
    // SEC-11: ethical walls. Reports read straight from the tables so compliance can see WHICH matters are walled without seeing their content.
    Route::get('/ethical-screens', [EthicalScreenController::class, 'index'])->middleware($perm($P::MANAGE_ETHICAL_SCREENS));
    Route::get('/ethical-screens/attempts', [EthicalScreenController::class, 'attempts'])->middleware($perm($P::MANAGE_ETHICAL_SCREENS));
    Route::post('/ethical-screens', [EthicalScreenController::class, 'store'])->middleware($perm($P::MANAGE_ETHICAL_SCREENS));
    Route::post('/ethical-screens/{id}/lift', [EthicalScreenController::class, 'lift'])->middleware($perm($P::MANAGE_ETHICAL_SCREENS));
    // PLAT-13: firm-side plan, trial, invoices and cancellation (open even while an ended trial is read-only).
    Route::get('/subscription', [\App\Http\Controllers\SubscriptionController::class, 'show'])->middleware($perm($P::MANAGE_SUBSCRIPTION));
    Route::post('/subscription/plan', [\App\Http\Controllers\SubscriptionController::class, 'choose'])->middleware([$perm($P::MANAGE_SUBSCRIPTION), 'throttle:10,1,subscription-plan']);
    Route::post('/subscription/cancel', [\App\Http\Controllers\SubscriptionController::class, 'cancel'])->middleware([$perm($P::MANAGE_SUBSCRIPTION), 'throttle:5,1,subscription-cancel']);

    // SEC-12: the firm's security policy and resetting a colleague's second factor.
    Route::get('/security/policy', [\App\Http\Controllers\MfaController::class, 'policy'])->middleware($perm($P::MANAGE_SECURITY_SETTINGS));
    Route::put('/security/policy', [\App\Http\Controllers\MfaController::class, 'updatePolicy'])->middleware($perm($P::MANAGE_SECURITY_SETTINGS));
    Route::get('/security/sso', [\App\Http\Controllers\SsoController::class, 'show'])->middleware([$perm($P::MANAGE_SECURITY_SETTINGS), 'entitled:sso']);
    Route::put('/security/sso', [\App\Http\Controllers\SsoController::class, 'update'])->middleware([$perm($P::MANAGE_SECURITY_SETTINGS), 'entitled:sso']);
    Route::get('/security/scim-tokens', [\App\Http\Controllers\ScimController::class, 'tokens'])->middleware([$perm($P::MANAGE_SECURITY_SETTINGS), 'entitled:sso']);
    Route::post('/security/scim-tokens', [\App\Http\Controllers\ScimController::class, 'createToken'])->middleware([$perm($P::MANAGE_SECURITY_SETTINGS), 'entitled:sso']);
    Route::delete('/security/scim-tokens/{id}', [\App\Http\Controllers\ScimController::class, 'revokeToken'])->whereNumber('id')->middleware($perm($P::MANAGE_SECURITY_SETTINGS));
    Route::delete('/security/sso', [\App\Http\Controllers\SsoController::class, 'destroy'])->middleware($perm($P::MANAGE_SECURITY_SETTINGS));
    Route::delete('/users/{user}/mfa', [\App\Http\Controllers\MfaController::class, 'reset'])->middleware($perm($P::MANAGE_SECURITY_SETTINGS));
    // SEC-13: legal holds, retention schedule and data-subject requests.
    Route::middleware($perm($P::MANAGE_COMPLIANCE))->prefix('compliance')->group(function () {
        $C = \App\Http\Controllers\ComplianceController::class;
        Route::get('/holds', [$C, 'holds']);
        Route::post('/holds', [$C, 'placeHold']);
        Route::post('/holds/{id}/release', [$C, 'releaseHold']);
        Route::get('/retention', [$C, 'retention']);
        Route::get('/retention/due', [$C, 'retentionDue']);
        Route::put('/retention/{class}', [$C, 'setRetention']);
        Route::get('/dsar', [$C, 'dsars']);
        Route::post('/dsar', [$C, 'createDsar']);
        Route::get('/dsar/{id}', [$C, 'showDsar']);
        Route::post('/dsar/{id}/verify', [$C, 'verifyDsar']);
        Route::post('/dsar/{id}/reject', [$C, 'rejectDsar']);
        Route::post('/dsar/{id}/export', [$C, 'exportDsar']);
        Route::post('/dsar/{id}/erase', [$C, 'eraseDsar']);
    });
    // PLAT-11: API keys and webhooks (a firm administrator; plan must include api_access).
    Route::middleware([$perm($P::MANAGE_API_ACCESS), 'entitled:api_access'])->prefix('integrations')->group(function () {
        $I = \App\Http\Controllers\IntegrationsController::class;
        Route::get('/api-keys', [$I, 'keys']);
        Route::post('/api-keys', [$I, 'createKey']);
        Route::delete('/api-keys/{id}', [$I, 'revokeKey']);
        Route::get('/webhooks', [$I, 'hooks']);
        Route::post('/webhooks', [$I, 'createHook']);
        Route::put('/webhooks/{id}', [$I, 'updateHook']);
        Route::delete('/webhooks/{id}', [$I, 'deleteHook']);
        Route::post('/webhooks/{id}/rotate-secret', [$I, 'rotateHook']);
        Route::post('/webhooks/{id}/ping', [$I, 'pingHook']);
        Route::get('/webhooks/{id}/deliveries', [$I, 'deliveries']);
        Route::post('/webhooks/deliveries/{deliveryId}/replay', [$I, 'replay']);
    });
    // PLAT-8: the firm's view and control of platform-support access.
    Route::get('/support-access', [\App\Http\Controllers\SupportAccessController::class, 'index'])->middleware($perm($P::MANAGE_SUPPORT_ACCESS));
    Route::get('/support-access/policy', [\App\Http\Controllers\SupportAccessController::class, 'policy'])->middleware($perm($P::MANAGE_SUPPORT_ACCESS));
    Route::put('/support-access/policy', [\App\Http\Controllers\SupportAccessController::class, 'updatePolicy'])->middleware($perm($P::MANAGE_SUPPORT_ACCESS));
    Route::get('/support-access/{id}/log', [\App\Http\Controllers\SupportAccessController::class, 'log'])->middleware($perm($P::MANAGE_SUPPORT_ACCESS));
    Route::post('/support-access/{id}/approve', [\App\Http\Controllers\SupportAccessController::class, 'approve'])->middleware($perm($P::MANAGE_SUPPORT_ACCESS));
    Route::post('/support-access/{id}/deny', [\App\Http\Controllers\SupportAccessController::class, 'deny'])->middleware($perm($P::MANAGE_SUPPORT_ACCESS));
    Route::post('/support-access/{id}/revoke', [\App\Http\Controllers\SupportAccessController::class, 'revoke'])->middleware($perm($P::MANAGE_SUPPORT_ACCESS));
    Route::get('/preview', [CasesController::class, 'preview'])->middleware(['audit.document-denial', $perm($P::VIEW_DOCUMENTS)]);

    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware($perm($P::LIST_INVOICES));
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware($perm($P::CREATE_INVOICES));
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware($perm($P::LIST_INVOICES));
    Route::match(['put', 'patch'], '/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware($perm($P::UPDATE_INVOICES));
    Route::put('/send-to-admin/{id}', [InvoiceController::class, 'sendToAdmin'])->middleware($perm($P::UPDATE_INVOICES));
    Route::get('/preview-invoice/{id}', [InvoiceController::class, 'preview'])->middleware($perm($P::LIST_INVOICES));
    Route::post('/invoiceItems', [InvoiceItemController::class, 'store'])->middleware($perm($P::CREATE_INVOICES));
    Route::get('/invoiceItems/{invoiceItem}', [InvoiceItemController::class, 'show'])->middleware($perm($P::LIST_INVOICES));
    Route::match(['put', 'patch'], '/invoiceItems/{invoiceItem}', [InvoiceItemController::class, 'update'])->middleware($perm($P::UPDATE_INVOICES));
    Route::delete('/invoiceItems/{invoiceItem}', [InvoiceItemController::class, 'destroy'])->middleware($perm($P::DELETE_INVOICES));

    // ---- Remaining modules: enforced at the route -----------------------
    $resource = function (string $name, string $controller, array $map) use ($perm) {
        // $map: action => permission (only listed actions are routed).
        // A value may be [permission, extra-middleware...] (e.g. a plan limit on store).
        foreach ($map as $action => $spec) {
            $spec = (array) $spec;
            $permission = $spec[0];
            $extra = array_slice($spec, 1);
            Route::resource($name, $controller)->only([$action])->middleware([$perm($permission), ...$extra]);
        }
    };
    $crud = fn (string $list, string $create, string $update, string $delete, bool $show = false) => array_filter([
        'index' => $list, 'store' => $create, 'show' => $show ? $list : null, 'update' => $update, 'destroy' => $delete,
    ]);

    // Invoice delete (object-level rule) and its bulk action are policy routes, below.
    $resource('users', UserController::class, array_merge(
        $crud($P::LIST_USERS, $P::CREATE_USERS, $P::UPDATE_USERS, $P::DELETE_USERS),
        ['store' => [$P::CREATE_USERS, 'entitled.limit:users']],   // PLAT-1: seat limit
    ));
    Route::get('/rolesDropDown', [UserController::class, 'getRolesDropdown'])->middleware($perm($P::LIST_ROLES));

    $resource('tasks', TaskController::class, $crud($P::LIST_TASK, $P::CREATE_TASK, $P::UPDATE_TASK, $P::DELETE_TASK));
    Route::post('/tasks/bulk-delete', [TaskController::class, 'destroyMany'])->middleware($perm($P::DELETE_TASK));
    Route::post('/tasks/{id}/restore', [TaskController::class, 'restore'])->middleware($perm($P::DELETE_TASK));

    $resource('expenses', ExpenseController::class, $crud($P::LIST_EXPENSES, $P::CREATE_EXPENSES, $P::UPDATE_EXPENSES, $P::DELETE_EXPENSES, show: true));
    Route::post('/expenses/bulk-delete', [ExpenseController::class, 'destroyMany'])->middleware($perm($P::DELETE_EXPENSES));

    $resource('hearings', HearingController::class, $crud($P::LIST_HEARINGS, $P::CREATE_HEARINGS, $P::UPDATE_HEARINGS, $P::DELETE_HEARINGS));
    Route::post('/hearings/bulk-delete', [HearingController::class, 'destroyMany'])->middleware($perm($P::DELETE_HEARINGS));
    Route::post('/hearings/{id}/restore', [HearingController::class, 'restore'])->middleware($perm($P::DELETE_HEARINGS));

    // Documents: no index/update routes (scaffold stubs); show/versions need list, store/destroy their own.
    // FUN-8: search inside documents (registered before documents/{id})
    Route::get('/documents/search', [\App\Http\Controllers\DocumentSearchController::class, 'search'])->middleware([$perm($P::LIST_DOCUMENTS), 'throttle:60,1,document-search']);
    Route::get('/cases/{case}/document-text', [\App\Http\Controllers\DocumentSearchController::class, 'states'])->whereNumber('case')->middleware($perm($P::LIST_DOCUMENTS));
    Route::post('/documents/{document}/reindex', [\App\Http\Controllers\DocumentSearchController::class, 'reindex'])->whereNumber('document')->middleware([$perm($P::CREATE_DOCUMENTS), 'throttle:30,1,document-reindex']);
    // ENG-22: direct uploads to the firm's bucket (registered before documents/{id})
    Route::post('/documents/uploads', [\App\Http\Controllers\DocumentUploadController::class, 'begin'])->middleware([$perm($P::CREATE_DOCUMENTS), 'throttle:60,1,document-upload-begin']);
    Route::post('/documents/uploads/{upload}/complete', [\App\Http\Controllers\DocumentUploadController::class, 'complete'])->whereUuid('upload')->middleware([$perm($P::CREATE_DOCUMENTS), 'throttle:60,1,document-upload-complete']);
    $resource('documents', DocumentController::class, ['store' => $P::CREATE_DOCUMENTS, 'show' => $P::LIST_DOCUMENTS, 'destroy' => $P::DELETE_DOCUMENTS]);
    Route::get('/documents/{groupId}/versions', [DocumentController::class, 'versions'])->middleware($perm($P::LIST_DOCUMENTS));

    // FUN-2: time tracking, payments/receipts, trust accounting
    // PLAT-1: each of these modules is a plan feature (entitled:<feature>), checked after authorization.
    Route::middleware('entitled:time_tracking')->group(function () use ($resource, $crud, $perm, $P) {
        $resource('time-entries', TimeEntryController::class, $crud($P::LIST_TIME_ENTRIES, $P::CREATE_TIME_ENTRIES, $P::UPDATE_TIME_ENTRIES, $P::DELETE_TIME_ENTRIES));
        Route::post('/time-entries/generate-invoice', [TimeEntryController::class, 'generateInvoice'])->middleware($perm($P::CREATE_INVOICES));
    });

    Route::middleware('entitled:payments')->group(function () use ($resource, $P) {
        $resource('payments', PaymentController::class, ['index' => $P::LIST_PAYMENTS, 'show' => $P::LIST_PAYMENTS, 'store' => $P::CREATE_PAYMENTS, 'destroy' => $P::DELETE_PAYMENTS]);
    });

    Route::middleware('entitled:trust_accounting')->group(function () use ($perm, $P) {
        Route::get('/trust-transactions', [TrustTransactionController::class, 'index'])->middleware($perm($P::LIST_TRUST_TRANSACTIONS));
        Route::post('/trust-transactions', [TrustTransactionController::class, 'store'])->middleware($perm($P::CREATE_TRUST_TRANSACTIONS));
        Route::put('/trust-transactions/{id}/void', [TrustTransactionController::class, 'void'])->middleware($perm($P::VOID_TRUST_TRANSACTIONS));

        // FUN-12: three-way reconciliation and retainer replenishment
        $R = \App\Http\Controllers\TrustReconciliationController::class;
        Route::get('/trust/reconciliations', [$R, 'index'])->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::post('/trust/reconciliations', [$R, 'store'])->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::get('/trust/reconciliations/{id}', [$R, 'show'])->whereNumber('id')->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::delete('/trust/reconciliations/{id}', [$R, 'destroy'])->whereNumber('id')->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::put('/trust/reconciliations/{id}/cleared', [$R, 'clear'])->whereNumber('id')->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::post('/trust/reconciliations/{id}/items', [$R, 'addItem'])->whereNumber('id')->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::delete('/trust/reconciliations/{id}/items/{item}', [$R, 'removeItem'])->whereNumber('id')->whereNumber('item')->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::post('/trust/reconciliations/{id}/sign-off', [$R, 'signOff'])->whereNumber('id')->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::get('/trust/reconciliations/{id}/export', [$R, 'export'])->whereNumber('id')->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::get('/trust/retainers', [$R, 'policies'])->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::put('/trust/retainers', [$R, 'savePolicy'])->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::delete('/trust/retainers/{client}', [$R, 'deletePolicy'])->whereNumber('client')->middleware($perm($P::RECONCILE_TRUST_ACCOUNTS));
        Route::get('/trust/replenishment', [$R, 'due'])->middleware($perm($P::LIST_TRUST_TRANSACTIONS));
        Route::post('/trust/replenishment/{client}/request', [$R, 'requestReplenishment'])->whereNumber('client')->middleware([$perm($P::RECONCILE_TRUST_ACCOUNTS), 'throttle:30,1,trust-replenish']);
    });

    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware([$perm($P::VIEW_DASHBOARD), 'replica']);   // ENG-23

    // FUN-4: server-side export / reporting
    Route::get('/export/cases', [ExportController::class, 'cases'])->middleware($perm($P::LIST_CASES));
    Route::get('/export/expenses', [ExportController::class, 'expenses'])->middleware($perm($P::LIST_EXPENSES));
    Route::get('/export/invoices', [ExportController::class, 'invoices'])->middleware($perm($P::LIST_INVOICES));
    Route::get('/export/invoices/{id}/pdf', [ExportController::class, 'invoicePdf'])->middleware($perm($P::LIST_INVOICES));
    Route::get('/export/firm-data', [ExportController::class, 'fullFirmExport'])->middleware(['entitled:firm_data_export', $perm($P::EXPORT_FIRM_DATA)]);
    // ENG-21: queued firm-wide export -> status (with a signed, expiring link when ready) -> download. Prefer these to the GET above.
    Route::post('/export/firm-data', [ExportController::class, 'requestFirmExport'])->middleware(['entitled:firm_data_export', $perm($P::EXPORT_FIRM_DATA), 'throttle:6,60,firm-export']);
    Route::get('/export/firm-data/{id}', [ExportController::class, 'firmExportStatus'])->whereNumber('id')->middleware(['entitled:firm_data_export', $perm($P::EXPORT_FIRM_DATA)]);
    Route::get('/export/firm-data/{id}/download', [ExportController::class, 'downloadFirmExport'])->whereNumber('id')
        ->middleware(['entitled:firm_data_export', $perm($P::EXPORT_FIRM_DATA), 'signed:relative'])->name('exports.firm.download');

    // Roles and permissions
    $resource('roles', RolesManagementController::class, $crud($P::LIST_ROLES, $P::CREATE_ROLES, $P::UPDATE_ROLES, $P::DELETE_ROLES));
    Route::get('/permissions', [RolesManagementController::class, 'allPermissions'])->middleware($perm($P::LIST_PERMISSIONS));
    Route::get('/permissions/{name}', [RolesManagementController::class, 'getRolePermissions'])->middleware($perm($P::LIST_ROLES));

    // PLAT-2: install / replace the license file (works while read-only; the gate exempts this path).
    Route::post('/license', function (\Illuminate\Http\Request $r, \App\Licensing\LicenseManager $l) {
        $r->validate(['license' => 'required|string|max:20000']);
        $status = $l->install($r->input('license'));
        app(\App\Entitlements\Entitlements::class)->forget();

        return response()->json($status->toArray(), $status->state === 'invalid' ? 422 : 200);
    })->middleware($perm($P::MANAGE_LICENSE));

    // ---- Object-level rules: the model's Policy decides (ENG-11) -----------
    // Invoice delete lets non-admins remove their own drafts (InvoicePolicy::delete; a refusal is a 404).
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy')->middleware('authz:policy,delete,Invoice,invoice');
    // Bulk: the caller must be able to delete invoices at all; each row is then checked against the same policy.
    Route::post('/invoices/bulk-delete', [InvoiceController::class, 'destroyMany'])->middleware('authz:policy,deleteAny,Invoice');
    // Global search: any signed-in user; each result type is included only if its policy allows viewAny.
    Route::get('/search', [SearchController::class, 'index'])->middleware('authz:authenticated');
});
