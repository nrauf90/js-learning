import { apiGet, getAuthToken } from './api.js';
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

async function boot() {
  initTheme();
  initShell({ current: 'admin-subscriptions' });
  if (!requireAuth()) return;
  await loadSubscriptions();
}

boot();
