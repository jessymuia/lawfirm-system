# Law Management System (Law)

A multi-firm law practice platform: a Laravel 11 / PHP 8.3 API, a Vue 3 firm app, and a separate
**Operator** console for the people who run the platform (firms, plans, billing, backups, staff).
Each firm has its own database, so firms never see each other's data.

## 1. Quick start with Docker (recommended)

Needs only [Docker](https://docs.docker.com/get-docker/) with Compose v2 (on Windows use Docker Desktop with WSL integration on,
and run the commands from your WSL Ubuntu shell, inside the project folder, not under `/mnt/c`).

    unzip law-management-system-updated.zip && cd law-management-system
    export UID GID                         # Linux/WSL: containers run as you, so files stay yours
    docker compose up --build

The first start takes a few minutes: it installs Composer and npm packages, builds the frontend, creates the database
and loads the demo data. Watch progress with `docker compose logs -f app` and wait for the line
`>>> Setup finished.`

| What | Where |
|------|-------|
| App (sign in as **Firm** or **Operator**) | http://localhost:8089 (other port: `APP_PORT=8090 docker compose up`) |
| MailHog (catches all outbound mail) | http://localhost:8025 |
| MySQL (GUI clients) | `127.0.0.1:33060`, user `root`, password `secret`, db `lawfirm` |

Stop with `Ctrl+C` (or `docker compose down`). Start again later with `docker compose up` (no `--build` needed).
`docker compose down -v` also wipes the database, so the next start begins fresh.

### Sign-in

The sign-in page has a **Firm / Operator** switch. Every demo login is listed in [docs/DEMO_LOGINS.md](docs/DEMO_LOGINS.md). The two you need first:

| Side | Email | Password |
|------|-------|----------|
| Operator (platform super admin) | `superadmin@law.co.ke` | `Law@Admin#2026` |
| Firm (Wanjiru & Otieno, managing partner) | `grace.kamau@wanjiruotieno.co.ke` | `Law@Grace2026!` |

All names, numbers and cases are invented. Change or delete these accounts before any real use.

## 2. Loading demo data

The first start loads the super admin and **one complete sample firm** (people, clients, matters, hearings, invoices).
More sample firms, in different billing situations, load on demand. Run these from the project folder
(skip the `docker compose exec app` part if you are not using Docker):

| Do this | Command |
|---|---|
| See the sample firms and which are loaded | `docker compose exec app php artisan law:demo-load --list` |
| Load one or more | `docker compose exec app php artisan law:demo-load coast-legal rift-valley-advocates` |
| Load all of them | `docker compose exec app php artisan law:demo-load --all` |
| Remove some | `docker compose exec app php artisan law:demo-remove coast-legal` (asks first; `--force` skips the question) |
| Remove all sample firms | `docker compose exec app php artisan law:demo-remove --all` |
| Re-run the base setup / restore the super admin password | `docker compose exec app php artisan law:demo-setup --reset-admin` |
| Refresh the usage figures shown in the Operator overview | `docker compose exec app php artisan tenants:usage` |

Sample firms:

| Slug | Firm | Opens at | Situation |
|---|---|---|---|
| `wanjiru-otieno` (default) | Wanjiru & Otieno Advocates, Nairobi | http://localhost:8089 | enterprise plan, paid |
| `coast-legal` | Coast Legal Partners, Mombasa | http://coast-legal.localhost:8089 | professional plan, first invoice open |
| `rift-valley-advocates` | Rift Valley Advocates LLP, Eldoret | http://rift-valley-advocates.localhost:8089 | free trial |

Browsers resolve `*.localhost` to your own machine, so no hosts-file change is needed. To see a populated Operator overview
(revenue, overdue invoices, trials, recent sign-ups), load all the firms: `law:demo-load --all`.
Removing a sample firm deletes its database, files and billing records, so it can be loaded fresh again. Only sample firms are
touched; real firms never are. These commands refuse to run when `APP_ENV=production`.

## 2b. Self-service trial signup and support access

**Trial signup.** The Docker stack turns it on (`TENANCY_SIGNUP_HOST=join.localhost`). The Firm sign-in page shows
"Start a free trial", which opens http://join.localhost:8089/signup. Fill in the form, then open the verification email in
MailHog (http://localhost:8025) and click the link. Your new firm is created with a 14-day trial and opens at
`http://<your-address>.localhost:8089`. In production set `TENANCY_SIGNUP_HOST` to your signup host; leave it unset to switch signup off.
Details: [docs/ops/SIGNUP.md](docs/ops/SIGNUP.md).

**Support access (audited impersonation).** Platform staff never have standing access to a firm.
1. Operator console, open a firm, **Support access** tab: request a session with a reason, duration and mode.
2. Firm side, **Settings, Support access**: the firm's admin approves or denies, sets the policy (view-only or edit, longest session) and can cut a session off at any moment.
3. Back in the console, **Start session** gives a one-time code. Every action is recorded in both the platform and the firm's own audit logs, and the firm can read the activity list.
Details: [docs/SUPPORT_ACCESS.md](docs/SUPPORT_ACCESS.md).

## 3. Everyday commands (Docker)

    docker compose exec app php artisan test                 # backend tests (in-memory sqlite)
    docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
    docker compose exec app vendor/bin/pint --test           # code style
    docker compose exec app php artisan billing:run          # issue due invoices now (also runs nightly at 02:30)
    docker compose run --rm assets npm run build             # rebuild the frontend after changing Vue/JS
    docker compose logs -f app                               # watch the app log

The stack also runs a queue worker (mail/SMS jobs) and the scheduler (hearing reminders, billing, nightly backups).

**After replacing project files** (for example unzipping an update over your folder), rebuild the frontend once:
`docker compose run --rm assets npm run build`, then hard-refresh the browser (Ctrl+Shift+R). The Operator console
(`public/platform-console.js`) needs no build.

## 4. Troubleshooting

| Symptom | Fix |
|---|---|
| Sign-in page shows an error right after start | Setup is still running in the background. Wait for `>>> Setup finished.` in `docker compose logs -f app`. |
| Port 8089 or 33060 already in use | `APP_PORT=8090 docker compose up`, or stop whatever uses the port. |
| Permission errors on `storage/` or `vendor/` | Run `export UID GID` before `docker compose up`, or `sudo chown -R $USER:$USER .`. |
| Changes to Vue files do not show | `docker compose run --rm assets npm run build`, then hard-refresh. |
| Want to start over | `docker compose down -v && docker compose up`. |
| `Connection refused ... 127.0.0.1:3306` when running `php artisan` on your host | Run artisan inside the container: `docker compose exec app php artisan ...`. |

This stack is for local testing only: `php artisan serve`, root database credentials and demo passwords are not a production setup.

## 5. Manual install (without Docker)

Needs PHP 8.3 (with the usual extensions: mbstring, xml, curl, zip, gd, bcmath, pdo_mysql), Composer 2, Node 22 with npm, and MySQL 8.

    cp .env.example .env              # then set DB_*, APP_URL, and the MAIL_* settings
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan key:generate

**Production** (single firm, one database):

    php artisan app:install --admin-name="Your Name" --admin-email=you@firm.co.ke
    # prints a generated admin password once if you omit --admin-password; safe to re-run after every upgrade
    # set APP_ENV=production, APP_DEBUG=false in .env
    # run a queue worker (php artisan queue:work) and a scheduler (* * * * * php artisan schedule:run)
    # point the web server's document root at public/

**Local multi-firm with demo data** (what Docker does for you):

    # in .env: TENANCY_MODE=multi, PLATFORM_CONSOLE_ON_APP_HOST=true, TENANCY_BASE_DOMAIN=localhost, APP_ENV=local
    php artisan law:demo-setup
    php artisan serve --port=8089
    php artisan law:demo-load --all   # optional extra sample firms

`php artisan app:init --wipe` drops every table and all data. Use it on a development machine only.
For production hosting, upgrades, backups and self-hosting see [docs/INSTALL_SELFHOST.md](docs/INSTALL_SELFHOST.md) and the `deploy/` folder.
