<?php

/**
 * PLAT-9: what travels in an eject/import bundle. EVERY table must be classified here, as `include` or `exclude`
 * (with a reason). A table that is neither fails the export and the test suite, so a new feature cannot silently be
 * left out of (or leaked into) a firm's portable data.
 *
 * The bundle format itself is versioned in App\Portability\Format (docs/PORTABILITY.md): changing it incompatibly is
 * a breaking API change (major version bump + a new golden fixture).
 */
return [
    'include' => [
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'court_types', 'courts', 'hearing_types', 'expense_categories',
        'case_types', 'currencies', 'exchange_rates', 'tax_rates', 'clients', 'cases', 'attorneys', 'hearings', 'tasks',
        'invoices', 'invoice_items', 'expenses', 'payments', 'time_entries', 'trust_transactions', 'sequences',
        'documents', 'document_accesses',
        'firm_security_settings', 'firm_settings',
        'matter_parties', 'conflict_checks',
        'offices', 'practice_groups',
        'invoice_payment_links', 'online_payment_attempts',
        'portal_accounts', 'portal_documents', 'portal_messages',
        'matter_emails', 'matter_email_attachments',
        'document_templates', 'signature_requests', 'signature_signers',
        'trust_reconciliations', 'trust_reconciliation_items', 'trust_transaction_clearances', 'trust_retainer_policies', 'trust_replenishment_requests',
        'legal_holds', 'retention_policies', 'retention_disposals', 'dsar_requests',
        'matter_access_grants', 'ethical_screens', 'ethical_screen_attempts',
        'support_access_sessions', 'support_access_log',
        'audits',
    ],

    'exclude' => [
        'migrations' => 'recorded in the manifest instead; the target runs its own migrations',
        'jobs' => 'transient queue state',
        'calendar_feeds' => 'FUN-16: secret subscription links of this deployment; people create new links after a move',
        'calendar_connections' => 'FUN-16: calendar tokens are encrypted with this deployment\'s key; people connect their calendars again after a move',
        'calendar_event_links' => 'FUN-16: belongs to the calendar connections above; rebuilt on the first sync after reconnecting',
        'document_texts' => 'FUN-8: derived from the documents themselves; rebuilt after a move by app:upgrade (BackfillDocumentText)',
        'portal_login_codes' => 'FUN-10: transient one-time sign-in codes',
        'portal_sessions' => 'FUN-10: client portal logins of this deployment; clients sign in again after a move',
        'audit_archives' => 'describes archive files in this install\'s storage; restore a month (audits:restore) before ejecting if it must travel',
        'failed_jobs' => 'transient queue state',
        'personal_access_tokens' => 'login tokens must never leave the system; people sign in again',
        'password_resets' => 'transient, secret',
        'api_keys' => 'API keys are secrets of this deployment; create new keys after a move',
        'webhook_endpoints' => 'endpoint secrets are encrypted with this deployment\'s key; register webhooks again after a move',
        'webhook_deliveries' => 'transient delivery log of this deployment',
        'integration_credentials' => 'a firm\'s third-party credentials are encrypted with this deployment\'s key; enter them again after a move',
        'user_mfa' => 'authenticator secrets are encrypted with this deployment\'s key and cannot be read elsewhere; people enrol again after a move',
        'sso_providers' => 'holds the IdP client secret encrypted with this deployment\'s key; reconfigure SSO after a move',
        'sso_states' => 'transient login state',
        'sso_links' => 'binds IdP subjects of this deployment\'s provider; they are re-bound on first SSO login after a move',
        'mfa_challenges' => 'transient login state',
        'health_probes' => 'operational history of this deployment, not firm data',
        'status_incidents' => 'platform status communications of this deployment, not firm data',
        'status_incident_updates' => 'platform status communications of this deployment, not firm data',
        'migration_id_maps' => 'bookkeeping of the legacy one-off migration, meaningless elsewhere',
        'firm_exports' => 'ENG-21 download requests of this deployment; the files they point at expire within a day',
        'document_uploads' => 'ENG-22 uploads in flight on this deployment\'s disk; they expire within minutes',
    ],

    // Seeded by migrations / first install. An import target may hold these (and only these) before the import;
    // with --replace-bootstrap they are replaced by the bundle's rows. `courts` is here since TEN-11: a fresh
    // install now holds the system courts. Imported rows keep their catalog columns as the bundle had them; the
    // next app:upgrade (or catalog:sync) adopts/adds system rows in a bundle exported before TEN-11.
    'bootstrap' => [
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'court_types', 'courts', 'hearing_types', 'expense_categories',
        'currencies', 'tax_rates',   // TEN-11/DATA-14: a fresh firm holds the catalog currencies and tax rates
    ],

    // Columns that only mean something inside one deployment (a control-plane id), never exported. The importer
    // leaves them null; in multi mode portability:import links the people to identities afterwards.
    'omit_columns' => [
        'users' => ['global_identity_id'],
    ],

    // Where `portability:export` writes when --output is not given.
    'directory' => storage_path('app/portability'),
];
