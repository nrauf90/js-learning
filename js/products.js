import { apiDelete, apiGet, apiPost, apiPut, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

let products = [];
let categories = [];
let editingId = null;
let searchTimer = null;

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('products.html')}`);
  return false;
}

function el(id) {
  return document.getElementById(id);
}

function showAlert(message, type = 'error') {
  const box = el('products-alert');
  if (!box) return;
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearAlert() {
  const box = el('products-alert');
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
}

function formatRs(amount) {
  const n = Number(amount) || 0;
  return `Rs ${n.toLocaleString('en-PK', { maximumFractionDigits: 2 })}`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  return div.innerHTML;
}

/* -------------------------------------------------------------- categories */

function renderCategories() {
  const select = el('product-category');
  const selected = select.value;
  select.innerHTML =
    '<option value="">Uncategorised</option>' +
    categories.map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
  select.value = selected;

  const list = el('categories-list');
  list.innerHTML = categories.length
    ? categories
        .map(
          (c) => `
        <li class="pos-category" data-category-id="${c.id}">
          <span>${escapeHtml(c.name)}</span>
          <span class="pos-category-count">${c.products_count} product${c.products_count === 1 ? '' : 's'}</span>
          <button type="button" class="pos-line-remove" aria-label="Delete ${escapeHtml(c.name)}">×</button>
        </li>`
        )
        .join('')
    : '<li class="cashflow-empty">No categories yet.</li>';

  list.querySelectorAll('[data-category-id]').forEach((row) => {
    row.querySelector('.pos-line-remove')?.addEventListener('click', () =>
      deleteCategory(Number(row.dataset.categoryId))
    );
  });
}

async function loadCategories() {
  const data = await apiGet('/api/product-categories');
  categories = data.categories || [];
  renderCategories();
}

async function deleteCategory(id) {
  const category = categories.find((c) => c.id === id);
  if (!window.confirm(`Delete "${category?.name}"? Its products stay, but become uncategorised.`)) return;

  try {
    await apiDelete(`/api/product-categories/${id}`);
    await Promise.all([loadCategories(), loadProducts()]);
  } catch (err) {
    showAlert(err.message || 'Could not delete the category');
  }
}

/* ---------------------------------------------------------------- products */

function renderProducts() {
  const list = el('products-list');
  const empty = el('products-empty');

  empty.hidden = products.length > 0;

  list.innerHTML = products
    .map((p) => {
      const stock = p.track_stock
        ? `<span class="pos-stock${p.low_stock ? ' is-low' : ''}">${p.stock_quantity} in stock</span>`
        : '<span class="pos-stock is-muted">Not tracked</span>';

      return `
      <li class="pos-catalog-row${p.is_active ? '' : ' is-inactive'}" data-product-id="${p.id}">
        <div class="pos-catalog-main">
          <span class="pos-catalog-name">${escapeHtml(p.name)}</span>
          <span class="pos-catalog-meta">
            ${escapeHtml(p.category?.name || 'Uncategorised')}
            ${p.sku ? ` · SKU ${escapeHtml(p.sku)}` : ''}
            ${p.barcode ? ` · ${escapeHtml(p.barcode)}` : ''}
            ${p.is_active ? '' : ' · Hidden from till'}
          </span>
        </div>
        <span class="pos-catalog-price">${formatRs(p.price)}</span>
        ${stock}
        <div class="pos-catalog-actions">
          ${p.track_stock ? '<button type="button" class="btn btn-secondary pos-mini" data-action="stock">Stock</button>' : ''}
          <button type="button" class="btn btn-secondary pos-mini" data-action="edit">Edit</button>
          <button type="button" class="btn btn-ghost pos-mini" data-action="delete">Delete</button>
        </div>
      </li>`;
    })
    .join('');

  list.querySelectorAll('[data-product-id]').forEach((row) => {
    const id = Number(row.dataset.productId);
    row.querySelector('[data-action="edit"]')?.addEventListener('click', () => startEdit(id));
    row.querySelector('[data-action="delete"]')?.addEventListener('click', () => deleteProduct(id));
    row.querySelector('[data-action="stock"]')?.addEventListener('click', () => adjustStock(id));
  });
}

async function loadProducts() {
  const params = new URLSearchParams({ per_page: '200' });
  const search = el('product-search').value.trim();
  if (search) params.set('search', search);
  if (el('filter-low-stock').checked) params.set('low_stock', '1');

  const data = await apiGet(`/api/products?${params.toString()}`);
  products = data.products || [];
  renderProducts();
}

function formValues() {
  const categoryId = el('product-category').value;
  const cost = el('product-cost').value;

  return {
    name: el('product-name').value.trim(),
    price: Number(el('product-price').value),
    cost: cost === '' ? null : Number(cost),
    sku: el('product-sku').value.trim() || null,
    barcode: el('product-barcode').value.trim() || null,
    product_category_id: categoryId === '' ? null : Number(categoryId),
    track_stock: el('product-track').checked,
    low_stock_threshold: Number(el('product-low').value) || 0,
  };
}

async function submitProduct(event) {
  event.preventDefault();
  clearAlert();

  const payload = formValues();
  if (!payload.name || !Number.isFinite(payload.price)) {
    showAlert('Name and price are required.');
    return;
  }

  try {
    if (editingId) {
      // Stock is not editable here — it only moves via sales or the Stock
      // button, so every change leaves an audit trail.
      await apiPut(`/api/products/${editingId}`, payload);
    } else {
      await apiPost('/api/products', {
        ...payload,
        stock_quantity: Number(el('product-stock').value) || 0,
      });
    }

    resetForm();
    await Promise.all([loadProducts(), loadCategories()]);
    showAlert(editingId ? 'Product updated.' : 'Product added.', 'success');
  } catch (err) {
    showAlert(err.message || 'Could not save the product');
  }
}

function startEdit(id) {
  const product = products.find((p) => p.id === id);
  if (!product) return;

  editingId = id;
  el('product-id').value = id;
  el('product-name').value = product.name;
  el('product-price').value = product.price;
  el('product-cost').value = product.cost ?? '';
  el('product-sku').value = product.sku ?? '';
  el('product-barcode').value = product.barcode ?? '';
  el('product-category').value = product.product_category_id ?? '';
  el('product-track').checked = product.track_stock;
  el('product-low').value = product.low_stock_threshold;

  el('product-form-title').textContent = `Edit ${product.name}`;
  el('product-submit').textContent = 'Save changes';
  el('product-cancel').hidden = false;
  // Opening stock only applies at creation.
  el('product-stock').closest('div').hidden = true;

  el('product-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function resetForm() {
  editingId = null;
  el('product-form').reset();
  el('product-id').value = '';
  el('product-track').checked = true;
  el('product-form-title').textContent = 'Add product';
  el('product-submit').textContent = 'Add product';
  el('product-cancel').hidden = true;
  el('product-stock').closest('div').hidden = false;
}

async function deleteProduct(id) {
  const product = products.find((p) => p.id === id);
  if (
    !window.confirm(
      `Delete "${product?.name}"? Past sales keep their record, but it disappears from the till.`
    )
  )
    return;

  try {
    await apiDelete(`/api/products/${id}`);
    if (editingId === id) resetForm();
    await loadProducts();
  } catch (err) {
    showAlert(err.message || 'Could not delete the product');
  }
}

async function adjustStock(id) {
  const product = products.find((p) => p.id === id);
  if (!product) return;

  const raw = window.prompt(
    `Adjust stock for "${product.name}".\nCurrently ${product.stock_quantity}.\n` +
      'Enter a positive number to add, negative to remove.',
    '0'
  );
  if (raw === null) return;

  const delta = Number(raw);
  if (!Number.isInteger(delta) || delta === 0) {
    showAlert('Enter a whole number other than zero.');
    return;
  }

  try {
    await apiPost(`/api/products/${id}/stock`, {
      quantity_delta: delta,
      type: delta > 0 ? 'restock' : 'adjustment',
    });
    await loadProducts();
    showAlert('Stock updated.', 'success');
  } catch (err) {
    showAlert(err.message || 'Could not adjust stock');
  }
}

/* -------------------------------------------------------------------- boot */

async function boot() {
  initTheme();
  initShell({ current: 'products' });
  if (!requireAuth()) return;

  el('product-form').addEventListener('submit', submitProduct);
  el('product-cancel').addEventListener('click', resetForm);

  el('category-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = el('category-name').value.trim();
    if (!name) return;
    try {
      await apiPost('/api/product-categories', { name });
      el('category-name').value = '';
      await loadCategories();
    } catch (err) {
      showAlert(err.message || 'Could not add the category');
    }
  });

  el('product-search').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      loadProducts().catch((err) => showAlert(err.message || 'Search failed'));
    }, 250);
  });
  el('filter-low-stock').addEventListener('change', () => {
    loadProducts().catch((err) => showAlert(err.message || 'Filter failed'));
  });

  try {
    await Promise.all([loadCategories(), loadProducts()]);
  } catch (err) {
    showAlert(err.message || 'Failed to load products');
  }
}

boot();
