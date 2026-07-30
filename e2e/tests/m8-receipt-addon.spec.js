import { test, expect } from '@playwright/test';
import { API, registerSubscribedUser } from '../helpers/qa-auth.js';

test.describe('M8 — Receipt addon scaffold', () => {
  test('receipt upload stub returns 501', async ({ request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_rcpt');
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const res = await request.post(`${API}/api/receipts/upload`, { headers });
    expect(res.status()).toBe(501);
    expect((await res.json()).message).toBe('Coming soon');
  });

  test('billing page shows Receipt AI addon card', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_rcpt_bill');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/billing.html');

    await expect(page.locator('#receipt-addon-card')).toBeVisible();
    await expect(page.locator('#receipt-addon-card')).toContainText(/Receipt AI/i);
    await expect(page.locator('#receipt-addon-card')).toContainText(/500/);
    await expect(page.locator('#receipt-addon-card .billing-addon-badge')).toContainText(/Coming soon/i);
  });

  test('cashflow form shows receipt upsell when addon inactive', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_rcpt_cf');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/cashflow.html');

    await expect(page.locator('#receipt-upsell')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#receipt-upsell')).toContainText(/Attach receipt \(Premium\)/i);
    await expect(page.locator('#receipt-upsell a')).toHaveAttribute('href', /billing/);
  });
});
