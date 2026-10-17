<?php

/**
 * SEC-15: every column the application encrypts with the app key (TEN-13: tenant-scope columns with the firm's own data
 * key when it has one). The rotation tooling (`secrets:check`, `secrets:rotate`)
 * walks this list, and a test fails if code starts encrypting something that is not listed here.
 */
return [
    'encrypted' => [
        ['scope' => 'tenant', 'table' => 'sso_providers', 'column' => 'client_secret', 'key' => 'id'],
        ['scope' => 'tenant', 'table' => 'user_mfa', 'column' => 'secret', 'key' => 'id'],
        ['scope' => 'tenant', 'table' => 'integration_credentials', 'column' => 'value', 'key' => 'id'],
        ['scope' => 'tenant', 'table' => 'webhook_endpoints', 'column' => 'secret', 'key' => 'id'],
        // FUN-16: people's calendar tokens (the OAuth state is encrypted too but lives 10 minutes, so it is not rotated)
        ['scope' => 'tenant', 'table' => 'calendar_connections', 'column' => 'access_token', 'key' => 'id'],
        ['scope' => 'tenant', 'table' => 'calendar_connections', 'column' => 'refresh_token', 'key' => 'id'],
        ['scope' => 'control', 'table' => 'platform_staff_mfa', 'column' => 'secret', 'key' => 'id'],
    ],

    // Source files allowed to call Crypt::encrypt*/encrypt(). Anything else must be added here AND to `encrypted` above.
    // (sso_states.verifier is encrypted too but lives 10 minutes, so it is not rotated.)
    'encrypting_sources' => [
        'app/Security/MfaCore.php',
        'app/Sso/SsoService.php',
        'app/Secrets/IntegrationCredentials.php',
        'app/Webhooks/WebhookService.php',
        'app/Secrets/SecretInventory.php',
        'app/Calendar/CalendarSync.php', 'app/Http/Controllers/CalendarController.php',   // FUN-16
        'app/Tenancy/Keys/FirmCrypt.php',   // TEN-13: the firm-key encrypter the tenant-scope sources above go through
    ],
];
