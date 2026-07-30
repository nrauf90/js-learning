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
  window.location.replace(`login.html?next=${encodeURIComponent('admin-entries.html')}`);
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

function renderEntries(entries) {
  const tbody = document.getElementById('entries-body');
  if (!tbody) return;

  if (!entries || entries.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="admin-table-empty">No entries found</td></tr>';
    return;
  }

  tbody.innerHTML = entries.map(e => `
    <tr>
      <td><strong>${escapeHtml(e.user?.name || 'Unknown')}</strong></td>
      <td>${escapeHtml(e.category?.name || '—')}</td>
      <td><strong>${formatRs(e.amount)}</strong></td>
      <td><span class="admin-badge admin-badge-${e.type === 'income' ? 'success' : 'danger'}">${e.type?.toUpperCase() || '—'}</span></td>
      <td>${escapeHtml(e.note || '—')}</td>
      <td>${formatDateTime(e.created_at)}</td>
    </tr>
  `).join('');
}

async function loadEntries() {
  try {
    const data = await apiGet('/api/admin/cash-entries');
    renderEntries(data.data || []);
  } catch (err) {
    showAlert(err.message || 'Failed to load entries');
  }
}

async function boot() {
  initTheme();
  initShell({ current: 'admin-entries' });
  if (!requireAuth()) return;
  await loadEntries();
}

boot();
