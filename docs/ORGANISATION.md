# Offices and practice groups (FUN-18)

A firm with several offices, or with departments like Litigation and Conveyancing, is still **one firm** (one tenant).
Offices and practice groups organise people and matters inside it. They never split it into tenants: that would force
the cross-tenant queries the architecture removed.

## The model

- **Offices:** name, code, city, address, phone, email. At most one is the head office; marking another moves the flag.
- **Practice groups:** name, code, description, optional head (a person).
- **People:** each person may belong to one office and one practice group (`users.office_id`, `users.practice_group_id`).
- **Matters:** each matter may belong to one office and one practice group. When a matter is opened without saying
  which, it takes its **responsible lawyer's** office and group. An update that does not mention them leaves them
  alone.

Everything is optional. A firm that never sets this up sees no new fields: the web app hides them while the lists are
empty.

## Managing it

Settings → **Offices & Groups** (permission `manage-organisation`):

- add, rename and remove offices and practice groups;
- **Move people** into an office or group, optionally taking their **open** matters with them. Closed and settled
  matters keep the office they were handled in, so reports on past work stay right;
- an office or group that still has people or open matters cannot be removed; move them first.

## Where it shows

- **Forms:** the user form and the matter form have Office and Practice group fields (once the firm has any). The
  matter form offers "Same as the responsible lawyer".
- **Lists:** the matters list filters by office and practice group (kept in the URL, like the status filter). The
  user list API takes `?office_id=` and `?practice_group_id=`.
- **API responses:** matters include `office_id`, `office`, `practice_group_id` and `practice_group`; users include
  `office_id` and `practice_group_id`.
- **Partner reports (FUN-17):** they group by office and practice group.

## API

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/organisation/options`: id + name lists for forms | any signed-in person |
| GET | `/api/v1/organisation`: offices and groups with people and open-matter counts | manage-organisation |
| POST/PUT/DELETE | `/api/v1/organisation/offices[/{id}]` | manage-organisation |
| POST/PUT/DELETE | `/api/v1/organisation/practice-groups[/{id}]` | manage-organisation |
| POST | `/api/v1/organisation/assign` `{user_ids, office_id?, practice_group_id?, apply_to_open_matters?}` | manage-organisation |

## Not included

Visibility is not restricted by office: who can see a matter is still decided by matter access (SEC-10) and ethical
walls (SEC-11). Office-scoped visibility, if a firm needs it, belongs there and not here.
