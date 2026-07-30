import { test, expect } from '@playwright/test';
import { API, registerAndToken, registerSubscribedUser, loginAdmin } from '../helpers/qa-auth.js';

test.describe('M11 — Admin Panel', () => {
  test('non-admin user cannot access /api/admin/dashboard', async ({ request }) => {
    const { token } = await registerAndToken(request, 'qa_nonadmin');
    const res = await request.get(`${API}/api/admin/dashboard`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    expect(res.status()).toBe(403);
    const body = await res.json();
    expect(body.error).toContain('Admin');
  });

  test('admin user can access /api/admin/dashboard', async ({ request }) => {
    const { token } = await loginAdmin(request);
    const res = await request.get(`${API}/api/admin/dashboard`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    expect(data.stats).toBeDefined();
    expect(data.stats.total_users).toBeGreaterThanOrEqual(0);
    expect(data.stats.active_subscriptions).toBeGreaterThanOrEqual(0);
    expect(data.stats.monthly_revenue).toBeDefined();
    expect(data.stats.total_entries).toBeGreaterThanOrEqual(0);
  });

  test('admin dashboard page loads with stats', async ({ page, request }) => {
    const { token } = await loginAdmin(request);

    // Note: seed localStorage via addInitScript (runs before any page script)
    // rather than networkidle, which never resolves because of the
    // always-pending Google Fonts / CDN requests on this page.
    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
      localStorage.setItem('cashflow_auth_user', JSON.stringify({
        email: 'admin@cashflow.local',
        name: 'Admin User',
        is_admin: true
      }));
    }, token);

    await page.goto('/admin.html');

    await expect(page.getByRole('heading', { name: /Admin Dashboard/i })).toBeVisible();
    await expect(page.locator('#stat-users')).not.toHaveText('—', { timeout: 15_000 });
    await expect(page.locator('#stat-subscriptions')).not.toHaveText('—');
    await expect(page.locator('#stat-revenue')).not.toHaveText('—');
    await expect(page.locator('#stat-entries')).not.toHaveText('—');
  });

  test('admin can view users list via API', async ({ request }) => {
    const { token } = await loginAdmin(request);

    // Create a test user first
    await registerAndToken(request, 'qa_admintest');

    const res = await request.get(`${API}/api/admin/users`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    expect(data.data).toBeDefined();
    expect(Array.isArray(data.data)).toBeTruthy();
    expect(data.data.length).toBeGreaterThan(0);
  });

  test('admin users page loads and displays users', async ({ page, request }) => {
    const { token } = await loginAdmin(request);

    // Create test users
    await registerAndToken(request, 'qa_user1');
    await registerAndToken(request, 'qa_user2');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
      localStorage.setItem('cashflow_auth_user', JSON.stringify({
        email: 'admin@cashflow.local',
        name: 'Admin User',
        is_admin: true
      }));
    }, token);

    await page.goto('/admin-users.html');

    await expect(page.getByRole('heading', { name: /User Management/i })).toBeVisible();
    await expect(page.locator('#users-table')).toBeVisible();

    // Wait for users to load (table should not show "Loading...")
    await expect(page.locator('#users-body')).not.toContainText('Loading...', { timeout: 15_000 });
  });

  test('admin can search users', async ({ page, request }) => {
    const { token } = await loginAdmin(request);

    // Create test users with unique prefix
    const testPrefix = `search_${Date.now()}`;
    await registerAndToken(request, testPrefix);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
      localStorage.setItem('cashflow_auth_user', JSON.stringify({
        email: 'admin@cashflow.local',
        name: 'Admin User',
        is_admin: true
      }));
    }, token);

    await page.goto('/admin-users.html');
    await expect(page.locator('#users-body')).not.toContainText('Loading...', { timeout: 15_000 });

    // Search for the test user
    await page.fill('#search-input', testPrefix);
    await page.waitForTimeout(1000); // Wait for debounce

    // Should show filtered results
    await expect(page.locator('#users-body')).toContainText(testPrefix);
  });

  test('admin can view user details via API', async ({ request }) => {
    const { token } = await loginAdmin(request);
    const { token: userToken } = await registerSubscribedUser(request, 'qa_details');

    // Get user ID by fetching users list
    const usersRes = await request.get(`${API}/api/admin/users`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    const usersData = await usersRes.json();
    const testUser = usersData.data.find(u => u.email.includes('qa_details'));
    expect(testUser).toBeDefined();

    // Get user details
    const res = await request.get(`${API}/api/admin/users/${testUser.id}`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    expect(data.user).toBeDefined();
    expect(data.user.id).toBe(testUser.id);
    expect(data.entries_stats).toBeDefined();
  });

  test('admin can update user via API', async ({ request }) => {
    const { token } = await loginAdmin(request);
    const { token: userToken } = await registerAndToken(request, 'qa_update');

    // Get user ID
    const usersRes = await request.get(`${API}/api/admin/users`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    const usersData = await usersRes.json();
    const testUser = usersData.data.find(u => u.email.includes('qa_update'));

    // Update user name
    const res = await request.put(`${API}/api/admin/users/${testUser.id}`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
      data: {
        name: 'Updated QA User',
      },
    });
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    expect(data.user.name).toBe('Updated QA User');
  });

  test('admin cannot demote themselves', async ({ request }) => {
    const { token, user } = await loginAdmin(request);

    const res = await request.put(`${API}/api/admin/users/${user.id}`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
      data: {
        is_admin: false,
      },
    });
    expect(res.status()).toBe(422);
    const data = await res.json();
    expect(data.error).toContain('yourself');
  });

  test('admin can view subscriptions via API', async ({ request }) => {
    const { token } = await loginAdmin(request);

    // Create a subscribed user
    await registerSubscribedUser(request, 'qa_sub');

    const res = await request.get(`${API}/api/admin/subscriptions`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    expect(data.data).toBeDefined();
    expect(Array.isArray(data.data)).toBeTruthy();
  });

  test('admin can view payments via API', async ({ request }) => {
    const { token } = await loginAdmin(request);

    // Create a subscribed user (which creates a payment)
    await registerSubscribedUser(request, 'qa_payment');

    const res = await request.get(`${API}/api/admin/payments`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    expect(data.data).toBeDefined();
    expect(Array.isArray(data.data)).toBeTruthy();
  });

  test('admin can view categories via API', async ({ request }) => {
    const { token } = await loginAdmin(request);

    const res = await request.get(`${API}/api/admin/categories`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    expect(res.ok()).toBeTruthy();
    const data = await res.json();
    expect(data.categories).toBeDefined();
    expect(Array.isArray(data.categories)).toBeTruthy();
  });

  test('admin can create category via API', async ({ request }) => {
    const { token } = await loginAdmin(request);
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const categoryName = `QA Category ${Date.now()}`;

    const res = await request.post(`${API}/api/admin/categories`, {
      headers,
      data: {
        name: categoryName,
        kind: 'expense',
      },
    });
    expect(res.status()).toBe(201);
    const data = await res.json();
    expect(data.category.name).toBe(categoryName);
    expect(data.category.kind).toBe('expense');

    // Clean up: this suite runs against the shared dev DB (not per-test
    // isolated), so leftover categories would inflate the category counts
    // asserted by earlier milestone specs (e.g. m3-cashflow.spec.js).
    await request.delete(`${API}/api/admin/categories/${data.category.id}`, { headers });
  });

  test('admin can update category via API', async ({ request }) => {
    const { token } = await loginAdmin(request);
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const categoryName = `QA Category ${Date.now()}`;

    // Create category first
    const createRes = await request.post(`${API}/api/admin/categories`, {
      headers,
      data: {
        name: categoryName,
        kind: 'expense',
      },
    });
    const category = (await createRes.json()).category;

    // Update category
    const updateRes = await request.put(`${API}/api/admin/categories/${category.id}`, {
      headers,
      data: {
        name: `${categoryName} Updated`,
      },
    });
    expect(updateRes.ok()).toBeTruthy();
    const data = await updateRes.json();
    expect(data.category.name).toBe(`${categoryName} Updated`);

    await request.delete(`${API}/api/admin/categories/${category.id}`, { headers });
  });

  test('admin cannot delete category in use', async ({ request }) => {
    const { token } = await loginAdmin(request);

    // Get an existing category (assuming Food exists from seeder)
    const categoriesRes = await request.get(`${API}/api/admin/categories`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    });
    const categories = (await categoriesRes.json()).categories;
    const categoryInUse = categories.find(c => c.cash_entries_count > 0);

    if (categoryInUse) {
      const deleteRes = await request.delete(`${API}/api/admin/categories/${categoryInUse.id}`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: 'application/json',
        },
      });
      expect(deleteRes.status()).toBe(422);
      const data = await deleteRes.json();
      expect(data.error).toMatch(/in use|used by/i);
    }
  });

  test('admin sidebar shows Admin Panel link', async ({ page, request }) => {
    const { token } = await loginAdmin(request);

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
      localStorage.setItem('cashflow_auth_user', JSON.stringify({
        email: 'admin@cashflow.local',
        name: 'Admin User',
        is_admin: true
      }));
    }, token);

    await page.goto('/dashboard.html');

    // Check if Admin Panel link is visible in sidebar
    await expect(page.getByRole('link', { name: /Admin Panel/i })).toBeVisible();
  });

  test('non-admin sidebar does not show Admin Panel link', async ({ page, request }) => {
    const { token } = await registerAndToken(request, 'qa_nonadmin2');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
      localStorage.setItem('cashflow_auth_user', JSON.stringify({
        email: 'test@example.com',
        name: 'Regular User',
        is_admin: false
      }));
    }, token);

    await page.goto('/dashboard.html');
    await expect(page.locator('#app-sidebar')).toBeVisible();

    // Admin Panel link should not be visible
    await expect(page.getByRole('link', { name: /Admin Panel/i })).not.toBeVisible();
  });

  test('non-admin user redirected from admin page', async ({ page, request }) => {
    const { token } = await registerAndToken(request, 'qa_nonadmin3');

    await page.addInitScript((t) => {
      localStorage.setItem('cashflow_auth_token', t);
      localStorage.setItem('cashflow_auth_user', JSON.stringify({
        email: 'test@example.com',
        name: 'Regular User',
        is_admin: false
      }));
    }, token);

    await page.goto('/admin.html');

    // Should show access denied alert
    await expect(page.locator('#admin-alert')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#admin-alert')).toContainText('Access denied');
  });
});
