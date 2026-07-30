import { test, expect } from '@playwright/test';

const API = process.env.QA_API_URL || 'http://127.0.0.1:8000';

function uniqueEmail() {
  return `qa_bill_${Date.now()}_${Math.floor(Math.random() * 1e6)}@example.com`;
}

async function registerAndToken(request) {
  const email = uniqueEmail();
  const res = await request.post(`${API}/api/register`, {
    data: {
      name: 'Billing QA',
      email,
      password: 'password123',
      password_confirmation: 'password123',
    },
  });
  expect(res.status()).toBe(201);
  return { token: (await res.json()).token };
}

test.describe('M6 — Billing', () => {
  test('plans endpoint is public', async ({ request }) => {
    const res = await request.get(`${API}/api/billing/plans`);
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    expect(body.plans.some((p) => p.id === 'monthly' && p.amount === 500)).toBeTruthy();
    expect(body.plans.some((p) => p.id === 'yearly' && p.amount === 5400)).toBeTruthy();
  });

  test('sandbox checkout activates subscription via API', async ({ request }) => {
    const { token } = await registerAndToken(request);
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const checkout = await request.post(`${API}/api/billing/checkout`, {
      headers,
      data: { plan: 'monthly', provider: 'jazzcash' },
    });
    expect(checkout.status()).toBe(201);
    const checkoutBody = await checkout.json();
    expect(checkoutBody.checkout.sandbox).toBeTruthy();

    const complete = await request.post(
      `${API}/api/billing/sandbox/complete/${checkoutBody.payment.id}`,
      { headers }
    );
    expect(complete.ok()).toBeTruthy();
    expect((await complete.json()).subscription.active).toBeTruthy();

    const sub = await request.get(`${API}/api/billing/subscription`, { headers });
    expect(sub.ok()).toBeTruthy();
    expect((await sub.json()).subscription.plan).toBe('monthly');
  });

  test('guest is redirected from billing page to login', async ({ page }) => {
    await page.goto('/billing.html');
    await page.evaluate(() => localStorage.clear());
    await page.goto('/billing.html');
    await expect(page).toHaveURL(/\/login(\.html)?(\?|$)/, { timeout: 15_000 });
  });

  test('logged-in user can complete sandbox checkout in UI', async ({ page, request }) => {
    const { token } = await registerAndToken(request);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/billing.html');

    await expect(page.getByRole('heading', { name: /Billing/i })).toBeVisible();
    await expect(page.locator('#plan-cards')).toBeVisible({ timeout: 15_000 });

    await page.click('#checkout-btn');

    await expect(page.locator('#billing-alert')).toContainText(/activated|completed/i, {
      timeout: 15_000,
    });
    await expect(page.locator('#subscription-info')).toContainText(/Active/i);
  });
});
