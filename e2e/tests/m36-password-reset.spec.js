import { expect, test } from '@playwright/test';
import { API, registerAndToken } from '../helpers/qa-auth.js';

/**
 * "I forgot my password" — walked the way a shopkeeper walks it.
 *
 * Until M36 the only way back into a locked-out account was for somebody else
 * to choose a new password and tell it to the account holder. These tests drive
 * the real pages against the real endpoints; the only thing they short-circuit
 * is reading the mailbox, and the token they use instead is minted by the same
 * broker the mailer uses (see QaController::passwordResetToken).
 */
test.describe('M36 — password reset', () => {
  test('the log in page offers a way out of a forgotten password', async ({ page }) => {
    await page.goto('/login.html');

    const link = page.locator('#forgot-link');
    await expect(link).toBeVisible();
    await link.click();

    await expect(page).toHaveURL(/forgot-password/);
    await expect(page.locator('#forgot-form')).toBeVisible();
  });

  test('asking for a link says the same thing for a known and an unknown address', async ({
    page,
    request,
  }) => {
    const { email } = await registerAndToken(request, 'reset');

    await page.goto('/forgot-password.html');
    await page.fill('#email', email);
    await page.click('#forgot-submit');

    const alert = page.locator('#auth-alert');
    await expect(alert).toBeVisible();
    const known = (await alert.textContent())?.trim();
    expect(known).toBeTruthy();

    // The form clears itself, so a shared counter phone does not keep the
    // address on screen.
    await expect(page.locator('#email')).toHaveValue('');

    await page.goto('/forgot-password.html');
    await page.fill('#email', `definitely-not-registered_${Date.now()}@example.com`);
    await page.click('#forgot-submit');

    await expect(alert).toBeVisible();
    // Identical wording is the whole point: anything else and this endpoint
    // becomes a way to find out which shopkeepers bank here.
    expect((await alert.textContent())?.trim()).toBe(known);
  });

  test('a shopkeeper sets a new password from the emailed link and logs in with it', async ({
    page,
    request,
  }) => {
    const { email, token } = await registerAndToken(request, 'reset');

    // Stand in for opening the email. The fragment is where the mailer puts
    // these — see AppServiceProvider::configurePasswordResetLinks().
    const resetToken = await freshResetToken(request, token, email);

    await page.goto(
      `/reset-password.html#token=${resetToken}&email=${encodeURIComponent(email)}`
    );

    // The address arrives filled in and locked: it is what the token was issued
    // for, and editing it can only produce a failure.
    await expect(page.locator('#email')).toHaveValue(email);
    await expect(page.locator('#email')).toHaveAttribute('readonly', '');

    // The token must not linger in the address bar, or it lands in history.
    await expect(page).toHaveURL(/reset-password\.html$/);

    const newPassword = 'brand-new-password-9';
    await page.fill('#password', newPassword);
    await page.fill('#password_confirmation', newPassword);
    await page.click('#reset-submit');

    await expect(page).toHaveURL(/login/, { timeout: 10_000 });

    // The new password works…
    const good = await request.post(`${API}/api/login`, {
      data: { email, password: newPassword },
    });
    expect(good.status()).toBe(200);

    // …and the old one does not.
    const bad = await request.post(`${API}/api/login`, {
      data: { email, password: 'password123' },
    });
    expect(bad.status()).toBe(422);
  });

  test('the reset ends every session that was already open', async ({ request }) => {
    const { email, token } = await registerAndToken(request, 'reset');

    const resetToken = await freshResetToken(request, token, email);

    const reset = await request.post(`${API}/api/password/reset`, {
      data: {
        token: resetToken,
        email,
        password: 'brand-new-password-9',
        password_confirmation: 'brand-new-password-9',
      },
    });
    expect(reset.status()).toBe(200);

    // The token that was live before the reset is dead after it — the point of
    // resetting is that the old credential is assumed loose.
    const stale = await request.get(`${API}/api/user`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    });
    expect(stale.status()).toBe(401);
  });

  test('the same link cannot be used twice', async ({ page, request }) => {
    const { email, token } = await registerAndToken(request, 'reset');
    const resetToken = await freshResetToken(request, token, email);

    const first = await request.post(`${API}/api/password/reset`, {
      data: {
        token: resetToken,
        email,
        password: 'brand-new-password-9',
        password_confirmation: 'brand-new-password-9',
      },
    });
    expect(first.status()).toBe(200);

    await page.goto(`/reset-password.html#token=${resetToken}&email=${encodeURIComponent(email)}`);
    await page.fill('#password', 'another-password-77');
    await page.fill('#password_confirmation', 'another-password-77');
    await page.click('#reset-submit');

    await expect(page.locator('#auth-alert')).toContainText(/invalid or has expired/i);
    await expect(page).toHaveURL(/reset-password/);
  });

  test('opening the reset page without a link refuses to take a password', async ({ page }) => {
    await page.goto('/reset-password.html');

    await expect(page.locator('#auth-alert')).toBeVisible();
    await expect(page.locator('#reset-submit')).toBeDisabled();
  });

  test('the two password boxes have to agree', async ({ page, request }) => {
    const { email, token } = await registerAndToken(request, 'reset');
    const resetToken = await freshResetToken(request, token, email);

    await page.goto(`/reset-password.html#token=${resetToken}&email=${encodeURIComponent(email)}`);
    await page.fill('#password', 'brand-new-password-9');
    await page.fill('#password_confirmation', 'something-else-entirely');
    await page.click('#reset-submit');

    await expect(page.locator('#auth-alert')).toContainText(/do not match/i);
  });
});

/**
 * The token the email would have carried.
 *
 * `token` is any logged-in bearer token — the QA endpoint is local/testing only
 * and additionally requires one, so it cannot be used anonymously even if the
 * environment guard were ever dropped.
 */
async function freshResetToken(request, token, email) {
  const res = await request.post(`${API}/api/qa/password-reset-token`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    data: { email },
  });
  expect(res.status()).toBe(200);
  return (await res.json()).token;
}
