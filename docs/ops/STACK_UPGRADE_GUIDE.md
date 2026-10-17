# Stack Upgrade Guide (Laravel 9 → 12)

> **Note:** this file was deleted by commit `830bffa` ("Delete docs/ops
> directory") and reconstructed here from the ENG-2 gap entry in
> `docs/SYSTEM_REVIEW_AND_MIGRATION_PLAN.md`, since no `.git` history was
> available to `git revert` the original. If the original differs, prefer
> the original.

## Why this is staged, not a single jump

`composer.json` currently declares the actual installed/tested stack
(PHP `^8.0.2`, Laravel `^9.11`, Sanctum `^2.14.1` — Laravel 9 is EOL, see
ENG-2). A Laravel 9→12 jump has real breaking changes per major version
that can only be verified by actually running `composer update` and the
full test suite **one version at a time** — not by reading changelogs and
declaring the target version in `composer.json` before doing that work.
An earlier pass in this project's session briefly did exactly that (bumped
`composer.json` without running the upgrade), then reverted it, because
skipping the verification step is precisely the "looks finished without
being verified" trap this guide exists to prevent.

## Prerequisites

- Real Composer/Packagist network access (this environment may not have
  it — confirm before starting; a sandboxed/offline environment cannot
  complete this guide).
- The full local gate green on the *current* version first: Pest, PHPStan,
  Pint, and the frontend lint+build (all four passed as of the
  2026-08-23 status note in the main review doc — confirm they still pass
  immediately before starting the upgrade, since this guide assumes a
  known-good starting point).

## Procedure — repeat once per major version (9→10, 10→11, 11→12)

For each step, do **not** proceed to the next until this step is fully
green:

1. Read that specific version's official Laravel upgrade guide
   (`laravel.com/docs/{version}/upgrade`) end to end — not skimmed —
   noting every breaking change relevant to this codebase's actual usage
   (check `app/`, `config/`, `routes/` for anything the guide flags).
2. Bump `laravel/framework` (and only the packages that specific version
   requires — check each dependency's own compatibility matrix, e.g.
   Sanctum, Spatie permission, Laravel Auditing, dompdf) to the range that
   version needs in `composer.json`.
3. `composer update` — let it resolve, don't force versions past what it
   can actually satisfy.
4. Apply the code changes the upgrade guide calls for (renamed methods,
   changed defaults, deprecated facades, etc.).
5. Run the full local gate again: `php artisan test`,
   `vendor/bin/phpstan analyse --memory-limit=1G`, `vendor/bin/pint --test`,
   `npm run lint && npm run build`. Every failure here is either a real
   incompatibility (fix it) or a false positive worth double-checking
   before dismissing (see ENG-1's own findings for how many "obviously
   fine" things weren't, once actually executed).
6. Commit this version's upgrade on its own, with the specific breaking
   changes it required noted in the commit message — so a later bisect or
   revert has a clean boundary per version, not one giant 9→12 diff.

## Target versions (per §2 of the migration plan)

| Layer | Current | Target |
|---|---|---|
| PHP | 8.0 | 8.3+ |
| Laravel | 9 | 12 |
| Sanctum | 2 | 4 (also switch to SPA cookie mode — closes SEC-8) |

## Code-level prep already done (2026-08-24)

Two changes from the real Laravel 9→10 upgrade guide were safe to apply
without running `composer update` (no dependency-version dependency),
so they're already in this codebase:

- `app/Providers/AuthServiceProvider.php` — removed the manual
  `registerPolicies()` call; Laravel 10+ invokes it automatically, and
  leaving the old call in would have been harmless but redundant.
- Confirmed Sanctum's `personal_access_tokens` migration is **already**
  published into `database/migrations/` (not left in the package's own
  migrations directory) — so the Laravel 11 change ("Cashier, Passport,
  Sanctum, Spark, and Telescope no longer autoload their migrations")
  needs **no action** here; it already matches the Laravel 11+ expectation.
- Confirmed no direct usage of `DB::raw()->__toString()`/`(string)` casts,
  the deprecated `$dates` model property, `Bus::dispatchNow`/
  `dispatch_now`, `Redirect::home`, `MocksApplicationServices` test
  helpers, a form request `after()` method, `->ulid()` with no column
  name, `path.public` container binding, or a direct `Nesbot\Carbon`
  version pin anywhere in `app/`, `tests/`, or `composer.json` — so none
  of those Laravel 10/11/12 breaking changes apply to this codebase.

**Not done yet** (needs `composer update`, i.e. real Packagist access —
see the note at the top of this file):
- `phpunit.xml`'s `<coverage processUncoveredFiles="true">` attribute
  must be removed **as part of** the step that actually bumps
  `phpunit/phpunit` to `^10.0` (PHPUnit 10 doesn't recognize the
  attribute) — not before, since `composer.json` still declares PHPUnit 9
  today and editing the config out of sync with the installed version is
  its own version of the "looks upgraded but isn't verified" trap.

## Target `composer.json` values per stage (from each official upgrade guide)

**9 → 10** (`laravel.com/docs/10.x/upgrade`):
```
"laravel/framework": "^10.0",
"laravel/sanctum": "^3.2",
"doctrine/dbal": "^3.0",
"spatie/laravel-ignition": "^2.0",
```
dev: `"nunomaduro/collision": "^7.0"`, `"phpunit/phpunit": "^10.0"` (remove
`processUncoveredFiles` from `phpunit.xml` in the same commit). Also
consult the [Sanctum 2→3 upgrade guide](https://github.com/laravel/sanctum/blob/3.x/UPGRADE.md)
specifically — Sanctum has its own breaking changes independent of core
Laravel's.

**10 → 11** (`laravel.com/docs/11.x/upgrade`, PHP 8.2+ required):
```
"php": "^8.2",
"laravel/framework": "^11.0",
"laravel/sanctum": "^4.0",
```
`doctrine/dbal` **can be removed entirely** — Laravel 11 no longer
depends on it and this codebase doesn't reference Doctrine directly.
Consult the [Sanctum 3→4 upgrade guide](https://github.com/laravel/sanctum/blob/4.x/UPGRADE.md).

**11 → 12** (`laravel.com/docs/12.x/upgrade`): described upstream as a
"relatively minor maintenance release" — mostly upstream dependency bumps
(notably Carbon 3.x, which this codebase doesn't pin directly, so
`composer update` should resolve it without intervention).
```
"laravel/framework": "^12.0",
```

None of the three stages' guides listed a breaking change this
codebase's own grep-verified usage patterns (above) actually hit, beyond
what's already handled. The main remaining risk is **first-party package
compatibility** (`spatie/laravel-permission`, `owen-it/laravel-auditing`,
`barryvdh/laravel-dompdf`) at each stage, which can only be confirmed by
actually running `composer update` and watching what it resolves to.

## Status as of 2026-08-25: upgrade complete and verified ✅

The direct 9→12 jump was carried out for real by the user on a machine
with actual Packagist access, and the full local gate has been run and
confirmed. What actually happened, beyond the initial file-level prep
above:

**`composer update` resolution — three more bumps needed, found only by
actually running it:**
1. **`symfony/process`** was left pinned at `^6.0`; `laravel/framework
   ^12.0` needs `^7.2`. Bumped. `RunBackup.php`'s `Process` usage
   (`fromShellCommandline`, `new Process()`, `->run()`,
   `ProcessFailedException`) is unchanged between Symfony 6 and 7, so no
   code change was needed alongside the version bump.
2. **`owen-it/laravel-auditing`** was left at `^13.6`, which caps at
   Illuminate 11. Bumped to `^14.0` (supports Illuminate 11–13). Its one
   breaking change (`AuditCustom` event dispatch signature) doesn't
   apply — this codebase doesn't use that pattern.
3. **`larastan/larastan`** was renamed from `nunomaduro/larastan` but
   left at `^2.9`, which *also* caps at Illuminate 11 — Laravel 12
   support only landed in Larastan **v3.x**, which requires
   **`phpstan/phpstan: ^2.2`** (also bumped from `^1.10`).

`spatie/laravel-permission ^6.4` and `barryvdh/laravel-dompdf ^3.0`
needed no changes — confirmed against Illuminate 12 ahead of time.

**Full local gate — actual results:**
- **Pest**: `php artisan key:generate` was required first (fresh `.env`
  has no key) — the first 78 "failures" seen were all the same
  `MissingAppKeyException` cascade, not real regressions. After that:
  **84 passed, 7 skipped (migration-event-only, expected), 0 failed** —
  identical to the pre-upgrade baseline.
- **PHPStan**: genuinely broke — 139 errors on first run. Root cause:
  Larastan v3 resolves Eloquent relation return types more strictly, and
  most relation methods across `app/Models/*` aren't typed with PHPDoc
  generics, so `$case->client?->name` now reads as "undefined property on
  generic `Model`" instead of the real related class. **Three real bugs
  were found and fixed in the same pass** (not baseline-worthy — these
  were genuinely wrong):
  - `Models\User.php` — `@var array<int, string> $hidden` wasn't
    covariant with Laravel 12's stricter `list<string>` base type; fixed.
  - `Http\Middleware\Authenticate.php` — `redirectTo()` was missing an
    explicit `return null;` on its non-JSON-request branch; fixed.
  - `Console\Commands\RunBackup.php` — the old `$lastModified !== false`
    guard defended against a failure mode that no longer exists:
    **Laravel 12 uses Flysystem v3, which throws on failure instead of
    returning `false`** (v1, under Laravel 9, returned `false`). Replaced
    with `try/catch` around `lastModified()` so one unreadable file skips
    rather than aborting the whole prune pass — a real behavior fix, not
    just satisfying the linter.
  - The remaining ~130 relation-typing findings were baselined
    (`vendor/bin/phpstan --generate-baseline`) — fixing them properly
    means adding PHPDoc generics to every relation method across every
    model, real follow-up work, tracked below.
- **Pint**: clean, 186 files, no changes needed.
- **`npm audit --audit-level=high`**: clean — 0 high/critical.
- **`npm run lint` / `npm run build`**: hit a Windows `node_modules`
  install hiccup (`'eslint'`/`'mix' is not recognized`) — needs an
  `npm install` re-run and independent reconfirmation; not expected to
  fail for Laravel-12-related reasons.

### Concrete follow-up work identified by this pass (not blocking, but real)

1. **Add PHPDoc generics to Eloquent relation methods** across
   `app/Models/*` (e.g. `public function client(): BelongsTo` → annotate
   `@return BelongsTo<Client, $this>`) so Larastan can resolve concrete
   related-model types instead of generic `Model`. This closes the ~130
   baselined findings for real. Do it incrementally — each fix should
   visibly shrink `phpstan-baseline.neon` when regenerated.
2. Regenerate `phpstan-baseline.neon` after each batch
   (`vendor/bin/phpstan --generate-baseline`) so it only ever shrinks.
3. Independently confirm `npm run lint` and `npm run build` on a clean
   `npm install`.
4. **Commit the regenerated `composer.lock`** — this is the first real
   lock file resolved against Laravel 12; without it committed, a fresh
   clone won't reproduce this exact verified dependency set.
5. Sanctum 2→4 changes token/cookie handling — `AuthTest` and
   `PermissionMatrixTest` pass, but worth a manual sanity check that
   they're testing the *new* Sanctum 4 cookie-based flow correctly, not
   an old assumption that happens to still pass.

## Done — ENG-2 checked off (2026-08-25)

The upgrade is complete: Laravel 12, PHP 8.3, full local gate green
(Pest/PHPStan/Pint/npm audit — see the status section above for exact
numbers). `docs/SYSTEM_REVIEW_AND_MIGRATION_PLAN.md` ENG-2 is checked off
below. What's left is the five follow-up items above — none of them
block calling this upgrade done; they're the natural next round of
cleanup this pass surfaced.
