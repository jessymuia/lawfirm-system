<?php

/*
 * FUN-15: filing email to matters. See docs/EMAIL_FILING.md.
 *
 * Each matter has an address "matter-<token>@<domain>". Point the domain's mail at an inbound provider (Mailgun,
 * SendGrid, Postmark…) or your own MTA, and have it POST each message to
 *   https://<firm-host>/api/v1/inbound/email/<webhook secret>
 * as raw MIME. In a multi-firm deployment use "{firm}" in the domain; it becomes the firm's key.
 */
return [
    'domain' => env('EMAIL_INGEST_DOMAIN'),                 // e.g. in.firm.example, or {firm}.in.saas.example
    'webhook_secret' => env('EMAIL_INGEST_SECRET'),         // at least 32 characters; the inbound URL's credential
    'mailgun_signing_key' => env('EMAIL_INGEST_MAILGUN_SIGNING_KEY'),   // optional: also verify Mailgun's signature
    'max_message_kb' => (int) env('EMAIL_INGEST_MAX_KB', 40_000),
];
