/**
 * Activity report: the catalogue's audit trail.
 *
 * Every value on this page came from somebody's keyboard — a product name, a
 * note on a stock correction — and is being played back into the DOM, so it all
 * goes through escapeHtml() on the way out, nested before/after values included.
 */
import { apiGet, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

let currentPage = 1;
const filters = { user_id: '', action: '', subject_type: '', from: '', to: '' };

const ACTION_LABELS = {
  created: 'Created',
  updated: 'Updated',
  deleted: 'Deleted',
  stock_adjusted: 'Stock adjusted',
};

const ACTION_BADGES = {
  created: 'admin-badge-success',
  updated: 'admin-badge-info',
  deleted: 'admin-badge-danger',
  stock_adjusted: 'admin-badge-warning',
};

const SUBJECT_LABELS = {
  Product: 'Product',
  ProductCategory: 'Category',
};

/** Field names the report spells out rather than showing the column name. */
const FIELD_LABELS = {
  image_path: 'Picture',
  is_active: 'On the till',
  low_stock_threshold: 'Low stock alert',
  product_category_id: 'Category',
  stock_quantity: 'Stock',
  track_stock: 'Tracks stock',
  user_name: 'Name',
};

function el(id) {
  return document.getElementById(id);
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('activity.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const box = el('activity-alert');
  if (!box) return;
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearAlert() {
  const box = el('activity-alert');
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  // The text-node serialiser escapes &, < and > but not quotes, and these
  // values are interpolated into attributes as well as into text.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatWhen(iso) {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleString('en-PK', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function fieldLabel(field) {
  if (FIELD_LABELS[field]) return FIELD_LABELS[field];
  return field.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

/**
 * One change value as text. Booleans and empty cells read better as words than
 * as `true` or an empty string, and anything unexpected is stringified rather
 * than allowed to render as "[object Object]".
 */
function formatValue(value) {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

function isDiff(value) {
  return (
    value !== null &&
    typeof value === 'object' &&
    !Array.isArray(value) &&
    ('from' in value || 'to' in value)
  );
}

/**
 * `changes` is `{field: {from, to}}` for an edit and `{field: value}` for a
 * create/delete snapshot or the context of a stock adjustment, so both shapes
 * are rendered here.
 */
function renderChanges(changes) {
  const entries = Object.entries(changes || {});
  if (entries.length === 0) return '<span class="activity-muted">—</span>';

  const rows = entries
    .map(([field, value]) => {
      const label = `<span class="activity-field">${escapeHtml(fieldLabel(field))}</span>`;

      if (isDiff(value)) {
        return `<li>
            ${label}
            <span class="activity-was">${escapeHtml(formatValue(value.from))}</span>
            <span class="activity-arrow" aria-hidden="true">→</span>
            <span class="activity-now">${escapeHtml(formatValue(value.to))}</span>
          </li>`;
      }

      return `<li>${label}<span class="activity-now">${escapeHtml(formatValue(value))}</span></li>`;
    })
    .join('');

  return `<ul class="activity-changes">${rows}</ul>`;
}

function renderRows(rows) {
  const body = el('activity-body');
  if (!body) return;

  if (!rows || rows.length === 0) {
    body.innerHTML = '<tr><td colspan="5" class="admin-table-empty">No activity recorded yet.</td></tr>';
    return;
  }

  body.innerHTML = rows
    .map((row) => {
      const badge = ACTION_BADGES[row.action] || 'admin-badge-muted';
      const action = ACTION_LABELS[row.action] || row.action;
      const subject = SUBJECT_LABELS[row.subject_type] || row.subject_type;
      // subject_label was captured when the change was made, so a deleted
      // product still reads as its name instead of a bare id.
      const label = row.subject_label || `#${row.subject_id ?? '?'}`;

      return `
      <tr>
        <td class="activity-when">${escapeHtml(formatWhen(row.created_at))}</td>
        <td>
          <strong>${escapeHtml(row.user_name || 'Unknown')}</strong>
          ${row.ip_address ? `<small class="activity-muted">${escapeHtml(row.ip_address)}</small>` : ''}
        </td>
        <td><span class="admin-badge ${badge}">${escapeHtml(action)}</span></td>
        <td>
          <strong>${escapeHtml(label)}</strong>
          <small class="activity-muted">${escapeHtml(subject)}</small>
        </td>
        <td>${renderChanges(row.changes)}</td>
      </tr>`;
    })
    .join('');
}

function renderActors(actors) {
  const select = el('filter-user');
  if (!select) return;

  const selected = select.value;
  select.innerHTML =
    '<option value="">Everyone</option>' +
    (actors || [])
      .map((a) => `<option value="${escapeHtml(String(a.id))}">${escapeHtml(a.name || 'Unknown')}</option>`)
      .join('');
  select.value = selected;
}

function renderPagination(meta) {
  const container = el('activity-pagination');
  if (!container) return;

  if (!meta || !meta.last_page || meta.last_page <= 1) {
    container.innerHTML = '';
    return;
  }

  const prev =
    meta.current_page > 1
      ? `<button type="button" class="admin-btn admin-btn-sm" data-page="${meta.current_page - 1}">Previous</button>`
      : '<button type="button" class="admin-btn admin-btn-sm" disabled>Previous</button>';

  const next =
    meta.current_page < meta.last_page
      ? `<button type="button" class="admin-btn admin-btn-sm" data-page="${meta.current_page + 1}">Next</button>`
      : '<button type="button" class="admin-btn admin-btn-sm" disabled>Next</button>';

  container.innerHTML = `${prev}<span class="admin-pagination-info">Page ${meta.current_page} of ${meta.last_page} (${meta.total} changes)</span>${next}`;
}

async function loadActivity() {
  const params = new URLSearchParams({ page: String(currentPage) });
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params.set(key, value);
  });

  try {
    const data = await apiGet(`/api/activity?${params.toString()}`);
    clearAlert();
    renderRows(data.activity || []);
    renderActors(data.actors || []);
    renderPagination(data.meta);
  } catch (err) {
    showAlert(err.message || 'Could not load the activity report');
  }
}

function initFilters() {
  const bind = (id, key) => {
    el(id)?.addEventListener('change', (event) => {
      filters[key] = event.target.value;
      currentPage = 1;
      loadActivity();
    });
  };

  bind('filter-user', 'user_id');
  bind('filter-action', 'action');
  bind('filter-subject', 'subject_type');
  bind('filter-from', 'from');
  bind('filter-to', 'to');

  el('filter-reset')?.addEventListener('click', () => {
    Object.keys(filters).forEach((key) => {
      filters[key] = '';
    });
    ['filter-user', 'filter-action', 'filter-subject', 'filter-from', 'filter-to'].forEach((id) => {
      const input = el(id);
      if (input) input.value = '';
    });
    currentPage = 1;
    loadActivity();
  });

  // Delegated so the buttons can be re-rendered on every page change without
  // leaking a listener each time.
  el('activity-pagination')?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) return;
    currentPage = Number(button.dataset.page) || 1;
    loadActivity();
  });
}

async function boot() {
  initTheme();
  initShell({ current: 'activity' });
  if (!requireAuth()) return;

  initFilters();
  await loadActivity();
}

boot();
