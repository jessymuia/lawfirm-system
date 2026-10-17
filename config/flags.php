<?php

/**
 * PLAT-3: the feature-flag registry. The ONLY place a flag exists; using an unregistered flag is a test failure.
 *
 * POLICY (docs/FEATURE_FLAGS.md): customer-specific behaviour is a flag, never a branch. Nobody writes
 * `if ($tenant->slug === 'acme')`, and nobody forks the code for a client. A flag is either on or off for a
 * tenant, by registry default, edition (plan), percentage rollout or a per-tenant override.
 *
 * Fields:
 *   description  what it does (required)
 *   type         release (temporary, ships dark) | experiment (temporary) | ops (kill switch, permanent)
 *                | customer (a per-firm behaviour, permanent)
 *   owner        who answers for it (required)
 *   default      bool, what everyone gets unless something below says otherwise
 *   editions     [plan => bool], overrides the default for a plan (self-hosted installs use their plan name too)
 *   rollout      0..100, % of tenants that get it when nothing more specific decides (stable per tenant)
 *   expires      YYYY-MM-DD, REQUIRED for release/experiment: the test suite fails after this date, forcing removal
 *
 * Resolution, highest wins:  killed (FLAGS_KILLED)  >  tenant override (control DB)  >  deployment override
 *                            (FLAGS env)  >  edition  >  rollout  >  default.
 */
return [
    // Self-hosted / deployment level: "name=on,other=off". Tenant overrides still win in SaaS.
    'overrides' => (string) env('FLAGS', ''),

    // Emergency kill switch, comma-separated flag names forced OFF everywhere, whatever any tenant says.
    'killed' => (string) env('FLAGS_KILLED', ''),

    'flags' => [
        'hearing_sms_reminders' => [
            'description' => 'Send SMS hearing reminders. Ops kill switch: turn off for one firm (or everywhere) when the SMS provider misbehaves.',
            'type' => 'ops',
            'owner' => 'platform',
            'default' => true,
        ],
    ],
];
