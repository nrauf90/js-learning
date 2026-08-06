import { test, expect } from '@playwright/test';

test.describe('M13 — Theme default & FOUC fix', () => {
  test('root paints light theme before any script runs (no dark-then-light flash)', async ({ page }) => {
    // Block every script so we can inspect the very first paint — the raw
    // browser-computed styles from CSS alone, before js/theme.js ever runs.
    await page.route('**/*.js', (route) => route.abort());
    await page.goto('/index.html').catch(() => {});

    const html = page.locator('html');
    await expect(html).not.toHaveAttribute('data-theme', 'dark');

    const bg = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--bg').trim());
    // Light --bg from css/styles.css :root (unattributed block).
    expect(bg).toBe('#f5f7fa');
  });

  test('toggle switches to dark and persists across reload', async ({ page }) => {
    await page.goto('/index.html');
    await page.evaluate(() => localStorage.removeItem('tax-calculator-theme'));
    await page.reload();

    const toggle = page.locator('#theme-toggle');
    await expect(toggle).toBeVisible();
    await expect(page.locator('html')).not.toHaveAttribute('data-theme', 'dark');

    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');

    const stored = await page.evaluate(() => localStorage.getItem('tax-calculator-theme'));
    expect(stored).toBe('dark');
  });

  test('explicit light choice persists even when OS prefers dark', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/index.html');
    await page.evaluate(() => localStorage.setItem('tax-calculator-theme', 'light'));
    await page.reload();

    await expect(page.locator('html')).not.toHaveAttribute('data-theme', 'dark');
  });
});
