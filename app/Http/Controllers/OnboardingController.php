<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Client;
use App\Models\User;
use App\Security\Mfa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * PLAT-13: the "getting started" checklist a new firm sees on its dashboard. Each step is worked out from the firm's own
 * data (no separate progress to keep in sync), in the order that leads to a first matter: a client, then a matter for
 * them, then a colleague, then two-factor. Steps the person may not do are marked so the app can leave them out.
 */
class OnboardingController extends Controller
{
    public function show(): JsonResponse
    {
        $u = Auth::user();
        $firstClient = Client::query()->orderBy('id')->value('id');
        $steps = [
            ['key' => 'client', 'title' => 'Add your first client', 'done' => $firstClient !== null, 'can' => $u->checkPermissionTo(P::CREATE_CLIENTS), 'link' => '/clients?new=1'],
            ['key' => 'matter', 'title' => 'Open a matter for them', 'done' => Cases::query()->exists(), 'can' => $u->checkPermissionTo(P::CREATE_CASES),
                'link' => '/cases?new=1'.($firstClient ? '&client_id='.$firstClient : '')],
            ['key' => 'colleague', 'title' => 'Invite a colleague', 'done' => User::whereNull('platform_staff_id')->count() > 1, 'can' => $u->checkPermissionTo(P::CREATE_USERS), 'link' => '/users?new=1'],
            ['key' => 'two_factor', 'title' => 'Turn on two-factor authentication', 'done' => Mfa::enabled($u), 'can' => true, 'link' => '/users/account-setting'],
        ];
        $open = array_filter($steps, fn ($s) => $s['can'] && ! $s['done']);

        return $this->response(true, 'success', [
            'steps' => $steps,
            'complete' => $open === [],
            'dismissed' => $u->onboarding_dismissed_at !== null,
            'show' => $open !== [] && $u->onboarding_dismissed_at === null,
        ], 200);
    }

    public function dismiss(): JsonResponse
    {
        Auth::user()->forceFill(['onboarding_dismissed_at' => now()])->save();

        return $this->response(true, 'success', null, 200);
    }
}
