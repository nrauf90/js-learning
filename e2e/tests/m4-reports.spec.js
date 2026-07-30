import { test, expect } from '@playwright/test';
import { API, registerSubscribedUser } from '../helpers/qa-auth.js';

test.describe('M4 — Reports', () => {
  test('guest is redirected from reports page to login', async ({ page }) => {
    await page.goto('/reports.html');
    await page.evaluate(() => localStorage.clear());
    await page.goto('/reports.html');
    await expect(page).toHaveURL(/\/login(\.html)?(\?|$)/, { timeout: 15_000 });
  });

  test('weekly and monthly report APIs aggregate entries', async ({ request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_rpt');
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
        amount: 1200,
        entry_date: '2026-07-30',
        note: 'Report QA',
      },
    });
    expect(created.status()).toBe(201);

    const weekly = await request.get(`${API}/api/reports/weekly?start=2026-07-30`, { headers });
    expect(weekly.ok()).toBeTruthy();
    const weeklyBody = await weekly.json();
    expect(weeklyBody.total_expense).toBeGreaterThanOrEqual(1200);
    expect(weeklyBody.period.start).toBeTruthy();
    expect(Array.isArray(weeklyBody.by_category)).toBeTruthy();
    expect(Array.isArray(weeklyBody.income_by_category)).toBeTruthy();
    expect(Array.isArray(weeklyBody.expense_by_category)).toBeTruthy();

    const monthly = await request.get(`${API}/api/reports/monthly?year=2026&month=7`, { headers });
    expect(monthly.ok()).toBeTruthy();
    const monthlyBody = await monthly.json();
    expect(monthlyBody.total_expense).toBeGreaterThanOrEqual(1200);
    expect(monthlyBody.period.start).toBe('2026-07-01');

    const yearly = await request.get(`${API}/api/reports/yearly?year=2026`, { headers });
    expect(yearly.ok()).toBeTruthy();
    const yearlyBody = await yearly.json();
    expect(yearlyBody.period.start).toBe('2026-01-01');
    expect(yearlyBody.period.end).toBe('2026-12-31');
  });

  test('logged-in user sees report summary and chart', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_rpt_ui');
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const cats = await request.get(`${API}/api/categories?kind=expense`, { headers });
    expect(cats.ok()).toBeTruthy();
    const categoryId = (await cats.json()).categories[0].id;

    await request.post(`${API}/api/cash-entries`, {
      headers,
      data: {
        category_id: categoryId,
        type: 'expense',
        amount: 2500,
        entry_date: '2026-07-30',
      },
    });

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/reports.html');

    await expect(page.getByRole('heading', { name: /Reports/i })).toBeVisible();
    await expect(page.locator('#report-expense')).not.toHaveText('—', { timeout: 15_000 });
    await expect(page.locator('#expense-chart')).toBeVisible();
  });
});
