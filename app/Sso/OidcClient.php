<?php

namespace App\Sso;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SEC-12: the relying-party side of OpenID Connect authorization-code flow with PKCE (S256), client_secret_post.
 * Every URL it fetches passes SsrfGuard. The ID token is verified (signature, iss, aud/azp, exp, nbf, iat, nonce) and the
 * email must be present and verified.
 */
class OidcClient
{
    public function __construct(private string $issuer, private string $clientId, private string $clientSecret) {}

    /** @return array<string,mixed> */
    public function metadata(bool $refresh = false): array
    {
        $key = 'oidc:meta:'.sha1($this->issuer);
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, (int) config('sso.metadata_ttl'), function () {
            $url = rtrim($this->issuer, '/').'/.well-known/openid-configuration';
            $meta = $this->getJson($url);
            if (($meta['issuer'] ?? null) !== $this->issuer) {
                throw new SsoException('The provider\'s issuer does not match the configured one.');
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
                if (empty($meta[$k])) {
                    throw new SsoException("Discovery document lacks {$k}.");
                }
                SsrfGuard::check($meta[$k]);
            }

            return $meta;
        });
    }

    public function authorizationUrl(string $state, string $nonce, string $verifier, string $redirectUri): string
    {
        $q = http_build_query([
            'response_type' => 'code', 'client_id' => $this->clientId, 'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile', 'state' => $state, 'nonce' => $nonce,
            'code_challenge' => Jwt::b64e(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ]);
        $base = $this->metadata()['authorization_endpoint'];

        return $base.(str_contains($base, '?') ? '&' : '?').$q;
    }

    /** @return array<string,mixed> the verified ID-token claims */
    public function authenticate(string $code, string $verifier, string $redirectUri, string $nonce): array
    {
        $token = $this->post($this->metadata()['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'code_verifier' => $verifier,
        ]);
        if (empty($token['id_token']) || ! is_string($token['id_token'])) {
            throw new SsoException('The provider returned no ID token.');
        }

        return $this->validate($token['id_token'], $nonce);
    }

    /** @return array<string,mixed> */
    public function validate(string $idToken, string $nonce): array
    {
        try {
            $claims = Jwt::verify($idToken, $this->jwks());
        } catch (SsoException $e) {
            if ($e->getMessage() !== 'No matching signing key.') {
                throw $e;
            }
            $claims = Jwt::verify($idToken, $this->jwks(refresh: true));   // the IdP may have rotated keys
        }

        $now = now()->timestamp;
        $leeway = (int) config('sso.leeway');
        $aud = (array) ($claims['aud'] ?? []);

        $checks = [
            'issuer' => ($claims['iss'] ?? null) === $this->issuer,
            'audience' => in_array($this->clientId, $aud, true),
            'authorized party' => count($aud) <= 1 || ($claims['azp'] ?? null) === $this->clientId,
            'expiry' => isset($claims['exp']) && is_numeric($claims['exp']) && $now <= $claims['exp'] + $leeway,
            'not-before' => ! isset($claims['nbf']) || $now >= $claims['nbf'] - $leeway,
            'issued-at' => isset($claims['iat']) && is_numeric($claims['iat']) && $now >= $claims['iat'] - $leeway,
            'nonce' => isset($claims['nonce']) && hash_equals($nonce, (string) $claims['nonce']),
            'subject' => ! empty($claims['sub']) && is_string($claims['sub']),
            'email' => ! empty($claims['email']) && is_string($claims['email']),
            'verified email' => ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? null) === 'true',
        ];
        foreach ($checks as $what => $ok) {
            if (! $ok) {
                throw new SsoException("ID token failed: {$what}.");
            }
        }

        return $claims;
    }

    /** @return array{keys:list<array<string,mixed>>} */
    private function jwks(bool $refresh = false): array
    {
        $key = 'oidc:jwks:'.sha1($this->issuer);
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, (int) config('sso.metadata_ttl'), fn () => $this->getJson($this->metadata()['jwks_uri']));
    }

    private function getJson(string $url): array
    {
        SsrfGuard::check($url);
        $r = Http::timeout(5)->withoutRedirecting()->acceptJson()->get($url);
        if (! $r->ok() || ! is_array($r->json())) {
            throw new SsoException("Could not read {$url} (HTTP {$r->status()}).");
        }

        return $r->json();
    }

    private function post(string $url, array $form): array
    {
        SsrfGuard::check($url);
        $r = Http::timeout(10)->withoutRedirecting()->asForm()->acceptJson()->post($url, $form);
        if (! $r->ok() || ! is_array($r->json())) {
            throw new SsoException("The token endpoint refused the request (HTTP {$r->status()}).");
        }

        return $r->json();
    }
}
