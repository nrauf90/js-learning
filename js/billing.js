import { apiGet, apiPost, getAuthToken } from './api.js';
import { initShell } from './shell.js';

const THEME_KEY = 'tax-calculator-theme';

let plans = [];
let providers = [];
let selectedPlan = 'monthly';
let selectedProvider = 'jazzcash';

function applyTheme(theme) {
  const root = document.documentElement;
  const toggle = document.getElementById('theme-toggle');
  root.setAttribute('data-theme', theme);
  if (!toggle) return;
  toggle.setAttribute('aria-pressed', String(theme === 'light'));
  toggle.setAttribute(
    'aria-label',
    theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme'
  );
}

function initTheme() {
  const stored = localStorage.getItem(THEME_KEY);
  if (stored === 'light' || stored === 'dark') {
    applyTheme(stored);
  } else {
    applyTheme(window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  }
  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    applyTheme(next);
    localStorage.setItem(THEME_KEY, next);
  });
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('billing.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('billing-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
}

function clearAlert() {
  const el = document.getElementById('billing-alert');
  if (!el) return;
  el.hidden = true;
  el.textContent = '';
}

function formatRs(amount) {
  return `Rs ${Math.round(Number(amount) || 0).toLocaleString('en-PK')}`;
}

function formatDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-PK', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

function renderSubscription(sub, trial) {
  const el = document.getElementById('subscription-info');
  if (sub?.active) {
    el.innerHTML = `
    <strong>${sub.plan === 'yearly' ? 'Yearly' : 'Monthly'}</strong> plan —
    ${sub.active ? 'Active' : sub.status}
    · Renewal date: <strong>${formatDate(sub.ends_at)}</strong>`;
    return;
  }
  if (trial?.active) {
    const days = trial.days_remaining ?? 0;
    el.innerHTML = `
      <strong>Free trial</strong> — ${days} day${days === 1 ? '' : 's'} remaining
      · ends <strong>${formatDate(trial.ends_at)}</strong>`;
    return;
  }
  if (trial?.expired) {
    el.textContent =
      'Your 7-day free trial has ended. Choose a plan below to keep using cash flow and reports.';
    return;
  }
  el.textContent = 'No active subscription. Choose a plan below to unlock cash flow and reports.';
}

function renderPlans() {
  const container = document.getElementById('plan-cards');
  const basePlans = plans.filter((p) => p.id === 'monthly' || p.id === 'yearly');

  container.innerHTML = basePlans
    .map(
      (plan) => `
      <label class="billing-plan-card${selectedPlan === plan.id ? ' selected' : ''}">
        <input type="radio" name="plan" value="${plan.id}" ${selectedPlan === plan.id ? 'checked' : ''} />
        <span class="billing-plan-name">${plan.name}</span>
        <span class="billing-plan-price">${formatRs(plan.amount)}</span>
        <span class="billing-plan-desc">${plan.description}</span>
      </label>`
    )
    .join('');

  container.querySelectorAll('input[name="plan"]').forEach((input) => {
    input.addEventListener('change', () => {
      selectedPlan = input.value;
      renderPlans();
      updateCheckoutButton();
    });
  });
}

function renderProviders() {
  const container = document.getElementById('provider-options');
  container.innerHTML = providers
    .map(
      (p) => `
      <label class="billing-provider${selectedProvider === p.id ? ' selected' : ''}">
        <input type="radio" name="provider" value="${p.id}" ${selectedProvider === p.id ? 'checked' : ''} />
        ${p.label}
      </label>`
    )
    .join('');

  container.querySelectorAll('input[name="provider"]').forEach((input) => {
    input.addEventListener('change', () => {
      selectedProvider = input.value;
      renderProviders();
    });
  });
}

function updateCheckoutButton() {
  const plan = plans.find((p) => p.id === selectedPlan);
  const btn = document.getElementById('checkout-btn');
  const note = document.getElementById('checkout-note');
  if (!plan || !btn) return;
  btn.disabled = false;
  btn.textContent = `Pay ${formatRs(plan.amount)} with ${selectedProvider === 'jazzcash' ? 'JazzCash' : 'EasyPaisa'}`;
  note.textContent = 'You will be redirected to complete payment. Sandbox mode completes locally when gateway keys are not configured.';
}

async function loadSubscription() {
  const data = await apiGet('/api/billing/subscription');
  renderSubscription(data.subscription, data.trial);
  renderAddonCard(data.addons);
}

function renderAddonCard(addons) {
  const card = document.getElementById('receipt-addon-card');
  if (!card) return;

  const plan = plans.find((p) => p.id === 'receipt_addon');
  const price = plan ? formatRs(plan.amount) : 'Rs 500';
  const active = addons?.receipt?.active === true;
  const badge = card.querySelector('.billing-addon-badge');

  card.querySelector('.billing-plan-price').textContent = `${price}/month`;
  if (badge) {
    badge.textContent = active ? 'Active' : 'Coming soon';
    badge.classList.toggle('active', active);
  }
}

async function loadPlans() {
  const data = await apiGet('/api/billing/plans');
  plans = data.plans || [];
  providers = data.providers || [];
  renderPlans();
  renderProviders();
  updateCheckoutButton();
}

function submitGatewayForm(checkout, paymentId) {
  const form = document.getElementById('gateway-form');
  form.innerHTML = '';
  form.method = checkout.method || 'POST';
  form.action = checkout.action_url;
  form.hidden = false;

  for (const [key, value] of Object.entries(checkout.fields || {})) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = key;
    input.value = value;
    form.appendChild(input);
  }

  if (checkout.sandbox) {
    completeSandbox(paymentId);
    return;
  }

  form.submit();
}

async function completeSandbox(paymentId) {
  clearAlert();
  try {
    const data = await apiPost(`/api/billing/sandbox/complete/${paymentId}`, {});
    showAlert(data.message || 'Subscription activated.', 'success');
    renderSubscription(data.subscription, data.trial);
    renderAddonCard(data.addons);
    await loadSubscription();
  } catch (err) {
    showAlert(err.message || 'Sandbox payment failed');
  }
}

async function onCheckout() {
  clearAlert();
  const btn = document.getElementById('checkout-btn');
  btn.disabled = true;

  try {
    const data = await apiPost('/api/billing/checkout', {
      plan: selectedPlan,
      provider: selectedProvider,
    });

    submitGatewayForm(data.checkout, data.payment.id);
  } catch (err) {
    showAlert(err.message || 'Checkout failed');
  } finally {
    btn.disabled = false;
    updateCheckoutButton();
  }
}

function handleReturnParams() {
  const params = new URLSearchParams(window.location.search);
  const status = params.get('status');
  if (!status) return;

  if (status === 'success') {
    showAlert('Payment received. Your subscription will appear shortly.', 'success');
  } else if (status === 'failed') {
    showAlert('Payment was not completed. Please try again.');
  }

  window.history.replaceState({}, '', window.location.pathname);
}

async function boot() {
  initTheme();
  initShell({ current: 'billing' });
  if (!requireAuth()) return;

  handleReturnParams();

  document.getElementById('checkout-btn').addEventListener('click', onCheckout);

  try {
    await loadPlans();
    await loadSubscription();
  } catch (err) {
    showAlert(err.message || 'Failed to load billing');
  }
}

boot();
