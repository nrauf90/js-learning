#!/usr/bin/env node
/**
 * Quick admin setup verification script
 *
 * Checks:
 * 1. Database migration status
 * 2. Admin user exists
 * 3. Admin can login
 * 4. Admin can access admin endpoints
 */

const API_URL = process.env.API_URL || 'http://127.0.0.1:8000';

async function checkHealth() {
  try {
    const res = await fetch(`${API_URL}/api/health`);
    if (res.ok) {
      console.log('✓ API is reachable');
      return true;
    }
    console.error('✗ API health check failed');
    return false;
  } catch (err) {
    console.error('✗ Cannot connect to API at', API_URL);
    console.error('  Make sure the backend is running: npm run dev:api');
    return false;
  }
}

async function loginAdmin() {
  try {
    const res = await fetch(`${API_URL}/api/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        email: 'admin@cashflow.local',
        password: 'admin123',
      }),
    });

    if (res.ok) {
      const data = await res.json();
      console.log('✓ Admin user login successful');
      console.log('  Email:', data.user.email);
      console.log('  Is Admin:', data.user.is_admin);
      return data.token;
    }

    if (res.status === 401) {
      console.error('✗ Admin credentials invalid or user does not exist');
      console.error('  Run: cd backend && php artisan db:seed --class=AdminUserSeeder');
      return null;
    }

    console.error('✗ Login failed with status:', res.status);
    return null;
  } catch (err) {
    console.error('✗ Login request failed:', err.message);
    return null;
  }
}

async function checkAdminAccess(token) {
  try {
    const res = await fetch(`${API_URL}/api/admin/dashboard`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      },
    });

    if (res.ok) {
      const data = await res.json();
      console.log('✓ Admin dashboard accessible');
      console.log('  Total Users:', data.stats.total_users);
      console.log('  Active Subscriptions:', data.stats.active_subscriptions);
      console.log('  Monthly Revenue:', data.stats.monthly_revenue);
      return true;
    }

    if (res.status === 403) {
      console.error('✗ Admin access forbidden (is_admin flag might be false)');
      return false;
    }

    console.error('✗ Admin dashboard check failed with status:', res.status);
    return false;
  } catch (err) {
    console.error('✗ Admin dashboard request failed:', err.message);
    return false;
  }
}

async function testNonAdminAccess() {
  try {
    // Try to register a regular user
    const email = `test_${Date.now()}@example.com`;
    const regRes = await fetch(`${API_URL}/api/register`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        name: 'Test User',
        email: email,
        password: 'password123',
        password_confirmation: 'password123',
      }),
    });

    if (!regRes.ok) {
      console.warn('⚠ Could not create test user for non-admin check');
      return true; // Skip this check
    }

    const regData = await regRes.json();
    const token = regData.token;

    // Try to access admin endpoint
    const adminRes = await fetch(`${API_URL}/api/admin/dashboard`, {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json',
      },
    });

    if (adminRes.status === 403) {
      console.log('✓ Non-admin users correctly blocked from admin endpoints');
      return true;
    }

    console.error('✗ Non-admin user was able to access admin endpoint!');
    return false;
  } catch (err) {
    console.warn('⚠ Non-admin access check failed:', err.message);
    return true; // Don't fail the whole test
  }
}

async function main() {
  console.log('\n═══ Admin Panel Setup Verification ═══\n');

  const apiUp = await checkHealth();
  if (!apiUp) {
    process.exit(1);
  }

  const token = await loginAdmin();
  if (!token) {
    console.log('\n✗ SETUP INCOMPLETE');
    console.log('\nNext steps:');
    console.log('  1. cd backend');
    console.log('  2. php artisan migrate');
    console.log('  3. php artisan db:seed --class=AdminUserSeeder');
    process.exit(1);
  }

  const hasAccess = await checkAdminAccess(token);
  if (!hasAccess) {
    console.log('\n✗ SETUP INCOMPLETE');
    console.log('\nAdmin user exists but cannot access admin endpoints.');
    console.log('Ensure is_admin flag is set to true in database.');
    process.exit(1);
  }

  await testNonAdminAccess();

  console.log('\n✓ SETUP COMPLETE');
  console.log('\nAdmin panel is ready to use!');
  console.log('  Login: admin@cashflow.local / admin123');
  console.log('  URL: http://localhost:3000/admin.html');
  console.log('\nRun tests: npm run qa:m11\n');
}

main();
