const { test, expect } = require('../fixtures/base');
const { loginAs } = require('../utils/auth');
const { createClient, createCase } = require('../fixtures/test-data');
const { uniqueSuffix } = require('../utils/api');
const { selectSearchOption } = require('../fixtures/ui-forms');

/**
 * Journey 5: Case expenses.
 *
 * Regression coverage note: implementing "verify it appears in the case
 * financials" surfaced a real, serious bug — the case detail page's
 * "Case Expenses" card called /api/expenses with no filter at all,
 * meaning it displayed every expense recorded across the entire firm on
 * every case's page, not just that case's own expenses. There was also
 * no running total displayed anywhere, so "verify totals are
 * recalculated correctly" had nothing to check against. Both are fixed
 * (see ExpenseController::index's case_id filter and view-case.vue's
 * totalExpenses computed property) — this spec's isolation and total
 * assertions are exactly what would have caught the original bug.
 */
test.describe('Case expenses', () => {
  let client;
  let caseA;
  let caseB;

  test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await loginAs(page, 'advocate');
    client = await createClient(page);
    caseA = await createCase(page, client);
    caseB = await createCase(page, client);
    await context.close();
  });

  test.afterAll(async () => {
    const { Db } = require('../utils/db');
    const db = new Db();
    await db.connect();
    if (caseA) await db.deleteCaseAndDependents(caseA.id);
    if (caseB) await db.deleteCaseAndDependents(caseB.id);
    await db.close();
  });

  async function recordExpenseViaUi(page, { caseNumber, amount, description }) {
    const categories = (await (await page.request.get('/api/v1/expense-categories/options')).json()).data;
    const advocates = (await (await page.request.get('/api/v1/advocates')).json()).data;
    test.skip(!categories.length, 'No expense categories seeded');

    const today = new Date();
    const expenseDate = `${String(today.getDate()).padStart(2, '0')}/${String(today.getMonth() + 1).padStart(2, '0')}/${today.getFullYear()}`;

    await page.locator('.btn-add-expense').click();

    // UI-16: Case and User/Advocate are typeahead SearchSelect comboboxes
    // (server-side search), not plain <select> elements — selectSearchOption
    // (fixtures/ui-forms.js) types the search term and retries if the
    // debounced search and this combobox's blur-cleanup logic race
    // (found via real, repeated E2E runs). Search is by the raw
    // case_number/name, since that's what the backend actually filters
    // on — the displayed option label ("Case Number => X") is not
    // itself searchable text.
    const caseGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Case' }) }).first();
    await selectSearchOption(page, caseGroup, caseNumber);

    const dateGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Expense Date' }) });
    await dateGroup.locator('input.app-date-picker').fill(expenseDate);

    await page.getByPlaceholder('0.00').fill(String(amount));

    const categoryGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Category' }) });
    await categoryGroup.locator('select').selectOption({ label: categories[0].name });

    await page.getByPlaceholder('Enter expense description').fill(description);
    await page.getByPlaceholder('Vendor name').fill('E2E Test Vendor');
    await page.getByPlaceholder('e.g., Cash, Card, Bank Transfer').fill('Cash');
    await page.getByPlaceholder('INV-000').fill(`INV-${uniqueSuffix()}`);

    const userGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'User/Advocate' }) });
    await selectSearchOption(page, userGroup, advocates[0].name);

    await page.locator('#client_modal button.btn-submit, #client_modal button[type="submit"]').first().click();
    await expect(page.locator('#client_modal')).toBeHidden();
  }

  test('records an expense against a case through the real UI, and it is persisted', async ({ page, db }) => {
    await loginAs(page, 'advocate');
    await page.goto('/expenses');

    const description = `E2E expense ${uniqueSuffix()}`;
    await recordExpenseViaUi(page, {
      caseNumber: caseA.case_number,
      amount: 2500,
      description,
    });

    await expect(page.getByText(description)).toBeVisible();

    const persisted = await db.getExpensesForCase(caseA.id);
    expect(persisted.length).toBe(1);
    expect(Number(persisted[0].amount)).toBeCloseTo(2500, 2);
    expect(persisted[0].description).toBe(description);
  });

  test('the expense appears in this case\'s financials, and totals recalculate correctly as more are added', async ({ page, db }) => {
    await loginAs(page, 'advocate');
    await page.evaluate((id) => window.localStorage.setItem('caseId', id), caseA.id);
    // UI-10: expenses now live under the Financials tab, not directly
    // on-screen — Overview is the default tab.
    await page.goto('/view-case#financials');

    await expect(page.getByText('Case Expenses')).toBeVisible();
    const totalBadge = page.getByTestId('case-expenses-total');
    await expect(totalBadge).toContainText('2,500.00');

    // Add a second expense to the SAME case. There is no "Add Expense"
    // control on the case page itself (matches pre-tab behaviour —
    // expenses are recorded from the standalone Expenses module, which
    // is where .btn-add-expense actually lives), so record it there and
    // come back to confirm the case's own total picked it up.
    const description2 = `E2E expense two ${uniqueSuffix()}`;
    await page.goto('/expenses');
    await recordExpenseViaUi(page, {
      caseNumber: caseA.case_number,
      amount: 1500.5,
      description: description2,
    });

    await page.goto('/view-case#financials');
    await expect(page.getByTestId('case-expenses-total')).toContainText('4,000.50');

    const dbTotal = (await db.getExpensesForCase(caseA.id)).reduce((sum, e) => sum + Number(e.amount), 0);
    expect(dbTotal).toBeCloseTo(4000.5, 2);
  });

  test('a different case\'s expenses page does not show this case\'s expenses (case isolation)', async ({ page, db }) => {
    // Regression guard for the exact bug found above: caseB must show
    // ZERO expenses despite caseA having recorded some, and must not
    // include caseA's totals.
    await loginAs(page, 'advocate');
    await page.evaluate((id) => window.localStorage.setItem('caseId', id), caseB.id);
    await page.goto('/view-case#financials');

    const totalBadge = page.getByTestId('case-expenses-total');
    await expect(totalBadge).toContainText('0.00');

    const caseBExpenses = await db.getExpensesForCase(caseB.id);
    expect(caseBExpenses.length).toBe(0);
  });
});
