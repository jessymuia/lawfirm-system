# Accessibility (UI-15)

The web app targets **WCAG 2.1 level AA**. Two mechanisms keep it there:

- an automated check that fails CI;
- design tokens, which keep the common cases right by default.

## The gate: axe in the end-to-end suite

`tests-e2e/specs/10-accessibility.spec.js` checks the following with [axe-core](https://github.com/dequelabs/axe-core)
(`@axe-core/playwright`, rules tagged `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`):

- the sign-in pages;
- every main screen, signed in as an administrator;
- a form in a dialog;
- the app painted in a light brand colour.

Each page is checked after its data has loaded, because badges, table headers and form labels only appear then.

**Any violation fails the test.** The failure names:

- the rule;
- the first elements that break it;
- what axe says is wrong with each.

The check runs in CI's e2e job with the rest of the Playwright suite (`npx playwright test`). Nothing is exempted: fix the page rather than adding an exception. When you add a screen, add its path to `SIGNED_IN` in the spec.

Run it locally against a running app:

    cd tests-e2e && npx playwright test specs/10-accessibility.spec.js

**What axe cannot check.** Keyboard order, meaningful focus after an action, and whether text alternatives actually describe things all need a person. Check them by hand when you change a flow. UI-10 did this for the existing screens.

## What pass 2 fixed

- **Zoom.** The viewport no longer sets `maximum-scale=1`, which stopped people zooming on phones.
- **Brand colour contrast.** The primary text and button colour (`--primary-600`) is now readable on white for any firm colour; see docs/BRANDING.md.
- **Secondary text.** Labels, hints and captions were `#888ea8` (3.2:1). They now use the `--text-muted` token: `#626779` (5.6:1) in light mode, and unchanged in dark mode.
- **Smaller contrast fixes.** Badges, export buttons, the cancel button, inline `code`, the dashboard's urgent badge and other-month days in the calendar.
- **Sortable table headers.** These used to be `role="button"`, which cannot carry `aria-sort`. They now keep their column-header role with `aria-sort`, and sorting is a real `<button>` inside the header. Clicking anywhere on the header still sorts.
- **Sidebar list.** The sidebar's `<ul>` contained the scroller's own `<div>` rails; the list now sits inside the scroller.
- **Account settings.** The form's inputs were not tied to their labels.

## Colours in new code

- Use the tokens (`var(--primary-600)`, `var(--text-muted)`, …) rather than hex values. `scripts/audit-hardcoded-colors.js` ratchets the count of hard-coded colours down.
- For small text, check contrast against the background it sits on, including tinted badge backgrounds: 4.5:1 for normal text, 3:1 for large or bold text (18.66px bold, or 24px).
