import { expect } from '@playwright/test';

export const API = process.env.QA_API_URL || 'http://127.0.0.1:8000';

export function uniqueEmail(prefix = 'qa') {
  return `${prefix}_${Date.now()}_${Math.floor(Math.random() * 1e6)}@example.com`;
}

export async function registerAndToken(request, prefix = 'qa') {
  const email = uniqueEmail(prefix);
  const res = await request.post(`${API}/api/register`, {
    data: {
      name: 'QA User',
      email,
      password: 'password123',
      password_confirmation: 'password123',
    },
  });
  expect(res.status()).toBe(201);
  return { email, token: (await res.json()).token };
}

let adminTokenPromise = null;

/**
 * One operator login per worker. Every subscribed fixture in the suite needs an
 * admin now, and /api/login is rate-limited — logging in per fixture would trip
 * the throttle long before the suite finished.
 */
function adminToken(request) {
  if (!adminTokenPromise) {
    adminTokenPromise = loginAdmin(request).then((session) => session.token);
  }
  return adminTokenPromise;
}

/**
 * Give a shop its subscription the way the product now does it: the platform
 * admin grants it. Shops cannot reach checkout, the portal or the sandbox
 * completer any more, so a fixture that bought its own access would be testing
 * a flow that no longer exists.
 */
export async function grantSubscription(request, email, plan = 'monthly') {
  const res = await request.post(`${API}/api/admin/subscriptions`, {
    headers: {
      Authorization: `Bearer ${await adminToken(request)}`,
      Accept: 'application/json',
    },
    data: { email, plan },
  });
  expect(res.status()).toBe(201);
}

export async function registerSubscribedUser(request, prefix = 'qa') {
  const { email, token } = await registerAndToken(request, prefix);
  await grantSubscription(request, email);
  return { email, token };
}

export async function expireTrial(request, token) {
  const headers = {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  };
  const res = await request.post(`${API}/api/qa/expire-trial`, { headers });
  expect(res.ok()).toBeTruthy();
}

export async function loginAdmin(request) {
  const res = await request.post(`${API}/api/login`, {
    data: {
      email: 'admin@cashflow.local',
      password: 'admin123',
    },
  });
  expect(res.status()).toBe(200);
  const data = await res.json();
  return { email: data.user.email, token: data.token, user: data.user };
}
