# Law Management System (Law): demo sign-ins

Created by `php artisan law:demo-setup` (the Docker stack runs it on first start; it is safe to run again). **Demo data only:**
the people, firms, companies, phone numbers and case numbers are invented. Change or delete these accounts before any real use.
The command refuses to run in a production environment.

## Commands

A fresh run loads the super admin and **one complete sample firm** (Wanjiru & Otieno Advocates). The other sample firms load on demand:

| Do this | Command |
|---|---|
| See the packs and whether each is loaded | `php artisan law:demo-load --list` |
| Load specific packs | `php artisan law:demo-load coast-legal rift-valley-advocates` |
| Load every pack | `php artisan law:demo-load --all` |
| Remove packs | `php artisan law:demo-remove coast-legal` (asks first; `--force` skips the question) |
| Remove all demo packs | `php artisan law:demo-remove --all` (`--with-admin` also removes the demo super admin) |
| Re-run the base setup | `php artisan law:demo-setup` (`--reset-admin` restores the demo password) |

In Docker prefix with `docker compose exec app`. Only the demo packs can be removed this way; real firms are never touched.
Works in multi-firm mode only and refuses to run in production. Removing a pack deletes its database, files and billing records, so it can be loaded fresh again.

Open the app (Docker: <http://localhost:8089>) and use the **Firm / Operator** switch on the sign-in page.

## Operator (platform super admin): choose "Operator"

| Name | Email | Password |
|---|---|---|
| Super Admin | `superadmin@law.co.ke` | `Law@Admin#2026` |

Override with `LAW_SUPERADMIN_EMAIL` / `LAW_SUPERADMIN_PASSWORD` before the first run, or restore the demo password with
`php artisan law:demo-setup --reset-admin`. Two-factor for platform staff is optional in the demo stack (`PLATFORM_MFA=optional`);
turn it on in the console under *Staff security*. Production keeps it required.

## Firms: choose "Firm"

The first firm answers on plain `http://localhost:8089`, so it is the one the Firm tab signs you in to. The other two (loaded on demand, see Commands) answer on
`http://<slug>.localhost:8089` (browsers resolve `*.localhost` to your own machine); use the same page there.

### Wanjiru & Otieno Advocates (Nairobi) — `http://localhost:8089` — enterprise plan, subscription paid

| Person | Role | Email | Password |
|---|---|---|---|
| Grace Wanjiru Kamau | Managing Partner (admin) | `grace.kamau@wanjiruotieno.co.ke` | `Law@Grace2026!` |
| David Otieno Odhiambo | Partner, Litigation (advocate) | `david.odhiambo@wanjiruotieno.co.ke` | `Law@David2026!` |
| Amina Hassan Abdi | Senior Associate, Commercial (advocate) | `amina.abdi@wanjiruotieno.co.ke` | `Law@Amina2026!` |
| Peter Kiprono Rotich | Associate, Land (advocate) | `peter.rotich@wanjiruotieno.co.ke` | `Law@Peter2026!` |
| Mercy Achieng Onyango | Associate, Family and Succession (advocate) | `mercy.onyango@wanjiruotieno.co.ke` | `Law@Mercy2026!` |
| Brian Mutua Musyoka | Court Clerk (read-only clerk) | `brian.musyoka@wanjiruotieno.co.ke` | `Law@Brian2026!` |
| Faith Njeri Gathoni | Accounts Clerk (read-only clerk) | `faith.gathoni@wanjiruotieno.co.ke` | `Law@Faith2026!` |

Nine matters at the High Court (Milimani), the Environment and Land Court, the Employment and Labour Relations Court and the
Chief Magistrate's Court; 29 hearings (past with outcomes, and coming up), tasks, time entries and five invoices (VAT at 16%).

### Coast Legal Partners (Mombasa) — `http://coast-legal.localhost:8089` — professional plan, first invoice open

| Person | Role | Email | Password |
|---|---|---|---|
| Hassan Mohamed Said | Managing Partner (admin) | `hassan.said@coastlegal.co.ke` | `Law@Hassan2026!` |
| Fatuma Bakari Juma | Senior Associate (advocate) | `fatuma.juma@coastlegal.co.ke` | `Law@Fatuma2026!` |
| Salim Kassim Mwinyi | Associate (advocate) | `salim.mwinyi@coastlegal.co.ke` | `Law@Salim2026!` |
| Rose Mwende Kioko | Court Clerk | `rose.kioko@coastlegal.co.ke` | `Law@Rose2026!` |

### Rift Valley Advocates LLP (Eldoret) — `http://rift-valley-advocates.localhost:8089` — free trial

| Person | Role | Email | Password |
|---|---|---|---|
| Joseph Kipchoge Cheruiyot | Senior Partner (admin) | `joseph.cheruiyot@riftvalleyadvocates.co.ke` | `Law@Joseph2026!` |
| Naomi Chebet Koech | Associate (advocate) | `naomi.koech@riftvalleyadvocates.co.ke` | `Law@Naomi2026!` |
| Samuel Kimani Ngugi | Court Clerk | `samuel.ngugi@riftvalleyadvocates.co.ke` | `Law@Samuel2026!` |

## Not in Docker?

Run it with `TENANCY_MODE=multi`, `TENANCY_BASE_DOMAIN=localhost`, `PLATFORM_CONSOLE_ON_APP_HOST=true`, `PLATFORM_MFA=optional` and a
MySQL the app can create databases on, then `php artisan law:demo-setup`.
