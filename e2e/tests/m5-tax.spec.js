import { test, expect } from '@playwright/test';

test.describe('M5 — Free tax calculator', () => {
  test('guest can open full calculator without login', async ({ page }) => {
    await page.goto('/calculator.html');
    await page.evaluate(() => localStorage.clear());

    await expect(page).not.toHaveURL(/login/);
    await expect(page.getByRole('heading', { name: /Pakistan Tax Calculator/i })).toBeVisible();
    await expect(page.locator('#income')).toBeVisible();
    await expect(page.locator('#results')).toBeVisible();
  });

  test('landing links to calculator without auth', async ({ page }) => {
    await page.goto('/index.html');
    await page.evaluate(() => localStorage.clear());

    await page.getByRole('link', { name: /Full tax calculator/i }).click();
    await expect(page).toHaveURL(/\/calculator(\.html)?(\?|$)/, { timeout: 10_000 });
    await expect(page.locator('#income')).toBeVisible();
  });

  test('nav Tax Calculator works for guests', async ({ page }) => {
    await page.goto('/index.html');
    await page.evaluate(() => localStorage.clear());

    await page.getByRole('navigation').getByRole('link', { name: 'Tax Calculator' }).click();
    await expect(page).toHaveURL(/\/calculator(\.html)?(\?|$)/, { timeout: 10_000 });
  });

  test('calculator computes tax from income input', async ({ page }) => {
    await page.goto('/calculator.html');

    await page.fill('#income', '1200000');
    await page.locator('#income').dispatchEvent('input');

    await expect(page.locator('#results')).toContainText(/Annual tax/i, { timeout: 10_000 });
    await expect(page.locator('#results')).not.toContainText(/Rs 0/);
  });

  test('landing widget updates total tax without login', async ({ page }) => {
    await page.goto('/index.html');

    await page.fill('#hero-income', '2400000');
    await page.locator('#hero-income').dispatchEvent('input');

    const total = page.locator('#hero-tax-total');
    await expect(total).not.toHaveText('Rs 0', { timeout: 10_000 });
    await expect(total).toContainText(/Rs/i);
  });
});
