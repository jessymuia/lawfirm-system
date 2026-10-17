/**
 * §3.2: "Authenticate using the appropriate test users and roles" and
 * "execute the workflow through the actual UI/API as a real user would."
 * This logs in through the real login form (not a mocked/injected
 * session), against the fixed-credential users created by
 * database/seeders/E2ETestSeeder.php — see that file for exactly which
 * permissions each role has.
 *
 * Selectors below are matched against the real markup in
 * resources/js/src/views/auth/login.vue: the email field's placeholder
 * reads "Username" (a pre-existing cosmetic label mismatch — it's an
 * email input — left as-is since it's not a functional bug), and the
 * password field's placeholder reads "Password".
 */
const TEST_USERS = {
  admin: { email: 'e2e-admin@lawfirm.test', password: 'E2ePassword!123', role: 'admin' },
  advocate: { email: 'e2e-advocate@lawfirm.test', password: 'E2ePassword!123', role: 'advocate' },
  clerk: { email: 'e2e-clerk@lawfirm.test', password: 'E2ePassword!123', role: 'clerk' },
  mfa: { email: 'e2e-mfa@lawfirm.test', password: 'E2ePassword!123', role: 'clerk' },
};

/**
 * Logs in as the given role through the real login page and waits for
 * the app to actually land away from /login (not just for the network
 * call to resolve) — this is the "wait for a real state change, not a
 * timeout" pattern used throughout the suite.
 */
async function loginAs(page, roleKey) {
  const user = TEST_USERS[roleKey];
  if (!user) throw new Error(`Unknown test user role: ${roleKey}`);

  await page.goto('/login');
  await page.locator('input[placeholder="Username"]').fill(user.email);
  await page.locator('input[placeholder="Password"]').fill(user.password);
  await page.getByRole('button', { name: 'Log In' }).click();

  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });

  return user;
}

async function logout(page) {
  // Bug fix (found via a real run): .logout-item lives inside the
  // account-menu dropdown and is only visible once that menu is open —
  // this previously tried to click it directly, which timed out waiting
  // for an element that was correctly in the DOM but hidden until the
  // toggle button was clicked.
  await page.getByRole('button', { name: 'Open account menu' }).click();
  await page.locator('.logout-item').click();
  await page.waitForURL((url) => url.pathname.startsWith('/login'), { timeout: 15_000 });
}

module.exports = { TEST_USERS, loginAs, logout };
