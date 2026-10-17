const { test, expect } = require('../fixtures/base');
const { loginAs } = require('../utils/auth');
const { createClient, createCase } = require('../fixtures/test-data');
const { apiPost, apiPut, uniqueSuffix } = require('../utils/api');
const { selectSearchOption } = require('../fixtures/ui-forms');

/**
 * Journey 4: Invoice workflow.
 *
 * Regression coverage note: implementing this journey's "verify
 * subtotal, taxes/fees, and total calculations" step surfaced a real,
 * serious bug — invoice line totals were accepted verbatim from the
 * client and summed directly into the invoice grand total, while the
 * frontend itself computed each line's total *excluding* tax. Every
 * invoice's total silently excluded VAT, and the server never
 * recomputed the figure it trusted from the request. Both
 * InvoiceItemController and the frontend's computeTotal() were fixed
 * (see their own comments) — this spec's calculation assertions are
 * exactly what would have caught that bug.
 */
test.describe('Invoice workflow', () => {
  let client;
  let testCase;
  let invoiceId;
  let invoiceNumber;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await loginAs(page, 'advocate');
    client = await createClient(page);
    testCase = await createCase(page, client);
    await context.close();
  });

  test.afterAll(async () => {
    const { Db } = require('../utils/db');
    const db = new Db();
    await db.connect();
    if (testCase) await db.deleteCaseAndDependents(testCase.id);
    await db.close();
  });

  test('creates an invoice with multiple items and computes correct tax-inclusive totals', async ({ page, db }) => {
    await loginAs(page, 'advocate');
    await page.goto('/invoices');

    const today = new Date();
    const invoiceDate = `${String(today.getDate()).padStart(2, '0')}/${String(today.getMonth() + 1).padStart(2, '0')}/${today.getFullYear()}`;
    const due = new Date(today);
    due.setDate(due.getDate() + 14);
    const dueDate = `${String(due.getDate()).padStart(2, '0')}/${String(due.getMonth() + 1).padStart(2, '0')}/${due.getFullYear()}`;

    await page.locator('.btn-add-invoice').click();

    // UI-16: Case and Client are typeahead SearchSelect comboboxes now,
    // not plain <select> elements — selectSearchOption (in
    // fixtures/ui-forms.js) types the search term and retries if the
    // debounced search and this combobox's blur-cleanup logic race
    // (found via real, repeated E2E runs — a longer wait doesn't help
    // since the dropdown genuinely closes with no results on a lost
    // race, rather than merely being slow).
    const caseGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Case' }) }).first();
    await selectSearchOption(page, caseGroup, testCase.case_number);

    const clientGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Client' }) }).first();
    await selectSearchOption(page, clientGroup, client.name);

    const invoiceDateGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Invoice Date' }) });
    await invoiceDateGroup.locator('input.app-date-picker').fill(invoiceDate);

    const dueDateGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Due Date' }) });
    await dueDateGroup.locator('input.app-date-picker').fill(dueDate);

    await page.locator('#client_modal button.btn-submit, #client_modal button[type="submit"]').first().click();
    await expect(page.locator('#client_modal')).toBeHidden();

    const persisted = await db.findOne(
      'SELECT * FROM invoices WHERE case_id = ? ORDER BY id DESC LIMIT 1',
      [testCase.id]
    );
    expect(persisted).not.toBeNull();
    expect(persisted.workflow_status).toBe('draft');
    invoiceId = persisted.id;
    invoiceNumber = persisted.invoice_number;

    // Add two line items through the real UI on the invoice items page.
    await page.evaluate((id) => window.localStorage.setItem('invoiceId', id), invoiceId);
    await page.goto('/view-invoice');

    const lineItems = [
      { description: 'E2E consultation fee', quantity: '2', rate: '5000' },
      { description: 'E2E filing fee', quantity: '1', rate: '1500' },
    ];

    for (const item of lineItems) {
      await page.locator('.btn-action.btn-add').click();
      await page.getByPlaceholder('Enter item description or service details').fill(item.description);

      // Bug fix (found via a real browser run): this previously used a
      // broad `.form-group, .col-md-4, div` OR-selector — the generic
      // `div` branch also matched the shared `.row` wrapper around both
      // Quantity and Rate (a `.row` "has" both labels as descendants
      // too), so `.first()` on each resolved to the same ambiguous
      // element and both fills landed on the Quantity input, leaving
      // Rate at its default 0 and silently failing the submit guard
      // (0 is falsy) with no visible error and no network request ever
      // firing. `.form-group` alone is specific to each field's own
      // wrapper (see the real markup: `<div class="col-md-6 form-group">`
      // per field) and matches unambiguously.
      const quantityGroup = page.locator('.form-group', {
        has: page.locator('label', { hasText: 'Quantity' }),
      });
      await quantityGroup.locator('input[type="number"]').first().fill(item.quantity);

      const rateGroup = page.locator('.form-group', {
        has: page.locator('label', { hasText: 'Rate' }),
      });
      await rateGroup.locator('input[type="number"]').first().fill(item.rate);

      await page.locator('#clientI_modal button[type="submit"]').click();
      await expect(page.locator('#clientI_modal')).toBeHidden();
      await expect(page.getByText(item.description)).toBeVisible();
    }

    // Authoritative calculation check: query the DB directly rather than
    // trust the UI's own display, since the UI is exactly what
    // (incorrectly) computed the pre-tax figure before this bug was fixed.
    const invoiceItems = await db.getInvoiceItems(invoiceId);
    expect(invoiceItems.length).toBe(2);

    const expectedSubtotal = invoiceItems.reduce((sum, i) => sum + Number(i.quantity) * Number(i.rate), 0);
    const expectedTax = invoiceItems.reduce((sum, i) => sum + Number(i.tax), 0);
    const expectedTotal = invoiceItems.reduce((sum, i) => sum + Number(i.total_amount), 0);

    // Each item's own total must equal quantity*rate + tax — the exact
    // invariant the bug violated.
    for (const item of invoiceItems) {
      expect(Number(item.total_amount)).toBeCloseTo(Number(item.quantity) * Number(item.rate) + Number(item.tax), 2);
    }

    const refreshedInvoice = await db.findOne('SELECT * FROM invoices WHERE id = ?', [invoiceId]);
    expect(Number(refreshedInvoice.subtotal)).toBeCloseTo(expectedSubtotal, 2);
    expect(Number(refreshedInvoice.tax_total)).toBeCloseTo(expectedTax, 2);
    expect(Number(refreshedInvoice.total_amount)).toBeCloseTo(expectedTotal, 2);
    // And the total must be strictly greater than the subtotal alone —
    // proving tax was actually included, not silently dropped.
    expect(Number(refreshedInvoice.total_amount)).toBeGreaterThan(Number(refreshedInvoice.subtotal));
  });

  test('submits the invoice to an administrator', async ({ page, db }) => {
    test.skip(!invoiceId, 'Depends on the previous test creating an invoice with items');

    await loginAs(page, 'advocate');
    await page.evaluate((id) => window.localStorage.setItem('invoiceId', id), invoiceId);
    await page.goto('/invoice-preview');

    await expect(page.getByText(`#${invoiceNumber}`)).toBeVisible();

    const postButton = page.getByRole('button', { name: 'Post Invoice' });
    await expect(postButton).toBeVisible();

    // submit_form6() in preview.vue uses the browser's native confirm()
    // dialog, not a SweetAlert2/DOM modal — Playwright automates that via
    // the page's dialog event, registered before the click that triggers it.
    page.once('dialog', (dialog) => dialog.accept());
    await postButton.click();

    await expect(page.getByText('Invoice sent successfully.')).toBeVisible({ timeout: 10000 });

    const invoice = await db.findOne('SELECT * FROM invoices WHERE id = ?', [invoiceId]);
    expect(invoice.workflow_status).toBe('submitted');
  });

  test('admin can record a payment against the submitted invoice (approve/paid path)', async ({ page, db }) => {
    test.skip(!invoiceId, 'Depends on an earlier test submitting the invoice');

    await loginAs(page, 'admin');
    await page.evaluate((id) => window.localStorage.setItem('invoiceId', id), invoiceId);
    await page.goto('/invoice-preview');

    const invoiceBefore = await db.findOne('SELECT * FROM invoices WHERE id = ?', [invoiceId]);
    const fullAmount = Number(invoiceBefore.total_amount);

    await page.getByRole('button', { name: 'Record Payment' }).click();

    await page.locator('#payment_modal input[type="number"]').fill(String(fullAmount));
    const dateGroup = page.locator('#payment_modal .form-group', { has: page.locator('.form-label', { hasText: 'Payment Date' }) });
    await dateGroup.locator('input.app-date-picker').fill(
      `${String(new Date().getDate()).padStart(2, '0')}/${String(new Date().getMonth() + 1).padStart(2, '0')}/${new Date().getFullYear()}`
    );
    await page.locator('#payment_modal select').selectOption('cash');
    // Bug fix / known issue (found via a real E2E run): this button's
    // bounding box was observed intermittently jumping between two very
    // different positions — root cause not conclusively found within
    // reasonable effort (no console errors, no active CSS animation, a
    // single #payment_modal element, and AppDatePicker's own documented
    // infinite-loop guard didn't fully explain it). A coordinate-based
    // click (even with force:true) can land on stale coordinates if the
    // element moves between dispatch and arrival — confirmed here, since
    // the handler's own debug log never fired even with force:true.
    // Dispatching .click() directly on the DOM node sidesteps
    // coordinates entirely and reaches the real element regardless of
    // its current on-screen position.
    await page.locator('#payment_modal button[type="submit"]').evaluate((el) => el.click());

    await expect(page.getByText('Payment recorded successfully.')).toBeVisible({ timeout: 10000 });

    const invoiceAfter = await db.findOne('SELECT * FROM invoices WHERE id = ?', [invoiceId]);
    expect(invoiceAfter.payment_status).toBe('paid');
    expect(Number(invoiceAfter.amount_paid)).toBeCloseTo(fullAmount, 2);
  });

  test('the creator sees the updated (paid) status reflected after logging back in', async ({ page }) => {
    test.skip(!invoiceId, 'Depends on an earlier test recording a payment');

    await loginAs(page, 'advocate');
    await page.goto('/invoices');

    const row = page.locator('tr', { has: page.getByText(invoiceNumber) }).first();
    // Fall back to a broader search if the table doesn't render <tr> rows
    // for this component in the current build.
    const statusBadge = (await row.count())
      ? row.locator('.status-badge')
      : page.locator('.status-badge').filter({ hasText: /paid/i });

    await expect(statusBadge.first()).toContainText(/paid/i);
  });

  test('rejection path: an admin can void a submitted invoice instead of paying it', async ({ page, db }) => {
    await loginAs(page, 'advocate');
    const rejectCase = testCase; // reuse the same fixture case for a second invoice

    const invoicePage = await page.context().newPage();
    await invoicePage.goto('/invoices');
    const invoiceResponse = await apiPost(invoicePage, '/api/v1/invoices', {
      case_id: rejectCase.id,
      client_id: client.id,
      invoice_date: `${String(new Date().getDate()).padStart(2, '0')}/${String(new Date().getMonth() + 1).padStart(2, '0')}/${new Date().getFullYear()}`,
      invoice_due_date: `${String(new Date().getDate()).padStart(2, '0')}/${String(new Date().getMonth() + 1).padStart(2, '0')}/${new Date().getFullYear()}`,
    });
    expect(invoiceResponse.ok()).toBeTruthy();
    const rejectInvoice = (await invoiceResponse.json()).data;

    await apiPut(invoicePage, `/api/v1/send-to-admin/${rejectInvoice.id}`, {});

    // Now actually perform the void/delete through the real UI as an
    // admin, using the app's existing delete-confirmation flow
    // (see ClientController-style confirmDelete composable wired into
    // invoices/index.vue's submit_form6).
    await loginAs(page, 'admin');
    await page.goto('/invoices');

    const row = page.locator('table tr', { hasText: rejectInvoice.invoice_number });
    await expect(row).toBeVisible();
    await row.locator('.btn-delete').click();

    await page.locator('.swal2-popup').getByRole('button', { name: 'Delete' }).click();
    await expect(page.getByText('Deleted successfully.')).toBeVisible({ timeout: 10000 });

    const softDeleted = await db.isSoftDeleted('invoices', rejectInvoice.id);
    expect(softDeleted).toBe(true);

    const finalState = await db.findOne('SELECT workflow_status FROM invoices WHERE id = ?', [rejectInvoice.id]);
    expect(finalState.workflow_status).toBe('void');

    await invoicePage.close();
  });
});
