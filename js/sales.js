/**
 * Sales history: what the shop took, what it made on it, and what is still owed.
 *
 * Every figure here is money the owner will act on, so two things are load
 * bearing throughout:
 *
 * 1. Profit is only as good as the costs that were recorded. The API says so
 *    with `profit.is_complete`, and this page never hides that — see
 *    profitParts().
 * 2. Customer names, notes and payment references are typed by hand at the
 *    till and played straight back into this DOM, so everything interpolated
 *    goes through escapeHtml() — including the values that land in attributes.
 *
 * The sale detail is the same thermal slip the till printed, rendered by
 * js/receipt.js rather than rebuilt here: a reprint that does not match the
 * paper the customer is holding is worse than no reprint at all.
 */
import { apiGet, apiPost, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';
import { initPaperSelect, paymentLabel, receiptDateTime, receiptNum, receiptSlipHTML } from './receipt.js';

/** Matches the windows GET /api/sales/stats returns, in the order shown. */
const STAT_WINDOWS = [
  ['today', 'Today'],
  ['this_week', 'This week'],
  ['this_month', 'This month'],
  ['last_month', 'Last month'],
  ['this_year', 'This year'],
];

const PAYMENT_STATUS_LABELS = { paid: 'Paid', partial: 'Part paid', pending: 'Unpaid' };

const PAYMENT_STATUS_BADGES = {
  paid: 'admin-badge-success',
  partial: 'admin-badge-warning',
  pending: 'admin-badge-danger',
};

/** A refund is a fact about the sale, not about its payment, so it reads as a second badge. */
const SALE_STATUS_LABELS = { refunded: 'Refunded', partially_refunded: 'Part refunded' };

/** Wallet and bank settlements carry a transaction id the shop reconciles against. */
const REFERENCE_METHODS = ['easypaisa', 'jazzcash', 'bank_transfer'];

const PERIOD_LABELS = {
  '': 'All time',
  today: 'Today',
  week: 'This week',
  month: 'This month',
};

const filters = { period: 'today', date_from: '', date_to: '', category_id: '', payment_status: '' };

let currentPage = 1;

/** The sale the detail dialog is showing, so the payment form knows its target. */
let openSale = null;

function el(id) {
  return document.getElementById(id);
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('sales.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const box = el('sales-alert');
  if (!box) return;
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearAlert() {
  const box = el('sales-alert');
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  // The text-node serialiser escapes &, < and > but leaves quotes alone, and
  // these values also land inside attributes (title, data-*). A customer named
  // `x" onmouseover="…` would otherwise break straight out of one.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/**
 * PKR, formatted exactly as the receipt formats it — the list and the slip are
 * read side by side, so they cannot disagree about what 1,200.5 looks like.
 * Deliberately not the USD helper in js/billing.js: shop money and subscription
 * money are different currencies.
 */
function formatRs(amount) {
  return `Rs ${receiptNum(amount)}`;
}

/* ----------------------------------------------------------------- profit */

/**
 * Turns the API's profit object into something honest to show.
 *
 * `amount` only covers lines that had a cost recorded. When `is_complete` is
 * false the rest of the sale contributed `unknown_cost_revenue` in takings that
 * the margin says nothing about — so an incomplete figure is never rendered as
 * if it were exact, and when *nothing* had a cost there is no figure at all,
 * only the gap.
 *
 * @returns {{ text: string, title: string }} title is empty when the figure is exact.
 */
function profitParts(profit) {
  const amount = Number(profit?.amount) || 0;
  const unknown = Number(profit?.unknown_cost_revenue) || 0;

  if (profit?.is_complete !== false) {
    return { text: formatRs(amount), title: '' };
  }

  const gap = `${formatRs(unknown)} of takings came from items with no recorded cost.`;

  return amount === 0
    ? { text: 'Unknown', title: `Nothing here has a recorded cost, so the profit cannot be worked out. ${gap}` }
    : { text: formatRs(amount), title: `Partial — the margin on the items that do have a cost. ${gap}` };
}

/**
 * @param {object} profit the API's `{ amount, is_complete, unknown_cost_revenue }`
 * @param {string} [className] extra class for the caller's own sizing
 */
function profitHTML(profit, className = '') {
  const { text, title } = profitParts(profit);

  if (!title) {
    return `<span class="sales-profit ${escapeHtml(className)}">${escapeHtml(text)}</span>`;
  }

  // The asterisk carries the warning visually and the .profit-note repeats it
  // for screen readers, which never see a title attribute.
  return `<span class="sales-profit is-partial ${escapeHtml(className)}" title="${escapeHtml(title)}">${escapeHtml(text)}<span class="profit-flag" aria-hidden="true">*</span><span class="profit-note">${escapeHtml(title)}</span></span>`;
}

/**
 * The asterisk needs its footnote whenever *either* half of the screen is
 * showing one: a sale from an earlier year can be flagged in the list while
 * every stats window happens to be complete, and vice versa.
 */
const partialShown = { stats: false, list: false };

function markPartial(where, profits) {
  partialShown[where] = profits.some((p) => p?.is_complete === false);
  const legend = el('sales-legend');
  if (legend) legend.hidden = !partialShown.stats && !partialShown.list;
}

/* ------------------------------------------------------------------ stats */

function renderStats(stats) {
  const grid = el('sales-stats');
  if (!grid) return;

  grid.innerHTML = STAT_WINDOWS.map(([key, label]) => {
    const figures = stats?.[key] || {};
    const count = Number(figures.sales_count) || 0;

    return `
      <div class="sales-stat" data-stat="${escapeHtml(key)}">
        <span class="sales-stat-label">${escapeHtml(label)}</span>
        <span class="sales-stat-value" data-stat-total>${escapeHtml(formatRs(figures.sales_total))}</span>
        <span class="sales-stat-profit" data-stat-profit>
          <span class="sales-stat-profit-label">Profit</span>
          ${profitHTML(figures.profit, 'sales-stat-profit-value')}
        </span>
        <span class="sales-stat-count">${count} sale${count === 1 ? '' : 's'}</span>
      </div>`;
  }).join('');

  markPartial('stats', STAT_WINDOWS.map(([key]) => stats?.[key]?.profit));
}

async function loadStats() {
  try {
    const data = await apiGet('/api/sales/stats');
    renderStats(data.stats || {});
  } catch (err) {
    showAlert(err.message || 'Could not load the sales summary');
  }
}

/* ---------------------------------------------------------------- filters */

async function loadCategories() {
  const select = el('filter-category');
  if (!select) return;

  try {
    const data = await apiGet('/api/product-categories');
    const selected = select.value;
    select.innerHTML =
      '<option value="">All categories</option>' +
      (data.categories || [])
        .map((c) => `<option value="${escapeHtml(String(c.id))}">${escapeHtml(c.name)}</option>`)
        .join('');
    select.value = selected;
  } catch {
    // A missing category list only costs one filter; the sales themselves
    // still load, so this is not worth an alert over the whole page.
  }
}

function buildQuery() {
  const params = new URLSearchParams({ page: String(currentPage), per_page: '25' });

  if (filters.period === 'custom') {
    // The API requires both ends of a custom range and 422s without them, so
    // the pair is only ever sent together (see setCustomRange).
    params.set('period', 'custom');
    params.set('date_from', filters.date_from);
    params.set('date_to', filters.date_to);
  } else if (filters.period) {
    params.set('period', filters.period);
  }

  if (filters.category_id) params.set('category_id', filters.category_id);
  if (filters.payment_status) params.set('payment_status', filters.payment_status);

  return params.toString();
}

/** A `yyyy-mm-dd` from the date inputs, said the way the rest of the app says dates. */
function formatDay(value) {
  const date = new Date(`${value}T00:00:00`);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString('en-PK', { day: '2-digit', month: 'short', year: 'numeric' });
}

function periodDescription(meta) {
  const total = Number(meta?.total) || 0;
  const label =
    filters.period === 'custom'
      ? `${formatDay(filters.date_from)} – ${formatDay(filters.date_to)}`
      : PERIOD_LABELS[filters.period] ?? 'All time';

  return `${label} · ${total} sale${total === 1 ? '' : 's'}`;
}

/* ------------------------------------------------------------------- list */

function statusCellHTML(sale) {
  const paymentStatus = PAYMENT_STATUS_LABELS[sale.payment_status] || sale.payment_status || '—';
  const badge = PAYMENT_STATUS_BADGES[sale.payment_status] || 'admin-badge-muted';
  const refund = SALE_STATUS_LABELS[sale.status];

  return `
    <span class="admin-badge ${badge}">${escapeHtml(paymentStatus)}</span>
    ${refund ? `<span class="admin-badge admin-badge-muted sales-refund-badge">${escapeHtml(refund)}</span>` : ''}`;
}

function renderRows(sales) {
  const body = el('sales-body');
  if (!body) return;

  markPartial('list', (sales || []).map((sale) => sale.profit));

  if (!sales || sales.length === 0) {
    body.innerHTML = '<tr><td colspan="9" class="admin-table-empty">No sales match these filters.</td></tr>';
    return;
  }

  body.innerHTML = sales
    .map((sale) => {
      const outstanding = Number(sale.outstanding_amount) || 0;

      return `
      <tr class="sales-row" data-sale-id="${escapeHtml(String(sale.id))}">
        <td>
          <button type="button" class="sales-ref">${escapeHtml(sale.reference || `#${sale.id}`)}</button>
          ${sale.customer_name ? `<small>${escapeHtml(sale.customer_name)}</small>` : ''}
          ${sale.is_offline ? '<small>Recorded offline</small>' : ''}
        </td>
        <td class="sales-when">${escapeHtml(receiptDateTime(sale.sold_at))}</td>
        <td class="sales-num">${escapeHtml(String(sale.items_count ?? 0))}</td>
        <td class="sales-num">${escapeHtml(formatRs(sale.total))}</td>
        <td class="sales-num">${profitHTML(sale.profit)}</td>
        <td class="sales-num">${escapeHtml(formatRs(sale.paid_amount))}</td>
        <td class="sales-num${outstanding > 0 ? ' is-due' : ''}">${outstanding > 0 ? escapeHtml(formatRs(outstanding)) : '—'}</td>
        <td>${escapeHtml(paymentLabel(sale.payment_method))}</td>
        <td class="sales-status">${statusCellHTML(sale)}</td>
      </tr>`;
    })
    .join('');
}

function renderPagination(meta) {
  const container = el('sales-pagination');
  if (!container) return;

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
    `<span class="admin-pagination-info">Page ${meta.current_page} of ${meta.last_page} (${meta.total} sales)</span>` +
    button(meta.current_page < meta.last_page ? meta.current_page + 1 : 0, 'Next');
}

async function loadSales() {
  try {
    const data = await apiGet(`/api/sales?${buildQuery()}`);
    clearAlert();
    renderRows(data.sales || []);
    renderPagination(data.meta);

    const range = el('sales-range');
    if (range) range.textContent = periodDescription(data.meta);
  } catch (err) {
    showAlert(err.message || 'Could not load the sales list');
  }
}

/* ----------------------------------------------------------------- detail */

/** `value` is markup: every caller escapes its own text before handing it over. */
function factHTML(label, value, extraClass = '') {
  return `
    <div class="sale-fact ${escapeHtml(extraClass)}">
      <dt>${escapeHtml(label)}</dt>
      <dd>${value}</dd>
    </div>`;
}

function renderFacts(sale) {
  const box = el('sale-facts');
  if (!box) return;

  const outstanding = Number(sale.outstanding_amount) || 0;
  const status = PAYMENT_STATUS_LABELS[sale.payment_status] || sale.payment_status || '—';

  box.innerHTML =
    factHTML('Profit', profitHTML(sale.profit, 'sale-fact-profit')) +
    factHTML('Paid', escapeHtml(formatRs(sale.paid_amount))) +
    factHTML(
      'Pending',
      outstanding > 0 ? `<span class="is-due">${escapeHtml(formatRs(outstanding))}</span>` : '—'
    ) +
    factHTML(
      'Status',
      `<span class="admin-badge ${PAYMENT_STATUS_BADGES[sale.payment_status] || 'admin-badge-muted'}">${escapeHtml(status)}</span>`
    ) +
    (sale.customer_phone ? factHTML('Phone', escapeHtml(sale.customer_phone)) : '') +
    (sale.note ? factHTML('Note', escapeHtml(sale.note), 'sale-fact-wide') : '');
}

function showPaymentAlert(message, type = 'error') {
  const box = el('sale-payment-alert');
  if (!box) return;
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearPaymentAlert() {
  const box = el('sale-payment-alert');
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
}

/** Only the methods that carry a transaction id ask for one. */
function toggleReferenceField() {
  const field = el('payment-reference-field');
  if (!field) return;
  field.hidden = !REFERENCE_METHODS.includes(el('payment-method').value);
}

function renderPaymentForm(sale) {
  const form = el('sale-payment-form');
  if (!form) return;

  const outstanding = Number(sale.outstanding_amount) || 0;

  form.reset();
  toggleReferenceField();

  // Nothing owed, nothing to adjust — and the API would reject the attempt
  // anyway, so the form is not offered.
  form.hidden = outstanding <= 0;
  if (form.hidden) return;

  el('sale-payment-due').textContent = `${formatRs(outstanding)} still owed on this sale.`;

  // Prefilled with the balance: settling in full is by far the common case, and
  // it is also the largest amount the API will accept.
  const amount = el('payment-amount');
  amount.max = outstanding.toFixed(2);
  amount.value = outstanding.toFixed(2);
}

function renderDetail(sale) {
  openSale = sale;

  el('sale-detail-title').textContent = `Receipt ${sale.reference || `#${sale.id}`}`;

  // showPayments: this is a reprint, so the instalment history is the point of
  // it. No shopName is passed — receipt.js reads the same cached user the till
  // does, which is what keeps the two headings identical.
  el('sale-receipt').innerHTML = receiptSlipHTML(sale, {
    showPayments: true,
    copyLabel: 'Sales Receipt (Copy)',
  });

  renderFacts(sale);
  clearPaymentAlert();
  renderPaymentForm(sale);
}

function openModal() {
  const modal = el('sale-detail');
  if (!modal) return;
  modal.hidden = false;
  document.body.classList.add('sales-modal-open');
}

function closeModal() {
  const modal = el('sale-detail');
  if (!modal || modal.hidden) return;
  modal.hidden = true;
  document.body.classList.remove('sales-modal-open');
  openSale = null;
}

async function openDetail(id) {
  try {
    const data = await apiGet(`/api/sales/${id}`);
    renderDetail(data.sale);
    openModal();
  } catch (err) {
    showAlert(err.message || 'Could not open that sale');
  }
}

/* ---------------------------------------------------------------- payment */

async function submitPayment(event) {
  event.preventDefault();
  if (!openSale) return;

  const amount = Number(el('payment-amount').value);
  const method = el('payment-method').value;
  const reference = el('payment-reference').value.trim();

  if (!Number.isFinite(amount) || amount <= 0) {
    showPaymentAlert('Enter an amount greater than zero.');
    return;
  }

  const button = el('payment-submit');
  button.disabled = true;

  try {
    const payload = { amount, method };
    if (REFERENCE_METHODS.includes(method) && reference) payload.reference = reference;

    const data = await apiPost(`/api/sales/${openSale.id}/payments`, payload);

    // The response is the updated sale with its history, so the slip, the
    // balance and the form all come from the same round trip.
    renderDetail(data.sale);
    showPaymentAlert(data.message || 'Payment recorded.', 'success');

    // The row's paid/pending columns and the profit tiles both moved.
    await Promise.all([loadSales(), loadStats()]);
  } catch (err) {
    // The 422 names the exact balance ("Only 500.00 is outstanding…"), which is
    // the only thing that tells the cashier what to type instead.
    showPaymentAlert(err.body?.errors?.amount?.[0] || err.message || 'Could not record the payment');
  } finally {
    button.disabled = false;
  }
}

/* ------------------------------------------------------------------ wiring */

function setPeriod(period) {
  filters.period = period;
  filters.date_from = '';
  filters.date_to = '';
  el('filter-from').value = '';
  el('filter-to').value = '';

  document.querySelectorAll('.sales-period [data-period]').forEach((button) => {
    button.classList.toggle('active', button.dataset.period === period);
  });

  currentPage = 1;
  loadSales();
}

function setCustomRange() {
  const from = el('filter-from').value;
  const to = el('filter-to').value;

  if (!from || !to) {
    showAlert('Pick both a start and an end date for a custom range.');
    return;
  }

  clearAlert();
  filters.period = 'custom';
  filters.date_from = from;
  filters.date_to = to;

  // A custom range replaces the period buttons rather than adding to them.
  document.querySelectorAll('.sales-period [data-period]').forEach((button) => {
    button.classList.remove('active');
  });

  currentPage = 1;
  loadSales();
}

function wireFilters() {
  document.querySelectorAll('.sales-period [data-period]').forEach((button) => {
    button.addEventListener('click', () => setPeriod(button.dataset.period));
  });

  el('filter-range').addEventListener('click', setCustomRange);

  el('filter-category').addEventListener('change', (event) => {
    filters.category_id = event.target.value;
    currentPage = 1;
    loadSales();
  });

  el('filter-payment-status').addEventListener('change', (event) => {
    filters.payment_status = event.target.value;
    currentPage = 1;
    loadSales();
  });

  el('filter-reset').addEventListener('click', () => {
    filters.category_id = '';
    filters.payment_status = '';
    el('filter-category').value = '';
    el('filter-payment-status').value = '';
    clearAlert();
    setPeriod('today');
  });

  // Delegated: both lists are re-rendered on every load, and per-row listeners
  // would leak one set each time.
  el('sales-body').addEventListener('click', (event) => {
    const row = event.target.closest('tr[data-sale-id]');
    if (!row) return;
    openDetail(Number(row.dataset.saleId));
  });

  el('sales-pagination').addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) return;
    currentPage = Number(button.dataset.page) || 1;
    loadSales();
  });
}

function wireModal() {
  el('sale-detail-close').addEventListener('click', closeModal);
  el('sale-detail-overlay').addEventListener('click', closeModal);
  el('sale-print').addEventListener('click', () => window.print());

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal();
  });

  el('payment-method').addEventListener('change', toggleReferenceField);
  el('sale-payment-form').addEventListener('submit', submitPayment);
}

/* -------------------------------------------------------------------- boot */

async function boot() {
  initTheme();
  initShell({ current: 'sales' });
  if (!requireAuth()) return;

  // Same persisted roll width the till uses, so a reprint comes out on the
  // paper that is actually in the printer.
  initPaperSelect(el('sale-paper'));

  wireFilters();
  wireModal();

  // Independent requests: a failing summary should not cost the list, and each
  // loader reports its own problem.
  await Promise.all([loadStats(), loadCategories(), loadSales()]);
}

boot();
