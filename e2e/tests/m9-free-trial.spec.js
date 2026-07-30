import { test, expect } from '@playwright/test';
import { API, registerAndToken, expireTrial } from '../helpers/qa-auth.js';

test.describe('M9 — 7-day free trial', () => {
  test('new user on trial can access cash-entries API', async ({ request }) => {
    const { token } = await registerAndToken(request, 'qa_trial');
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const res = await request.get(`${API}/api/cash-entries`, { headers });
    expect(res.ok()).toBeTruthy();
  });

  test('subscription endpoint shows active trial', async ({ request }) => {
    const { token } = await registerAndToken(request, 'qa_trial_api');
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const res = await request.get(`${API}/api/billing/subscription`, { headers });
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    expect(body.trial.active).toBeTruthy();
    expect(body.trial.days_remaining).toBeGreaterThanOrEqual(0);
  });

  test('new user on trial can open cashflow page', async ({ page, request }) => {
    const { token } = await registerAndToken(request, 'qa_trial_ui');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/cashflow.html');
    await expect(page.getByRole('heading', { name: /Daily Cash Flow/i })).toBeVisible();
    await expect(page).not.toHaveURL(/billing/);
  });

  test('billing page shows free trial status for new user', async ({ page, request }) => {
    const { token } = await registerAndToken(request, 'qa_trial_bill');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/billing.html');
    await expect(page.locator('#subscription-info')).toContainText(/Free trial/i, {
      timeout: 15_000,
    });
  });

  test('expired trial user sees subscribe prompt on billing', async ({ page, request }) => {
    const { token } = await registerAndToken(request, 'qa_trial_exp');
    await expireTrial(request, token);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/billing.html');
    await expect(page.locator('#subscription-info')).toContainText(/trial has ended/i, {
      timeout: 15_000,
    });
  });
});
