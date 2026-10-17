# Client portal (FUN-10)

A firm's clients see their own matters, exchange documents and messages with the firm, and pay invoices, at
`https://<firm-host>/portal`. Plan feature `client_portal` (Professional and Enterprise; always on for self-hosted).

## Who can sign in

- **Access is given per client.** On a matter's *Client portal* tab, someone with `manage-client-portal` gives the
  client access; the client is texted an invitation with the portal address. Withdrawing access ends every open
  portal session at once. Both are audited.
- **No passwords.** The client enters the phone number on their client record and gets a six-digit code by SMS. Codes
  last ten minutes, allow five tries and work once; only an HMAC of the code is stored. A number written differently
  (`0712 345 678`, `+254712345678`) still matches, by its last nine digits. Two clients sharing one number cannot sign
  in, because a code sent to it could not say who is signing in.
- **No enumeration.** Asking for a code gives the same answer whether or not the number belongs to anyone.
- **Sessions** are bearer tokens kept in the browser tab (`sessionStorage`), stored hashed on the server. They end after
  an hour without use, twelve hours at most, on sign-out, or when access is withdrawn.
- Clients are not users of the firm: a portal session never reaches staff endpoints, and a staff login means nothing
  to the portal (`portal.session` middleware).

## What the client sees

| Area | Shown | Never shown |
|---|---|---|
| Matters | their own matters: number, description, status, advocate, next hearing, unread messages | other clients' matters (404, same as missing) |
| Hearings | dates and courts | notes, outcomes |
| Documents | documents the firm shared, and the ones they sent | any document not shared |
| Messages | the thread for the matter | — |
| Invoices | issued invoices with what is outstanding; **Pay** opens the online payment page (FUN-11) | drafts, voided invoices, time entries |

Every read starts from the signed-in client's id; ids sent by the browser are only ever used to narrow that set.

## Exchange

- **Sharing:** on the matter's portal tab, pick a document of the matter and share it (optionally texting the client).
  Stop sharing removes it from the portal; the document itself is untouched. The tab shows when the client first opened it.
- **Client uploads** become ordinary matter documents (same file types and 25 MB limit as staff uploads) and are
  listed on the portal tab as *From the client*.
- **Messages:** a reply from the firm texts the client that a message is waiting (the text itself stays in the portal).
  Messages from clients show as unread on the portal tab until opened; `GET /api/v1/client-portal/unread` lists matters
  with unread client messages (respecting matter access).
- Downloads, uploads, shares and access changes are audited (`portal_*` events).

## Read-only firms

When a firm is read-only (expired licence or trial), clients can still sign in and read and download; uploads, messages
and new payment links wait until the firm is active again.

## Not covered yet

- Email sign-in and notifications (clients are reached by phone).
- Several contacts per client (one phone number per client record).
- Staff unread badge in the navigation (the API exists).
