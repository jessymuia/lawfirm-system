const { test, expect } = require('../fixtures/base');
const { loginAs } = require('../utils/auth');
const { createClient } = require('../fixtures/test-data');
const { selectSearchOption } = require('../fixtures/ui-forms');

/**
 * SEC-13: the Compliance screen. A compliance officer places a legal hold on a client, sets a retention period, and
 * records and verifies a data-subject request, all through the page, and the database shows each step happened.
 */
/** Answer the app's own confirmation dialog (use-confirm.js), typing a reason first when it asks for one. */
async function confirmDialog(page, button, answer) {
  const dialog = page.locator('.swal2-popup');
  await expect(dialog).toBeVisible();
  if (answer !== undefined) await dialog.locator('textarea.swal2-textarea').fill(answer);
  await dialog.getByRole('button', { name: button }).click();
  await expect(dialog).toBeHidden();
}

test.describe('Compliance: holds, retention and data-subject requests', () => {
  test('place and release a hold, set retention, and take a request through verification', async ({ page, db }) => {
    await loginAs(page, 'admin');
    const client = await createClient(page);

    await page.goto('/settings/compliance');
    await selectSearchOption(page, page.locator('form').filter({ hasText: 'Place hold' }), client.name);
    await page.getByLabel('Reason').fill('Pending appeal');
    await page.getByRole('button', { name: 'Place hold' }).click();
    await confirmDialog(page, 'Place hold');
    await expect(page.getByRole('cell', { name: `Client ${client.name}` })).toBeVisible();
    const [hold] = await db.query('SELECT * FROM legal_holds WHERE scope_type = ? AND scope_id = ?', ['client', client.id]);
    expect(hold.reason).toBe('Pending appeal');

    await page.getByRole('button', { name: 'Release' }).first().click();
    await confirmDialog(page, 'Release hold', 'Appeal decided');
    await expect(page.getByRole('cell', { name: /^Released / }).first()).toBeVisible();

    await page.getByRole('tab', { name: 'Retention' }).click();
    await page.getByLabel('Years to keep: Emails filed to closed or settled matters').fill('8');
    await page.getByRole('row', { name: /Emails filed/ }).getByRole('button', { name: 'Save' }).click();
    await expect.poll(async () => (await db.query("SELECT retain_years FROM retention_policies WHERE record_class = 'matter_emails'"))[0]?.retain_years).toBe(8);

    await page.getByRole('tab', { name: 'Data-subject requests' }).click();
    await selectSearchOption(page, page.locator('form').filter({ hasText: 'Record request' }), client.name);
    await page.getByLabel('Requester').fill('Jane Wanjiru');
    await page.getByRole('button', { name: 'Record request' }).click();
    const row = page.getByRole('row', { name: /Jane Wanjiru/ });
    await row.getByRole('button', { name: 'Verify identity' }).click();
    await confirmDialog(page, 'Mark as verified', 'Checked national ID at reception');
    await expect(row.getByRole('cell', { name: 'verified' })).toBeVisible();
    const [dsar] = await db.query('SELECT * FROM dsar_requests WHERE client_id = ?', [client.id]);
    expect([dsar.status, dsar.verification_note]).toEqual(['verified', 'Checked national ID at reception']);
  });
});
