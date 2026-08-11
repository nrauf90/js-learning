import { test, expect } from '@playwright/test';
import {
  API,
  registerAndToken,
  registerSubscribedUser,
  expireTrial,
} from '../helpers/qa-auth.js';

test.describe('M7 — Subscription gating', () => {
  test('user with expired trial gets 402 on cash-entries API', async ({ request }) => {
    const { token } = await registerAndToken(request, 'qa_gate');
    await expireTrial(request, token);
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const res = await request.get(`${API}/api/cash-entries`, { headers });
    expect(res.status()).toBe(402);
    const body = await res.json();
    expect(body.code).toBe('trial_expired');
    // Self-serve billing is gone, so the 402 must name an action the shop can
    // actually take rather than telling them to subscribe.
    expect(body.message).toMatch(/contact the administrator/i);
  });

  test('subscribed user can access cash-entries API', async ({ request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_sub');
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const res = await request.get(`${API}/api/cash-entries`, { headers });
    expect(res.ok()).toBeTruthy();
  });

  test('expired shop is told to contact the administrator, not to subscribe', async ({
    page,
    request,
  }) => {
    const { email, token } = await registerAndToken(request, 'qa_cf_gate');
    await expireTrial(request, token);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/cashflow.html');

    const notice = page.locator('#subscription-lapsed');
    await expect(notice).toBeVisible({ timeout: 15_000 });
    await expect(notice).toContainText(/contact the administrator/i);
    // Their own details, so they can quote the account being asked about.
    await expect(page.locator('#subscription-lapsed-details')).toContainText(email);
    // The old behaviour was a redirect to a checkout they can no longer use.
    await expect(page).not.toHaveURL(/billing/);
    await expect(notice.getByRole('button', { name: /subscribe/i })).toHaveCount(0);
  });

  test('subscribed user can open cashflow page', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_cf_ok');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/cashflow.html');
    await expect(page.getByRole('heading', { name: /Daily Cash Flow/i })).toBeVisible();
    await expect(page.locator('#subscription-lapsed')).toHaveCount(0);
  });

  test('landing page stays public without login', async ({ page }) => {
    await page.goto('/index.html');
    await expect(page.getByRole('heading', { name: /Dukaan sambhalo/i })).toBeVisible();
    await expect(page).not.toHaveURL(/login|billing/);
  });
});
