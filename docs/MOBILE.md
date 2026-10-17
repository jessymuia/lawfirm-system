# Mobile: the installable web app (FUN-19)

Hearing days are spent away from a desk. The web app can be installed on a phone (or desktop) like an app and has a
view made for the court corridor. There is no separate native app to maintain: it is the same app, same sign-in, same
permissions.

## Installing

- **Android (Chrome, Edge):** open the firm's address, then *Install app* (menu, or the button on *Today*).
- **iPhone (Safari):** *Share → Add to Home Screen*.
- **Desktop Chrome/Edge:** the install icon in the address bar.

The installed app opens on **Today**, under the firm's name (`/manifest.webmanifest`, generated per firm) and the
scales icon (`public/images/pwa`). Installing requires HTTPS (or `localhost`).

## Today

`/today` (sidebar *Today*), for the signed-in person:

- **Hearings:** their hearings today and tomorrow (matters they are responsible for or work on). Each shows the time,
  court and courtroom, the judicial officer, a *Call client* button and a link to the matter.
- **Tasks:** tasks due today and overdue.
- **Record outcome:** what happened in court and, if the court gave one, the **next date** (and time, and what it is
  for). The next date becomes a new hearing in the same court and courtroom, in one step
  (`POST /api/v1/hearings/{id}/outcome`). It needs `update-hearings`, plus `create-hearings` for the next date. Matter
  access applies, and it is audited (`hearing_outcome_recorded`). The next hearing then reaches the person's synced
  calendar (FUN-16).

## Offline

The service worker (`public/sw.js`):

- **Cache first:** the app's files (scripts, styles, fonts, images), which the build versions.
- **Network first:** pages. Offline, the app shell last seen is shown, or `offline.html`.
- **Kept for offline reading:** only the hearing-day data (`/api/v1/today`, `/api/v1/court-diary`). It is wiped when
  someone signs out or a new person signs in on the device. Every other API response is never cached.
- **Never touched:** the client-facing pages (portal, payment, signing), calendar feeds and callbacks.
- **Offline writes:** recording outcomes needs a connection; the button is hidden while offline.

## Not covered yet

- Push notifications (hearing reminders arrive by SMS, FUN-3).
- Queueing outcomes typed offline to send later.
- Native app-store apps (the PWA covers the hearing-day need first, per the backlog).
