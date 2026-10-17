/**
 * §3.2 note on scope: every journey's *actual behaviour under test* goes
 * through the real UI in the spec files. This helper is only used to set
 * up *prerequisite* fixture data quickly and realistically (e.g. "a
 * client must already exist before we can test creating a case for it")
 * — via the same real API a user's browser calls, not a mock. It works
 * because Playwright's `page.request` shares cookies with the page's
 * browser context automatically, so a call made here after `loginAs()`
 * is authenticated exactly as the logged-in user.
 *
 * Sanctum's SPA auth requires the XSRF-TOKEN cookie to be present and
 * echoed back as the X-XSRF-TOKEN header on state-changing requests
 * (see resources/js/src/api.js's ensureCsrfCookie, which axios normally
 * handles automatically) — `page.request` does not do this automatically,
 * so `ensureCsrf` fetches the cookie and this module attaches the header
 * on every call.
 */

async function ensureCsrf(page) {
  await page.request.get('/sanctum/csrf-cookie');
  const cookies = await page.context().cookies();
  const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
  if (!xsrfCookie) {
    throw new Error('XSRF-TOKEN cookie was not set after requesting /sanctum/csrf-cookie');
  }
  return decodeURIComponent(xsrfCookie.value);
}

async function apiPost(page, url, data) {
  const token = await ensureCsrf(page);
  const response = await page.request.post(url, {
    data,
    headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
  });
  return response;
}

async function apiPut(page, url, data) {
  const token = await ensureCsrf(page);
  const response = await page.request.put(url, {
    data,
    headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
  });
  return response;
}

async function apiDelete(page, url) {
  const token = await ensureCsrf(page);
  const response = await page.request.delete(url, {
    headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
  });
  return response;
}

async function apiGet(page, url, params = {}) {
  const response = await page.request.get(url, { params, headers: { Accept: 'application/json' } });
  return response;
}

/** Unique-enough suffix for fixture data, so parallel/repeated runs
 * never collide on DATA-5's DB-level unique constraints (case_number,
 * client phone number). */
function uniqueSuffix() {
  return `${Date.now()}${Math.floor(Math.random() * 1000)}`;
}

module.exports = { ensureCsrf, apiPost, apiPut, apiDelete, apiGet, uniqueSuffix };
