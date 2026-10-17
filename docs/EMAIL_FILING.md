# Filing email to matters (FUN-15)

A matter file without its correspondence is always out of date. Every matter has its own email address; mail sent,
forwarded or copied (Cc/Bcc) to it is filed to the matter with its attachments. Saved messages (`.eml`) can also be
uploaded on the matter's **Emails** tab.

## What is kept

- **The message:** sender, recipients, date, subject and readable text. HTML is converted to text and never displayed
  as HTML.
- **Conversations:** messages in the same conversation (by `References` / `In-Reply-To`) are linked.
- **The original `.eml`:** stored on the firm's files disk with its SHA-256, downloadable as evidence (audited).
- **Attachments:** ordinary matter documents, so they are searchable (FUN-8). They follow the upload rules (document
  types, 25 MB). Others are listed as *not filed* with the reason.
- **Duplicates:** a message filed to the same matter twice (by two people, or sent and uploaded) is kept once (by
  `Message-ID`).
- **Several matters:** one message sent to several matter addresses is filed to each.
- **Removing:** removing a filed message leaves its attachments in the matter's documents. Filing and removing are
  audited.

## Who may file by mail

Settings → Offices & Groups → *Email filing* (permission `manage-organisation`):

| Setting | Filed when the sender is |
|---|---|
| Only our people (default) | the sign-in address of one of the firm's users |
| Anyone at our email domains | any address at a domain one of the firm's users has |
| Anyone with the address | anyone |

Refused mail is ignored (logged, not filed), so a leaked address cannot be used to put documents into a matter. To
file a client's email, forward it from your own address, or copy the matter address into your reply.

## Setting it up (operators)

1. **Choose an inbound mail domain and point its MX at your provider:**
   - Mailgun routes, SendGrid Inbound Parse, Postmark inbound, or your own MTA.
   - Set `EMAIL_INGEST_DOMAIN`. Addresses look like `matter-<24 characters>@<domain>`.
   - On a multi-firm deployment, use `{firm}` in the domain (e.g. `{firm}.in.example.com`) and route each firm's
     subdomain to that firm's host.
2. **Set the webhook secret:** `EMAIL_INGEST_SECRET`, a random string of at least 32 characters.
3. **Have the provider POST each message, as raw MIME, to**
   `https://<firm-host>/api/v1/inbound/email/<EMAIL_INGEST_SECRET>`:
   - **Mailgun:** forward to the URL with *raw MIME* (`body-mime`). Optionally set `EMAIL_INGEST_MAILGUN_SIGNING_KEY`
     to also verify Mailgun's signature.
   - **SendGrid:** Inbound Parse with *POST the raw, full MIME message* (`email`).
   - **Any MTA:** `curl -H 'Content-Type: message/rfc822' --data-binary @- <url>`.
4. **What the endpoint answers:**
   - `200` with the number of matters the message was filed to, also when it filed nothing (unknown address, sender not
     allowed), so providers do not retry;
   - `404` for a wrong secret or signature.

While a firm is read-only (expired licence or trial), inbound mail is refused (`403`); providers retry and then bounce.

## Not covered yet

- Outlook `.msg` files (save as `.eml`, or forward instead).
- Reading a mailbox over IMAP / Microsoft Graph instead of forwarding.
- Moving portability bundles: the parsed messages and attachments travel; the original `.eml` files stay on the old
  install's disk.
