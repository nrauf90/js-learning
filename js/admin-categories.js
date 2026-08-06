import { apiGet, apiPost, apiPut, apiDelete, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

let selectedCategoryId = null;

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('admin-categories.html')}`);
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

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function renderCategories(categories) {
  const tbody = document.getElementById('categories-body');
  if (!tbody) return;

  if (!categories || categories.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" class="admin-table-empty">No categories found</td></tr>';
    return;
  }

  // Use data-* attributes (not inline onclick string interpolation) so
  // names containing quotes/apostrophes (e.g. "Kids' Education") can't
  // break the generated markup.
  tbody.innerHTML = categories.map(cat => `
    <tr>
      <td><strong>${escapeHtml(cat.name)}</strong></td>
      <td><span class="admin-badge admin-badge-${cat.kind === 'income' ? 'success' : 'danger'}">${cat.kind.toUpperCase()}</span></td>
      <td>${cat.cash_entries_count || 0}</td>
      <td>
        <div class="admin-actions">
          <button class="admin-btn admin-btn-sm admin-btn-secondary" data-action="edit" data-id="${cat.id}" data-name="${escapeHtml(cat.name)}" data-kind="${escapeHtml(cat.kind)}">Edit</button>
          <button class="admin-btn admin-btn-sm admin-btn-danger" data-action="delete" data-id="${cat.id}" data-name="${escapeHtml(cat.name)}">Delete</button>
        </div>
      </td>
    </tr>
  `).join('');
}

async function loadCategories() {
  try {
    const data = await apiGet('/api/admin/categories');
    renderCategories(data.categories || []);
  } catch (err) {
    showAlert(err.message || 'Failed to load categories');
  }
}

async function saveCategory(e) {
  e.preventDefault();
  const id = document.getElementById('category-id').value;
  const name = document.getElementById('category-name').value;
  const kind = document.getElementById('category-kind').value;

  try {
    if (id) {
      await apiPut(`/api/admin/categories/${id}`, { name, kind });
      showAlert('Category updated', 'success');
    } else {
      await apiPost('/api/admin/categories', { name, kind });
      showAlert('Category created', 'success');
    }
    closeModal();
    await loadCategories();
  } catch (err) {
    showAlert(err.message || 'Failed to save category');
  }
}

function editCategory(id = '', name = '', kind = 'expense') {
  document.getElementById('modal-title').textContent = id ? 'Edit Category' : 'Add Category';
  document.getElementById('category-id').value = id;
  document.getElementById('category-name').value = name;
  document.getElementById('category-kind').value = kind;
  document.getElementById('category-modal').hidden = false;
}

function deleteCategory(id, name) {
  selectedCategoryId = id;
  document.getElementById('delete-category-name').textContent = name;
  document.getElementById('delete-modal').hidden = false;
}

async function confirmDelete() {
  if (!selectedCategoryId) return;
  try {
    await apiDelete(`/api/admin/categories/${selectedCategoryId}`);
    showAlert('Category deleted', 'success');
    closeDeleteModal();
    await loadCategories();
  } catch (err) {
    showAlert(err.message || 'Failed to delete category');
  }
}

function closeModal() {
  document.getElementById('category-modal').hidden = true;
  document.getElementById('category-form').reset();
}

function closeDeleteModal() {
  document.getElementById('delete-modal').hidden = true;
}

function initTableActions() {
  document.getElementById('categories-body')?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const { action, id, name, kind } = btn.dataset;
    if (action === 'edit') editCategory(id, name, kind);
    if (action === 'delete') deleteCategory(id, name);
  });
}

async function boot() {
  initTheme();
  initShell({ current: 'admin-categories' });
  if (!requireAuth()) return;

  document.getElementById('add-category-btn')?.addEventListener('click', () => editCategory());
  document.getElementById('modal-close')?.addEventListener('click', closeModal);
  document.getElementById('modal-cancel')?.addEventListener('click', closeModal);
  document.getElementById('category-form')?.addEventListener('submit', saveCategory);
  document.getElementById('delete-close')?.addEventListener('click', closeDeleteModal);
  document.getElementById('delete-cancel')?.addEventListener('click', closeDeleteModal);
  document.getElementById('delete-confirm')?.addEventListener('click', confirmDelete);
  initTableActions();

  await loadCategories();
}

boot();
