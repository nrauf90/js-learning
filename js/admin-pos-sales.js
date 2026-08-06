import { apiGet, getAuthToken } from './api.js';
import { initShell } from './shell.js';

const THEME_KEY = 'tax-calculator-theme';

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
}

function initTheme() {
  const stored = localStorage.getItem(THEME_KEY);
  applyTheme(stored === 'light' ? 'light' : 'dark');
  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    applyTheme(next);
    localStorage.setItem(THEME_KEY, next);
  });
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('admin-pos-sales.html')}`);
  return false;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function formatRs(n) {
  return `Rs ${Math.round(Number(n) || 0).toLocaleString('en-PK')}`;
}

async function loadSales() {
  const status = document.getElementById('status-filter')?.value || '';
  const path = status ? `/api/admin/pos/sales?status=${encodeURIComponent(status)}` : '/api/admin/pos/sales';
  const data = await apiGet(path);
  const tbody = document.getElementById('sales-body');
  const sales = data.data || [];

  if (!sales.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="admin-table-empty">No sales found</td></tr>';
    return;
  }

  tbody.innerHTML = sales.map((s) => `
    <tr>
      <td>#${s.id}</td>
      <td>${escapeHtml(s.user?.name || '—')}</td>
      <td>${formatRs(s.total)}</td>
      <td>${escapeHtml(s.payment_method || 'cash')}</td>
      <td><span class="admin-badge">${escapeHtml(s.status)}</span></td>
      <td>${escapeHtml(s.sync_source || 'online')}</td>
      <td>${s.sold_at ? new Date(s.sold_at).toLocaleString('en-PK') : '—'}</td>
    </tr>
  `).join('');
}

async function loadRefunds() {
  const data = await apiGet('/api/admin/pos/refunds');
  const tbody = document.getElementById('refunds-body');
  const refunds = data.data || [];

  if (!refunds.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="admin-table-empty">No refunds</td></tr>';
    return;
  }

  tbody.innerHTML = refunds.map((r) => `
    <tr>
      <td>#${r.id}</td>
      <td>Sale #${r.pos_sale_id}</td>
      <td>${escapeHtml(r.user?.name || '—')}</td>
      <td>${formatRs(r.total_refunded)}</td>
      <td>${r.refunded_at ? new Date(r.refunded_at).toLocaleString('en-PK') : '—'}</td>
    </tr>
  `).join('');
}

async function init() {
  if (!requireAuth()) return;
  initTheme();
  initShell({ current: 'admin-pos-sales' });
  document.getElementById('status-filter')?.addEventListener('change', loadSales);
  await loadSales();
  await loadRefunds();
}

init();
