<?php

namespace App\Sso;

use App\Tenancy\Keys\FirmCrypt;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** SEC-12: one firm's single sign-on: configuration, starting a sign-in, and turning an IdP response into a firm user. */
class SsoService
{
    public function provider(bool $onlyEnabled = true): ?object
    {
        $q = DB::table('sso_providers');
        $onlyEnabled && $q->where('enabled', true);

        return $q->first();
    }

    public function client(object $p): OidcClient
    {
        return new OidcClient($p->issuer, $p->client_id, FirmCrypt::decryptString($p->client_secret));
    }

    /** SSO is a plan feature (`sso`); a downgrade switches it off without deleting the configuration. */
    public function available(): bool
    {
        return app(\App\Entitlements\Entitlements::class)->has('sso') && $this->provider() !== null;
    }

    /** @return string the URL to send the browser to */
    public function start(string $redirectUri): string
    {
        $p = $this->provider() ?? throw new SsoException('SSO is not configured.');
        DB::table('sso_states')->where('expires_at', '<', now())->delete();

        if (($p->protocol ?? 'oidc') === 'saml') {
            return $this->startSaml($p, request()->getSchemeAndHttpHost());
        }

        $state = Str::random(48);
        $nonce = Str::random(32);
        $verifier = Str::random(64);
        DB::table('sso_states')->insert([
            'state_hash' => hash('sha256', $state), 'nonce' => $nonce, 'verifier' => Crypt::encryptString($verifier),
            'expires_at' => now()->addMinutes((int) config('sso.state_minutes')), 'created_at' => now(),
        ]);

        return $this->client($p)->authorizationUrl($state, $nonce, $verifier, $redirectUri);
    }

    /** @return array{0: User, 1: object} the firm user and the provider row */
    public function complete(string $code, string $state, string $redirectUri): array
    {
        $p = $this->provider() ?? throw new SsoException('SSO is not configured.');
        if (($p->protocol ?? 'oidc') !== 'oidc') {
            throw new SsoException('This firm signs in with SAML.');
        }
        $row = DB::table('sso_states')->where('state_hash', hash('sha256', $state))->where('expires_at', '>', now())->first();
        if (! $row) {
            throw new SsoException('Unknown or expired state.');
        }
        // single use: even a failed attempt burns the state
        if (DB::table('sso_states')->where('id', $row->id)->delete() !== 1) {
            throw new SsoException('State already used.');
        }

        $claims = $this->client($p)->authenticate($code, Crypt::decryptString($row->verifier), $redirectUri, $row->nonce);

        return [$this->bindUser($p, Str::lower(trim($claims['email'])), $claims['sub']), $p];
    }

    /**
     * The step both protocols share: the verified email must belong to a person the firm already has, and the IdP subject is
     * bound to them on first use. No account is created here: an assertion must not mint access (SCIM provisioning does that, by token).
     */
    private function bindUser(object $p, string $email, string $subject): User
    {
        $claims = ['sub' => $subject];
        $domains = array_map('strtolower', (array) json_decode((string) $p->allowed_email_domains, true));
        if ($domains && ! in_array(Str::after($email, '@'), $domains, true)) {
            throw new SsoException("Email domain not allowed: {$email}.");
        }

        // Only people the firm already has. No automatic account creation: an IdP assertion must not mint access.
        $user = User::whereNull('platform_staff_id')->whereRaw('lower(email) = ?', [$email])->first();
        if (! $user) {
            throw new SsoException("No firm user for {$email}.");
        }

        // Bind the IdP subject on first use; afterwards a different subject for the same email is refused
        // (an email address re-assigned to someone else at the IdP must not inherit the account).
        $link = DB::table('sso_links')->where('user_id', $user->id)->where('issuer', $p->issuer)->first();
        if ($link && ! hash_equals($link->subject, $claims['sub'])) {
            throw new SsoException("Subject mismatch for {$email}.");
        }
        if (! $link) {
            if (DB::table('sso_links')->where('issuer', $p->issuer)->where('subject', $claims['sub'])->exists()) {
                throw new SsoException('That identity is already linked to another user.');
            }
            DB::table('sso_links')->insert(['user_id' => $user->id, 'issuer' => $p->issuer, 'subject' => $claims['sub'], 'last_login_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('sso_links')->where('id', $link->id)->update(['last_login_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    // ---- SAML 2.0 (SP-initiated, HTTP-Redirect request, HTTP-POST response) -----------------------------------------

    public static function samlEntityId(string $host): string
    {
        return rtrim($host, '/').'/api/v1/sso/saml/metadata';
    }

    public static function samlAcsUrl(string $host): string
    {
        return rtrim($host, '/').'/api/v1/sso/saml/acs';
    }

    private function startSaml(object $p, string $host): string
    {
        $requestId = '_'.bin2hex(random_bytes(16));
        $relay = Str::random(48);
        DB::table('sso_states')->insert([
            'state_hash' => hash('sha256', $relay), 'nonce' => $requestId, 'verifier' => Crypt::encryptString('saml'),
            'expires_at' => now()->addMinutes((int) config('sso.state_minutes')), 'created_at' => now(),
        ]);

        $xml = '<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            .' ID="'.$requestId.'" Version="2.0" IssueInstant="'.gmdate('Y-m-d\TH:i:s\Z').'"'
            .' Destination="'.e($p->saml_sso_url).'" ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"'
            .' AssertionConsumerServiceURL="'.e(self::samlAcsUrl($host)).'">'
            .'<saml:Issuer>'.e(self::samlEntityId($host)).'</saml:Issuer>'
            .'<samlp:NameIDPolicy AllowCreate="false"/></samlp:AuthnRequest>';

        $sep = str_contains($p->saml_sso_url, '?') ? '&' : '?';

        return $p->saml_sso_url.$sep.http_build_query(['SAMLRequest' => base64_encode(gzdeflate($xml)), 'RelayState' => $relay], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{0: User, 1: object} */
    public function completeSaml(string $samlResponse, string $relayState, string $host): array
    {
        $p = $this->provider() ?? throw new SsoException('SSO is not configured.');
        if (($p->protocol ?? 'oidc') !== 'saml') {
            throw new SsoException('This firm does not sign in with SAML.');
        }
        $row = DB::table('sso_states')->where('state_hash', hash('sha256', $relayState))->where('expires_at', '>', now())->first();
        if (! $row) {
            throw new SsoException('Unknown or expired state.');
        }
        // single use: even a failed attempt burns the request, so a captured response cannot be replayed
        if (DB::table('sso_states')->where('id', $row->id)->delete() !== 1) {
            throw new SsoException('State already used.');
        }

        $xml = base64_decode($samlResponse, true);
        if ($xml === false) {
            throw new SsoException('Response is not base64.');
        }
        $certs = \App\Sso\Saml\SamlValidator::parseCertificates((string) $p->saml_certificate);
        if (! $certs) {
            throw new SsoException('No usable IdP certificate is configured.');
        }
        $result = (new \App\Sso\Saml\SamlValidator())->validate($xml, [
            'idp_entity_id' => $p->issuer, 'certificates' => $certs, 'sp_entity_id' => self::samlEntityId($host),
            'acs_url' => self::samlAcsUrl($host), 'request_id' => $row->nonce, 'leeway' => (int) config('sso.leeway'),
        ]);

        return [$this->bindUser($p, Str::lower(trim($result['email'])), $result['name_id']), $p];
    }

    public static function samlMetadata(string $host): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="'.e(self::samlEntityId($host)).'">'
            .'<md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            .'<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>'
            .'<md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="'.e(self::samlAcsUrl($host)).'" index="0" isDefault="true"/>'
            .'</md:SPSSODescriptor></md:EntityDescriptor>';
    }

    /**
     * Save the firm's provider. The configuration is proven (discovery + issuer + SSRF check) before it is stored, so a typo
     * cannot lock anyone out. The secret is write-only: omit it to keep the stored one.
     */
    public function configure(array $d, ?int $actor): object
    {
        $existing = $this->provider(false);
        if (($d['protocol'] ?? 'oidc') === 'saml') {
            return $this->configureSaml($d, $actor, $existing);
        }
        $secret = $d['client_secret'] ?? null;
        if (! $secret && ! $existing) {
            throw new SsoException('A client secret is required.');
        }
        $secretPlain = $secret ?: FirmCrypt::decryptString($existing->client_secret);

        $issuer = rtrim($d['issuer'], '/');
        SsrfGuard::check($issuer);
        (new OidcClient($issuer, $d['client_id'], $secretPlain))->metadata(refresh: true);

        $row = [
            'protocol' => 'oidc', 'saml_sso_url' => null, 'saml_certificate' => null,
            'issuer' => $issuer, 'client_id' => $d['client_id'], 'client_secret' => FirmCrypt::encryptString($secretPlain),
            'enabled' => (bool) ($d['enabled'] ?? false), 'enforce' => (bool) ($d['enforce'] ?? false),
            'idp_enforces_mfa' => (bool) ($d['idp_enforces_mfa'] ?? false),
            'allowed_email_domains' => json_encode(array_values(array_map('strtolower', (array) ($d['allowed_email_domains'] ?? [])))),
            'updated_by' => $actor, 'updated_at' => now(),
        ];
        if ($row['enforce'] && ! $row['enabled']) {
            throw new SsoException('SSO cannot be enforced while it is switched off.');
        }
        if ($existing) {
            DB::table('sso_providers')->where('id', $existing->id)->update($row);
        } else {
            DB::table('sso_providers')->insert($row + ['created_at' => now()]);
        }

        return $this->provider(false);
    }

    private function configureSaml(array $d, ?int $actor, ?object $existing): object
    {
        $url = (string) ($d['saml_sso_url'] ?? '');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ($scheme !== 'https' && ! (config('sso.allow_http') && $scheme === 'http'))) {
            throw new SsoException('The identity provider sign-in URL must be an https address.');
        }
        $entity = trim((string) ($d['issuer'] ?? ''));
        if ($entity === '') {
            throw new SsoException('The identity provider entity ID is required.');
        }
        $certs = \App\Sso\Saml\SamlValidator::parseCertificates((string) ($d['saml_certificate'] ?? ($existing->saml_certificate ?? '')));
        if (! $certs) {
            throw new SsoException('Paste the identity provider signing certificate (PEM).');
        }
        if (! empty($d['enforce']) && empty($d['enabled'])) {
            throw new SsoException('SSO cannot be enforced while it is switched off.');
        }
        $row = [
            'protocol' => 'saml', 'issuer' => $entity, 'client_id' => 'saml', 'client_secret' => FirmCrypt::encryptString('-'),
            'saml_sso_url' => $url, 'saml_certificate' => implode("\n", $certs),
            'enabled' => (bool) ($d['enabled'] ?? false), 'enforce' => (bool) ($d['enforce'] ?? false),
            'idp_enforces_mfa' => (bool) ($d['idp_enforces_mfa'] ?? false),
            'allowed_email_domains' => json_encode(array_values(array_map('strtolower', (array) ($d['allowed_email_domains'] ?? [])))),
            'updated_by' => $actor, 'updated_at' => now(),
        ];
        if ($existing) {
            DB::table('sso_providers')->where('id', $existing->id)->update($row);
        } else {
            DB::table('sso_providers')->insert($row + ['created_at' => now()]);
        }

        return $this->provider(false);
    }

    /** What the API may show about the provider: never the secret. */
    public static function view(?object $p): ?array
    {
        return $p ? [
            'protocol' => $p->protocol ?? 'oidc', 'saml_sso_url' => $p->saml_sso_url ?? null, 'has_certificate' => ! empty($p->saml_certificate),
            'issuer' => $p->issuer, 'client_id' => $p->client_id, 'has_secret' => true, 'enabled' => (bool) $p->enabled, 'enforce' => (bool) $p->enforce,
            'idp_enforces_mfa' => (bool) $p->idp_enforces_mfa, 'allowed_email_domains' => json_decode((string) $p->allowed_email_domains, true) ?: [],
        ] : null;
    }
}
