<?php

namespace App\Payments;

/**
 * Where a payment gateway reads its keys: a firm's own collection accounts (FUN-11, IntegrationCredentials) or the
 * platform's account that bills firms for their subscription (PLAT-4, PlatformCredentials).
 */
interface Credentials
{
    public function resolve(string $provider, string $name): ?string;

    /** @return array<string, ?string> */
    public function all(string $provider): array;
}
