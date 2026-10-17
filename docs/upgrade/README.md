# Upgrade guides (PLAT-10)

One guide per release that needs one: `docs/upgrade/<version>.md`. `unreleased.md` collects the actions for the next release while it is
being built; `scripts/release.sh` renames it to the release's version. Start from [TEMPLATE.md](TEMPLATE.md).

**Where customers see them**
- `php artisan release:notes [from] [to]` (default: the release recorded in this installation → this code). `app:upgrade` prints the same summary and points to the guides before it changes anything.
- `GET /api/v1/releases` and `GET /api/v1/releases/{version}` (any signed-in user): structured changelog, breaking changes and the guide text.

**Rules (enforced by `php artisan release:check`, run by `scripts/release.sh` and the release workflow)**
- CHANGELOG.md keeps `## [Unreleased]` first, then releases newest first as `## [x.y.z] - YYYY-MM-DD`; `VERSION` equals the newest release.
- A release that has a breaking change (an entry under `### Breaking`, or one starting with `BREAKING`) or that is a minor/major step needs a guide with the sections *Before you upgrade*, *Required actions* and *Rollback*.
- Entries are written for customers. Everything here is readable by every signed-in user of every firm, so no internal hostnames, no names of firms, no security-sensitive detail.

**Drafting the changelog from commits.** With conventional commits (`feat(scope)!: …`, `fix: …`):
`git log --format=%s v1.0.0..HEAD | php artisan release:draft` prints grouped entries and a suggested bump. A person edits the draft; it is never written automatically.

**Skipping versions.** Patch releases may be skipped. Do not skip a minor or major boundary without reading the guides between: `release:notes` lists them.
