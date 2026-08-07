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
    await expect(page.locator('#subscription-lapsed')).toHaveCount(0);
  });

  test('dashboard shows remaining trial days without offering a purchase', async ({
    page,
    request,
  }) => {
    const { token } = await registerAndToken(request, 'qa_trial_bill');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/dashboard.html');
    await expect(page.locator('#dashboard-alert')).toContainText(/Free trial: \d+ day/i, {
      timeout: 15_000,
    });
    await expect(page.locator('#dashboard-alert')).toContainText(/contact the administrator/i);
  });

  test('expired trial user is told to contact the administrator', async ({ page, request }) => {
    const { email, token } = await registerAndToken(request, 'qa_trial_exp');
    await expireTrial(request, token);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    // The dashboard reads billing state directly rather than tripping the gate,
    // so it has its own copy of the message and its own way to get it wrong.
    await page.goto('/dashboard.html');
    const empty = page.locator('#week-empty');
    await expect(empty).toContainText(/free trial has ended/i, { timeout: 15_000 });
    await expect(empty).toContainText(/contact the administrator/i);
    await expect(empty).toContainText(email);
    await expect(page.locator('#week-empty a')).toHaveCount(0);
  });
});
