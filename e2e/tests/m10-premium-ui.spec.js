import { test, expect } from '@playwright/test';
import { API, registerAndToken, registerSubscribedUser } from '../helpers/qa-auth.js';

test.describe('M10 — Premium UI, profile & PDF reports', () => {
  test('password can be updated via API and used to log in', async ({ request }) => {
    const { email, token } = await registerAndToken(request, 'qa_pwd');
    const headers = {
      Authorization: `Bearer ${token}`,
      Accept: 'application/json',
    };

    const update = await request.put(`${API}/api/user/password`, {
      headers,
      data: {
        current_password: 'password123',
        password: 'new-password-456',
        password_confirmation: 'new-password-456',
      },
    });
    expect(update.ok()).toBeTruthy();

    const login = await request.post(`${API}/api/login`, {
      data: { email, password: 'new-password-456' },
    });
    expect(login.ok()).toBeTruthy();
  });

  test('wrong current password is rejected', async ({ request }) => {
    const { token } = await registerAndToken(request, 'qa_pwd_bad');

    const update = await request.put(`${API}/api/user/password`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
      data: {
        current_password: 'not-my-password',
        password: 'new-password-456',
        password_confirmation: 'new-password-456',
      },
    });
    expect(update.status()).toBe(422);
  });

  test('profile page updates password via UI', async ({ page, request }) => {
    const { token } = await registerAndToken(request, 'qa_profile_ui');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/profile.html');
    await expect(page.getByRole('heading', { name: /Profile/i })).toBeVisible();

    await page.fill('#current-password', 'password123');
    await page.fill('#new-password', 'brand-new-pass1');
    await page.fill('#confirm-password', 'brand-new-pass1');
    await page.click('#password-save');

    await expect(page.locator('#profile-alert')).toContainText(/Password updated/i, {
      timeout: 15_000,
    });
  });

  test('dashboard shows sidebar and charts for subscribed user', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_dash_ui');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/dashboard.html');
    await expect(page.locator('#app-sidebar')).toBeVisible();
    await expect(page.locator('#app-sidebar')).toContainText('Cash Flow');
    await expect(page.locator('#trend-chart')).toBeVisible();
    await expect(page.locator('#net-chart')).toBeVisible();
  });

  test('reports page offers PDF download', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pdf_ui');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
    }, token);

    await page.goto('/reports.html');
    await expect(page.locator('#download-pdf')).toBeVisible();
    await expect(page.locator('#report-income')).not.toHaveText('—', { timeout: 15_000 });

    const downloadPromise = page.waitForEvent('download', { timeout: 20_000 });
    await page.click('#download-pdf');
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/cashflow-.*\.pdf/);
  });
});
