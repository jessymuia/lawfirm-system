const { test, expect } = require('../fixtures/base');
const { loginAs } = require('../utils/auth');
const { createClient, createCase } = require('../fixtures/test-data');

/**
 * Journey 7: Delete flows / Audit logging.
 *
 * Covers two representative delete flows (client, case) end to end, plus
 * a dedicated unauthorized-deletion check. Every "delete" in this app is
 * a soft-delete (DefaultAppModel uses SoftDeletes) with a deleted_by
 * stamp (DATA-7), and every model extending DefaultAppModel is Auditable
 * (owen-it/laravel-auditing, config/audit.php tracks the 'deleted' event)
 * — this spec asserts both, not just that the row vanished from the UI.
 */
test.describe('Delete flows / Audit logging', () => {
  let client;
  let standaloneCase;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await loginAs(page, 'advocate');
    client = await createClient(page);
    standaloneCase = await createCase(page, client);
    await context.close();
  });

  test.afterAll(async () => {
    const { Db } = require('../utils/db');
    const db = new Db();
    await db.connect();
    // Best-effort: these may already be gone by the time cleanup runs,
    // since deleting them is exactly what the tests below do.
    if (standaloneCase) await db.deleteCaseAndDependents(standaloneCase.id).catch(() => {});
    if (client) await db.deleteClientAndDependents(client.id).catch(() => {});
    await db.close();
  });

  test('deleting a case: confirmation dialog, soft-delete, and audit record', async ({ page, db }) => {
    await loginAs(page, 'advocate');
    await page.goto('/cases');

    const row = page.locator('table tr', { hasText: standaloneCase.case_number });
    await expect(row).toBeVisible();

    const auditCountBefore = (await db.query(
      "SELECT COUNT(*) as total FROM audits WHERE auditable_type = 'App\\\\Models\\\\Cases' AND auditable_id = ? AND event = 'deleted'",
      [standaloneCase.id]
    ))[0].total;

    await row.locator('.btn-action.btn-delete').click();

    // Confirmation dialog appears — this app uses a shared SweetAlert2
    // confirm (see composables/use-confirm-delete.js), not a native
    // browser confirm(), so it's a real DOM element to assert against.
    const dialog = page.locator('.swal2-popup');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText(`Delete ${standaloneCase.case_number}?`);

    await dialog.getByRole('button', { name: 'Delete' }).click();

    // Visible UI result: the row disappears without a page reload.
    // Bug fix / test update (found via a real E2E run): the toast text
    // changed from a generic "Deleted successfully." to a per-item
    // "Deleted {label}." once undo-delete shipped (see
    // use-confirm-delete.js) — the label needs to be in the message so
    // the Undo action has context. This test predates that change.
    await expect(page.getByText(`Deleted ${standaloneCase.case_number}.`)).toBeVisible({ timeout: 10000 });
    await expect(row).toHaveCount(0);

    // Actually soft-deleted, per the app's own design (not hard-deleted).
    const softDeleted = await db.isSoftDeleted('cases', standaloneCase.id);
    expect(softDeleted).toBe(true);
    const persisted = await db.findOne('SELECT deleted_by FROM cases WHERE id = ?', [standaloneCase.id]);
    expect(persisted.deleted_by).not.toBeNull();

    // Audit record created for this specific deletion.
    const auditRow = await db.getLatestAuditFor('App\\Models\\Cases', standaloneCase.id, 'deleted');
    expect(auditRow).not.toBeNull();
    const auditCountAfter = (await db.query(
      "SELECT COUNT(*) as total FROM audits WHERE auditable_type = 'App\\\\Models\\\\Cases' AND auditable_id = ? AND event = 'deleted'",
      [standaloneCase.id]
    ))[0].total;
    expect(auditCountAfter).toBe(auditCountBefore + 1);
  });

  test('deleting a client: confirmation dialog, soft-delete, and audit record', async ({ page, db }) => {
    // A fresh client for this test, independent of the shared one used
    // for setup elsewhere in this file — deleting the shared `client`
    // fixture here would break other tests relying on it still existing.
    await loginAs(page, 'advocate');
    const dedicatedClient = await createClient(page);

    await page.goto('/clients');

    const row = page.locator('table tr', { hasText: dedicatedClient.name });
    await expect(row).toBeVisible();

    await row.locator('.btn-action.btn-delete').click();

    const dialog = page.locator('.swal2-popup');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Delete' }).click();

    await expect(page.getByText(`Deleted ${dedicatedClient.name}.`)).toBeVisible({ timeout: 10000 });
    await expect(row).toHaveCount(0);

    const softDeleted = await db.isSoftDeleted('clients', dedicatedClient.id);
    expect(softDeleted).toBe(true);

    const auditRow = await db.getLatestAuditFor('App\\Models\\Client', dedicatedClient.id, 'deleted');
    expect(auditRow).not.toBeNull();
  });

  test('cancelling the confirmation dialog leaves the record untouched', async ({ page, db }) => {
    await loginAs(page, 'advocate');
    const cancelTestClient = await createClient(page);

    await page.goto('/clients');

    const row = page.locator('table tr', { hasText: cancelTestClient.name });
    await row.locator('.btn-action.btn-delete').click();

    const dialog = page.locator('.swal2-popup');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();

    await expect(dialog).toBeHidden();
    await expect(row).toBeVisible();

    const softDeleted = await db.isSoftDeleted('clients', cancelTestClient.id);
    expect(softDeleted).toBe(false);

    await db.deleteClientAndDependents(cancelTestClient.id);
  });

  test('a user without delete permission cannot delete a client, even though the button is visible', async ({ page, db }) => {
    // The delete button itself has no client-side permission gating in
    // this app (it's always rendered) — the real protection is entirely
    // server-side. This deliberately proves the action fails even though
    // the UI affordance to attempt it is present, per the requirement's
    // "unauthorized users cannot perform the deletion."
    //
    // The fixture client must be created by a user who actually holds
    // create-clients (advocate) — the clerk role, on purpose, has
    // neither create nor delete permissions for clients.
    await loginAs(page, 'advocate');
    const targetClient = await createClient(page);

    await loginAs(page, 'clerk'); // has list-clients but not delete-clients (see RoleSeeder)
    await page.goto('/clients');

    const row = page.locator('table tr', { hasText: targetClient.name });
    await expect(row).toBeVisible();

    await row.locator('.btn-action.btn-delete').click();
    const dialog = page.locator('.swal2-popup');
    await dialog.getByRole('button', { name: 'Delete' }).click();

    // The shared confirmDelete composable surfaces the server's error
    // message on failure rather than "Deleted successfully."
    await expect(page.getByText('Deleted successfully.')).not.toBeVisible();

    const softDeleted = await db.isSoftDeleted('clients', targetClient.id);
    expect(softDeleted).toBe(false);

    await db.deleteClientAndDependents(targetClient.id);
  });

  // UI-24: the success toast after a delete now offers a 10-second
  // "Undo" action (see composables/use-confirm-delete.js /
  // use-toast.js). Clicking it should call the module's restore()
  // endpoint, bring the row back into the list without a full reload,
  // clear deleted_by, and write its own 'restored' audit event —
  // distinct from and in addition to the 'deleted' one already written.
  test('undo on the delete toast restores the client and both events are audited', async ({ page, db }) => {
    await loginAs(page, 'advocate');
    const undoTestClient = await createClient(page);

    await page.goto('/clients');

    const row = page.locator('table tr', { hasText: undoTestClient.name });
    await expect(row).toBeVisible();

    await row.locator('.btn-action.btn-delete').click();
    const dialog = page.locator('.swal2-popup');
    await dialog.getByRole('button', { name: 'Delete' }).click();

    const undoToast = page.locator('.swal2-toast', { hasText: `Deleted ${undoTestClient.name}` });
    await expect(undoToast).toBeVisible({ timeout: 10000 });
    await expect(row).toHaveCount(0);

    const softDeletedAfterDelete = await db.isSoftDeleted('clients', undoTestClient.id);
    expect(softDeletedAfterDelete).toBe(true);

    // Click "Undo" before the 10s toast window closes.
    await undoToast.getByRole('button', { name: 'Undo' }).click();
    await expect(page.getByText('Restored.')).toBeVisible({ timeout: 10000 });

    // The row is back in the list without a page reload.
    await expect(page.locator('table tr', { hasText: undoTestClient.name })).toBeVisible();

    const softDeletedAfterRestore = await db.isSoftDeleted('clients', undoTestClient.id);
    expect(softDeletedAfterRestore).toBe(false);

    const persisted = await db.findOne('SELECT deleted_by FROM clients WHERE id = ?', [undoTestClient.id]);
    expect(persisted.deleted_by).toBeNull();

    // Both the delete and the restore wrote their own audit row — undo
    // is a real, separately-audited action, not a client-side-only
    // "un-delete" that leaves the trail incomplete.
    const deletedAudit = await db.getLatestAuditFor('App\\Models\\Client', undoTestClient.id, 'deleted');
    expect(deletedAudit).not.toBeNull();
    const restoredAudit = await db.getLatestAuditFor('App\\Models\\Client', undoTestClient.id, 'restored');
    expect(restoredAudit).not.toBeNull();

    await db.deleteClientAndDependents(undoTestClient.id);
  });
});
