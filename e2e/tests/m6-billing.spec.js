import { test, expect } from '@playwright/test';
import { API, registerAndToken, loginAdmin } from '../helpers/qa-auth.js';

function authHeaders(token) {
  return {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  };
}

test.describe('M6 — Billing', () => {
  test('plans endpoint is public', async ({ request }) => {
    const res = await request.get(`${API}/api/billing/plans`);
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    // Amounts are env-configurable, so assert on shape rather than a figure
    // that would have to be edited every time pricing moves in Paddle.
    expect(body.provider.id).toBe('paddle');
    expect(body.plans.some((p) => p.id === 'monthly' && p.amount > 0)).toBeTruthy();
    expect(body.plans.some((p) => p.id === 'yearly' && p.amount > 0)).toBeTruthy();
  });

  test('shop account is refused checkout, portal and cancel', async ({ request }) => {
    const { token } = await registerAndToken(request, 'qa_bill_shop');
    const headers = authHeaders(token);

    const checkout = await request.post(`${API}/api/billing/checkout`, {
      headers,
      data: { plan: 'monthly' },
    });
    expect(checkout.status()).toBe(403);

    expect((await request.post(`${API}/api/billing/portal`, { headers })).status()).toBe(403);
    expect((await request.post(`${API}/api/billing/cancel`, { headers })).status()).toBe(403);
    // The sandbox completer can hand out access without a webhook, so it is
    // gated too — id 1 is enough to prove the middleware fires first.
    expect(
      (await request.post(`${API}/api/billing/sandbox/complete/1`, { headers })).status()
    ).toBe(403);
  });

  test('shop account can still read its own subscription state', async ({ request }) => {
    const { email, token } = await registerAndToken(request, 'qa_bill_read');

    const res = await request.get(`${API}/api/billing/subscription`, {
      headers: authHeaders(token),
    });
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    expect(body.trial.active).toBeTruthy();
    // The lapsed notice quotes these back to the shopkeeper.
    expect(body.account.email).toBe(email);
  });

  test('admin can complete a sandbox checkout via API', async ({ request }) => {
    const { token } = await loginAdmin(request);
    const headers = authHeaders(token);

    const checkout = await request.post(`${API}/api/billing/checkout`, {
      headers,
      data: { plan: 'monthly' },
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
  });

  test('admin can grant a shop its subscription without the shop buying it', async ({
    request,
  }) => {
    const { email, token: shopToken } = await registerAndToken(request, 'qa_bill_grant');
    const { token: adminToken } = await loginAdmin(request);

    const grant = await request.post(`${API}/api/admin/subscriptions`, {
      headers: authHeaders(adminToken),
      data: { email, plan: 'monthly' },
    });
    expect(grant.status()).toBe(201);

    const state = await request.get(`${API}/api/billing/subscription`, {
      headers: authHeaders(shopToken),
    });
    const body = await state.json();
    expect(body.subscription.active).toBeTruthy();
    // Nothing at Paddle backs a hand-granted row, so no portal and no cancel.
    expect(body.subscription.managed).toBeFalsy();
  });

  test('admin grants a subscription from the admin screen', async ({ page, request }) => {
    // The screen that replaces self-serve checkout, driven the way an operator
    // drives it — a shop with no subscription of its own gets one.
    const { email } = await registerAndToken(request, 'qa_bill_ui_grant');
    const { token } = await loginAdmin(request);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/admin-subscriptions.html');

    await page.fill('#grant-email', email);
    await page.selectOption('#grant-plan', 'yearly');
    await page.click('#grant-submit');

    await expect(page.locator('#admin-alert')).toContainText(/Subscription active until/i, {
      timeout: 15_000,
    });
    await expect(page.locator('#subs-body')).toContainText(email);
  });

  test('guest is redirected from billing page to login', async ({ page }) => {
    await page.goto('/billing.html');
    await page.evaluate(() => localStorage.clear());
    await page.goto('/billing.html');
    await expect(page).toHaveURL(/\/login(\.html)?(\?|$)/, { timeout: 15_000 });
  });

  test('shop account landing on the billing page is sent away', async ({ page, request }) => {
    const { token } = await registerAndToken(request, 'qa_bill_page');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/billing.html');

    await expect(page).toHaveURL(/\/dashboard(\.html)?(\?|$)/, { timeout: 15_000 });
    await expect(page.locator('#checkout-btn')).toHaveCount(0);
  });

  test('admin can still complete a sandbox checkout in the UI', async ({ page, request }) => {
    const { token } = await loginAdmin(request);

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
