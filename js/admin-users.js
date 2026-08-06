import { apiGet, apiPut, apiDelete, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

let currentPage = 1;
let searchQuery = '';
let adminOnlyFilter = false;
let selectedUserId = null;

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('admin-users.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('admin-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
  setTimeout(() => {
    el.hidden = true;
  }, 5000);
}

function formatDate(dateString) {
  if (!dateString) return '—';
  const d = new Date(dateString);
  return d.toLocaleDateString('en-PK', { year: 'numeric', month: 'short', day: 'numeric' });
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function renderUsers(users) {
  const tbody = document.getElementById('users-body');
  if (!tbody) return;

  if (!users || users.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="admin-table-empty">No users found</td></tr>';
    return;
  }

  tbody.innerHTML = users.map(user => `
    <tr>
      <td><strong>${escapeHtml(user.name || 'No name')}</strong></td>
      <td>${escapeHtml(user.email || '—')}</td>
      <td>${formatDate(user.created_at)}</td>
      <td>${(user.cash_entries_count || 0).toLocaleString('en-PK')}</td>
      <td>${(user.subscriptions_count || 0).toLocaleString('en-PK')}</td>
      <td>
        ${user.is_admin ? '<span class="admin-badge admin-badge-success">Admin</span>' : '<span class="admin-badge admin-badge-muted">User</span>'}
      </td>
      <td>
        <div class="admin-actions">
          <button class="admin-btn admin-btn-sm admin-btn-secondary" data-action="edit" data-id="${user.id}" title="Edit user">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
          </button>
          <button class="admin-btn admin-btn-sm admin-btn-danger" data-action="delete" data-id="${user.id}" data-name="${escapeHtml(user.name || 'User')}" title="Delete user">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          </button>
        </div>
      </td>
    </tr>
  `).join('');
}

function renderPagination(data) {
  const container = document.getElementById('pagination');
  if (!container) return;

  if (!data.last_page || data.last_page <= 1) {
    container.innerHTML = '';
    return;
  }

  const prev = data.current_page > 1 ?
    `<button class="admin-btn admin-btn-sm" onclick="window.changePage(${data.current_page - 1})">Previous</button>` :
    `<button class="admin-btn admin-btn-sm" disabled>Previous</button>`;

  const next = data.current_page < data.last_page ?
    `<button class="admin-btn admin-btn-sm" onclick="window.changePage(${data.current_page + 1})">Next</button>` :
    `<button class="admin-btn admin-btn-sm" disabled>Next</button>`;

  const info = `<span class="admin-pagination-info">Page ${data.current_page} of ${data.last_page} (${data.total} total)</span>`;

  container.innerHTML = `${prev}${info}${next}`;
}

async function loadUsers() {
  try {
    const params = new URLSearchParams({ page: currentPage });
    if (searchQuery) params.set('search', searchQuery);
    if (adminOnlyFilter) params.set('admin_only', 'true');

    const data = await apiGet(`/api/admin/users?${params.toString()}`);

    renderUsers(data.data || []);
    renderPagination(data);
  } catch (err) {
    if (err.status === 403) {
      showAlert('Access denied. Admin privileges required.');
      setTimeout(() => {
        window.location.href = 'dashboard.html';
      }, 2000);
    } else {
      showAlert(err.message || 'Failed to load users');
    }
  }
}

async function editUser(userId) {
  try {
    const data = await apiGet(`/api/admin/users/${userId}`);
    const user = data.user;

    document.getElementById('user-id').value = user.id;
    document.getElementById('user-name').value = user.name || '';
    document.getElementById('user-email').value = user.email || '';
    document.getElementById('user-is-admin').checked = user.is_admin || false;

    document.getElementById('user-modal').hidden = false;
  } catch (err) {
    showAlert(err.message || 'Failed to load user details');
  }
}

async function saveUser(event) {
  event.preventDefault();

  const userId = document.getElementById('user-id').value;
  const name = document.getElementById('user-name').value;
  const email = document.getElementById('user-email').value;
  const isAdmin = document.getElementById('user-is-admin').checked;

  try {
    await apiPut(`/api/admin/users/${userId}`, { name, email, is_admin: isAdmin });
    showAlert('User updated successfully', 'success');
    closeModal();
    await loadUsers();
  } catch (err) {
    showAlert(err.message || 'Failed to update user');
  }
}

function deleteUser(userId, userName) {
  selectedUserId = userId;
  document.getElementById('delete-user-name').textContent = userName;
  document.getElementById('delete-modal').hidden = false;
}

async function confirmDelete() {
  if (!selectedUserId) return;

  try {
    await apiDelete(`/api/admin/users/${selectedUserId}`);
    showAlert('User deleted successfully', 'success');
    closeDeleteModal();
    selectedUserId = null;
    await loadUsers();
  } catch (err) {
    showAlert(err.message || 'Failed to delete user');
  }
}

function closeModal() {
  document.getElementById('user-modal').hidden = true;
  document.getElementById('user-form').reset();
}

function closeDeleteModal() {
  document.getElementById('delete-modal').hidden = true;
  selectedUserId = null;
}

window.closeModal = closeModal;
window.closeDeleteModal = closeDeleteModal;

function changePage(page) {
  currentPage = page;
  loadUsers();
}

function initFilters() {
  const searchInput = document.getElementById('search-input');
  const adminFilter = document.getElementById('admin-only-filter');

  let searchTimeout;
  searchInput?.addEventListener('input', (e) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
      searchQuery = e.target.value.trim();
      currentPage = 1;
      loadUsers();
    }, 500);
  });

  adminFilter?.addEventListener('change', (e) => {
    adminOnlyFilter = e.target.checked;
    currentPage = 1;
    loadUsers();
  });
}

function initModals() {
  // User edit modal
  document.getElementById('modal-close')?.addEventListener('click', closeModal);
  document.getElementById('modal-cancel')?.addEventListener('click', closeModal);
  document.getElementById('modal-overlay')?.addEventListener('click', closeModal);
  document.getElementById('user-form')?.addEventListener('submit', saveUser);

  // Delete modal
  document.getElementById('delete-close')?.addEventListener('click', closeDeleteModal);
  document.getElementById('delete-cancel')?.addEventListener('click', closeDeleteModal);
  document.getElementById('delete-overlay')?.addEventListener('click', closeDeleteModal);
  document.getElementById('delete-confirm')?.addEventListener('click', confirmDelete);
}

function initTableActions() {
  document.getElementById('users-body')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const { action, id, name } = btn.dataset;
    if (action === 'edit') editUser(id);
    if (action === 'delete') deleteUser(id, name);
  });
}

async function boot() {
  initTheme();
  initShell({ current: 'admin-users' });
  if (!requireAuth()) return;

  initFilters();
  initModals();
  initTableActions();
  await loadUsers();
}

// Pagination buttons are rendered with numeric ids only, so inline
// onclick handlers are safe there and still rely on this global.
window.changePage = changePage;

boot();
