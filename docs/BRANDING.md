# Firm branding (UI-13)

**Settings → Branding** (permission `manage-branding`, given to the built-in `admin` role) sets how the firm looks
to its own people and on the documents it sends.

| Setting | Where it shows |
|---|---|
| Firm name as shown | sidebar, header, sign-in page, browser tab, installed app's name, letterhead |
| Primary colour | the web app's whole primary colour scale, the installed app's theme colour, the letterhead rule and name |
| Logo (PNG, JPEG or WebP, up to 1 MB) | sidebar, header, sign-in page, invoice preview, letterhead |
| Letterhead: address, phone, email, website, tax PIN | under the logo on invoice PDFs and signature certificates |
| Invoice footer | at the foot of every invoice PDF (bank details, payment terms) |

## How the colour reaches the app

The web app's components colour themselves through design tokens (`var(--primary-50)` … `var(--primary-900)`,
`resources/js/src/assets/sass/design-tokens.scss`). On load, `resources/js/src/branding.ts` derives the whole scale
from the firm's colour and sets it on the page:

- **600** is the firm's colour, darkened just enough to be readable as text on white (4.9:1, WCAG AA with a margin for
  the light tints; UI-15). A colour that is already dark enough is used unchanged. The theme colour of the installed app
  is the firm's colour exactly.
- **50 to 500** are mixed toward white; **700 to 900** are mixed toward black.

The last branding seen is kept in the browser, so the next visit paints in the firm's colour at once. The colours of
the existing components are now on these tokens.

**Ratchet on hard-coded colours.** `scripts/audit-hardcoded-colors.js` counts the hex colours still written directly
into components (692 when branding landed, down from 758). CI fails if the count rises above
`scripts/hardcoded-colors-baseline.json`. When a sweep lowers it, lower the baseline to lock the gain in.

## Storage and security

- **Storage:**
  - Settings are kept in the firm's own database (`firm_settings.branding`), so they travel with a firm export.
  - The logo is kept on the firm's files disk under its prefix (TEN-12).
  - Nothing is shared with other firms.
- **Public endpoints:** `GET /api/v1/branding` and `GET /api/v1/branding/logo` need no sign-in, because the sign-in
  page shows them. They expose only the name, the colour and the logo; letterhead lines are visible only in
  settings and on documents.
- **Logo checks:** the logo is checked by its bytes, not its name or the browser's label. SVG is refused because it
  can carry script.
- **Escaping:** every text value is escaped wherever it is shown, including in PDFs.
- **Audit trail:** changes record `branding_updated`, `branding_logo_changed` and `branding_logo_removed`.
