import { apiGet, getAuthToken } from './api.js';
import { initShell } from './shell.js';

const THEME_KEY = 'tax-calculator-theme';

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  const toggle = document.getElementById('theme-toggle');
  if (toggle) toggle.setAttribute('aria-pressed', String(theme === 'light'));
}

function initTheme() {
  const stored = localStorage.getItem(THEME_KEY);
  const theme = stored === 'light' || stored === 'dark'
    ? stored
    : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  applyTheme(theme);
  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    applyTheme(next);
    localStorage.setItem(THEME_KEY, next);
  });
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('admin-pos.html')}`);
  return false;
}

function formatRs(n) {
  return `Rs ${Math.round(Number(n) || 0).toLocaleString('en-PK')}`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

async function loadDashboard() {
  try {
    const data = await apiGet('/api/admin/pos/dashboard');
    const stats = data.stats || {};
    document.getElementById('pos-stat-sales').textContent = formatRs(stats.today_sales);
    document.getElementById('pos-stat-refunds').textContent = formatRs(stats.today_refunds);
    document.getElementById('pos-stat-net').textContent = formatRs(stats.today_net);
    document.getElementById('pos-stat-products').textContent = String(stats.active_products ?? 0);
    document.getElementById('pos-stat-total').textContent = String(stats.total_sales ?? 0);

    const tbody = document.getElementById('pos-recent-body');
    const sales = data.recent_sales || [];
    if (!sales.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="admin-table-empty">No sales yet</td></tr>';
      return;
    }
    tbody.innerHTML = sales.map((s) => `
      <tr>
        <td>#${s.id}</td>
        <td>${formatRs(s.total)}</td>
        <td>${escapeHtml(s.payment_method || 'cash')}</td>
        <td><span class="admin-badge">${escapeHtml(s.status)}</span></td>
        <td>${s.sold_at ? new Date(s.sold_at).toLocaleString('en-PK') : '—'}</td>
      </tr>
    `).join('');
  } catch (err) {
    document.getElementById('admin-alert').hidden = false;
    document.getElementById('admin-alert').textContent = err.message || 'Failed to load POS dashboard';
  }
}

async function init() {
  if (!requireAuth()) return;
  initTheme();
  initShell({ current: 'admin-pos' });
  await loadDashboard();
}

init();
