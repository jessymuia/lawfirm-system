const { test, expect } = require('../fixtures/base');
const { TEST_USERS, loginAs, logout } = require('../utils/auth');
const { totp } = require('../utils/totp');
const { Db } = require('../utils/db');

/**
 * Journey 9 (SEC-12): two-factor authentication through the web app. The person enrols from Account Settings
 * (scanning is stood in for by reading the setup key off the page), signs out, and signs back in through the
 * second step with an authenticator code and then with a recovery code. Uses its own seeded user
 * (E2ETestSeeder: e2e-mfa@lawfirm.test) so enrolment never changes how other specs sign in.
 */
const user = TEST_USERS.mfa;

const resetSecondFactor = async () => {
  const db = new Db();
  await db.connect();
  await db.query('DELETE FROM user_mfa WHERE user_id = (SELECT id FROM users WHERE email = ?)', [user.email]);
  await db.query('DELETE FROM mfa_challenges WHERE user_id = (SELECT id FROM users WHERE email = ?)', [user.email]).catch(() => {});
  await db.close();
};

const signInWithPassword = async (page) => {
  await page.goto('/login');
  await page.locator('input[placeholder="Username"]').fill(user.email);
  await page.locator('input[placeholder="Password"]').fill(user.password);
  await page.getByRole('button', { name: 'Log In' }).click();
};

test.describe('Two-factor authentication', () => {
  test.beforeAll(resetSecondFactor);
  test.afterAll(resetSecondFactor);

  test('enrol, then sign in with an authenticator code and with a recovery code', async ({ page, db }) => {
    await loginAs(page, 'mfa');

    // ---- enrol from Account Settings ---------------------------------------------------------------------------
    await page.goto('/users/account-setting');
    const card = page.getByTestId('two-factor-card');
    await card.getByTestId('mfa-begin').click();
    const secret = (await card.getByTestId('mfa-secret').textContent()).trim();
    expect(secret.replace(/\s/g, '')).toMatch(/^[A-Z2-7]{16,}$/);
    await expect(card.locator('img[alt="QR code for your authenticator app"]')).toBeVisible();

    await card.getByTestId('mfa-confirm-code').fill(totp(secret));
    await card.getByRole('button', { name: 'Turn on' }).click();
    const codes = card.getByTestId('mfa-recovery-codes').locator('code');
    await expect(codes.first()).toBeVisible();
    const recoveryCode = (await codes.first().textContent()).trim();
    await card.getByTestId('mfa-done').click();
    await expect(card.getByText('On', { exact: true })).toBeVisible();

    const row = await db.findOne('SELECT confirmed_at FROM user_mfa WHERE user_id = (SELECT id FROM users WHERE email = ?)', [user.email]);
    expect(row && row.confirmed_at).toBeTruthy();

    await logout(page);

    // ---- a password alone is no longer a session -------------------------------------------------------------
    await signInWithPassword(page);
    await expect(page.getByLabel('Authentication code')).toBeVisible();
    expect((await page.request.get('/api/v1/dashboard')).status()).toBe(401);

    // a wrong code keeps the person on the step
    await page.getByLabel('Authentication code').fill('000000');
    await page.getByRole('button', { name: 'Verify' }).click();
    await expect(page.getByLabel('Authentication code')).toHaveValue('');
    await expect(page).toHaveURL(/\/login/);

    // the next step's code (the enrolment already used this one; replays are refused)
    await page.getByLabel('Authentication code').fill(totp(secret, 1));
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });
    expect((await page.request.get('/api/v1/profile')).ok()).toBeTruthy();

    await logout(page);

    // ---- a recovery code, once ---------------------------------------------------------------------------------
    await signInWithPassword(page);
    await page.getByRole('button', { name: 'Use a recovery code' }).click();
    await page.getByLabel('Recovery code').fill(recoveryCode);
    await page.getByRole('button', { name: 'Verify' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });
    expect((await page.request.get('/api/v1/profile')).ok()).toBeTruthy();
  });
});
