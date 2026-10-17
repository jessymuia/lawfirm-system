# Security headers and upload scanning

SEC-14 technical controls that the application now provides itself.

## Response headers

`App\Http\Middleware\SecurityHeaders` runs on every response (web and API). It never replaces a header that is
already set, so a controller (or a proxy in front) can still override one. Pages that send their own strict, nonce-based policy (status, signup,
signing, portal, pay and platform console pages) keep it alone and get no second, generic policy.

| Header | Value | Setting |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | — |
| `X-Frame-Options` | `SAMEORIGIN` | — |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | — |
| `Permissions-Policy` | camera, microphone and location off; payment only for Stripe Checkout | `security_headers.permissions_policy` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`, **only on HTTPS requests** | `SECURITY_HSTS_MAX_AGE` (0 = off), `SECURITY_HSTS_INCLUDE_SUBDOMAINS` |
| `Content-Security-Policy-Report-Only` or `Content-Security-Policy` | `config/security_headers.php` → `csp` | `SECURITY_CSP_MODE` = `report-only` (default) \| `enforce` \| `off`; `SECURITY_CSP_CONNECT` adds origins to `connect-src` |

Behind a TLS-terminating proxy, HSTS is only sent when Laravel knows the request was HTTPS: configure trusted
proxies (`TRUSTED_PROXIES`) so `X-Forwarded-Proto` is honoured. Before turning on `includeSubDomains`, make sure
every subdomain of the host (all firm subdomains on a multi-tenant deployment, and anything else under the same
domain) serves HTTPS, because browsers then refuse plain HTTP to all of them for a year.

`docker/prod/nginx.conf` still sets the first three headers for static files it serves itself; PHP responses
then carry the same values twice, which browsers accept.

### Rolling out the Content Security Policy

1. Ship with the default `report-only`. Browsers report violations to `POST /api/v1/csp-report`, which logs a
   `csp violation` warning (directive, blocked URI, source file and line only; never page content) and answers 204.
   The endpoint needs no session or CSRF token and is limited to 60 reports a minute per client.
2. Use the app for a week or two: every screen, file uploads (add the bucket's origin with `SECURITY_CSP_CONNECT`
   when direct uploads are on), payment, SSO sign-in, the client portal.
3. When the log has no violations that come from the app itself (browser extensions report too, usually
   `chrome-extension:`/`moz-extension:` sources), set `SECURITY_CSP_MODE=enforce`.

## Malware scanning of uploads

`App\Documents\MalwareScanner` sends each document to **clamd** (ClamAV's daemon) over its `INSTREAM` protocol before
the document is filed: uploads through the API and the client portal (`StoreDocument`) and direct-to-bucket uploads
(`DirectUploads::complete`, which scans the object where it landed).

| Setting | Default | |
|---|---|---|
| `DOCUMENTS_MALWARE_SCAN` | `off` | `clamd` turns scanning on |
| `CLAMD_HOST` / `CLAMD_PORT` | `127.0.0.1` / `3310` | where clamd listens (TCP) |
| `CLAMD_TIMEOUT` | `30` | seconds per scan |
| `DOCUMENTS_MALWARE_FAIL_OPEN` | `false` | when clamd cannot be reached: refuse the upload (default) or accept it unscanned, with an error in the log |

- **Infected** → `422` with `"code": "malware_detected"`; nothing is stored (a direct upload's object is deleted) and
  an `Upload refused: malware detected` warning with the signature and file name is logged (with the firm id, like
  every log line).
- **Scanner unavailable** → `503` with `"code": "malware_scan_unavailable"` and a `Malware scanner unavailable` error
  in the log; failing open logs the same error and files the document.
- clamd's `StreamMaxLength` (default 25 MB) must be at least the largest upload you accept: direct uploads go up to
  `DOCUMENTS_DIRECT_MAX_MB` (500). A larger stream gets an `ERROR` from clamd and is treated as unscannable.

Running clamd: the `clamav/clamav` image (`docker run -d -p 3310:3310 clamav/clamav:stable`) keeps its signatures
current with `freshclam`; give it ~1.5 GB of memory. Check it with `echo PING | nc <host> 3310` → `PONG`. Documents
filed before scanning was turned on are not rescanned; scan the storage bucket or volume once with `clamscan -r` if
that matters to you.

Tests use a stand-in that speaks the same protocol (`tests/Support/fake-clamd.php`) and flags the EICAR test string.
