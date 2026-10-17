# Self-hosted installation guide (PLAT-5)

Three ways to run the same release. Pick one.

| Method | Use when | Artifact |
|---|---|---|
| **Docker Compose** | One server, simplest | image + `deploy/selfhost/docker-compose.yml` |
| **Helm (Kubernetes)** | You already run a cluster | chart `deploy/helm/lawfirm` (OCI: `oci://ghcr.io/<owner>/charts/lawfirm`) |
| **Bare metal / VM** | No containers allowed | `lawfirm-<version>.tar.gz` bundle |

Releases follow [semver](https://semver.org/) (`MAJOR.MINOR.PATCH`, pre-releases `-rc.N`). Pin a version; never track `latest` in production.
Every release publishes: the image (`ghcr.io/<owner>/lawfirm:<version>`, amd64 + arm64), the Helm chart, the bundle + its `.sha256`, and release notes from `CHANGELOG.md`.

## Minimum system requirements
These are sizing **estimates for a firm of up to ~25 users**, not measured limits (load testing is backlog item ENG-27).

| | Minimum | Comfortable |
|---|---|---|
| CPU | 2 vCPU | 4 vCPU |
| RAM | 4 GB (app 1 GB + database 2 GB + OS) | 8 GB |
| Disk | 20 GB + your documents | SSD, 100 GB+; plan for document growth |
| Database | MySQL 8.0 or MariaDB 10.6+ | managed service with backups |
| OS (compose) | any Linux with Docker 24+ and Compose v2 | |
| PHP (bare metal only) | 8.3 with `pdo_mysql bcmath gd zip intl exif pcntl opcache sodium` | |
| Network | outbound SMTP; inbound 443 via your reverse proxy | |

Database support: **tested on MariaDB 10.11** (and SQLite for the test suite). MySQL 8.0 is the compose default and is expected to
work (the schema and the ledger triggers use syntax both share), but a full run against real MySQL 8 has not been done yet: do a
trial install and `php artisan test` equivalent checks before relying on it.

## A. Docker Compose
```bash
mkdir lawfirm && cd lawfirm
# copy deploy/selfhost/docker-compose.yml and deploy/selfhost/.env.example from the release
cp .env.example .env
docker run --rm ghcr.io/<owner>/lawfirm:<version> artisan key:generate --show   # -> put in APP_KEY
$EDITOR .env        # APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD, APP_URL, INSTALL_ADMIN_EMAIL, mail settings
docker compose up -d
docker compose logs migrate     # first run: shows the generated admin password ONCE if you left it blank
```
What happens: `db` starts and becomes healthy, `migrate` runs `php artisan app:install` (schema, permissions, roles, first
admin if none exists) and exits, then `web`, `queue` and `scheduler` start. Open `APP_URL` through your TLS reverse proxy.
Missing secrets stop `docker compose` with a message naming the variable.

**TLS / reverse proxy.** The stack serves plain HTTP on `HTTP_PORT` (8080). Put nginx, Caddy, Traefik or a cloud load balancer in front,
terminate TLS there, and set `TRUSTED_PROXIES` to its address so the app sees `https` and the real client IP. Example Caddy:
`lawfirm.example.com { reverse_proxy 127.0.0.1:8080 }` with `TRUSTED_PROXIES=127.0.0.1`.
Without it, secure cookies and generated URLs will be wrong.

## B. Helm
```bash
helm install lawfirm oci://ghcr.io/<owner>/charts/lawfirm --version <version> -f my-values.yaml
```
Minimum `my-values.yaml`:
```yaml
app: { url: https://lawfirm.example.com, statefulDomains: lawfirm.example.com }
database: { host: mysql.internal }
secrets: { appKey: "base64:…", dbPassword: "…" }     # or: existingSecret: my-secret  (keys APP_KEY, DB_PASSWORD)
ingress: { enabled: true, className: nginx, host: lawfirm.example.com }
```
- The database is **external**; the chart does not run one.
- A `pre-install,pre-upgrade` hook Job runs `app:install` before new pods start, so pods never meet an old schema.
- The chart-managed Secret is also a hook (it must exist before the Job). `helm uninstall` leaves it: delete it by hand.
- Documents live on a PVC kept on uninstall. `ReadWriteOnce` (default) pins web, queue and scheduler to one node and limits web to 1 replica;
  to scale web, use `persistence.accessMode: ReadWriteMany` (NFS/CephFS/EFS) or move documents to S3-compatible storage.
- The chart renders in CI with the real `helm lint`/`helm template`. It has **not** been installed on a live cluster by us yet.

## C. Bare metal / VM
```bash
tar xzf lawfirm-<version>.tar.gz && cd lawfirm-<version>
sha256sum -c ../lawfirm-<version>.tar.gz.sha256            # verify the download first
cp .env.example .env && php artisan key:generate           # then edit DB_*, APP_URL, MAIL_*, TRUSTED_PROXIES
php artisan app:install --admin-email=you@firm.example     # migrate + permissions + roles + first admin
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
Serve `public/` with nginx + php-fpm (see `docker/prod/nginx.conf` for a working server block; remember `client_max_body_size`),
run `php artisan queue:work --queue=default,bulk --tries=3 --timeout=3700 --max-time=3600` (docs/ops/QUEUES.md) under Supervisor/systemd, and add the scheduler to cron:
`* * * * * php /path/artisan schedule:run`. The bundle ships built assets and production dependencies.

## After install
1. Sign in as the admin; **change the password** and remove `INSTALL_ADMIN_PASSWORD` from your config.
2. Install your license if your agreement requires one: `php artisan license:install firm.lic` (see `LICENSING.md`; `LICENSE_REQUIRED=true`
   makes an unlicensed install read-only, so install it first).
3. Configure backups (`php artisan backup:run`, scheduled nightly; see `docs/ops/BACKUP_STRATEGY.md`): the image includes `mysqldump`.
   **Test a restore** before you trust it.
4. Look at `docker compose logs web`: one JSON object per line with a `request_id` you can quote to support.

## Upgrading and rolling back
Details: `docs/ops/UPGRADE_ROLLBACK.md`.
1. **Back up** the database and the storage volume (`php artisan backup:run`). Read `CHANGELOG.md` for every version between yours and the target.
2. Preview: `php artisan app:upgrade --dry-run` lists pending migrations and changes nothing.
3. Change `LAWFIRM_VERSION` (compose) or `--version` (Helm). Compose: `docker compose pull && docker compose up -d`; the `migrate` service runs
   `app:install` → `app:upgrade` first. Helm: `helm upgrade …`; the pre-upgrade hook does the same. Bare metal: `deploy/switch-release.sh deploy <bundle>`
   unpacks beside the live release, runs `app:upgrade`, flips a symlink atomically and health-checks.
4. `app:upgrade` is idempotent and re-syncs the admin role so new permissions reach it.
5. **Rollback:** migrations are forward-only but follow expand/contract (`MIGRATION_POLICY.md`), so the *previous minor release keeps working against the
   new schema*. Bare metal: `switch-release.sh rollback`. Containers: redeploy the previous version tag. `app:upgrade` refuses anything more than one
   minor older; then restore the backup from step 1 and redeploy the matching release.
6. Do not skip a MINOR/MAJOR release without reading the notes in between.

## Do not
- Run `php artisan app:init`: it **wipes the database**. (It now refuses without `--wipe`.) Use `app:install`.
- Publish the database port, or reuse the root DB password for the app (compose keeps it out of the app containers).
- Commit `.env`, or lose `APP_KEY`.

## What is and is not verified (honest status)
Verified by automated tests: the installer (idempotent, never wipes), release/versioning script, the release bundle contents (no tests, no
vendor-only license tools, checksum), the app booting with those tools removed, compose structure and required variables, the Helm chart
rendered through a stand-in renderer (structure, selectors, hooks, secrets, scaling guard), proxy trust, and the image recipe's static rules.
**Not verified here** (no Docker daemon, Helm or cluster in the build sandbox): `docker build` of the image, the container actually starting,
`docker compose up`, `helm lint`/install, multi-arch builds. The release workflow performs the image build and real `helm lint` on every tag; do a
trial release of an `-rc` tag and run this guide end to end before the first customer install.
