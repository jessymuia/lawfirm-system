# Moving between firms (UI-16)

On a multi-firm deployment one person can belong to several firms with a single login (TEN-5). When they do, the
account menu in the header lists their firms under **Switch firm**. Choosing one opens that firm already signed in,
without typing the password again. On a single-firm install the list has one entry and the switcher does not show.

## How it works

Every firm has its own host and its own session (TEN-10), so one firm's session is never valid at another. Moving
from firm A to firm B therefore takes four steps:

1. **List firms.** `GET /api/v1/firms` (signed in at A) lists the person's active firms and marks the current one.
2. **Issue a link.** `POST /api/v1/firms/{slug}/switch` (at A) checks that the person belongs to B and that B is
   active. It then issues a random 48-character token, stores only its SHA-256 on the control plane, and answers
   `https://<B's host>/switch/<token>`. A records `firm_switch_issued` in its audit trail.
3. **Open the link.** The browser opens that address on B's host.
4. **Redeem.** B checks the token:
   - it was issued for B;
   - it has not been used before;
   - it is less than 60 seconds old.

   B then spends the token and checks the person: the login is still active, they are still a member of B, and
   their user in B has not been removed. If all is well, B signs them in with a fresh session and records
   `firm_switched_in`.

## The target firm's rules still apply

Switching skips only the password, and never anything a firm asks for beyond it. The person is sent to B's own
sign-in page instead, with a short explanation, when:

| B requires | Sent to | Message |
|---|---|---|
| single sign-on (`enforce` on, and the person is not a security administrator) | `/login?switch=sso` | use "Sign in with SSO" |
| the person's two-factor code at B | `/login?switch=mfa` | enter your two-factor code here |
| (a used, expired, wrong-firm or revoked link) | `/login?switch=invalid` | sign in, or switch again |

A firm whose policy requires two-factor from someone who has not enrolled yet still lets them in. They then land on
the enrolment page, exactly as after a normal sign-in (SEC-12).

## Limits

- **Token use:** tokens are single-use and expire after a minute. `throttle:20,1` limits issuing and
  `throttle:30,1` limits redeeming.
- **Access:** switching never grants access to a firm. Membership is the person's existing one, and their
  permissions in B are B's.
