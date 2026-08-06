import { apiGet, apiPost, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

let plans = [];
let currency = 'USD';
let selectedPlan = 'monthly';
let subscription = null;

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

function formatMoney(amount) {
  const value = Number(amount) || 0;
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency,
      // Whole-dollar prices read better without the trailing .00, but keep
      // cents when a plan actually has them.
      minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
    }).format(value);
  } catch {
    return `${currency} ${value}`;
  }
}

function formatDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

function renderSubscription(sub, trial) {
  const el = document.getElementById('subscription-info');
  subscription = sub;

  if (sub?.active) {
    const planName = sub.plan === 'yearly' ? 'Yearly' : 'Monthly';
    const ending = sub.cancel_at_period_end
      ? `Cancels on <strong>${formatDate(sub.ends_at)}</strong>`
      : `Renews on <strong>${formatDate(sub.renews_at || sub.ends_at)}</strong>`;
    const dunning =
      sub.status === 'past_due'
        ? ' · <strong>Payment failed</strong> — update your card to avoid losing access.'
        : '';

    el.innerHTML = `<strong>${planName}</strong> plan — Active · ${ending}${dunning}`;
    renderManageControls(sub);
    return;
  }

  renderManageControls(null);

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

function renderManageControls(sub) {
  const wrap = document.getElementById('manage-billing');
  if (!wrap) return;

  // Only Paddle-managed subscriptions have a hosted portal behind them. Rows
  // created by local test mode have no external id and nothing to link to.
  const managed = Boolean(sub?.active && sub?.managed);
  wrap.hidden = !managed;

  const cancelBtn = document.getElementById('cancel-btn');
  if (cancelBtn) cancelBtn.hidden = !managed || Boolean(sub?.cancel_at_period_end);
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
        <span class="billing-plan-price">${formatMoney(plan.amount)}</span>
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

function updateCheckoutButton() {
  const plan = plans.find((p) => p.id === selectedPlan);
  const btn = document.getElementById('checkout-btn');
  const note = document.getElementById('checkout-note');
  if (!plan || !btn) return;
  btn.disabled = false;
  btn.textContent = `Subscribe — ${formatMoney(plan.amount)}/${plan.interval}`;
  note.textContent =
    'Checkout is handled by Paddle, our payment provider and merchant of record. ' +
    'Applicable sales tax or VAT is calculated at checkout.';
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
  const active = addons?.receipt?.active === true;
  const badge = card.querySelector('.billing-addon-badge');

  if (plan) {
    card.querySelector('.billing-plan-price').textContent = `${formatMoney(plan.amount)}/month`;
  }
  if (badge) {
    badge.textContent = active ? 'Active' : 'Coming soon';
    badge.classList.toggle('active', active);
  }
}

async function loadPlans() {
  const data = await apiGet('/api/billing/plans');
  plans = data.plans || [];
  currency = data.currency || 'USD';
  renderPlans();
  updateCheckoutButton();
}

async function completeTestPayment(paymentId) {
  clearAlert();
  try {
    const data = await apiPost(`/api/billing/sandbox/complete/${paymentId}`, {});
    showAlert(data.message || 'Subscription activated.', 'success');
    renderSubscription(data.subscription, data.trial);
    renderAddonCard(data.addons);
  } catch (err) {
    showAlert(err.message || 'Test payment failed');
  }
}

async function onCheckout() {
  clearAlert();
  const btn = document.getElementById('checkout-btn');
  btn.disabled = true;

  try {
    const data = await apiPost('/api/billing/checkout', { plan: selectedPlan });

    if (data.checkout?.sandbox) {
      // Local test mode: no provider involved, completed via the
      // authenticated endpoint rather than a hosted checkout.
      await completeTestPayment(data.payment.id);
      return;
    }

    if (!data.redirect_url) {
      showAlert('Checkout could not be started. Please try again.');
      return;
    }

    window.location.href = data.redirect_url;
  } catch (err) {
    showAlert(err.message || 'Checkout failed');
  } finally {
    btn.disabled = false;
    updateCheckoutButton();
  }
}

async function onManageBilling() {
  clearAlert();
  const btn = document.getElementById('portal-btn');
  btn.disabled = true;

  try {
    const data = await apiPost('/api/billing/portal', {});
    if (!data.url) {
      showAlert('Could not open the billing portal. Please try again.');
      return;
    }
    // Portal links are single-use and short-lived, so this is always a fresh
    // one — never cache or bookmark it.
    window.location.href = data.url;
  } catch (err) {
    showAlert(err.message || 'Could not open the billing portal');
  } finally {
    btn.disabled = false;
  }
}

async function onCancel() {
  clearAlert();
  const endsOn = formatDate(subscription?.ends_at);
  if (!window.confirm(`Cancel your subscription? You keep access until ${endsOn}.`)) return;

  const btn = document.getElementById('cancel-btn');
  btn.disabled = true;

  try {
    const data = await apiPost('/api/billing/cancel', {});
    showAlert(data.message || 'Cancellation scheduled.', 'success');
    await loadSubscription();
  } catch (err) {
    showAlert(err.message || 'Could not cancel the subscription');
  } finally {
    btn.disabled = false;
  }
}

function handleReturnParams() {
  const params = new URLSearchParams(window.location.search);
  const status = params.get('status');
  if (!status) return;

  if (status === 'success') {
    // Provider webhooks are the source of truth and can land after the
    // browser gets redirected back, so promise "shortly" rather than
    // claiming the subscription is already live.
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
  document.getElementById('portal-btn').addEventListener('click', onManageBilling);
  document.getElementById('cancel-btn').addEventListener('click', onCancel);

  try {
    await loadPlans();
    await loadSubscription();
  } catch (err) {
    showAlert(err.message || 'Failed to load billing');
  }
}

boot();
