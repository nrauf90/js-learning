/**
 * Thermal receipt slip — shared by the till (js/pos.js) and the sale detail
 * view (js/sales.js).
 *
 * It lives here rather than in pos.js because a reprinted receipt has to be
 * byte-identical to the one the customer was handed at the counter; two copies
 * of this markup would drift the first time either side changed.
 *
 * Sized for 80mm and 58mm rolls. See the `.receipt-slip` / `.slip-*` rules in
 * css/styles.css and the `@media print` block that strips everything else.
 */
import { formatQuantity, formatUnitPrice, isMeasured } from './units.js';

const PAPER_KEY = 'cashflow_receipt_paper';
const USER_KEY = 'cashflow_auth_user';

const PAYMENT_LABELS = {
  cash: 'Cash',
  card: 'Card',
  easypaisa: 'EasyPaisa',
  jazzcash: 'JazzCash',
  bank_transfer: 'Bank transfer',
  credit: 'On credit',
  other: 'Paid',
};

const STATUS_LABELS = {
  paid: 'PAID',
  partial: 'PART PAID',
  pending: 'UNPAID',
};

export function paymentLabel(method) {
  return PAYMENT_LABELS[method] || 'Paid';
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  return div.innerHTML;
}

/**
 * Bare number, no currency mark. Thermal rolls are 32–48 characters wide, so
 * repeating "Rs" on every line costs the item names; the currency is stated
 * once, on the total.
 */
export function receiptNum(amount) {
  return (Number(amount) || 0).toLocaleString('en-PK', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export function receiptDateTime(iso) {
  const date = iso ? new Date(iso) : new Date();
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString('en-PK', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function storedShopName() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null')?.name || 'PK Galla';
  } catch {
    return 'PK Galla';
  }
}

/**
 * Sets the physical page size for printing.
 *
 * `@page` cannot be selected by class, so the rule is rewritten rather than
 * toggled. Without an exact size and zero margins the driver falls back to A4
 * and the till spits out one near-empty page per sale.
 */
export function applyPaperWidth(mm) {
  document.body.dataset.paper = String(mm);

  let style = document.getElementById('receipt-print-page');
  if (!style) {
    style = document.createElement('style');
    style.id = 'receipt-print-page';
    document.head.appendChild(style);
  }
  style.textContent = `@page { size: ${mm}mm auto; margin: 0; }`;
}

/**
 * Roll width is a property of the till's printer, not of any one sale, so the
 * choice is persisted and reapplied on every page that can print.
 */
export function initPaperSelect(select) {
  const saved = localStorage.getItem(PAPER_KEY);

  if (select) {
    if (saved === '58' || saved === '80') select.value = saved;
    applyPaperWidth(select.value);

    select.addEventListener('change', () => {
      localStorage.setItem(PAPER_KEY, select.value);
      applyPaperWidth(select.value);
    });
    return;
  }

  applyPaperWidth(saved === '58' ? '58' : '80');
}

function itemsHTML(items) {
  // Quantity, unit price and line total each get their own column so the
  // customer can check the arithmetic on the paper without redoing it.
  return items
    .map((item) => {
      // Weighed and poured goods are stored per gram or millilitre but quoted
      // per kilo or litre, so the stored figures would print as "250 @ 0.25" —
      // arithmetic no customer can check. The API sends the spoken form ready
      // made; a sale replayed from the offline outbox was built at this till
      // and carries no labels at all, so the same wording is derived here.
      if (isMeasured(item)) {
        const quantity = item.quantity_label || formatQuantity(item.quantity, item.unit_type);
        const unitPrice = item.unit_price_label || formatUnitPrice(item.unit_price, item.price_unit);

        // "250 g @ Rs 250 / kg" will not sit in the 4ch quantity column of a
        // 58mm roll, so it takes the width under the name and only the amount
        // stays in the column the eye is already following down the slip.
        return `
      <div class="slip-item">
        <div class="slip-item-name">${escapeHtml(item.name)}</div>
        <div class="slip-row slip-item-measure">
          <span>${escapeHtml(`${quantity} @ ${unitPrice}`)}</span>
          <span class="slip-line">${receiptNum(item.line_total)}</span>
        </div>
      </div>`;
      }

      return `
      <div class="slip-item">
        <div class="slip-item-name">${escapeHtml(item.name)}</div>
        <div class="slip-item-calc">
          <span class="slip-qty">${escapeHtml(item.quantity)}</span>
          <span class="slip-unit">@ ${receiptNum(item.unit_price)}</span>
          <span class="slip-line">${receiptNum(item.line_total)}</span>
        </div>
      </div>`;
    })
    .join('');
}

/**
 * Instalment history. Only rendered on a reprint — at the counter the sale has
 * at most the one payment that just happened, and printing a one-row "history"
 * of it reads as a duplicate charge.
 */
function paymentsHTML(payments) {
  if (!payments || payments.length === 0) return '';

  const rows = payments
    .map(
      (p) => `
      <div class="slip-row">
        <span>${escapeHtml(receiptDateTime(p.paid_at))}</span>
        <span>${escapeHtml(paymentLabel(p.method))} ${receiptNum(p.amount)}</span>
      </div>`
    )
    .join('');

  return `
      <div class="slip-rule"></div>
      <div class="slip-row slip-col-head"><span>Payments</span><span></span></div>
      ${rows}`;
}

/**
 * The one line that says what money changed hands.
 *
 * On a settled sale it is the method and the amount. On an udhaar sale it is
 * the deposit — "On credit 100.00" reads as "a hundred rupees of credit" to the
 * customer holding the slip, when what happened is that a hundred was paid and
 * the rest went on the book. A deposit of nothing prints no line at all: the
 * balance due below is the whole story, and a row of zeroes only invites the
 * question of what it was for.
 */
function moneyLineHTML(isCredit, collected, method) {
  if (!isCredit) {
    return `<div class="slip-row"><span>${escapeHtml(paymentLabel(method))}</span><span>${receiptNum(collected)}</span></div>`;
  }

  if (Number(collected) <= 0) return '';

  return `<div class="slip-row"><span>Deposit paid</span><span>${receiptNum(collected)}</span></div>`;
}

/**
 * @param {object} sale  a sale payload from POST/GET /api/sales
 * @param {{ shopName?: string, showPayments?: boolean, copyLabel?: string }} [options]
 */
export function receiptSlipHTML(sale, options = {}) {
  const shopName = options.shopName || storedShopName();
  const items = sale.items || [];
  // A measured line has no piece count to add up — its quantity is 250 grams,
  // not 250 things — so weighed and poured goods count as the one line they
  // are and only counted goods contribute their pieces.
  const itemCount = items.reduce(
    (sum, item) => sum + (isMeasured(item) ? 1 : Number(item.quantity) || 0),
    0
  );

  // Tendered is null for anything but cash, where "what the customer handed
  // over" is just the total — an empty line there reads as a missing amount.
  const tendered = sale.amount_tendered === null || sale.amount_tendered === undefined
    ? sale.total
    : sale.amount_tendered;

  // A credit sale's money line is what has actually been collected, not the
  // total — printing the total next to "On credit" would read as settled.
  const isCredit = sale.payment_method === 'credit' || Number(sale.outstanding_amount) > 0;
  const collected = isCredit ? (sale.paid_amount ?? 0) : tendered;

  const outstanding = Number(sale.outstanding_amount) || 0;
  const status = sale.payment_status && STATUS_LABELS[sale.payment_status];

  return `
    <div class="receipt-slip">
      <p class="slip-shop">${escapeHtml(shopName)}</p>
      <p class="slip-sub">${escapeHtml(options.copyLabel || 'Sales Receipt')}</p>

      <div class="slip-rule"></div>
      <div class="slip-row"><span>Receipt</span><span>${escapeHtml(sale.reference || '—')}</span></div>
      <div class="slip-row"><span>Date</span><span>${escapeHtml(receiptDateTime(sale.sold_at))}</span></div>
      ${sale.customer_name ? `<div class="slip-row"><span>Customer</span><span>${escapeHtml(sale.customer_name)}</span></div>` : ''}

      <div class="slip-rule"></div>
      <div class="slip-item-calc slip-col-head"><span>Qty</span><span>Unit</span><span>Amount</span></div>
      <div class="slip-rule"></div>
      ${itemsHTML(items)}

      <div class="slip-rule"></div>
      <div class="slip-row"><span>Items</span><span>${itemCount}</span></div>
      <div class="slip-row"><span>Subtotal</span><span>${receiptNum(sale.subtotal)}</span></div>
      ${sale.discount_amount > 0 ? `<div class="slip-row"><span>Discount</span><span>-${receiptNum(sale.discount_amount)}</span></div>` : ''}
      ${sale.refunded_amount > 0 ? `<div class="slip-row"><span>Refunded</span><span>-${receiptNum(sale.refunded_amount)}</span></div>` : ''}
      ${
        Math.abs(Number(sale.rounding_adjustment) || 0) >= 0.005
          ? `<div class="slip-row"><span>Rounding</span><span>${Number(sale.rounding_adjustment) > 0 ? '+' : '-'}${receiptNum(Math.abs(Number(sale.rounding_adjustment)))}</span></div>`
          : ''
      }

      <div class="slip-rule"></div>
      <div class="slip-row slip-total"><span>TOTAL</span><span>Rs ${receiptNum(sale.total)}</span></div>
      <div class="slip-rule"></div>

      ${moneyLineHTML(isCredit, collected, sale.payment_method)}
      ${sale.change_due > 0 ? `<div class="slip-row"><span>Change</span><span>${receiptNum(sale.change_due)}</span></div>` : ''}
      ${outstanding > 0 ? `<div class="slip-row slip-due"><span>Balance due</span><span>${receiptNum(outstanding)}</span></div>` : ''}
      ${options.showPayments ? paymentsHTML(sale.payments) : ''}
      ${status ? `<div class="slip-rule"></div><p class="slip-status">${escapeHtml(status)}</p>` : ''}

      <div class="slip-rule"></div>
      <p class="slip-foot">Thank you for shopping!</p>
      <p class="slip-foot slip-foot-small">No exchange without this receipt</p>
    </div>`;
}
