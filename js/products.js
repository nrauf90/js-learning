import { API_BASE_URL, apiDelete, apiGet, apiPost, apiPut, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';
import {
  TYPE_EACH,
  TYPE_VOLUME,
  TYPE_WEIGHT,
  UNITS,
  codesForType,
  formatQuantity,
  fromBase,
  isMeasured,
  shortOf,
  toBase,
} from './units.js';

let products = [];
let categories = [];
let editingId = null;
let editingCategoryId = null;
let searchTimer = null;
let currentPage = 1;

/**
 * One screen of catalogue per request. A shop with two thousand lines used to
 * put every one of them into this list on load, which is slow to render and
 * impossible to read; the till is the screen that wants the whole catalogue in
 * memory (see js/pos.js), not this one.
 */
const PER_PAGE = 25;

/**
 * The product being edited, held rather than looked up by id: the form stays
 * open while the list pages underneath it, so `products` may no longer contain
 * the row whose picture the Remove button is about to delete.
 */
let editingProduct = null;

/** The product whose count the stock dialog is currently adjusting. */
let stockTarget = null;

/**
 * Mirrors StockMovement::REASONS on the API.
 *
 * A fixed list rather than the note box, because the whole point of asking is
 * to be able to add the year up by cause — "kharab ho gaya", "spoiled" and
 * "bad" are three spellings of one number.
 */
const STOCK_REASONS = [
  { value: 'damaged', label: 'Damaged or broken' },
  { value: 'expired', label: 'Past its date' },
  { value: 'spoiled', label: 'Spoiled (sabzi, doodh, roti)' },
  { value: 'theft', label: 'Theft or missing' },
  { value: 'sample', label: 'Given away or used in the shop' },
  { value: 'count_correction', label: 'Count correction (recount, not a loss)' },
];

/**
 * Files chosen but not yet sent. A picture can only be attached to a row that
 * exists, so on "Add product" the file waits here until the POST comes back
 * with an id.
 */
const staged = { product: null, category: null };

/** Object URLs for the local previews, revoked when they are replaced. */
const previewUrls = { product: null, category: null };

/** Mirrors CatalogImageStore on the API — see uploadImage(). */
const MAX_IMAGE_BYTES = 2 * 1024 * 1024;
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * What a shop actually quotes in. Grams and millilitres are the units the till
 * stores, never the ones a price list is written in, so they are offered but
 * never pre-selected.
 */
const DEFAULT_PRICE_UNIT = {
  [TYPE_EACH]: 'pc',
  [TYPE_WEIGHT]: 'kg',
  [TYPE_VOLUME]: 'l',
};

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

/** Shop money is PKR — unrelated to the USD the subscription is billed in. */
function formatRs(amount) {
  const n = Number(amount) || 0;
  return `Rs ${n.toLocaleString('en-PK', { maximumFractionDigits: 2 })}`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  // The text-node serialiser escapes &, < and >, but leaves quotes alone —
  // and these values also land inside attributes (alt, aria-label). A product
  // named `x" onerror="…` would otherwise break straight out of one.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* ------------------------------------------------------------------- units */

/**
 * Every money and quantity box on this form is read in the chosen price unit,
 * so the labels carry it. Without them "Price 250" and "Opening stock 12.5" are
 * both ambiguous, and the shopkeeper has no way to tell that the till is holding
 * the figures per gram underneath.
 */
function renderUnitLabels() {
  const short = shortOf(el('product-price-unit').value);

  el('product-price-label').textContent = `Price (PKR per ${short})`;
  el('product-cost-label').textContent = `Cost (PKR per ${short})`;
  el('product-stock-label').textContent = `Opening stock (${short})`;
  el('product-low-label').textContent = `Low stock alert at (${short})`;
  // Typed in the quoted unit like everything else on the form: a peti holding
  // two dozen is "2" against a dozen price and "24" against a piece price, and
  // the server lands both on the same 24 pieces underneath.
  el('product-pack-size-label').textContent = `Pack size (${short} in one pack)`;
}

/**
 * Rebuilds "Price per" for the kind of goods chosen. Only the units that belong
 * to that kind are offered — quoting daal in litres is a slip of the finger, not
 * a business decision, and the server rejects it anyway.
 */
function renderUnitFields(unitType, priceUnit = null) {
  const select = el('product-price-unit');
  const codes = codesForType(unitType);
  const chosen = codes.includes(priceUnit) ? priceUnit : DEFAULT_PRICE_UNIT[unitType] ?? codes[0];

  select.innerHTML = codes
    .map((code) => `<option value="${code}">${escapeHtml(UNITS[code].label)}</option>`)
    .join('');
  select.value = chosen;

  renderUnitLabels();
}

/* ------------------------------------------------------------------ images */

/**
 * Posts one file as multipart.
 *
 * Not apiPost(): api.js always sets `Content-Type: application/json`, and any
 * Content-Type we set by hand stops the browser writing the multipart boundary
 * the server needs to find the file. So the base URL and the bearer token are
 * reused and the request itself is built here.
 */
async function uploadImage(path, file) {
  const body = new FormData();
  body.append('image', file);

  const token = getAuthToken();
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body,
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(data?.errors?.image?.[0] || data?.message || 'The picture could not be uploaded.');
  }

  return data;
}

function setPreview(kind, url) {
  const thumb = el(`${kind}-image-thumb`);
  const empty = el(`${kind}-image-empty`);
  const remove = el(`${kind}-image-remove`);

  if (url) {
    thumb.src = url;
    thumb.hidden = false;
    empty.hidden = true;
  } else {
    thumb.removeAttribute('src');
    thumb.hidden = true;
    empty.hidden = false;
  }

  remove.hidden = !url;
}

function releasePreview(kind) {
  if (previewUrls[kind]) {
    URL.revokeObjectURL(previewUrls[kind]);
    previewUrls[kind] = null;
  }
}

/** Drops the staged file and puts the saved picture (if any) back on screen. */
function clearStaged(kind, savedUrl = null) {
  staged[kind] = null;
  releasePreview(kind);
  const input = el(`${kind}-image`);
  if (input) input.value = '';
  setPreview(kind, savedUrl);
}

/**
 * The API re-checks type and size — this only saves a doomed 2 MB round trip
 * and gives the message straight away.
 */
function stageFile(kind, savedUrl = null) {
  const input = el(`${kind}-image`);
  const file = input.files?.[0] || null;

  if (!file) {
    clearStaged(kind, savedUrl);
    return;
  }

  if (!IMAGE_TYPES.includes(file.type)) {
    showAlert('Pick a JPG, PNG or WebP image.');
    clearStaged(kind, savedUrl);
    return;
  }

  if (file.size > MAX_IMAGE_BYTES) {
    showAlert('That picture is larger than 2 MB. Please pick a smaller one.');
    clearStaged(kind, savedUrl);
    return;
  }

  releasePreview(kind);
  staged[kind] = file;
  previewUrls[kind] = URL.createObjectURL(file);
  setPreview(kind, previewUrls[kind]);
  clearAlert();
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
        <li class="pos-category${c.id === editingCategoryId ? ' is-editing' : ''}" data-category-id="${c.id}">
          ${thumbHTML(c.image_url, c.name, 'pos-category-pic')}
          <span>${escapeHtml(c.name)}</span>
          <span class="pos-category-count">${c.products_count} product${c.products_count === 1 ? '' : 's'}</span>
          <button type="button" class="btn btn-secondary pos-mini" data-action="edit-category">Edit</button>
          <button type="button" class="pos-line-remove" aria-label="Delete ${escapeHtml(c.name)}">×</button>
        </li>`
        )
        .join('')
    : '<li class="cashflow-empty">No categories yet.</li>';

  list.querySelectorAll('[data-category-id]').forEach((row) => {
    const id = Number(row.dataset.categoryId);
    row.querySelector('[data-action="edit-category"]')?.addEventListener('click', () => startCategoryEdit(id));
    row.querySelector('.pos-line-remove')?.addEventListener('click', () => deleteCategory(id));
  });
}

async function loadCategories() {
  const data = await apiGet('/api/product-categories');
  categories = data.categories || [];
  renderCategories();
}

function startCategoryEdit(id) {
  const category = categories.find((c) => c.id === id);
  if (!category) return;

  editingCategoryId = id;
  el('category-id').value = id;
  el('category-name').value = category.name;
  el('category-form-title').textContent = `Edit ${category.name}`;
  el('category-submit').textContent = 'Save';
  el('category-cancel').hidden = false;

  clearStaged('category', category.image_url || null);
  renderCategories();
}

function resetCategoryForm() {
  editingCategoryId = null;
  el('category-form').reset();
  el('category-id').value = '';
  el('category-form-title').textContent = 'Categories';
  el('category-submit').textContent = 'Add';
  el('category-cancel').hidden = true;
  clearStaged('category');
  renderCategories();
}

async function submitCategory(event) {
  event.preventDefault();
  clearAlert();

  const name = el('category-name').value.trim();
  if (!name) return;

  try {
    const saved = editingCategoryId
      ? await apiPut(`/api/product-categories/${editingCategoryId}`, { name })
      : await apiPost('/api/product-categories', { name });

    // The picture needs a row to hang off, so it always follows the save.
    const id = saved?.category?.id ?? editingCategoryId;
    if (staged.category && id) {
      await uploadImage(`/api/product-categories/${id}/image`, staged.category);
    }

    resetCategoryForm();
    await Promise.all([loadCategories(), loadProducts()]);
  } catch (err) {
    showAlert(err.message || 'Could not save the category');
  }
}

async function removeCategoryImage() {
  const category = categories.find((c) => c.id === editingCategoryId);

  // A picture that was only ever staged has nothing to delete on the server.
  if (staged.category || !category?.image_url) {
    clearStaged('category', category?.image_url || null);
    return;
  }

  try {
    await apiDelete(`/api/product-categories/${category.id}/image`);
    clearStaged('category');
    await loadCategories();
  } catch (err) {
    showAlert(err.message || 'Could not remove the picture');
  }
}

async function deleteCategory(id) {
  const category = categories.find((c) => c.id === id);
  if (!window.confirm(`Delete "${category?.name}"? Its products stay, but become uncategorised.`)) return;

  try {
    await apiDelete(`/api/product-categories/${id}`);
    if (editingCategoryId === id) resetCategoryForm();
    await Promise.all([loadCategories(), loadProducts()]);
  } catch (err) {
    showAlert(err.message || 'Could not delete the category');
  }
}

/* ---------------------------------------------------------------- products */

/**
 * The URL is built by the API, but it still lands in an attribute here, so it
 * goes through the same escape as everything else rather than being trusted
 * because of where it came from.
 */
function thumbHTML(imageUrl, name, className) {
  if (!imageUrl) {
    return `<span class="${className} is-empty" aria-hidden="true"></span>`;
  }

  return `<span class="${className}"><img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(name)}" loading="lazy" /></span>`;
}

/**
 * The date on the packet, said the way it would be said out loud.
 *
 * Something already off is the most urgent thing on the shelf, so it is called
 * out flatly rather than as a countdown — "Expires in -2 days" is a puzzle at
 * the moment the shopkeeper least wants one.
 */
function expiryBadge(product) {
  if (product.is_expired) return 'Expired';
  if (!product.is_expiring_soon) return '';

  const days = Number(product.days_to_expiry);
  if (days === 0) return 'Expires today';
  if (days === 1) return 'Expires tomorrow';
  return `Expires in ${days} days`;
}

function renderProducts() {
  const list = el('products-list');
  const empty = el('products-empty');

  empty.hidden = products.length > 0;

  list.innerHTML = products
    .map((p) => {
      // The server's labels, not the raw columns: those are per gram, so a daal
      // priced at Rs 250/kg would read "Rs 0.25" with "5000 in stock" beside it
      // and look like the catalogue had lost the shop's prices.
      const stock = p.track_stock
        ? `<span class="pos-stock${p.low_stock ? ' is-low' : ''}">${escapeHtml(p.stock_label)} in stock</span>`
        : '<span class="pos-stock is-muted">Not tracked</span>';

      const expiry = expiryBadge(p);

      return `
      <li class="pos-catalog-row${p.is_active ? '' : ' is-inactive'}" data-product-id="${p.id}">
        ${thumbHTML(p.image_url, p.name, 'pos-catalog-pic')}
        <div class="pos-catalog-main">
          <span class="pos-catalog-name">${escapeHtml(p.name)}</span>
          <span class="pos-catalog-meta">
            ${escapeHtml(p.category?.name || 'Uncategorised')}
            ${p.sku ? ` · SKU ${escapeHtml(p.sku)}` : ''}
            ${p.barcode ? ` · ${escapeHtml(p.barcode)}` : ''}
            ${p.pack_label_text ? ` · ${escapeHtml(p.pack_label_text)}` : ''}
            ${p.is_active ? '' : ' · Hidden from till'}
            ${expiry ? ` · <span class="pos-expiry-badge${p.is_expired ? ' is-expired' : ''}">${escapeHtml(expiry)}</span>` : ''}
          </span>
        </div>
        <span class="pos-catalog-price">${escapeHtml(p.unit_price_label)}</span>
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
    row.querySelector('[data-action="stock"]')?.addEventListener('click', () => openStockDialog(id));
  });
}

/**
 * The filters go to the API, never to the array that came back: a search that
 * only looked at the page on screen would answer "no products match" about a
 * catalogue that has fifteen of them on page four.
 */
function listParams() {
  const params = new URLSearchParams({ page: String(currentPage), per_page: String(PER_PAGE) });
  const search = el('product-search').value.trim();
  if (search) params.set('search', search);
  if (el('filter-low-stock').checked) params.set('low_stock', '1');
  if (el('filter-expiring').checked) params.set('expiring', '1');
  return params;
}

function renderPagination(meta) {
  const container = el('products-pagination');
  const page = Number(meta?.current_page) || 1;
  const lastPage = Number(meta?.last_page) || 1;
  const perPage = Number(meta?.per_page) || PER_PAGE;
  const total = Number(meta?.total) || 0;
  const first = total === 0 ? 0 : (page - 1) * perPage + 1;

  el('products-range').textContent =
    total === 0
      ? 'No products'
      : `${first}–${Math.min(page * perPage, total)} of ${total} product${total === 1 ? '' : 's'}`;

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
    `<span class="admin-pagination-info">Page ${page} of ${lastPage} (${total} products)</span>` +
    button(page < lastPage ? page + 1 : 0, 'Next');
}

async function loadProducts() {
  const data = await apiGet(`/api/products?${listParams().toString()}`);
  products = data.products || [];

  // Deleting the last row of the last page leaves the catalogue on a page that
  // no longer exists, which reads as an empty shop. Step back and ask again.
  const lastPage = Number(data.meta?.last_page) || 1;
  if (products.length === 0 && currentPage > lastPage) {
    currentPage = lastPage;
    await loadProducts();
    return;
  }

  renderProducts();
  renderPagination(data.meta);
}

function formValues() {
  const categoryId = el('product-category').value;
  const cost = el('product-cost').value;
  const expiry = el('product-expiry').value;

  // Price, cost and the threshold go up exactly as typed, quoted in price_unit;
  // the server divides them down to the base unit. Converting here as well would
  // be a second opinion about the same number, and the two would drift.
  return {
    name: el('product-name').value.trim(),
    unit_type: el('product-unit-type').value,
    price_unit: el('product-price-unit').value,
    price: Number(el('product-price').value),
    cost: cost === '' ? null : Number(cost),
    sku: el('product-sku').value.trim() || null,
    barcode: el('product-barcode').value.trim() || null,
    product_category_id: categoryId === '' ? null : Number(categoryId),
    track_stock: el('product-track').checked,
    low_stock_threshold: Number(el('product-low').value) || 0,
    // A pack of zero is not a pack — an empty box means the product is bought
    // the way it is sold, which is a pack of one.
    pack_size: Number(el('product-pack-size').value) || 1,
    pack_label: el('product-pack-label').value.trim() || null,
    expiry_date: expiry || null,
    track_expiry: el('product-track-expiry').checked,
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

  // A free counted item is a giveaway; a free weighed one is a division by zero.
  // "Pachaas ka daal" works out the weight from the rate, so a zero rate leaves
  // the till unable to answer the most common request over the counter.
  if (isMeasured(payload) && payload.price <= 0) {
    showAlert('A weighed or poured product needs a price above zero.');
    return;
  }

  const wasEditing = Boolean(editingId);

  try {
    let saved;
    if (editingId) {
      // Stock is not editable here — it only moves via sales or the Stock
      // button, so every change leaves an audit trail.
      saved = await apiPut(`/api/products/${editingId}`, payload);
    } else {
      saved = await apiPost('/api/products', {
        ...payload,
        stock_quantity: Number(el('product-stock').value) || 0,
      });
    }

    // The picture needs a row to hang off, so it always follows the save.
    const id = saved?.product?.id ?? editingId;
    if (staged.product && id) {
      await uploadImage(`/api/products/${id}/image`, staged.product);
    }

    resetForm();
    await Promise.all([loadProducts(), loadCategories()]);
    showAlert(wasEditing ? 'Product updated.' : 'Product added.', 'success');
  } catch (err) {
    showAlert(err.message || 'Could not save the product');
  }
}

async function removeProductImage() {
  const product = editingProduct;

  // A picture that was only ever staged has nothing to delete on the server.
  if (staged.product || !product?.image_url) {
    clearStaged('product', product?.image_url || null);
    return;
  }

  try {
    await apiDelete(`/api/products/${product.id}/image`);
    clearStaged('product');
    await loadProducts();
  } catch (err) {
    showAlert(err.message || 'Could not remove the picture');
  }
}

function startEdit(id) {
  const product = products.find((p) => p.id === id);
  if (!product) return;

  editingId = id;
  editingProduct = product;
  el('product-id').value = id;
  el('product-name').value = product.name;

  // Units before figures: the boxes below are all read in the price unit, so the
  // select has to be rebuilt and the labels rewritten before anything is typed
  // into them.
  el('product-unit-type').value = product.unit_type ?? TYPE_EACH;
  renderUnitFields(el('product-unit-type').value, product.price_unit);

  el('product-price').value = product.display_price;
  el('product-cost').value = product.display_cost ?? '';
  el('product-sku').value = product.sku ?? '';
  el('product-barcode').value = product.barcode ?? '';
  el('product-category').value = product.product_category_id ?? '';
  el('product-track').checked = product.track_stock;
  el('product-low').value = fromBase(product.low_stock_threshold, product.price_unit);
  // display_pack_size, not pack_size: the stored figure is in base units, so a
  // 50 kg bori would come back into the box reading 50000.
  el('product-pack-size').value = product.display_pack_size ?? 1;
  el('product-pack-label').value = product.pack_label ?? '';
  el('product-expiry').value = product.expiry_date ?? '';
  el('product-track-expiry').checked = Boolean(product.track_expiry);

  el('product-form-title').textContent = `Edit ${product.name}`;
  el('product-submit').textContent = 'Save changes';
  el('product-cancel').hidden = false;
  // Opening stock only applies at creation.
  el('product-stock').closest('div').hidden = true;

  // Whatever is already on the product, so the editor shows what the till shows.
  clearStaged('product', product.image_url || null);

  el('product-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function resetForm() {
  editingId = null;
  editingProduct = null;
  el('product-form').reset();
  el('product-id').value = '';
  el('product-track').checked = true;
  // form.reset() cannot restore a select whose options JS built, so "Price per"
  // is rebuilt rather than reset.
  el('product-unit-type').value = TYPE_EACH;
  renderUnitFields(TYPE_EACH);
  el('product-form-title').textContent = 'Add product';
  el('product-submit').textContent = 'Add product';
  el('product-cancel').hidden = true;
  el('product-stock').closest('div').hidden = false;
  clearStaged('product');
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

/* ----------------------------------------------------------- stock dialog */

function setStockError(message) {
  const box = el('stock-error');
  box.hidden = !message;
  box.textContent = message || '';
}

function openStockDialog(id) {
  const product = products.find((p) => p.id === id);
  if (!product) return;

  stockTarget = product;
  const unit = shortOf(product.price_unit);

  el('stock-modal-title').textContent = `Adjust stock — ${product.name}`;
  el('stock-modal-note').textContent =
    `Currently ${product.stock_label} in stock. ` +
    `Enter the change in ${unit} — a minus takes stock off the shelf.`;
  el('stock-delta-label').textContent = `Change (${unit})`;

  el('stock-delta').value = '';
  el('stock-note').value = '';
  el('stock-reason').value = '';
  setStockError('');
  renderStockPreview();

  el('stock-modal').hidden = false;
  document.body.classList.add('pos-modal-open');
  el('stock-delta').focus();
}

function closeStockDialog() {
  const modal = el('stock-modal');
  if (!modal || modal.hidden) return;

  stockTarget = null;
  modal.hidden = true;
  document.body.classList.remove('pos-modal-open');
}

/**
 * What the adjustment does, in stock and in rupees, as it is typed.
 *
 * The money is the point. A shopkeeper writing off "3" has no feel for what
 * that costs, and wastage only ever gets looked at once somebody can see it
 * adding up — so the loss is priced live rather than after the fact.
 */
function renderStockPreview() {
  const preview = el('stock-preview');
  if (!stockTarget) return;

  const typed = Number(el('stock-delta').value);
  const delta = Number.isFinite(typed) ? toBase(typed, stockTarget.price_unit) : 0;

  // Only a reduction needs an explanation — a delivery explains itself, and
  // asking on the way in would slow the one path that is always benign.
  el('stock-reason-field').hidden = delta >= 0;

  if (!delta) {
    preview.textContent = '';
    delete preview.dataset.state;
    return;
  }

  const after = formatQuantity(Number(stockTarget.stock_quantity) + delta, stockTarget.unit_type);
  // `cost` is per base unit, so it multiplies the converted delta — never the
  // figure as typed, which would price half a kilo of daal as half a rupee.
  const value = stockTarget.cost == null ? null : Math.abs(delta) * Number(stockTarget.cost);

  preview.dataset.state = delta < 0 ? 'short' : 'over';

  if (value === null) {
    preview.textContent = `Stock becomes ${after}. No cost recorded, so the value cannot be worked out.`;
    return;
  }

  preview.textContent =
    delta < 0
      ? `Stock becomes ${after} — ${formatRs(value)} off the shelf at cost.`
      : `Stock becomes ${after} — ${formatRs(value)} added at cost.`;
}

async function submitStock(event) {
  event.preventDefault();
  if (!stockTarget) return;

  const unit = shortOf(stockTarget.price_unit);
  const delta = Number(el('stock-delta').value);

  // Whole numbers only would have made half a sack of atta impossible to book in.
  if (!Number.isFinite(delta) || delta === 0) {
    setStockError(`Enter an amount in ${unit} other than zero.`);
    return;
  }

  const reason = el('stock-reason').value;
  if (delta < 0 && !reason) {
    setStockError('Choose what happened to the stock.');
    return;
  }

  const note = el('stock-note').value.trim();

  try {
    // Quoted in the product's own price unit, like everything else the
    // shopkeeper types — the server converts before it touches the count.
    await apiPost(`/api/products/${stockTarget.id}/stock`, {
      quantity_delta: delta,
      type: delta > 0 ? 'restock' : 'adjustment',
      reason: delta < 0 ? reason : null,
      note: note || null,
    });

    closeStockDialog();
    await loadProducts();
    showAlert('Stock updated.', 'success');
  } catch (err) {
    setStockError(err.message || 'Could not adjust stock');
  }
}

/* -------------------------------------------------------------------- boot */

async function boot() {
  initTheme();
  initShell({ current: 'products' });
  if (!requireAuth()) return;

  el('product-form').addEventListener('submit', submitProduct);
  el('product-cancel').addEventListener('click', resetForm);
  el('product-unit-type').addEventListener('change', (event) => renderUnitFields(event.target.value));
  el('product-price-unit').addEventListener('change', renderUnitLabels);
  renderUnitFields(TYPE_EACH);
  // Typing a date is the request to be warned about it. Filing the date and
  // then staying quiet is the one outcome nobody types a date hoping for — but
  // the box stays visible, so it can still be turned back off deliberately.
  el('product-expiry').addEventListener('change', (event) => {
    if (event.target.value) el('product-track-expiry').checked = true;
  });
  el('product-image').addEventListener('change', () => {
    stageFile('product', editingProduct?.image_url || null);
  });
  el('product-image-remove').addEventListener('click', () => {
    removeProductImage().catch((err) => showAlert(err.message || 'Could not remove the picture'));
  });

  el('category-form').addEventListener('submit', submitCategory);
  el('category-cancel').addEventListener('click', resetCategoryForm);
  el('category-image').addEventListener('change', () => {
    const category = categories.find((c) => c.id === editingCategoryId);
    stageFile('category', category?.image_url || null);
  });
  el('category-image-remove').addEventListener('click', () => {
    removeCategoryImage().catch((err) => showAlert(err.message || 'Could not remove the picture'));
  });

  // Every filter goes back to page one: page four of the old result set is
  // rarely a page of the new one, and is often past its end.
  el('product-search').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      currentPage = 1;
      loadProducts().catch((err) => showAlert(err.message || 'Search failed'));
    }, 250);
  });
  [el('filter-low-stock'), el('filter-expiring')].forEach((filter) => {
    filter.addEventListener('change', () => {
      currentPage = 1;
      loadProducts().catch((err) => showAlert(err.message || 'Filter failed'));
    });
  });

  // Delegated: the pager is re-rendered on every load, and per-button
  // listeners would leak one set each time.
  el('products-pagination').addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) return;
    currentPage = Number(button.dataset.page) || 1;
    loadProducts().catch((err) => showAlert(err.message || 'Could not load the catalogue'));
  });

  // A blank first option rather than a pre-selected reason: defaulting to
  // "Damaged" would file every hurried write-off under the wrong cause, and a
  // wastage report is only worth reading if the causes are true.
  el('stock-reason').innerHTML =
    '<option value="">Choose one…</option>' +
    STOCK_REASONS.map((r) => `<option value="${r.value}">${escapeHtml(r.label)}</option>`).join('');

  el('stock-form').addEventListener('submit', submitStock);
  el('stock-delta').addEventListener('input', renderStockPreview);
  el('stock-modal-dismiss').addEventListener('click', closeStockDialog);
  el('stock-modal-overlay').addEventListener('click', closeStockDialog);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeStockDialog();
  });

  try {
    await Promise.all([loadCategories(), loadProducts()]);
  } catch (err) {
    showAlert(err.message || 'Failed to load products');
  }
}

boot();
