# Court diary and calendar sync (FUN-16)

## Hearing details and the court diary

- **Hearing details:** hearings can record a **time**, a **length** (default one hour), the **courtroom**, the
  **judicial officer** and the **judiciary e-filing reference**. All are optional; a hearing without a time is an
  all-day entry.
- **Court diary** (sidebar, permission `list-hearings`):
  - lists every hearing day by day, timed ones first, for up to three months at a time;
  - can be narrowed to one court or one advocate;
  - prints cleanly;
  - shows only matters the viewer may open (matter access and ethical walls, SEC-10).
  - API: `GET /api/v1/court-diary?from=&to=&court_id=&assigned_to=`.

## Subscription links (any calendar app)

*Calendar Sync → Subscription links* creates a secret iCalendar link:

- **My hearings and tasks:** hearings on matters the person is responsible for or works on, and their open tasks.
- **Court diary:** every hearing the person may see (needs `list-hearings`).

How the links behave:

- **Calendar apps:** paste the link into Google Calendar (*Other calendars → From URL*), Outlook (*Subscribe from
  web*) or Apple Calendar. They refresh on their own schedule, usually every few hours.
- **Secret links:** the link is the credential (only its SHA-256 is stored) and is shown once. *Turn off* ends it, and
  removing the person ends all their links.
- **What a link shows:** it always shows what its owner may see **now**, not when it was made, so a lost matter grant
  also disappears from the feed.
- **What never leaves the system:** hearing notes and outcomes.
- **Window:** 30 days back to a year ahead (`CALENDAR_PAST_DAYS`, `CALENDAR_FUTURE_DAYS`).

## Direct sync with Google Calendar and Microsoft 365

*Calendar Sync → Connect* links a person's own calendar (OAuth, events scope only). After that:

- **This system to the calendar:** hearings and open tasks are created, updated and removed in the calendar right after
  they change here (queued), and every 15 minutes (`calendar:sync`) as a safety net.
- **Calendar back to this system:**
  - A **task** moved to another day in the calendar takes that day as its due date (audited as
    `calendar_task_moved`).
  - A **hearing** moved or deleted in the calendar is **put back**: the court sets the date. Change hearings here.
- **Disconnect:** removes the events this system created, then forgets the tokens.
- **Token storage:** tokens are encrypted at rest. A token the provider refuses marks the connection *Reconnect*.

### Setting it up (operators)

1. **Register an OAuth app.**
   - **Google:** in Google Cloud, create an OAuth client (Web), enable the Calendar API, and add the
     `.../auth/calendar.events` scope to the consent screen.
   - **Microsoft:** in Entra ID, register an app with the delegated permissions `Calendars.ReadWrite`, `User.Read`
     and `offline_access`.
2. **Register the redirect URI:** `CALENDAR_OAUTH_REDIRECT`, by default `APP_URL/calendar/oauth/callback`.
   - On a multi-firm deployment, register **one** address. The state names the firm's host, encrypted with the
     deployment key, and the browser is handed on to that host to finish.
3. **Set the credentials:** `GOOGLE_CALENDAR_CLIENT_ID` / `_SECRET` and `MICROSOFT_CALENDAR_CLIENT_ID` / `_SECRET` /
   `_TENANT`, or per firm under Integrations (`google_calendar`, `microsoft_calendar`). A firm's own app wins.
4. **Keep the scheduler running** (`calendar:sync`), plus a queue worker or the default `sync` queue.

## E-filing

The judiciary e-filing portal has no public API, so filing itself happens there. Record the e-filing case number on
the hearing; it appears in the diary, the feeds and the synced events.

## Not covered yet

- Filing through the e-filing portal, and importing cause lists.
- Shared team calendars; per-person choice of which calendar to sync into (the default calendar is used).
