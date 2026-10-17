<?php

namespace App\Tenancy;

use App\Http\Middleware\PlatformHost;
use App\Signup\SignupService;
use Illuminate\Http\Request;

/**
 * TEN-17: what the sign-in page offers beyond the firm's own login, decided where the deployment mode is allowed to be known.
 * A self-hosted install gets no operator login, no platform link and no free-trial link; the page just renders what it is given.
 */
class LoginPage
{
    public function __construct(private TenantManager $tenants)
    {
    }

    /** @return array{signupUrl: ?string, operatorLogin: bool, platformLoginUrl: ?string} */
    public function context(Request $request): array
    {
        $multi = $this->tenants->isMulti();
        // Local/demo: the operator signs in right on this page (no separate host). Otherwise a link to the admin host.
        $operatorLogin = $multi && PlatformHost::embedded();
        $platformHost = $multi && ! $operatorLogin ? config('tenancy.platform_host') : null;

        // PLAT-13: a "Start a free trial" link, only when self-service signup is switched on (it lives on its own host).
        $signupUrl = null;
        if ($multi && SignupService::available()) {
            $port = in_array($request->getPort(), [80, 443], true) ? '' : ':'.$request->getPort();
            $signupUrl = $request->getScheme().'://'.trim((string) config('tenancy.signup_host'), '/').$port.'/signup';
        }

        return [
            'signupUrl' => $signupUrl,
            'operatorLogin' => $operatorLogin,
            'platformLoginUrl' => $platformHost ? 'https://'.trim($platformHost, '/').'/console' : null,
        ];
    }
}
