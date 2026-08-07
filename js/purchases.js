/**
 * Stock In — the delivery van at the back door.
 *
 * This screen is where `products.cost` actually comes from. Before it existed
 * the cost was whatever someone last typed into the catalogue by hand, so most
 * sale lines carried no cost at all and every profit figure in the app read
 * close to zero.
 *
 * Two things are load bearing throughout:
 *
 * 1. Quantities and costs are typed, shown and sent in the product's own
 *    `price_unit` — "50 kg at Rs 180". The API divides them down to grams once,
 *    on the way in. Converting here as well would be a second opinion about the
 *    same number, and the two would drift.
 * 2. Supplier names, invoice numbers and notes are typed by hand and played
 *    straight back into this DOM, so everything interpolated goes through
 *    escapeHtml() — including the values that land inside attributes.
 *
 * The screen has two views. The list is where a delivery gets typed up; opening
 * one swaps in the invoice, which is read with the supplier's paper bill lying
 * next to the keyboard — hence a full-width view rather than a dialog, and hence
 * the line items, the money and the payment history all on one page.
 */
import { apiGet, apiPost, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';
import { paymentLabel, receiptDateTime, receiptNum } from './receipt.js';
import { priceToBase, shortOf, toBase } from './units.js';

let products = [];
let suppliers = [];
let currentPage = 1;
let searchTimer = null;

/** The delivery being typed up. One entry per product line. */
let lines = [];

/** Line identity, so a row survives a re-render without leaning on its index. */
let nextLineKey = 1;

/** The invoice the detail view is showing, so the payment form knows its target. */
let openPurchase = null;

const PAYMENT_STATUS_LABELS = { paid: 'Paid', partial: 'Part paid', unpaid: 'Unpaid' };

const PAYMENT_STATUS_BADGES = {
  paid: 'admin-badge-success',
  partial: 'admin-badge-warning',
  unpaid: 'admin-badge-danger',
};

/** Wallet and bank settlements carry a transaction id the shop reconciles against. */
const REFERENCE_METHODS = ['easypaisa', 'jazzcash', 'bank_transfer'];

function el(id) {
  return document.getElementById(id);
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('purchases.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const box = el('purchases-alert');
  if (!box) return;
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearAlert() {
  const box = el('purchases-alert');
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  // The text-node serialiser escapes &, < and > but leaves quotes alone, and
  // these values also land inside attributes (aria-label, data-*). A supplier
  // named `x" onmouseover="…` would otherwise break straight out of one.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * PKR, formatted the way the receipt and the sales list format it. Deliberately
 * not the USD helper in js/billing.js: shop money and subscription money are
 * different currencies.
 */
function formatRs(amount) {
  return `Rs ${receiptNum(amount)}`;
}

/**
 * Today in the shop's own clock. `toISOString()` would give the UTC date, which
 * in Pakistan is yesterday for the first five hours of every morning — the
 * delivery would be filed a day early.
 */
function todayValue() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  const day = String(now.getDate()).padStart(2, '0');
  return `${now.getFullYear()}-${month}-${day}`;
}

function numberOf(value) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

/* -------------------------------------------------------------- suppliers */

function renderSuppliers() {
  const select = el('purchase-supplier');
  const selected = select.value;

  select.innerHTML =
    '<option value="">No supplier</option>' +
    suppliers
      .map((s) => `<option value="${escapeHtml(String(s.id))}">${escapeHtml(s.name)}</option>`)
      .join('');

  select.value = selected;
}

async function loadSuppliers() {
  const data = await apiGet('/api/suppliers');
  suppliers = data.suppliers || [];
  renderSuppliers();
}

async function submitSupplier(event) {
  event.preventDefault();
  clearAlert();

  const name = el('supplier-name').value.trim();
  if (!name) {
    showAlert('Enter the supplier name.');
    return;
  }

  try {
    const saved = await apiPost('/api/suppliers', {
      name,
      phone: el('supplier-phone').value.trim() || null,
    });

    el('supplier-form').reset();
    await loadSuppliers();

    // Adding a supplier is almost always the first step of booking their
    // delivery in, so the new one is selected rather than left to be found.
    if (saved?.supplier?.id) el('purchase-supplier').value = String(saved.supplier.id);

    showAlert('Supplier added.', 'success');
  } catch (err) {
    showAlert(err.body?.errors?.name?.[0] || err.message || 'Could not save the supplier');
  }
}

/* --------------------------------------------------------------- products */

function renderProductOptions() {
  const select = el('purchase-product');
  const selected = select.value;

  select.innerHTML = products.length
    ? products
        .map(
          (p) =>
            `<option value="${escapeHtml(String(p.id))}">${escapeHtml(p.name)} — ${escapeHtml(p.unit_price_label)}</option>`
        )
        .join('')
    : '<option value="">No products match</option>';

  select.value = selected;
  // The filtered list may no longer hold the previous pick; fall back to the
  // first match so "Add item" always has something to add.
  if (!select.value && products.length) select.value = String(products[0].id);
}

async function loadProducts() {
  const params = new URLSearchParams({ per_page: '200' });
  const search = el('purchase-search').value.trim();
  if (search) params.set('search', search);

  const data = await apiGet(`/api/products?${params.toString()}`);
  products = data.products || [];
  renderProductOptions();
}

/* ------------------------------------------------------------------ lines */

/**
 * The line total, worked out exactly the way PurchaseService works it out: the
 * quoted cost is taken down to the base unit first, then multiplied by the
 * quantity in base units. Multiplying the two quoted figures instead would
 * agree most of the time and disagree on the awkward ones — Rs 1,000 a darjan
 * is Rs 83.3333 a piece — leaving the screen showing a total the saved
 * delivery does not have.
 */
function lineTotal(line) {
  const unit = line.product.price_unit;
  return round2(priceToBase(line.unitCost, unit) * toBase(line.quantity, unit));
}

function round2(value) {
  return Math.round((numberOf(value) + Number.EPSILON) * 100) / 100;
}

function totals() {
  const subtotal = round2(lines.reduce((sum, line) => sum + lineTotal(line), 0));
  const discount = Math.min(Math.max(numberOf(el('purchase-discount').value), 0), subtotal);
  const total = round2(subtotal - discount);
  const paid = Math.min(Math.max(numberOf(el('purchase-paid').value), 0), total);

  return { subtotal, discount, total, paid, outstanding: round2(total - paid) };
}

function renderTotals() {
  const { subtotal, total, outstanding } = totals();

  el('purchase-subtotal').textContent = formatRs(subtotal);
  el('purchase-total').textContent = formatRs(total);
  el('purchase-outstanding').textContent = formatRs(outstanding);

  lines.forEach((line) => {
    const row = el('purchase-lines').querySelector(`[data-line-key="${line.key}"]`);
    const cell = row?.querySelector('.pos-line-total');
    if (cell) cell.textContent = formatRs(lineTotal(line));
  });
}

function renderLines() {
  const list = el('purchase-lines');

  el('purchase-empty').hidden = lines.length > 0;

  list.innerHTML = lines
    .map((line) => {
      const unit = shortOf(line.product.price_unit);
      const name = escapeHtml(line.product.name);

      return `
      <li class="pos-line" data-line-key="${line.key}">
        <div class="pos-line-main">
          <span class="pos-line-name">${name}</span>
          <span class="pos-line-unit">In stock: ${escapeHtml(line.product.stock_label)}</span>
        </div>
        <div class="pos-line-qty">
          <input class="pos-qty-input" type="number" min="0" step="any" data-field="quantity"
                 value="${escapeHtml(String(line.quantity))}"
                 aria-label="${name}: quantity received in ${escapeHtml(unit)}" />
          <input class="pos-qty-input" type="number" min="0" step="any" data-field="unitCost"
                 value="${escapeHtml(String(line.unitCost))}"
                 aria-label="${name}: cost in PKR per ${escapeHtml(unit)}" />
        </div>
        <span class="pos-line-total">${escapeHtml(formatRs(lineTotal(line)))}</span>
        <button type="button" class="pos-line-remove" aria-label="Remove ${name}">&times;</button>
      </li>`;
    })
    .join('');

  renderTotals();
}

function addLine() {
  clearAlert();

  const productId = Number(el('purchase-product').value);
  const product = products.find((p) => p.id === productId);

  if (!product) {
    showAlert('Pick a product to add.');
    return;
  }

  const existing = lines.find((line) => line.product.id === product.id);

  // Already on the note — almost always a double tap rather than a genuine
  // second consignment, so the cursor goes to the line that is already there
  // instead of quietly stacking a duplicate under it.
  if (existing) {
    const row = el('purchase-lines').querySelector(`[data-line-key="${existing.key}"]`);
    row?.querySelector('[data-field="quantity"]')?.focus();
    return;
  }

  lines.push({
    key: nextLineKey++,
    product,
    quantity: 1,
    // Seeded with what this product cost last time, which is the figure the
    // shopkeeper is checking the new invoice against.
    unitCost: product.display_cost ?? 0,
  });

  renderLines();

  const row = el('purchase-lines').querySelector(`[data-line-key="${lines[lines.length - 1].key}"]`);
  row?.querySelector('[data-field="quantity"]')?.select();
}

function removeLine(key) {
  lines = lines.filter((line) => line.key !== key);
  renderLines();
}

/**
 * Typed figures are written back into the line without re-rendering the row —
 * replacing the input under the cursor would drop focus on every keystroke.
 */
function updateLine(event) {
  const input = event.target.closest('[data-field]');
  if (!input) return;

  const row = input.closest('[data-line-key]');
  const line = lines.find((l) => l.key === Number(row?.dataset.lineKey));
  if (!line) return;

  line[input.dataset.field] = input.value === '' ? 0 : numberOf(input.value);
  renderTotals();
}

/* ------------------------------------------------------------------- save */

function resetPurchaseForm() {
  lines = [];
  el('purchase-form').reset();
  el('purchase-date').value = todayValue();
  el('purchase-discount').value = '0';
  el('purchase-paid').value = '0';
  renderLines();
}

async function submitPurchase(event) {
  event.preventDefault();
  clearAlert();

  if (lines.length === 0) {
    showAlert('Add at least one item to the delivery.');
    return;
  }

  const empty = lines.find((line) => numberOf(line.quantity) <= 0);
  if (empty) {
    showAlert(`${empty.product.name}: enter a quantity greater than zero.`);
    return;
  }

  const { discount, paid } = totals();
  const supplierId = el('purchase-supplier').value;
  const button = el('purchase-submit');
  button.disabled = true;

  try {
    const saved = await apiPost('/api/purchases', {
      supplier_id: supplierId === '' ? null : Number(supplierId),
      invoice_number: el('purchase-invoice').value.trim() || null,
      purchase_date: el('purchase-date').value || null,
      note: el('purchase-note').value.trim() || null,
      discount_amount: discount,
      amount_paid: paid,
      items: lines.map((line) => ({
        product_id: line.product.id,
        quantity: numberOf(line.quantity),
        unit_cost: numberOf(line.unitCost),
      })),
    });

    resetPurchaseForm();

    // Stock and cost both moved, so the picker is reloaded alongside the list —
    // and an unpaid delivery is a new debt, so the payables move too.
    currentPage = 1;
    await Promise.all([loadProducts(), loadPurchases(), loadPayables()]);

    showAlert(`Stock in ${saved?.purchase?.reference || ''} saved.`.trim(), 'success');
  } catch (err) {
    // The 422 names the offending line ("Atta: enter a quantity…"), which is
    // the only thing that tells the shopkeeper what to change.
    showAlert(err.body?.errors?.items?.[0] || err.message || 'Could not save the delivery');
  } finally {
    button.disabled = false;
  }
}

/* ------------------------------------------------------------------- list */

function renderRows(purchases) {
  const body = el('purchases-body');

  if (!purchases || purchases.length === 0) {
    body.innerHTML = '<tr><td colspan="9" class="admin-table-empty">No deliveries recorded yet.</td></tr>';
    return;
  }

  body.innerHTML = purchases
    .map((purchase) => {
      const outstanding = numberOf(purchase.outstanding_amount);
      const status = PAYMENT_STATUS_LABELS[purchase.payment_status] || purchase.payment_status || '—';
      const badge = PAYMENT_STATUS_BADGES[purchase.payment_status] || 'admin-badge-muted';

      return `
      <tr class="sales-row" data-purchase-id="${escapeHtml(String(purchase.id))}">
        <td><button type="button" class="sales-ref">${escapeHtml(purchase.reference || `#${purchase.id}`)}</button></td>
        <td>${escapeHtml(formatDay(purchase.purchase_date))}</td>
        <td>${escapeHtml(purchase.supplier?.name || '—')}</td>
        <td>${escapeHtml(purchase.invoice_number || '—')}</td>
        <td class="sales-num">${escapeHtml(String(purchase.items_count ?? 0))}</td>
        <td class="sales-num">${escapeHtml(formatRs(purchase.total))}</td>
        <td class="sales-num">${escapeHtml(formatRs(purchase.amount_paid))}</td>
        <td class="sales-num${outstanding > 0 ? ' is-due' : ''}">${outstanding > 0 ? escapeHtml(formatRs(outstanding)) : '—'}</td>
        <td class="sales-status"><span class="admin-badge ${badge}">${escapeHtml(status)}</span></td>
      </tr>`;
    })
    .join('');
}

/** A `yyyy-mm-dd` said the way the rest of the app says dates. */
function formatDay(value) {
  if (!value) return '—';
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString('en-PK', { day: '2-digit', month: 'short', year: 'numeric' });
}

function renderPagination(meta) {
  const container = el('purchases-pagination');

  if (!meta || !meta.last_page || meta.last_page <= 1) {
    container.innerHTML = '';
    return;
  }

  const button = (page, label) =>
    page
      ? `<button type="button" class="admin-btn admin-btn-sm" data-page="${page}">${label}</button>`
      : `<button type="button" class="admin-btn admin-btn-sm" disabled>${label}</button>`;

  container.innerHTML =
    button(meta.current_page > 1 ? meta.current_page - 1 : 0, 'Previous') +
    `<span class="admin-pagination-info">Page ${meta.current_page} of ${meta.last_page} (${meta.total} deliveries)</span>` +
    button(meta.current_page < meta.last_page ? meta.current_page + 1 : 0, 'Next');
}

async function loadPurchases() {
  const data = await apiGet(`/api/purchases?page=${currentPage}&per_page=25`);
  renderRows(data.purchases || []);
  renderPagination(data.meta);

  const total = numberOf(data.meta?.total);
  el('purchases-range').textContent = `${total} ${total === 1 ? 'delivery' : 'deliveries'}`;
}

/* -------------------------------------------------- what I owe suppliers */

function statTileHTML(label, value, footnote = '') {
  return `
    <div class="sales-stat">
      <span class="sales-stat-label">${escapeHtml(label)}</span>
      <span class="sales-stat-value">${escapeHtml(value)}</span>
      ${footnote ? `<span class="sales-stat-count">${escapeHtml(footnote)}</span>` : ''}
    </div>`;
}

/**
 * The shop's own payables — the mirror of the khata's "who owes me". A
 * shopkeeper opens this page at the end of a month to find out how much of the
 * stock on his shelves has not been paid for yet, and to whom.
 */
function renderPayables(data) {
  const grid = el('payables-summary');
  const invoices = numberOf(data?.invoices_count);
  const suppliersOwed = data?.suppliers || [];

  grid.innerHTML = [
    statTileHTML(
      'Total owed',
      formatRs(data?.total_outstanding),
      `${invoices} invoice${invoices === 1 ? '' : 's'} open`
    ),
    statTileHTML(
      'Suppliers owed',
      String(suppliersOwed.length),
      data?.oldest_unpaid_date ? `Oldest bill ${formatDay(data.oldest_unpaid_date)}` : 'Nothing outstanding'
    ),
    // Named rather than only totalled: "who do I have to go and see" is the
    // second question, and it is the one that gets acted on.
    ...suppliersOwed
      .slice(0, 4)
      .map((s) =>
        statTileHTML(
          s.name,
          formatRs(s.outstanding),
          `${numberOf(s.invoices_count)} invoice${numberOf(s.invoices_count) === 1 ? '' : 's'}`
        )
      ),
  ].join('');

  const body = el('payables-body');
  const rows = data?.purchases || [];

  if (rows.length === 0) {
    body.innerHTML = '<tr><td colspan="6" class="admin-table-empty">Every supplier is paid up.</td></tr>';
    return;
  }

  body.innerHTML = rows
    .map(
      (purchase) => `
      <tr class="sales-row" data-purchase-id="${escapeHtml(String(purchase.id))}">
        <td><button type="button" class="sales-ref">${escapeHtml(purchase.reference || `#${purchase.id}`)}</button></td>
        <td>${escapeHtml(formatDay(purchase.purchase_date))}</td>
        <td>${escapeHtml(purchase.supplier?.name || '—')}</td>
        <td class="sales-num">${escapeHtml(formatRs(purchase.total))}</td>
        <td class="sales-num">${escapeHtml(formatRs(purchase.amount_paid))}</td>
        <td class="sales-num is-due">${escapeHtml(formatRs(purchase.outstanding_amount))}</td>
      </tr>`
    )
    .join('');
}

async function loadPayables() {
  renderPayables(await apiGet('/api/purchases/outstanding'));
}

/* ----------------------------------------------------------- the invoice */

/** `value` is markup: every caller escapes its own text before handing it over. */
function factHTML(label, value, extraClass = '') {
  return `
    <div class="sale-fact ${escapeHtml(extraClass)}">
      <dt>${escapeHtml(label)}</dt>
      <dd>${value}</dd>
    </div>`;
}

function statusBadgeHTML(purchase) {
  const status = PAYMENT_STATUS_LABELS[purchase.payment_status] || purchase.payment_status || '—';
  const badge = PAYMENT_STATUS_BADGES[purchase.payment_status] || 'admin-badge-muted';

  return `<span class="admin-badge ${badge}">${escapeHtml(status)}</span>`;
}

function renderDetailHeader(purchase) {
  el('purchase-detail-title').textContent = purchase.reference || `Delivery #${purchase.id}`;

  el('purchase-detail-subtitle').textContent =
    [purchase.supplier?.name, purchase.invoice_number, formatDay(purchase.purchase_date)]
      .filter(Boolean)
      .join(' · ') || 'Delivery';

  el('purchase-detail-facts').innerHTML =
    factHTML('Supplier', escapeHtml(purchase.supplier?.name || 'Not recorded')) +
    (purchase.supplier?.phone ? factHTML('Phone', escapeHtml(purchase.supplier.phone)) : '') +
    factHTML('Invoice no.', escapeHtml(purchase.invoice_number || '—')) +
    factHTML('Delivery date', escapeHtml(formatDay(purchase.purchase_date))) +
    factHTML('Reference', escapeHtml(purchase.reference || `#${purchase.id}`)) +
    factHTML('Status', statusBadgeHTML(purchase)) +
    (purchase.note ? factHTML('Note', escapeHtml(purchase.note), 'sale-fact-wide') : '');
}

/**
 * The goods, quoted the way the shop bought them. `quantity_label` and
 * `unit_cost_label` are the API's own words for the snapshotted units — "50 kg"
 * and "Rs 180 / kg" — so this table cannot disagree with the delivery note
 * about what arrived, which converting the base figures here would risk.
 */
function renderDetailItems(purchase) {
  const body = el('purchase-detail-items');
  const items = purchase.items || [];

  if (items.length === 0) {
    body.innerHTML = '<tr><td colspan="4" class="admin-table-empty">No lines on this delivery.</td></tr>';
    return;
  }

  body.innerHTML = items
    .map(
      (item) => `
      <tr>
        <td>${escapeHtml(item.name)}</td>
        <td class="sales-num">${escapeHtml(item.quantity_label ?? '')}</td>
        <td class="sales-num">${escapeHtml(item.unit_cost_label ?? '')}</td>
        <td class="sales-num">${escapeHtml(formatRs(item.line_total))}</td>
      </tr>`
    )
    .join('');
}

function renderDetailTotals(purchase) {
  const outstanding = numberOf(purchase.outstanding_amount);

  el('purchase-detail-subtotal').textContent = formatRs(purchase.subtotal);
  el('purchase-detail-discount').textContent = formatRs(purchase.discount_amount);
  el('purchase-detail-total').textContent = formatRs(purchase.total);
  el('purchase-detail-paid').textContent = formatRs(purchase.amount_paid);

  const owed = el('purchase-detail-outstanding');
  owed.textContent = formatRs(outstanding);
  owed.classList.toggle('is-due', outstanding > 0);
}

/**
 * Newest first, because the last payment is the one being checked against the
 * supplier's receipt. `balance_after` still comes from the API in the order the
 * money actually moved — recomputing it down a reversed list would print the
 * wrong figure against every row but one.
 */
function renderPaymentHistory(purchase) {
  const body = el('purchase-payments-body');
  const payments = [...(purchase.payments || [])].reverse();

  el('purchase-payments-range').textContent =
    `${payments.length} payment${payments.length === 1 ? '' : 's'}`;

  if (payments.length === 0) {
    body.innerHTML = '<tr><td colspan="7" class="admin-table-empty">Nothing paid on this invoice yet.</td></tr>';
    return;
  }

  body.innerHTML = payments
    .map((payment) => {
      const balance = numberOf(payment.balance_after);

      return `
      <tr>
        <td class="sales-when">${escapeHtml(receiptDateTime(payment.paid_at))}</td>
        <td class="sales-num">${escapeHtml(formatRs(payment.amount))}</td>
        <td>${escapeHtml(paymentLabel(payment.method))}</td>
        <td>${escapeHtml(payment.paid_by_name || '—')}</td>
        <td>${escapeHtml(payment.reference || '—')}</td>
        <td>${escapeHtml(payment.note || '—')}</td>
        <td class="sales-num${balance > 0 ? ' is-due' : ''}">${escapeHtml(formatRs(balance))}</td>
      </tr>`;
    })
    .join('');
}

/** Only the methods that carry a transaction id ask for one. */
function togglePaymentReferenceField() {
  el('purchase-payment-reference-field').hidden =
    !REFERENCE_METHODS.includes(el('purchase-payment-method').value);
}

function renderPaymentForm(purchase) {
  const form = el('purchase-payment-form');
  const outstanding = numberOf(purchase.outstanding_amount);

  form.reset();
  togglePaymentReferenceField();

  // Nothing owed, nothing to pay — and the API would reject the attempt anyway,
  // so the form is not offered.
  form.hidden = outstanding <= 0;
  if (form.hidden) return;

  el('purchase-payment-due').textContent =
    `${formatRs(outstanding)} still owed on this invoice. Pay it all now or put something against it.`;

  // Prefilled with the balance: clearing in full is the common case, and it is
  // also the largest amount the API will accept. Typing over it is one gesture,
  // which is what makes a part payment easy.
  const amount = el('purchase-payment-amount');
  amount.max = outstanding.toFixed(2);
  amount.value = outstanding.toFixed(2);
}

function showPaymentAlert(message, type = 'error') {
  const box = el('purchase-payment-alert');
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearPaymentAlert() {
  const box = el('purchase-payment-alert');
  box.hidden = true;
  box.textContent = '';
}

function renderDetail(purchase) {
  openPurchase = purchase;

  renderDetailHeader(purchase);
  renderDetailItems(purchase);
  renderDetailTotals(purchase);
  renderPaymentForm(purchase);
  renderPaymentHistory(purchase);
}

function showListView() {
  openPurchase = null;
  el('purchase-detail').hidden = true;
  el('purchases-list-view').hidden = false;
}

async function openDetail(id) {
  try {
    const data = await apiGet(`/api/purchases/${id}`);

    clearPaymentAlert();
    renderDetail(data.purchase);

    el('purchases-list-view').hidden = true;
    el('purchase-detail').hidden = false;
    // The list can be scrolled a long way down by the time a row is clicked,
    // and the invoice opens above the fold or not at all.
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (err) {
    showAlert(err.message || 'Could not open that delivery');
  }
}

async function submitPurchasePayment(event) {
  event.preventDefault();
  if (!openPurchase) return;

  const amount = numberOf(el('purchase-payment-amount').value);
  const method = el('purchase-payment-method').value;

  if (amount <= 0) {
    showPaymentAlert('Enter an amount greater than zero.');
    return;
  }

  const payload = {
    amount,
    method,
    paid_by_name: el('purchase-payment-paid-by').value.trim() || null,
    note: el('purchase-payment-note').value.trim() || null,
  };

  const reference = el('purchase-payment-reference').value.trim();
  if (REFERENCE_METHODS.includes(method) && reference) payload.reference = reference;

  const button = el('purchase-payment-submit');
  button.disabled = true;

  try {
    const data = await apiPost(`/api/purchases/${openPurchase.id}/payments`, payload);

    renderDetail(data.purchase);
    showPaymentAlert(data.message || 'Payment recorded.', 'success');

    // The row's paid and pending columns, and the payables totals, all moved.
    await Promise.all([loadPurchases(), loadPayables()]);
  } catch (err) {
    // The 422 names the exact balance ("Only Rs 25,000.00 is outstanding…"),
    // which is the only thing that tells the shopkeeper what to type instead.
    showPaymentAlert(err.body?.errors?.amount?.[0] || err.message || 'Could not record the payment');
  } finally {
    button.disabled = false;
  }
}

/* -------------------------------------------------------------------- boot */

function wire() {
  el('supplier-form').addEventListener('submit', submitSupplier);
  el('purchase-form').addEventListener('submit', submitPurchase);
  el('purchase-clear').addEventListener('click', resetPurchaseForm);
  el('purchase-add-line').addEventListener('click', addLine);

  el('purchase-discount').addEventListener('input', renderTotals);
  el('purchase-paid').addEventListener('input', renderTotals);

  // Delegated: the line list is re-rendered on every change, and per-row
  // listeners would leak one set each time.
  el('purchase-lines').addEventListener('input', updateLine);
  el('purchase-lines').addEventListener('click', (event) => {
    const button = event.target.closest('.pos-line-remove');
    if (!button) return;
    removeLine(Number(button.closest('[data-line-key]')?.dataset.lineKey));
  });

  el('purchase-search').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      loadProducts().catch((err) => showAlert(err.message || 'Search failed'));
    }, 250);
  });

  // The search box sits inside the delivery form, so Enter would otherwise
  // submit a half-typed note. Looking for a product is what Enter means here.
  el('purchase-search').addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    el('purchase-add-line').focus();
  });

  el('purchases-pagination').addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) return;
    currentPage = Number(button.dataset.page) || 1;
    loadPurchases().catch((err) => showAlert(err.message || 'Could not load deliveries'));
  });

  // Delegated for the same reason the line list is: both tables are re-rendered
  // on every load, and per-row listeners would leak one set each time.
  [el('purchases-body'), el('payables-body')].forEach((table) => {
    table.addEventListener('click', (event) => {
      const row = event.target.closest('tr[data-purchase-id]');
      if (!row) return;
      openDetail(Number(row.dataset.purchaseId));
    });
  });

  el('purchase-detail-back').addEventListener('click', showListView);
  el('purchase-payment-method').addEventListener('change', togglePaymentReferenceField);
  el('purchase-payment-form').addEventListener('submit', submitPurchasePayment);
}

async function boot() {
  initTheme();
  initShell({ current: 'purchases' });
  if (!requireAuth()) return;

  wire();
  el('purchase-date').value = todayValue();
  renderLines();

  try {
    await Promise.all([loadSuppliers(), loadProducts(), loadPurchases(), loadPayables()]);
  } catch (err) {
    showAlert(err.message || 'Failed to load the stock in screen');
  }
}

boot();
