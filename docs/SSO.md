# Multi-factor authentication, single sign-on and provisioning (SEC-12)

## What is implemented
- **TOTP MFA** (RFC 6238, verified against the RFC test vectors) for firm users and platform staff, with 10 single-use recovery codes.
- **Firm policy**: a user holding `manage-security-settings` can require MFA for the whole firm (`PUT /api/v1/security/policy`). They must have their own MFA first. Users without MFA get `403 mfa_enrollment_required` on every route except `/mfa*` and logout.
- **OIDC SSO per firm** (authorization-code + PKCE S256), configured at `PUT /api/v1/security/sso` (needs `manage-security-settings` and the `sso` entitlement). The configuration is proven (discovery, issuer match, SSRF check) before it is saved. The client secret is write-only.
- **Enforcement**: with `enforce` on, password login is refused (`403 sso_required`) except for security administrators (break-glass if the IdP is down).
- **IdP MFA attestation**: a firm may declare its IdP enforces MFA (`idp_enforces_mfa`); only then does an SSO login satisfy the firm MFA policy.
- **Platform staff**: MFA is required by default (`PLATFORM_MFA=required`); staff without it receive an enroll-only token. Recovery: `php artisan platform:staff mfa-reset <email>`.

- **SAML 2.0 per firm** (SP-initiated; HTTP-Redirect request, HTTP-POST response), configured at the same `PUT /api/v1/security/sso` with `protocol: "saml"`, `issuer` (the IdP entity ID), `saml_sso_url` and `saml_certificate` (PEM; two while the IdP rolls its key). The firm gives the IdP its entity ID `https://<firm host>/api/v1/sso/saml/metadata` and reply URL `https://<firm host>/api/v1/sso/saml/acs` (the metadata file is served at the first). A firm uses OIDC **or** SAML, not both.
- **SCIM 2.0 user provisioning** at `https://<firm host>/api/v1/scim/v2` with a firm-issued bearer token (Settings, Security; `manage-security-settings`, plan feature `sso`). The IdP can create people (with the role chosen on the token), rename them, and deactivate or reactivate them. Users only: no groups, no bulk.

## SAML: what is checked, and what is refused
The response is accepted only if all of this holds (`app/Sso/Saml/SamlValidator.php`): no DTD or entities; exactly one assertion in the whole document, no encrypted assertions; a signature on the Response or the Assertion that is a direct child of the element it signs, references exactly that element's own unique ID, uses RSA-SHA256/384/512 with a SHA-2 digest and an enveloped-signature transform only (SHA-1 and any other transform are refused), verifies against the firm's configured certificate (the certificate inside the message is ignored), and whose digest matches the canonical form; everything the app reads comes from the assertion inside the verified element. Then: issuer = the configured entity ID, status Success, audience includes our entity ID, a bearer confirmation whose Recipient is our reply URL and InResponseTo is the request we made, validity windows (60 s leeway), an AuthnStatement, and a usable email address (an `email`/`mail` attribute, or a NameID that is an email).
Each sign-in starts with a request ID stored for 10 minutes; the response must answer exactly that request and the request is burnt on first use, so a captured response cannot be replayed. **IdP-initiated sign-in is not supported** (there is no request to bind to); start from the firm's login page.
Verification was tested against responses signed by an independent library (signxml/lxml) and against forged variants: tampered content, signature wrapping (extra assertion, assertion hidden under Extensions, duplicate ID), wrong key, SHA-1, expired, wrong audience, wrong request, wrong issuer, unsigned, DTD (`tests/Unit/SamlValidatorTest.php`, fixtures in `tests/Fixtures/saml-cases.json`). Still worth covering in the penetration test.

## SCIM: rules
- Authenticated by `Authorization: Bearer scm_<prefix>_<secret>`; only the hash is stored; shown once; revocable; the token can reach only `/scim/v2/*`.
- `userName` is the email address and **cannot be changed** through SCIM (it would break the sign-in link). `DELETE` and `active: false` deactivate (the app's soft delete, history stays attributable, API tokens are revoked); `active: true` reactivates, within the plan's user limit.
- The last security administrator cannot be deactivated by the IdP (`409`), so the firm can always sign in with a password and fix the connection. Platform staff are invisible to SCIM.
- Supported: `GET/POST /Users`, `GET/PUT/PATCH/DELETE /Users/{id}`, `filter=userName eq "x"`, `startIndex`/`count`, `ServiceProviderConfig`, `ResourceTypes`, `Schemas`. PATCH accepts both the path form (Entra: `path: "active", value: "False"`) and the no-path object form (Okta). Every change is in the firm's audit trail (`scim_user_*`).

## Not implemented (by decision)
- **Just-in-time user creation at sign-in**: SSO only signs in people the firm already has (created by hand or by SCIM), matched by verified email, then bound to the IdP subject (`sub` / NameID). A changed subject for the same email is refused.
- SCIM groups and bulk; SAML IdP-initiated sign-in, encrypted assertions and signed requests.

## Threats covered by tests
Token defects refused: wrong key, `alg: none`, HS256 with the public key, unknown `kid`, wrong issuer/audience, multiple audiences without `azp`, expired, not-yet-valid, future `iat`, wrong/missing nonce, missing/empty subject, missing/unverified email. State is single-use and expires; PKCE verifier is sent; signing-key rotation is handled; the SSRF guard refuses private/loopback/non-https hosts.

## Operating notes and limits
- MFA and SSO secrets are encrypted with the app key (per-tenant keys are SEC-15). They are **not** part of an eject bundle: after a move users re-enroll MFA and SSO is reconfigured.
- MFA is per firm user record: a person in two firms enrolls in each.
- Resetting a colleague's MFA (`DELETE /users/{user}/mfa`) revokes API tokens; cookie sessions expire on their own.
- The SSRF guard resolves DNS before connecting; a DNS-rebinding window remains. For hostile networks also restrict egress.
- Both callbacks (`/sso/callback`, `/sso/saml/acs`) start the session themselves (`sso.session`): the browser arrives from the identity provider, which Sanctum does not treat as the app's own frontend.
- Env: `PLATFORM_MFA`, `SSO_BLOCK_PRIVATE_HOSTS`, `SSO_ALLOW_HTTP`, `SSO_SUCCESS_REDIRECT`, `SSO_FAILURE_REDIRECT`.
- `SSO_SUCCESS_REDIRECT` defaults to `/login?sso=complete`: the login page loads the new session from `GET /api/v1/session` and routes the person on (to enrolment first if the policy requires it).

## In the web app
- **Login**: a password answered with `mfa_required` turns into a code step (authenticator code, or "Use a recovery code"); "Sign in with SSO" appears when `/sso/config` says SSO is available, and under `enforce` the password form is labelled as the administrators' way in. The session-expired dialog has the same code step.
- **Enrolment the policy requires**: `/auth/two-factor`. Sign-in answers carry a fifth element, `mustEnrolMfa`, so the app goes there directly; any later `403 mfa_enrollment_required` also sends the person there.
- **Account Settings**: set up two-factor (QR drawn in the browser, never by a third-party service), see the recovery codes left, make new ones, turn it off (password and a current code; not while the firm requires it).
- **Settings → Security** (`manage-security-settings`): the firm's two-factor policy and its OIDC provider (shows the redirect URI to register; says so when the plan has no `sso`).
- Rate limits are per endpoint: every `throttle` names its own key (`ThrottleKeysTest`), so loading the login page cannot use up the code step's tries.
