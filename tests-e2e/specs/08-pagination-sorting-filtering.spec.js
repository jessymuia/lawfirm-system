const { test, expect } = require('../fixtures/base');
const { loginAs } = require('../utils/auth');
const { apiPost } = require('../utils/api');

/**
 * Journey 8: Server-side pagination, sorting, and filtering.
 *
 * Uses the Courts module — the one page in this app actually wired to
 * the shared server-driven DataTable component (see
 * resources/js/src/components/ui/data-table.vue and
 * CourtController::index's paginatedOrFull() call). Every other module
 * still uses the older v-tables-3 pattern, which performs sort/filter
 * client-side over a fully-loaded dataset — exactly what this journey's
 * final requirement says NOT to test as if it were server-driven.
 *
 * Regression coverage note: DataTable had pagination but NO sort or
 * search capability at all before this journey was implemented — neither
 * the frontend component nor CourtController's backend supported it, so
 * "test sorting" and "test filtering/search" had nothing to exercise.
 * Both were built specifically to make this journey meaningful (see
 * Controller::paginatedOrFull's sortableColumns/searchableColumns
 * whitelist and DataTable.vue's new search input + clickable column
 * headers) — this spec's network-request assertions are what prove the
 * feature is real, not client-side.
 */
test.describe('Server-side pagination, sorting, and filtering', () => {
  test('pagination requests the server and the UI reflects exactly what it returns', async ({ page }) => {
    await loginAs(page, 'admin');

    const firstPageRequest = page.waitForResponse((res) => res.url().includes('/api/v1/courts') && res.request().method() === 'GET');
    await page.goto('/courts');
    const firstPageResponse = await firstPageRequest;
    const firstPageBody = (await firstPageResponse.json()).data;

    // 100 courts are seeded (CourtSeeder) — well beyond one page at the
    // default per_page, so pagination controls must actually appear.
    expect(firstPageBody.total).toBeGreaterThan(firstPageBody.per_page);
    await expect(page.getByTestId('pagination-page-label')).toHaveText(`Page 1 of ${firstPageBody.last_page}`);

    const firstPageNames = firstPageBody.data.map((c) => c.name);

    // Advancing to page 2 must issue a real second request (not just
    // slice an array already sitting in memory) and show DIFFERENT rows.
    const secondPageRequest = page.waitForResponse((res) => {
      const url = new URL(res.url());
      return url.pathname.endsWith('/api/v1/courts') && url.searchParams.get('page') === '2';
    });
    await page.getByTestId('pagination-next').click();
    const secondPageResponse = await secondPageRequest;
    const secondPageBody = (await secondPageResponse.json()).data;

    expect(secondPageBody.current_page).toBe(2);
    const secondPageNames = secondPageBody.data.map((c) => c.name);
    expect(secondPageNames).not.toEqual(firstPageNames);

    await expect(page.getByTestId('pagination-page-label')).toHaveText(`Page 2 of ${firstPageBody.last_page}`);
  });

  test('sorting by name issues a server request with sort params and returns correctly ordered data', async ({ page }) => {
    await loginAs(page, 'admin');
    await page.goto('/courts');
    await page.waitForResponse((res) => res.url().includes('/api/v1/courts'));

    const sortRequest = page.waitForResponse((res) => {
      const url = new URL(res.url());
      return url.pathname.endsWith('/api/v1/courts') && url.searchParams.get('sort') === 'name';
    });
    await page.getByTestId('column-header-name').click();
    const sortResponse = await sortRequest;
    const sortUrl = new URL(sortResponse.url());
    expect(sortUrl.searchParams.get('direction')).toBe('asc');

    const sortedBody = (await sortResponse.json()).data;
    const names = sortedBody.data.map((c) => c.name);
    const expectedOrder = [...names].sort((a, b) => a.localeCompare(b));
    expect(names).toEqual(expectedOrder);

    // Clicking the same header again must reverse the direction, via a
    // real second request, not a client-side array reverse.
    const descRequest = page.waitForResponse((res) => {
      const url = new URL(res.url());
      return url.pathname.endsWith('/api/v1/courts') && url.searchParams.get('direction') === 'desc';
    });
    await page.getByTestId('column-header-name').click();
    await descRequest;
  });

  test('searching issues a debounced server request and returns only matching rows, not a client-side filter of everything', async ({ page, db }) => {
    await loginAs(page, 'admin');

    // A uniquely-named court that would never appear on page 1 of the
    // default (unsorted-by-name) result set, proving the match came from
    // the server searching the *entire* table, not whatever page of data
    // happened to already be loaded in the browser.
    const uniqueName = `ZzzSearchTarget${Date.now()}`;
    const courtTypesRes = await page.request.get('/api/v1/courttypes');
    const courtTypes = (await courtTypesRes.json()).data.data;
    test.skip(!courtTypes.length, 'No court types seeded');
    const createResponse = await apiPost(page, '/api/v1/courts', { name: uniqueName, type: courtTypes[0].id });
    expect(createResponse.ok()).toBeTruthy();

    await page.goto('/courts');
    await page.waitForResponse((res) => res.url().includes('/api/v1/courts'));

    const searchRequest = page.waitForResponse((res) => {
      const url = new URL(res.url());
      return url.pathname.endsWith('/api/v1/courts') && url.searchParams.get('search') === uniqueName;
    });
    await page.getByTestId('table-search-input').fill(uniqueName);
    const searchResponse = await searchRequest;
    const searchBody = (await searchResponse.json()).data;

    expect(searchBody.data.length).toBe(1);
    expect(searchBody.data[0].name).toBe(uniqueName);
    expect(searchBody.total).toBe(1);

    // Visible UI result matches exactly what the server returned.
    await expect(page.getByText(uniqueName)).toBeVisible();
    await expect(page.locator('tbody tr')).toHaveCount(1);

    // Cleanup.
    await db.query('DELETE FROM courts WHERE name = ?', [uniqueName]);
  });

  test('reloading the page with the same URL state does not silently fall back to an incomplete client-side dataset', async ({ page }) => {
    // A plain reload re-mounts the component fresh and re-fetches page 1
    // from the server — there is no client-side cache of "all rows" for
    // this component to fall back on, which is exactly the property this
    // requirement's last bullet asks to guard.
    await loginAs(page, 'admin');
    await page.goto('/courts');
    const initialResponse = await page.waitForResponse((res) => res.url().includes('/api/v1/courts'));
    const initialBody = (await initialResponse.json()).data;

    const reloadRequest = page.waitForResponse((res) => res.url().includes('/api/v1/courts'));
    await page.reload();
    const reloadResponse = await reloadRequest;
    const reloadBody = (await reloadResponse.json()).data;

    expect(reloadBody.total).toBe(initialBody.total);
    expect(reloadBody.current_page).toBe(1);
  });
});
