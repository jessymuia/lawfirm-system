const { test, expect } = require('../fixtures/base');
const { loginAs } = require('../utils/auth');
const { expectAccessible } = require('../utils/a11y');
const { apiPut } = require('../utils/api');

/**
 * UI-15: every main screen passes axe-core's WCAG 2.1 A/AA rules, signed out and signed in, so an accessibility
 * regression fails CI like any other. Pages are checked once they have loaded their data (network idle), because most
 * of what can go wrong (contrast of badges, labels of form fields, table headers) is only on screen then.
 */
const SIGNED_OUT = ['/login', '/auth/pass-recovery'];

const SIGNED_IN = [
  '/', '/today', '/clients', '/cases', '/cases/create', '/invoices', '/tasks', '/hearings', '/hearings/calendar',
  '/expenses', '/time-entries', '/users', '/roles', '/reports', '/trust', '/conflicts', '/court-diary',
  '/document-templates', '/courts', '/courttypes', '/hearingtypes', '/expense-categories', '/calendar-sync',
  '/settings/branding', '/settings/finance', '/settings/compliance', '/settings/security', '/settings/organisation', '/settings/subscription', '/users/account-setting',
];

async function open(page, path) {
  await page.goto(path);
  await page.waitForLoadState('networkidle');
}

test.describe('Accessibility (axe, WCAG 2.1 AA)', () => {
  for (const path of SIGNED_OUT) {
    test(`signed out: ${path}`, async ({ page }) => {
      await open(page, path);
      await expectAccessible(page, path);
    });
  }

  test('signed in: every main screen', async ({ page }) => {
    test.setTimeout(300_000);
    await loginAs(page, 'admin');
    for (const path of SIGNED_IN) {
      await open(page, path);
      await expectAccessible(page, path);
    }
  });

  test('signed in: a form in a dialog', async ({ page }) => {
    await loginAs(page, 'admin');
    await open(page, '/clients');
    await page.getByRole('button', { name: 'Add New Client' }).first().click();
    const dialog = page.locator('.modal.show');
    await dialog.waitFor();
    // scan the dialog as it ends up, not mid fade-in (a half-transparent label reads as low contrast)
    await dialog.evaluate((el) => Promise.all(el.getAnimations({ subtree: true }).map((a) => a.finished)));
    await expectAccessible(page, '/clients add-client dialog', { include: '.modal.show' });
  });

  test('a firm\'s light brand colour is darkened until text in it is readable', async ({ page }) => {
    await loginAs(page, 'admin');
    const before = (await page.request.get('/api/v1/branding/settings').then((r) => r.json())).data;
    try {
      expect((await apiPut(page, '/api/v1/branding', { color: '#ffcc00' })).ok()).toBeTruthy();
      await open(page, '/');
      const ratio = await page.evaluate(() => {
        const hex = getComputedStyle(document.documentElement).getPropertyValue('--primary-600').trim();
        const lin = (v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
        const [r, g, b] = [1, 3, 5].map((i) => lin(parseInt(hex.slice(i, i + 2), 16) / 255));
        return 1.05 / (0.2126 * r + 0.7152 * g + 0.0722 * b + 0.05);
      });
      expect(ratio).toBeGreaterThanOrEqual(4.5);
      await expectAccessible(page, '/ in a light brand colour');
    } finally {
      await apiPut(page, '/api/v1/branding', { color: before.color });
    }
  });
});
