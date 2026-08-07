/**
 * Platform-admin screen: onboard a shop owner (login + shop in one call) and
 * list the shops already on the platform.
 */
import { apiGet, apiPost, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

let currentPage = 1;
let searchQuery = '';

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('admin-shops.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('admin-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function clearAlert() {
  const el = document.getElementById('admin-alert');
  if (!el) return;
  el.hidden = true;
  el.textContent = '';
}

function firstValidationError(err, fallback) {
  const errors = err?.body?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first[0]) return first[0];
  }
  return err?.message || fallback;
}

function formatDate(dateString) {
  if (!dateString) return '—';
  return new Date(dateString).toLocaleDateString('en-PK', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

/** Owner and shop names are free text typed by an operator — never inlined raw. */
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  // Quotes survive textContent → innerHTML, so they are handled here too —
  // these strings sit inside HTML attributes as well as text nodes.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function fieldValue(id) {
  return document.getElementById(id)?.value.trim() || '';
}

function renderShopAdmins(rows) {
  const tbody = document.getElementById('shop-admins-body');
  if (!tbody) return;

  if (!rows || rows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="admin-table-empty">No shop admins yet</td></tr>';
    return;
  }

  tbody.innerHTML = rows
    .map(
      (row) => `
    <tr>
      <td><strong>${escapeHtml(row.name)}</strong></td>
      <td>${escapeHtml(row.email)}</td>
      <td>
        ${
          row.shop
            ? `${escapeHtml(row.shop.name)}${
                row.shop.address
                  ? `<br /><small>${escapeHtml(row.shop.address)}</small>`
                  : ''
              }`
            : '<span class="admin-badge admin-badge-warning">No shop yet</span>'
        }
      </td>
      <td>${row.shop && row.shop.phone ? escapeHtml(row.shop.phone) : '—'}</td>
      <td>${Number(row.staff_count || 0).toLocaleString('en-PK')}</td>
      <td>${formatDate(row.created_at)}</td>
    </tr>`
    )
    .join('');
}

function renderPagination(data) {
  const container = document.getElementById('pagination');
  if (!container) return;

  if (!data.last_page || data.last_page <= 1) {
    container.innerHTML = '';
    return;
  }

  const prev =
    data.current_page > 1
      ? `<button class="admin-btn admin-btn-sm" onclick="window.changePage(${data.current_page - 1})">Previous</button>`
      : '<button class="admin-btn admin-btn-sm" disabled>Previous</button>';

  const next =
    data.current_page < data.last_page
      ? `<button class="admin-btn admin-btn-sm" onclick="window.changePage(${data.current_page + 1})">Next</button>`
      : '<button class="admin-btn admin-btn-sm" disabled>Next</button>';

  const info = `<span class="admin-pagination-info">Page ${data.current_page} of ${data.last_page} (${data.total} total)</span>`;

  container.innerHTML = `${prev}${info}${next}`;
}

async function loadShopAdmins() {
  try {
    const params = new URLSearchParams({ page: currentPage });
    if (searchQuery) params.set('search', searchQuery);

    const data = await apiGet(`/api/admin/shop-admins?${params.toString()}`);
    renderShopAdmins(data.data || []);
    renderPagination(data);
  } catch (err) {
    if (err.status === 403) {
      showAlert('Access denied. Admin privileges required.');
      setTimeout(() => {
        window.location.href = 'dashboard.html';
      }, 2000);
      return;
    }
    showAlert(err.message || 'Failed to load shop admins');
  }
}

async function createShopAdmin(event) {
  event.preventDefault();
  clearAlert();

  const password = document.getElementById('owner-password').value;
  const confirmation = document.getElementById('owner-password-confirm').value;

  if (password !== confirmation) {
    showAlert('The two passwords do not match.');
    return;
  }

  const button = document.getElementById('shop-admin-save');
  button.disabled = true;

  try {
    const data = await apiPost('/api/admin/shop-admins', {
      name: fieldValue('owner-name'),
      email: fieldValue('owner-email'),
      password,
      password_confirmation: confirmation,
      shop_name: fieldValue('new-shop-name'),
      shop_phone: fieldValue('new-shop-phone') || null,
      shop_address: fieldValue('new-shop-address') || null,
    });

    document.getElementById('shop-admin-form').reset();
    showAlert(`${data.shop.name} created. The owner can log in with that email.`, 'success');
    currentPage = 1;
    await loadShopAdmins();
  } catch (err) {
    showAlert(firstValidationError(err, 'Could not create that shop admin.'));
  } finally {
    button.disabled = false;
  }
}

function initFilters() {
  let searchTimeout;
  document.getElementById('search-input')?.addEventListener('input', (e) => {
    clearTimeout(searchTimeout);
    const value = e.target.value.trim();
    searchTimeout = setTimeout(() => {
      searchQuery = value;
      currentPage = 1;
      loadShopAdmins();
    }, 500);
  });
}

function changePage(page) {
  currentPage = page;
  loadShopAdmins();
}

// Pagination buttons are rendered with numeric ids only, so the inline
// onclick handlers there are safe and still rely on this global.
window.changePage = changePage;

async function boot() {
  initTheme();
  initShell({ current: 'admin-shops' });
  if (!requireAuth()) return;

  initFilters();
  document.getElementById('shop-admin-form')?.addEventListener('submit', createShopAdmin);
  await loadShopAdmins();
}

boot();
