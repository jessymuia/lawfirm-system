/**
 * Reusable helpers for filling the client/case creation modals through
 * the real rendered form — several journeys create a client and/or case
 * as their first step, so this avoids duplicating brittle selector logic
 * across spec files. None of the target elements have data-testid or
 * label `for` associations in the actual markup, so these locate by
 * placeholder text and by the field-group's visible label text, matched
 * against the real templates in resources/js/src/views/menu/{clients,cases}.
 */

/**
 * UI-16: types into a SearchSelect combobox and clicks the matching
 * result. Retries the whole type-and-look sequence a few times because
 * this custom combobox's blur-cleanup logic can occasionally race with
 * the debounced search (found via real, repeated E2E runs against a
 * live server — not something a longer fixed wait fixes, since the
 * dropdown had genuinely closed with zero results rather than merely
 * being slow) — a quick retry recovers cleanly rather than the test
 * hanging for the full timeout on a lost race.
 */
async function selectSearchOption(page, groupLocator, searchText, attempts = 3) {
  const input = groupLocator.locator('input.search-select-input');
  const option = page.locator('.search-select-option', { hasText: searchText }).first();

  for (let attempt = 1; attempt <= attempts; attempt++) {
    await input.click();
    await input.fill('');
    await input.pressSequentially(searchText, { delay: 30 });
    try {
      await option.waitFor({ state: 'visible', timeout: 5_000 });
      await option.click();
      return;
    } catch (err) {
      if (attempt === attempts) throw err;
    }
  }
}

async function fillClientForm(page, { name, phone, address, advocateName }) {
  await page.locator('#clientName').fill(name);
  await page.locator('#phoneNumber').fill(phone);
  await page.locator('#address').fill(address);
  await page.locator('#advocate').selectOption({ label: advocateName });
}

async function submitClientForm(page) {
  await page.locator('#client_modal button.btn-submit, #client_modal button[type="submit"]').first().click();
}

async function fillCaseForm(page, { caseNumber, description, clientName, attorneyName, startDate, caseType, policeStation, courtName, opposingParty }) {
  await page.getByPlaceholder('Enter case number').fill(caseNumber);
  await page.getByPlaceholder('Brief description').fill(description);

  // UI-16: Client, Attorney, and Court are typeahead SearchSelect
  // comboboxes (server-side search), not plain <select> elements — type
  // enough to match, then click the resulting option once the debounced
  // search returns it, same pattern used in 03-/04-/05-'s own UI helpers.
  // UI-16: SearchSelect is a custom typeahead combobox — .fill() sets
  // the value in one shot without the focus/keystroke choreography this
  // component's blur-cleanup logic expects, which was found (via a real
  // browser run) to close the dropdown and clear the input right after
  // fill() completes. pressSequentially() simulates real keystrokes,
  // matching how a person actually uses this control.
  const clientGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Client' }) });
  await selectSearchOption(page, clientGroup, clientName);

  const attorneyGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Attorney' }) });
  await selectSearchOption(page, attorneyGroup, attorneyName);

  const startDateGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Start Date' }) });
  await startDateGroup.locator('input.app-date-picker').fill(startDate);

  await page.getByPlaceholder('e.g., Civil, Criminal').fill(caseType);
  await page.getByPlaceholder('Station name').fill(policeStation);

  const courtGroup = page.locator('.form-group', { has: page.locator('.form-label', { hasText: 'Court' }) });
  await selectSearchOption(page, courtGroup, courtName);

  await page.getByPlaceholder('Opposing party name').fill(opposingParty);
}

module.exports = { fillClientForm, submitClientForm, fillCaseForm, selectSearchOption };
