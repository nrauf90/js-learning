import { test, expect } from '@playwright/test';

const API = process.env.QA_API_URL || 'http://127.0.0.1:8000';

test.describe('M1 — Foundation', () => {
  test('API health returns ok', async ({ request }) => {
    const res = await request.get(`${API}/api/health`);
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    expect(body.status).toBe('ok');
    expect(body.app).toBeTruthy();
  });

  test('CORS allows frontend origin on health', async ({ request }) => {
    const res = await request.get(`${API}/api/health`, {
      headers: { Origin: 'http://localhost:3000' },
    });
    expect(res.ok()).toBeTruthy();
    const acao = res.headers()['access-control-allow-origin'];
    expect(acao === 'http://localhost:3000' || acao === '*').toBeTruthy();
  });

  test('landing page loads', async ({ page }) => {
    await page.goto('/index.html');
    await expect(page.getByRole('heading', { name: /Dukaan sambhalo/i })).toBeVisible();
    await expect(page.getByRole('link', { name: /Start free trial/i }).first()).toBeVisible();
  });
});
