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

  test('user with expired trial is redirected from cashflow to billing', async ({ page, request }) => {
    const { token } = await registerAndToken(request, 'qa_cf_gate');
    await expireTrial(request, token);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/cashflow.html');
    await expect(page).toHaveURL(/\/billing(\.html)?(\?|$)/, { timeout: 15_000 });
  });

  test('subscribed user can open cashflow page', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_cf_ok');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/cashflow.html');
    await expect(page.getByRole('heading', { name: /Daily Cash Flow/i })).toBeVisible();
    await expect(page).not.toHaveURL(/billing/);
  });

  test('landing page stays public without login', async ({ page }) => {
    await page.goto('/index.html');
    await expect(page.getByRole('heading', { name: /Ring up sales/i })).toBeVisible();
    await expect(page).not.toHaveURL(/login|billing/);
  });
});
