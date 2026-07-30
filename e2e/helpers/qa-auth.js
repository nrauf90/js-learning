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

export async function activateSandboxSubscription(request, token) {
  const headers = {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  };

  const checkout = await request.post(`${API}/api/billing/checkout`, {
    headers,
    data: { plan: 'monthly', provider: 'jazzcash' },
  });
  expect(checkout.status()).toBe(201);
  const paymentId = (await checkout.json()).payment.id;

  const complete = await request.post(`${API}/api/billing/sandbox/complete/${paymentId}`, {
    headers,
  });
  expect(complete.ok()).toBeTruthy();
}

export async function registerSubscribedUser(request, prefix = 'qa') {
  const { email, token } = await registerAndToken(request, prefix);
  await activateSandboxSubscription(request, token);
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
