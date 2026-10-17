# Feature flags and edition gating (PLAT-3)

## The policy

**Customer-specific behaviour is a flag, never a branch. We never fork the code for a client.**
No `if ($tenant->slug === 'acme')`, no client-named config, no long-lived customer branch. If one firm needs something
different, it becomes a flag in `config/flags.php` that any firm can have. `FeatureFlagsTest` fails the build when code
compares a tenant slug/key/domain with a literal.

## Flag or entitlement?

| Question | Use |
|---|---|
| Does this plan *include* the capability (commercial)? | Entitlement (`config/entitlements.php`, PLAT-1) |
| Is this behaviour on for this firm / edition / rollout / right now (operational)? | Flag (`config/flags.php`) |

## Defining a flag

Add it to `config/flags.php` with `description`, `owner`, `type` and `default`:

- `release`: ships dark, turned on gradually. **Temporary: needs `expires`**; the suite fails after that date until the flag and its dead branch are removed.
- `experiment`: same, for trials.
- `ops`: a permanent kill switch (e.g. `hearing_sms_reminders`).
- `customer`: a permanent per-firm behaviour.

Optional: `editions` (`['professional' => true]`) and `rollout` (0-100, stable per tenant).

Use it:

```php
app(\App\Flags\Flags::class)->enabled('name');   // code
Route::middleware('flag:name')->...               // route (404 when off)
GET /api/v1/flags                                  // the SPA: {data: {name: bool}}
```

An unregistered flag is always off, and the suite fails if code uses an unregistered flag or a registered flag is unused.

## Resolution order (highest wins)

1. **Killed**: `FLAGS_KILLED=a,b` forces off everywhere, whatever a tenant says (emergency switch).
2. **Tenant override**: set by platform staff per firm (SaaS).
3. **Deployment override**: `FLAGS="a=on,b=off"` (self-hosted installs, or all of SaaS).
4. **Edition**: the flag's `editions[<plan>]`.
5. **Rollout**: `crc32(flag|tenant) % 100 < rollout`; the same firm always gets the same answer.
6. **Default**.

## Per-firm overrides (platform console)

```
GET /api/platform/tenants/{slug}/flags            viewer and up
PUT /api/platform/tenants/{slug}/flags            operator and up
    {"flags": {"name": true|false|null}, "reason": "why"}   null removes the override
```

A reason is required, unknown flag names are refused (422), and every change is in the platform audit log.

## Limits

- Flags are read at request/job time; a long-running worker picks up changes at its next job (memo is cleared per job).
- Tenant overrides live in the control DB and exist only in multi mode; self-hosted uses `FLAGS`.
- The SPA does not yet read `/flags`.
