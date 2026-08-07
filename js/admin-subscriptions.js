import { apiGet, apiPost, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('admin-subscriptions.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('admin-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
  setTimeout(() => { el.hidden = true; }, 5000);
}

function formatDate(dateString) {
  if (!dateString) return '—';
  return new Date(dateString).toLocaleDateString('en-PK', { year: 'numeric', month: 'short', day: 'numeric' });
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function renderSubscriptions(subs) {
  const tbody = document.getElementById('subs-body');
  if (!tbody) return;

  if (!subs || subs.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="admin-table-empty">No subscriptions found</td></tr>';
    return;
  }

  tbody.innerHTML = subs.map(sub => `
    <tr>
      <td><strong>${escapeHtml(sub.user?.name || 'Unknown')}</strong><br><small>${escapeHtml(sub.user?.email || '')}</small></td>
      <td><span class="admin-badge admin-badge-info">${sub.plan?.toUpperCase() || '—'}</span></td>
      <td><span class="admin-badge admin-badge-${sub.status === 'active' ? 'success' : 'muted'}">${escapeHtml(sub.status || '—')}</span></td>
      <td>${formatDate(sub.starts_at)}</td>
      <td>${formatDate(sub.ends_at)}</td>
      <td>${formatDate(sub.created_at)}</td>
    </tr>
  `).join('');
}

async function loadSubscriptions() {
  try {
    const data = await apiGet('/api/admin/subscriptions');
    renderSubscriptions(data.data || []);
  } catch (err) {
    showAlert(err.message || 'Failed to load subscriptions');
  }
}

/**
 * The only route a shop has to a working subscription now that self-serve
 * checkout is closed. Extending is the same call as granting — the backend
 * adds a term to whatever the shop still has left rather than restarting it.
 */
async function onGrant(event) {
  event.preventDefault();

  const email = document.getElementById('grant-email').value.trim();
  const plan = document.getElementById('grant-plan').value;
  const endsAt = document.getElementById('grant-ends-at').value;
  if (!email) {
    showAlert('Enter the shop owner’s email.');
    return;
  }

  const btn = document.getElementById('grant-submit');
  btn.disabled = true;

  try {
    const payload = { email, plan };
    if (endsAt) payload.ends_at = endsAt;

    const data = await apiPost('/api/admin/subscriptions', payload);
    showAlert(`Subscription active until ${formatDate(data.subscription?.ends_at)}.`, 'success');
    document.getElementById('grant-form').reset();
    await loadSubscriptions();
  } catch (err) {
    const errors = err.body?.errors;
    showAlert(
      errors ? Object.values(errors).flat().join(' ') : err.message || 'Could not grant subscription'
    );
  } finally {
    btn.disabled = false;
  }
}

async function boot() {
  initTheme();
  initShell({ current: 'admin-subscriptions' });
  if (!requireAuth()) return;

  document.getElementById('grant-form')?.addEventListener('submit', onGrant);
  await loadSubscriptions();
}

boot();
