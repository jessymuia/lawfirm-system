<?php

namespace App\Http\Controllers;

use App\Branding\Branding;
use App\Http\Requests\Branding\LogoRequest;
use App\Http\Requests\Branding\UpdateRequest;
use App\Security\SecurityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** UI-13: the firm's branding. See docs/BRANDING.md. */
class BrandingController extends Controller
{
    /** Public: the sign-in page and the app shell show the firm's name, colour and logo. */
    public function show(): JsonResponse
    {
        return $this->response(true, 'success', Branding::public(), 200);
    }

    /** Public: the logo itself (cache-busted by its version in the URL). */
    public function logo(): Response
    {
        $logo = Branding::logo();
        abort_if(! $logo, 404);

        return response($logo[0], 200, ['Content-Type' => $logo[1], 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Everything, for the branding settings page. */
    public function settings(): JsonResponse
    {
        $b = Branding::get();
        unset($b['logo']);

        return $this->response(true, 'success', $b + Branding::public(), 200);
    }

    public function update(UpdateRequest $r): JsonResponse
    {
        $b = Branding::update($r->validated(), (int) Auth::id());
        SecurityPolicy::audit('branding_updated', 'firm', 0, [], array_keys($r->validated()));
        unset($b['logo']);

        return $this->response(true, 'Branding saved.', $b + Branding::public(), 200);
    }

    public function uploadLogo(LogoRequest $r): JsonResponse
    {
        try {
            Branding::setLogo($r->file('logo'), (int) Auth::id());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => ['logo' => [$e->getMessage()]]], 422);
        }
        SecurityPolicy::audit('branding_logo_changed', 'firm', 0, [], []);

        return $this->response(true, 'Logo saved.', Branding::public(), 200);
    }

    public function removeLogo(): JsonResponse
    {
        Branding::removeLogo((int) Auth::id());
        SecurityPolicy::audit('branding_logo_removed', 'firm', 0, [], []);

        return $this->response(true, 'Logo removed.', Branding::public(), 200);
    }
}
