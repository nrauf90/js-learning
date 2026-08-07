import { apiGet, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

let currentPage = 1;

/**
 * One screen of the system-wide ledger per request. The endpoint has always
 * paginated; this page used to ask for page one and render it as though it were
 * the whole table, so an admin could not reach entry 21 at all.
 */
const PER_PAGE = 25;

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

/**
 * `meta` is the Laravel paginator's own top-level body here — the admin
 * endpoints return the paginator itself rather than wrapping it — but the
 * fields, and the pager built from them, are the ones every other list on the
 * site uses.
 */
function renderPagination(meta) {
  const container = document.getElementById('pagination');
  if (!container) return;

  const page = Number(meta?.current_page) || 1;
  const lastPage = Number(meta?.last_page) || 1;
  const perPage = Number(meta?.per_page) || PER_PAGE;
  const total = Number(meta?.total) || 0;
  const first = total === 0 ? 0 : (page - 1) * perPage + 1;
  const range = document.getElementById('entries-range');

  if (range) {
    range.textContent =
      total === 0
        ? 'No entries'
        : `${first}–${Math.min(page * perPage, total)} of ${total} entr${total === 1 ? 'y' : 'ies'}`;
  }

  if (lastPage <= 1) {
    container.innerHTML = '';
    return;
  }

  const button = (target, label) =>
    target
      ? `<button type="button" class="admin-btn admin-btn-sm" data-page="${target}">${label}</button>`
      : `<button type="button" class="admin-btn admin-btn-sm" disabled>${label}</button>`;

  container.innerHTML =
    button(page > 1 ? page - 1 : 0, 'Previous') +
    `<span class="admin-pagination-info">Page ${page} of ${lastPage} (${total} entries)</span>` +
    button(page < lastPage ? page + 1 : 0, 'Next');
}

async function loadEntries() {
  try {
    const params = new URLSearchParams({ page: String(currentPage), per_page: String(PER_PAGE) });
    const data = await apiGet(`/api/admin/cash-entries?${params.toString()}`);

    renderEntries(data.data || []);
    renderPagination(data);
  } catch (err) {
    showAlert(err.message || 'Failed to load entries');
  }
}

async function boot() {
  initTheme();
  initShell({ current: 'admin-entries' });
  if (!requireAuth()) return;

  // Delegated: the pager is re-rendered on every load, and per-button
  // listeners would leak one set each time.
  document.getElementById('pagination')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) return;
    currentPage = Number(button.dataset.page) || 1;
    loadEntries();
  });

  await loadEntries();
}

boot();
