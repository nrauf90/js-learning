import { test, expect } from '@playwright/test';
import { API, registerSubscribedUser } from '../helpers/qa-auth.js';

test.describe('M3 — Daily cash flow', () => {
  test('guest is redirected from cashflow page to login', async ({ page }) => {
    await page.goto('/cashflow.html');
    await page.evaluate(() => localStorage.clear());
    await page.goto('/cashflow.html');
    await expect(page).toHaveURL(/\/login(\.html)?(\?|$)/, { timeout: 15_000 });
    await expect(page.locator('#login-form')).toBeVisible();
  });

  test('API CRUD for cash entries', async ({ request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_cf');
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const cats = await request.get(`${API}/api/categories?kind=expense`, { headers });
    expect(cats.ok()).toBeTruthy();
    const categoryId = (await cats.json()).categories[0].id;

    const created = await request.post(`${API}/api/cash-entries`, {
      headers,
      data: {
        category_id: categoryId,
        type: 'expense',
        amount: 750,
        entry_date: '2026-07-30',
        note: 'QA petrol',
      },
    });
    expect(created.status()).toBe(201);
    const entryId = (await created.json()).entry.id;

    const list = await request.get(`${API}/api/cash-entries?date=2026-07-30`, { headers });
    expect(list.ok()).toBeTruthy();
    expect((await list.json()).entries.some((e) => e.id === entryId)).toBeTruthy();

    const updated = await request.put(`${API}/api/cash-entries/${entryId}`, {
      headers,
      data: { amount: 800 },
    });
    expect(updated.ok()).toBeTruthy();
    expect((await updated.json()).entry.amount).toBe(800);

    const deleted = await request.delete(`${API}/api/cash-entries/${entryId}`, { headers });
    expect(deleted.ok()).toBeTruthy();
  });

  test('logged-in user can add an expense in the UI', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_cf_ui');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/cashflow.html');

    await expect(page.getByRole('heading', { name: /Daily Cash Flow/i })).toBeVisible();
    await expect(page.locator('#entry-category option')).toHaveCount(10, { timeout: 15_000 });

    await page.fill('#entry-amount', '1200');
    await page.fill('#entry-note', 'UI grocery');
    await page.click('#entry-submit');

    await expect(page.locator('.entry-row').filter({ hasText: 'UI grocery' })).toBeVisible({
      timeout: 15_000,
    });
  });

  test('logged-in user can add an income entry in the UI', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_cf_inc');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/cashflow.html');

    await expect(page.locator('#entry-category option')).toHaveCount(10, { timeout: 15_000 });

    await page.selectOption('#entry-type', 'income');
    await page.waitForResponse(
      (r) => r.url().includes('/api/categories') && r.url().includes('kind=income') && r.ok()
    );
    await expect(page.locator('#entry-category option')).toHaveCount(5, { timeout: 15_000 });

    await page.fill('#entry-amount', '75000');
    await page.fill('#entry-note', 'UI salary');
    await page.click('#entry-submit');

    await expect(page.locator('.entry-row').filter({ hasText: 'UI salary' })).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.locator('.entry-type-income').first()).toBeVisible();
  });
});
