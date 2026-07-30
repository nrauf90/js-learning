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
  window.location.replace(`login.html?next=${encodeURIComponent('admin.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('admin-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
}

function formatRs(amount) {
  const n = Number(amount) || 0;
  return `Rs ${Math.round(n).toLocaleString('en-PK')}`;
}

function formatDate(dateString) {
  if (!dateString) return '—';
  const d = new Date(dateString);
  return d.toLocaleDateString('en-PK', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dateString) {
  if (!dateString) return '—';
  const d = new Date(dateString);
  return d.toLocaleString('en-PK', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function renderStats(stats) {
  document.getElementById('stat-users').textContent = stats.total_users?.toLocaleString('en-PK') || '0';
  document.getElementById('stat-subscriptions').textContent = stats.active_subscriptions?.toLocaleString('en-PK') || '0';
  document.getElementById('stat-revenue').textContent = formatRs(stats.monthly_revenue || 0);
  document.getElementById('stat-entries').textContent = stats.total_entries?.toLocaleString('en-PK') || '0';
}

function renderRecentUsers(users) {
  const tbody = document.getElementById('recent-users-body');
  if (!tbody) return;

  if (!users || users.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" class="admin-table-empty">No recent users</td></tr>';
    return;
  }

  tbody.innerHTML = users.map(user => `
    <tr>
      <td><strong>${escapeHtml(user.name || 'No name')}</strong></td>
      <td>${escapeHtml(user.email || '—')}</td>
      <td>${formatDate(user.created_at)}</td>
      <td>
        ${user.is_admin ? '<span class="admin-badge admin-badge-success">Admin</span>' : '<span class="admin-badge admin-badge-muted">User</span>'}
      </td>
    </tr>
  `).join('');
}

function renderRecentPayments(payments) {
  const tbody = document.getElementById('recent-payments-body');
  if (!tbody) return;

  if (!payments || payments.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" class="admin-table-empty">No recent payments</td></tr>';
    return;
  }

  tbody.innerHTML = payments.map(payment => {
    const statusClass = payment.status === 'completed' ? 'success' :
                        payment.status === 'pending' ? 'warning' : 'danger';
    return `
      <tr>
        <td><strong>${escapeHtml(payment.user?.name || 'Unknown')}</strong><br><small>${escapeHtml(payment.user?.email || '')}</small></td>
        <td><strong>${formatRs(payment.amount)}</strong></td>
        <td>${payment.provider ? payment.provider.toUpperCase() : '—'}</td>
        <td><span class="admin-badge admin-badge-${statusClass}">${payment.status || '—'}</span></td>
        <td>${formatDateTime(payment.created_at)}</td>
      </tr>
    `;
  }).join('');
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

async function loadDashboard() {
  try {
    const data = await apiGet('/api/admin/dashboard');

    renderStats(data.stats || {});
    renderRecentUsers(data.recent_users || []);
    renderRecentPayments(data.recent_payments || []);
  } catch (err) {
    if (err.status === 403) {
      showAlert('Access denied. Admin privileges required.');
      setTimeout(() => {
        window.location.href = 'dashboard.html';
      }, 2000);
    } else {
      showAlert(err.message || 'Failed to load admin dashboard');
    }
  }
}

async function boot() {
  initTheme();
  initShell({ current: 'admin' });
  if (!requireAuth()) return;

  await loadDashboard();
}

boot();
