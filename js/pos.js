import { apiGet, apiPost, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';
import { changeDue, computeTotals, createCart } from './cart.js';

const cart = createCart();
let products = [];
let searchTimer = null;
let submitting = false;

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('pos.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('pos-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
}

function clearAlert() {
  const el = document.getElementById('pos-alert');
  if (!el) return;
  el.hidden = true;
  el.textContent = '';
}

/** Shop-floor money is PKR — unrelated to the USD the subscription is billed in. */
function formatRs(amount) {
  const n = Number(amount) || 0;
  return `Rs ${n.toLocaleString('en-PK', { maximumFractionDigits: 2 })}`;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  return div.innerHTML;
}

function el(id) {
  return document.getElementById(id);
}

/* ---------------------------------------------------------------- catalog */

function renderProducts() {
  const grid = el('pos-products');
  const empty = el('pos-products-empty');
  if (!grid) return;

  empty.hidden = products.length > 0;

  grid.innerHTML = products
    .map((p) => {
      const out = p.track_stock && p.stock_quantity <= 0;
      const stockLabel = p.track_stock ? `${p.stock_quantity} in stock` : 'Not tracked';
      return `
        <button type="button" class="pos-product${out ? ' is-out' : ''}"
                data-product-id="${p.id}" ${out ? 'disabled' : ''}>
          <span class="pos-product-name">${escapeHtml(p.name)}</span>
          <span class="pos-product-price">${formatRs(p.price)}</span>
          <span class="pos-product-stock${p.low_stock ? ' is-low' : ''}">${out ? 'Out of stock' : stockLabel}</span>
        </button>`;
    })
    .join('');

  grid.querySelectorAll('[data-product-id]').forEach((btn) => {
    btn.addEventListener('click', () => addToCart(Number(btn.dataset.productId)));
  });
}

async function loadProducts(search = '') {
  const params = new URLSearchParams({ active: '1', per_page: '60' });
  if (search) params.set('search', search);

  const data = await apiGet(`/api/products?${params.toString()}`);
  products = data.products || [];
  renderProducts();
  return products;
}

async function loadDaySummary() {
  const box = el('pos-day-summary');
  if (!box) return;
  try {
    const data = await apiGet('/api/sales/today');
    box.innerHTML = `
      <span>Today: <strong>${data.sales_count}</strong> sale${data.sales_count === 1 ? '' : 's'}</span>
      <span>Taken: <strong>${formatRs(data.total)}</strong></span>`;
  } catch {
    // A failed summary must never block selling.
    box.textContent = '';
  }
}

/* ------------------------------------------------------------------- cart */

function addToCart(productId) {
  const product = products.find((p) => p.id === productId);
  if (!product) return;

  const result = cart.add(product);
  if (!result.ok) {
    showAlert(result.reason);
    return;
  }

  clearAlert();
  renderTicket();
}

function renderTicket() {
  const list = el('pos-lines');
  const empty = el('pos-lines-empty');
  const lines = cart.toArray();

  empty.hidden = lines.length > 0;

  list.innerHTML = lines
    .map(
      (line) => `
      <li class="pos-line" data-line-id="${line.productId}">
        <div class="pos-line-main">
          <span class="pos-line-name">${escapeHtml(line.name)}</span>
          <span class="pos-line-unit">${formatRs(line.unitPrice)} each</span>
        </div>
        <div class="pos-line-qty">
          <button type="button" class="pos-qty-btn" data-step="-1" aria-label="Reduce quantity">−</button>
          <input class="pos-qty-input" type="number" min="0" step="1" value="${line.quantity}"
                 aria-label="Quantity for ${escapeHtml(line.name)}" />
          <button type="button" class="pos-qty-btn" data-step="1" aria-label="Increase quantity">+</button>
        </div>
        <span class="pos-line-total">${formatRs(line.lineTotal)}</span>
        <button type="button" class="pos-line-remove" aria-label="Remove ${escapeHtml(line.name)}">×</button>
      </li>`
    )
    .join('');

  list.querySelectorAll('.pos-line').forEach((row) => {
    const id = Number(row.dataset.lineId);
    const input = row.querySelector('.pos-qty-input');

    row.querySelectorAll('.pos-qty-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        applyQuantity(id, Number(input.value) + Number(btn.dataset.step));
      });
    });
    input.addEventListener('change', () => applyQuantity(id, input.value));
    row.querySelector('.pos-line-remove').addEventListener('click', () => {
      cart.remove(id);
      renderTicket();
    });
  });

  renderTotals();
}

function applyQuantity(productId, quantity) {
  const result = cart.setQuantity(productId, quantity);
  if (!result.ok) showAlert(result.reason);
  else clearAlert();
  renderTicket();
}

function renderTotals() {
  const totals = computeTotals(cart.toArray(), el('pos-discount').value);

  el('pos-subtotal').textContent = formatRs(totals.subtotal);
  el('pos-total').textContent = formatRs(totals.total);

  renderChange(totals.total);

  el('pos-complete').disabled = cart.isEmpty() || submitting;
}

function renderChange(total) {
  const isCash = el('pos-method').value === 'cash';
  el('pos-cash-fields').hidden = !isCash;

  const note = el('pos-change');
  const tendered = el('pos-tendered').value;

  if (!isCash || tendered === '') {
    note.textContent = '';
    note.dataset.state = '';
    return;
  }

  const change = changeDue(total, tendered);
  if (change === null) {
    note.textContent = 'Short — amount received is less than the total.';
    note.dataset.state = 'short';
  } else {
    note.textContent = `Change due: ${formatRs(change)}`;
    note.dataset.state = 'ok';
  }
}

/* ---------------------------------------------------------------- checkout */

async function completeSale() {
  if (cart.isEmpty() || submitting) return;

  const method = el('pos-method').value;
  const tenderedRaw = el('pos-tendered').value;
  const totals = computeTotals(cart.toArray(), el('pos-discount').value);

  if (method === 'cash' && tenderedRaw !== '' && changeDue(totals.total, tenderedRaw) === null) {
    showAlert('Amount received is less than the total due.');
    return;
  }

  submitting = true;
  el('pos-complete').disabled = true;
  clearAlert();

  try {
    const payload = {
      items: cart.toPayloadItems(),
      discount_amount: totals.discount,
      payment_method: method,
    };
    if (method === 'cash' && tenderedRaw !== '') {
      payload.amount_tendered = Number(tenderedRaw);
    }

    const data = await apiPost('/api/sales', payload);
    renderReceipt(data.sale);
    resetTicket();

    // Stock moved server-side, so pull fresh counts before the next sale.
    await Promise.all([loadProducts(el('pos-search').value.trim()), loadDaySummary()]);
  } catch (err) {
    showAlert(err.message || 'Could not complete the sale.');
  } finally {
    submitting = false;
    renderTotals();
  }
}

function resetTicket() {
  cart.clear();
  el('pos-discount').value = '0';
  el('pos-tendered').value = '';
  renderTicket();
}

function renderReceipt(sale) {
  const card = el('pos-receipt');
  const body = el('pos-receipt-body');
  if (!card || !body || !sale) return;

  const lines = (sale.items || [])
    .map(
      (item) => `
      <tr>
        <td>${escapeHtml(item.name)}</td>
        <td class="pos-receipt-num">${item.quantity}</td>
        <td class="pos-receipt-num">${formatRs(item.line_total)}</td>
      </tr>`
    )
    .join('');

  body.innerHTML = `
    <p class="pos-receipt-ref">Receipt <strong>${escapeHtml(sale.reference)}</strong></p>
    <table class="pos-receipt-table">
      <thead><tr><th>Item</th><th class="pos-receipt-num">Qty</th><th class="pos-receipt-num">Total</th></tr></thead>
      <tbody>${lines}</tbody>
    </table>
    <dl class="pos-totals">
      <div><dt>Subtotal</dt><dd>${formatRs(sale.subtotal)}</dd></div>
      ${sale.discount_amount > 0 ? `<div><dt>Discount</dt><dd>−${formatRs(sale.discount_amount)}</dd></div>` : ''}
      <div class="pos-total-row"><dt>Total</dt><dd>${formatRs(sale.total)}</dd></div>
      ${sale.amount_tendered !== null ? `<div><dt>Received</dt><dd>${formatRs(sale.amount_tendered)}</dd></div>` : ''}
      ${sale.change_due > 0 ? `<div><dt>Change</dt><dd>${formatRs(sale.change_due)}</dd></div>` : ''}
    </dl>`;

  card.hidden = false;
  card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/* -------------------------------------------------------------------- boot */

function wireSearch() {
  const input = el('pos-search');
  const form = el('pos-search-form');

  // Enter means "a scanner just submitted a full code": try an exact lookup and
  // put the item straight on the ticket, which is what the cashier expects.
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = input.value.trim();
    if (!code) return;

    try {
      const data = await apiGet(`/api/products/lookup?code=${encodeURIComponent(code)}`);
      if (!products.some((p) => p.id === data.product.id)) products.push(data.product);
      addToCart(data.product.id);
      input.value = '';
      await loadProducts('');
    } catch {
      // Not a known code — fall back to treating it as a search term.
      await loadProducts(code).catch(() => {});
    }
    input.focus();
  });

  input.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      loadProducts(input.value.trim()).catch((err) => showAlert(err.message || 'Search failed'));
    }, 250);
  });
}

async function boot() {
  initTheme();
  initShell({ current: 'pos' });
  if (!requireAuth()) return;

  wireSearch();

  el('pos-discount').addEventListener('input', renderTotals);
  el('pos-tendered').addEventListener('input', renderTotals);
  el('pos-method').addEventListener('change', renderTotals);
  el('pos-complete').addEventListener('click', completeSale);
  el('pos-clear').addEventListener('click', () => {
    resetTicket();
    clearAlert();
  });
  el('pos-new-sale').addEventListener('click', () => {
    el('pos-receipt').hidden = true;
    el('pos-search').focus();
  });
  el('pos-print').addEventListener('click', () => window.print());

  renderTicket();

  try {
    await Promise.all([loadProducts(), loadDaySummary()]);
  } catch (err) {
    showAlert(err.message || 'Failed to load products');
  }
}

boot();
