<?php

namespace App\Http\Controllers;

use App\Http\Requests\Signup\StoreRequest;
use App\Signup\SignupException;
use App\Signup\SignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** PLAT-13: the public signup page and its API (signup host only, see routes/signup.php). */
class SignupController extends Controller
{
    public function __construct(private SignupService $signups) {}

    public function page()
    {
        $nonce = bin2hex(random_bytes(16));

        return response()->view('signup.page', [
            'nonce' => $nonce,
            'trialDays' => (int) config('billing.trial.days', 14),
            'baseDomain' => (string) config('tenancy.base_domain'),
        ])->withHeaders([
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',   // the verify page carries its token in the URL
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ]);
    }

    /** Live check while the person types their firm's address. */
    public function slug(Request $r): JsonResponse
    {
        $slug = Str::lower(trim((string) $r->query('slug', '')));
        $problem = $this->signups->slugProblem($slug, $r->query('email'));

        return $this->response(true, 'success', ['slug' => $slug, 'available' => $problem === null, 'problem' => $problem], 200);
    }

    /**
     * Always the same answer for a valid form, whether or not the email already has a login elsewhere: the email itself
     * is the only place anything about the account is said.
     */
    public function store(StoreRequest $r): JsonResponse
    {
        try {
            $this->signups->submit($r->validated(), $r->ip());
        } catch (SignupException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => [$e->field => [$e->getMessage()]]], $e->status);
        }

        return $this->response(true, 'Check your email: we sent a link to confirm the address and create your firm.', null, 202);
    }

    public function verify(Request $r): JsonResponse
    {
        return $this->answer(fn () => $this->signups->verify((string) $r->input('token', '')));
    }

    public function status(Request $r): JsonResponse
    {
        return $this->answer(fn () => $this->signups->status((string) $r->query('token', '')));
    }

    private function answer(\Closure $f): JsonResponse
    {
        try {
            return $this->response(true, 'success', $f(), 200);
        } catch (SignupException $e) {
            return $this->response(false, $e->getMessage(), null, $e->status);
        }
    }
}
