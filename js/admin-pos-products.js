import { apiGet, apiPost, apiPut, apiDelete, getAuthToken } from './api.js';
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
  window.location.replace(`login.html?next=${encodeURIComponent('admin-pos-products.html')}`);
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

async function loadProducts() {
  const data = await apiGet('/api/admin/pos/products');
  const tbody = document.getElementById('products-body');
  const products = data.products || [];
  if (!products.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="admin-table-empty">No products</td></tr>';
    return;
  }
  tbody.innerHTML = products.map((p) => `
    <tr>
      <td>${escapeHtml(p.sku)}</td>
      <td>${escapeHtml(p.name)}</td>
      <td>${formatRs(p.price)}</td>
      <td>${p.is_active ? '<span class="admin-badge admin-badge-success">Active</span>' : '<span class="admin-badge admin-badge-muted">Inactive</span>'}</td>
      <td>
        <button type="button" class="btn-secondary btn-sm" data-edit="${p.id}">Edit</button>
        <button type="button" class="btn-secondary btn-sm" data-delete="${p.id}">Delete</button>
      </td>
    </tr>
  `).join('');

  tbody.querySelectorAll('[data-edit]').forEach((btn) => {
    btn.addEventListener('click', () => openEditModal(products.find((p) => p.id === Number(btn.dataset.edit))));
  });
  tbody.querySelectorAll('[data-delete]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Delete this product?')) return;
      await apiDelete(`/api/admin/pos/products/${btn.dataset.delete}`);
      loadProducts();
    });
  });
}

function openEditModal(product) {
  document.getElementById('product-modal').hidden = false;
  document.getElementById('product-id').value = product?.id || '';
  document.getElementById('product-sku').value = product?.sku || '';
  document.getElementById('product-name').value = product?.name || '';
  document.getElementById('product-price').value = product?.price || '';
  document.getElementById('product-active').checked = product?.is_active !== false;
  document.getElementById('modal-title').textContent = product ? 'Edit Product' : 'Add Product';
}

async function saveProduct(e) {
  e.preventDefault();
  const id = document.getElementById('product-id').value;
  const payload = {
    sku: document.getElementById('product-sku').value,
    name: document.getElementById('product-name').value,
    price: parseFloat(document.getElementById('product-price').value),
    is_active: document.getElementById('product-active').checked,
  };
  if (id) {
    await apiPut(`/api/admin/pos/products/${id}`, payload);
  } else {
    await apiPost('/api/admin/pos/products', payload);
  }
  document.getElementById('product-modal').hidden = true;
  loadProducts();
}

async function init() {
  if (!requireAuth()) return;
  initTheme();
  initShell({ current: 'admin-pos-products' });
  document.getElementById('add-product-btn')?.addEventListener('click', () => openEditModal(null));
  document.getElementById('product-form')?.addEventListener('submit', saveProduct);
  document.getElementById('modal-close')?.addEventListener('click', () => {
    document.getElementById('product-modal').hidden = true;
  });
  await loadProducts();
}

init();
