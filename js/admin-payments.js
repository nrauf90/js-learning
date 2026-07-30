import { apiGet, getAuthToken } from './api.js';
import { initShell } from './shell.js';

const THEME_KEY = 'tax-calculator-theme';

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
  window.location.replace(`login.html?next=${encodeURIComponent('admin-payments.html')}`);
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

function formatRs(amount) {
  return `Rs ${Math.round(amount || 0).toLocaleString('en-PK')}`;
}

function formatDateTime(dateString) {
  if (!dateString) return '—';
  return new Date(dateString).toLocaleString('en-PK', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function renderPayments(payments) {
  const tbody = document.getElementById('payments-body');
  if (!tbody) return;

  if (!payments || payments.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="admin-table-empty">No payments found</td></tr>';
    return;
  }

  tbody.innerHTML = payments.map(p => `
    <tr>
      <td><strong>${escapeHtml(p.user?.name || 'Unknown')}</strong><br><small>${escapeHtml(p.user?.email || '')}</small></td>
      <td><strong>${formatRs(p.amount)}</strong></td>
      <td><span class="admin-badge admin-badge-info">${p.provider?.toUpperCase() || '—'}</span></td>
      <td><span class="admin-badge admin-badge-${p.status === 'completed' ? 'success' : (p.status === 'pending' ? 'warning' : 'danger')}">${escapeHtml(p.status || '—')}</span></td>
      <td>${formatDateTime(p.created_at)}</td>
    </tr>
  `).join('');
}

async function loadPayments() {
  try {
    const data = await apiGet('/api/admin/payments');
    renderPayments(data.data || []);
  } catch (err) {
    showAlert(err.message || 'Failed to load payments');
  }
}

async function boot() {
  initTheme();
  initShell({ current: 'admin-payments' });
  if (!requireAuth()) return;
  await loadPayments();
}

boot();
