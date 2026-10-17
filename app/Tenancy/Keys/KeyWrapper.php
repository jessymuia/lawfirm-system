<?php

namespace App\Tenancy\Keys;

use App\Tenancy\Models\Tenant;

/**
 * TEN-13: what protects a firm's data key at rest. The wrapped form is bound to the firm (its slug), so a wrapped key
 * copied onto another firm's row does not unwrap there.
 */
interface KeyWrapper
{
    /** The `provider` value stored with the key. */
    public function name(): string;

    public function wrap(string $dataKey, Tenant $tenant, ?string $keyRef): string;

    /** @throws KeyUnavailable when the key cannot be unwrapped (wrong master key, customer key revoked or deleted) */
    public function unwrap(string $wrapped, Tenant $tenant, ?string $keyRef): string;
}
