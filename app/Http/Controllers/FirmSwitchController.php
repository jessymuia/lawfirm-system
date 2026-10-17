<?php

namespace App\Http\Controllers;

use App\Security\SecurityPolicy;
use App\Tenancy\FirmSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** UI-16: the firm switcher. See App\Tenancy\FirmSwitch. */
class FirmSwitchController extends Controller
{
    public function __construct(private FirmSwitch $switch) {}

    /** The firms the signed-in person belongs to (the switcher shows when there is more than one). */
    public function index(): JsonResponse
    {
        return $this->response(true, 'success', $this->switch->firms(Auth::user()), 200);
    }

    /** Where to send the browser to continue in another of the person's firms. */
    public function switch(Request $r, string $firm): JsonResponse
    {
        $url = $this->switch->issue(Auth::user(), $firm, $r->getScheme());
        abort_if($url === null, 404);

        return $this->response(true, 'success', ['url' => $url], 200);
    }

    /** On the target firm's host: sign the person in, or send them to sign in there when that firm asks for more. */
    public function redeem(Request $r, string $token): RedirectResponse
    {
        [$user, $why] = $this->switch->redeem($token);
        if (! $user) {
            return redirect('/login?switch='.$why);
        }
        Auth::guard('web')->login($user);
        $r->session()->regenerate();
        SecurityPolicy::audit('firm_switched_in', 'user', (int) $user->id, [], []);

        return redirect('/');
    }
}
