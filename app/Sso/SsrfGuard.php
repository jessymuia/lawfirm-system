<?php

namespace App\Sso;

/**
 * SEC-12: the identity-provider URLs are typed in by a firm's administrator and then fetched BY THE SERVER, so they must
 * not be able to point at the cloud metadata service or the internal network. https only; the host must resolve, and
 * only to public addresses. (Limit: the name is checked, then fetched by name, so a DNS-rebinding attacker with control
 * of the IdP's DNS has a narrow window. Pin egress at the network layer for a stronger guarantee.)
 */
final class SsrfGuard
{
    public static function check(string $url): void
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        $host = $p['host'] ?? '';
        if (! $host || ! in_array($scheme, config('sso.allow_http') ? ['https', 'http'] : ['https'], true)) {
            throw new SsoException('The identity provider URL must be https.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new SsoException('The identity provider URL must not contain credentials.');
        }
        if (! config('sso.block_private_hosts')) {
            return;
        }
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if (! $ips) {
            throw new SsoException('The identity provider host does not resolve.');
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new SsoException('The identity provider host resolves to a private or reserved address.');
            }
        }
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }
}
