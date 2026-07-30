import { test, expect } from '@playwright/test';

const API = process.env.QA_API_URL || 'http://127.0.0.1:8000';

function uniqueEmail() {
  return `qa_${Date.now()}_${Math.floor(Math.random() * 1e6)}@example.com`;
}

test.describe('M2 — Authentication', () => {
  test('unauthenticated /api/user returns 401', async ({ request }) => {
    const res = await request.get(`${API}/api/user`, {
      headers: { Accept: 'application/json' },
    });
    expect(res.status()).toBe(401);
  });

  test('signup page loads', async ({ page }) => {
    await page.goto('/signup.html');
    await expect(page.getByRole('heading', { name: /Sign up/i })).toBeVisible();
    await expect(page.locator('#signup-form')).toBeVisible();
  });

  test('login page loads', async ({ page }) => {
    await page.goto('/login.html');
    await expect(page.getByRole('heading', { name: /Log in/i })).toBeVisible();
    await expect(page.locator('#login-form')).toBeVisible();
  });

  test('register via API returns token', async ({ request }) => {
    const email = uniqueEmail();
    const res = await request.post(`${API}/api/register`, {
      data: {
        name: 'QA User',
        email,
        password: 'password123',
        password_confirmation: 'password123',
      },
    });
    expect(res.status()).toBe(201);
    const body = await res.json();
    expect(body.token).toBeTruthy();
    expect(body.user.email).toBe(email);

    const me = await request.get(`${API}/api/user`, {
      headers: { Authorization: `Bearer ${body.token}` },
    });
    expect(me.ok()).toBeTruthy();
    expect((await me.json()).user.email).toBe(email);
  });

  test('signup form creates session and redirects', async ({ page }) => {
    const email = uniqueEmail();
    await page.goto('/signup.html');
    await page.fill('#name', 'QA Browser User');
    await page.fill('#email', email);
    await page.fill('#password', 'password123');
    await page.fill('#password_confirmation', 'password123');
    await page.click('#signup-submit');

    await page.waitForURL(/\/dashboard(\.html)?(\?|$)/, { timeout: 15_000 });
    const token = await page.evaluate(() => localStorage.getItem('cashflow_auth_token'));
    expect(token).toBeTruthy();
  });

  test('login form works for existing user', async ({ page, request }) => {
    const email = uniqueEmail();
    const reg = await request.post(`${API}/api/register`, {
      data: {
        name: 'Login QA',
        email,
        password: 'password123',
        password_confirmation: 'password123',
      },
    });
    expect(reg.status()).toBe(201);

    await page.goto('/login.html');
    await page.fill('#email', email);
    await page.fill('#password', 'password123');
    await page.click('#login-submit');

    await page.waitForURL(/\/dashboard(\.html)?(\?|$)/, { timeout: 15_000 });
    const token = await page.evaluate(() => localStorage.getItem('cashflow_auth_token'));
    expect(token).toBeTruthy();
  });

  test('bad login shows alert', async ({ page }) => {
    await page.goto('/login.html');
    await page.fill('#email', 'nobody@example.com');
    await page.fill('#password', 'wrong-password');
    await page.click('#login-submit');

    const alert = page.locator('#auth-alert');
    await expect(alert).toBeVisible({ timeout: 10_000 });
    await expect(alert).not.toBeHidden();
  });
});
