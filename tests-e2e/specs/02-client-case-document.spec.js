const path = require('path');
const fs = require('fs');
const { test, expect } = require('../fixtures/base');
const { loginAs } = require('../utils/auth');
const { uniqueSuffix, apiDelete } = require('../utils/api');
const { fillClientForm, submitClientForm, fillCaseForm } = require('../fixtures/ui-forms');

// A tiny, real PDF fixture file for the upload step. Written once at
// module load so every test in this file shares the same file on disk
// rather than each test creating its own (the file's *content* isn't
// what's under test — the upload/storage/audit pipeline is).
const FIXTURE_PDF_PATH = path.join(__dirname, '..', 'fixtures', 'sample-document.pdf');
const MINIMAL_PDF_BYTES = Buffer.from(
  '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' +
  '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF'
);
if (!fs.existsSync(FIXTURE_PDF_PATH)) {
  fs.writeFileSync(FIXTURE_PDF_PATH, MINIMAL_PDF_BYTES);
}

test.describe('Client -> Case -> Document', () => {
  let createdClientId;
  let createdCaseId;
  let createdDocumentId;

  test.afterAll(async () => {
    // Real cleanup so re-running the suite doesn't accumulate fixture
    // rows — uses the Db helper directly since this runs outside any
    // single test's `db` fixture lifecycle.
    const { Db } = require('../utils/db');
    const db = new Db();
    await db.connect();
    if (createdCaseId) await db.deleteCaseAndDependents(createdCaseId);
    else if (createdClientId) await db.deleteClientAndDependents(createdClientId);
    await db.close();
  });

  test('creates a client through the real UI', async ({ page, db }) => {
    await loginAs(page, 'advocate');
    await page.goto('/clients');

    const suffix = uniqueSuffix();
    const clientName = `E2E Client ${suffix}`;
    const phone = `07${suffix}`.slice(0, 10);

    await page.locator('.btn-add-client').click();
    await fillClientForm(page, {
      name: clientName,
      phone,
      address: '123 Test Street, Nairobi',
      advocateName: (await (await page.request.get('/api/v1/advocates')).json()).data[0].name,
    });
    await submitClientForm(page);

    // Visible UI result: the modal closes and the new client appears in
    // the list without a page reload.
    await expect(page.locator('#client_modal')).toBeHidden();
    await expect(page.getByText(clientName)).toBeVisible();

    // Persisted state, not just what the UI claims.
    const persisted = await db.getClientByPhone(phone);
    expect(persisted).not.toBeNull();
    expect(persisted.name).toBe(clientName);
    createdClientId = persisted.id;
  });

  test('creates a case for that client through the real UI', async ({ page, db }) => {
    test.skip(!createdClientId, 'Depends on the previous test creating a client');

    await loginAs(page, 'advocate');
    await page.goto('/cases');

    const suffix = uniqueSuffix();
    const caseNumber = `E2E-CASE-${suffix}`;
    const client = await db.findOne('SELECT * FROM clients WHERE id = ?', [createdClientId]);
    const advocates = (await (await page.request.get('/api/v1/advocates')).json()).data;
    const courts = (await (await page.request.get('/api/v1/courts')).json()).data.data;
    test.skip(!courts.length, 'No courts seeded — cannot create a case');

    const today = new Date();
    const startDate = `${String(today.getDate()).padStart(2, '0')}/${String(today.getMonth() + 1).padStart(2, '0')}/${today.getFullYear()}`;

    await page.locator('.btn-add-case').click();
    await fillCaseForm(page, {
      caseNumber,
      description: 'E2E test case description',
      clientName: client.name,
      attorneyName: advocates[0].name,
      startDate,
      caseType: 'civil',
      policeStation: 'Test Station',
      courtName: courts[0].name,
      opposingParty: 'E2E Opposing Party',
    });
    await page.locator('#client_modal button.btn-submit, #client_modal button[type="submit"]').first().click();

    await expect(page.locator('#client_modal')).toBeHidden();
    await expect(page.getByText(caseNumber)).toBeVisible();

    const persisted = await db.getCaseByCaseNumber(caseNumber);
    expect(persisted).not.toBeNull();
    expect(persisted.client_id).toBe(createdClientId);
    createdCaseId = persisted.id;
  });

  test('uploads a document to the case and it is stored successfully', async ({ page, db }) => {
    test.skip(!createdCaseId, 'Depends on the previous test creating a case');

    await loginAs(page, 'advocate');
    await localStorageSetCaseId(page, createdCaseId);
    // UI-10: view-case.vue is now tabbed — Documents lives behind its
    // own hash rather than being on-screen by default (Overview is).
    await page.goto('/view-case#documents');

    await expect(page.getByText('Case Documents')).toBeVisible();

    await page.locator('.btn-add').first().click();
    const docTitle = `E2E Document ${uniqueSuffix()}`;
    await page.locator('#clientD_modal input[placeholder="Document Title"]').fill(docTitle);
    await page.locator('#clientD_modal input[type="file"]').setInputFiles(FIXTURE_PDF_PATH);

    // Wait for the real upload to actually complete (the form's own
    // progress state reaching 100%), not an arbitrary sleep.
    await expect(page.locator('#clientD_modal .progress-bar')).toHaveText('100%', { timeout: 15000 });

    await page.locator('#clientD_modal button[type="submit"]').click();
    await expect(page.locator('#clientD_modal')).toBeHidden();

    // Visible UI result: the document appears in the case's document list.
    await expect(page.getByText(docTitle)).toBeVisible();

    // Persisted state.
    const persistedDoc = await db.findOne(
      'SELECT * FROM documents WHERE case_id = ? AND title = ? ORDER BY id DESC LIMIT 1',
      [createdCaseId, docTitle]
    );
    expect(persistedDoc).not.toBeNull();
    expect(persistedDoc.is_current).toBe(1);
    createdDocumentId = persistedDoc.id;
  });

  test('previewing the document records a successful audit entry', async ({ page, db, request }) => {
    test.skip(!createdDocumentId, 'Depends on the previous test uploading a document');

    await loginAs(page, 'advocate');

    const beforeCount = (await db.getDocumentAccessRecords(createdDocumentId)).length;

    const response = await page.request.get('/api/v1/preview', { params: { id: createdDocumentId } });
    expect(response.ok()).toBeTruthy();

    const afterRecords = await db.getDocumentAccessRecords(createdDocumentId);
    expect(afterRecords.length).toBe(beforeCount + 1);
    expect(afterRecords[0].outcome).toBe('success');
    expect(afterRecords[0].action).toBe('preview');
  });

  test('a user without document permissions cannot preview the document, and the denial is itself audited', async ({ page, db }) => {
    test.skip(!createdDocumentId, 'Depends on an earlier test uploading a document');

    await loginAs(page, 'clerk'); // clerk role has no VIEW_DOCUMENTS permission — see RoleSeeder

    const beforeCount = (await db.getDocumentAccessRecords(createdDocumentId)).length;

    const response = await page.request.get('/api/v1/preview', { params: { id: createdDocumentId } });
    expect(response.status()).toBe(403);

    // The denial itself must be recorded, not just silently rejected —
    // see CasesController::preview()'s "Unsuccessfull" outcome branch.
    const afterRecords = await db.getDocumentAccessRecords(createdDocumentId);
    expect(afterRecords.length).toBe(beforeCount + 1);
    expect(afterRecords[0].outcome).toBe('Unsuccessfull');
  });
});

function localStorageSetCaseId(page, caseId) {
  // view-case.vue reads the case id from localStorage rather than a
  // route param (see its onMounted -> fetchData) — this mirrors exactly
  // how the real "View Details" link on the cases list sets it before
  // navigating, so this isn't bypassing app behaviour, just replicating
  // the click that would normally set it.
  return page.addInitScript((id) => {
    window.localStorage.setItem('caseId', id);
  }, caseId);
}
