import { test, expect } from '@playwright/test';
import { API, loginAdmin, registerSubscribedUser } from '../helpers/qa-auth.js';

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

  // The billing page is a platform-operator screen now — a shop account is
  // redirected off it, so the add-on card is checked as the operator sees it.
  test('billing page shows Receipt AI addon card', async ({ page, request }) => {
    const { token } = await loginAdmin(request);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/billing.html');

    await expect(page.locator('#receipt-addon-card')).toBeVisible();
    await expect(page.locator('#receipt-addon-card')).toContainText(/Receipt AI/i);
    // Priced in USD since M23 — Paddle is the merchant of record and does not
    // support PKR, so the old "Rs 500" assertion no longer matches the card.
    await expect(page.locator('#receipt-addon-card')).toContainText(/\$5/);
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
    // Shops no longer buy add-ons themselves, so the upsell names who to ask
    // rather than linking to a checkout that would refuse them.
    await expect(page.locator('#receipt-upsell')).toContainText(/contact the administrator/i);
    await expect(page.locator('#receipt-upsell a')).toHaveCount(0);
  });
});
