/**
 * The khata — the credit notebook by the till.
 *
 * Three things this screen has to get right, because they are what the notebook
 * is for:
 *
 * 1. "Who owes me" comes first and is totalled. A shopkeeper opens this page to
 *    find out how much of the shop is sitting on other people's shelves.
 * 2. Age is shown next to the amount. Rs 5,000 owed since last week and Rs 5,000
 *    owed since March are completely different problems.
 * 3. A payment is a lump sum, not a line item. The customer puts money on the
 *    counter; the API spreads it across their oldest sales and answers with what
 *    it cleared, which is what gets read back to them.
 * 4. Part payments are the normal case, so the page shows what is left both
 *    before the payment is taken and after every payment already made — the
 *    running history is what settles an argument at the counter.
 *
 * Names, phone numbers and notes are typed by hand at the till and played
 * straight back into this DOM, so everything interpolated goes through
 * escapeHtml() — including the values that land inside attributes.
 */
import { apiDelete, apiGet, apiPost, apiPut, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';
import { paymentLabel, receiptDateTime, receiptNum, rememberShop, storedShop } from './receipt.js';
import { reminderText, reminderUrl, whatsappNumber } from './whatsapp.js';

/** The aging bands the API reports, in the order they are read. */
const AGING_BUCKETS = [
  ['days_0_30', '0–30 days'],
  ['days_31_60', '31–60 days'],
  ['days_61_90', '61–90 days'],
  ['days_90_plus', 'Over 90 days'],
];

/** Wallet and bank settlements carry a transaction id the shop reconciles against. */
const REFERENCE_METHODS = ['easypaisa', 'jazzcash', 'bank_transfer'];

const ENTRY_LABELS = { sale: 'Credit sale', payment: 'Payment', refund: 'Goods returned' };

let view = 'owing';
let searchTimer = null;
let currentPage = 1;

/**
 * One screen of the notebook per request. A khata with four hundred names on it
 * used to arrive whole and render whole, which is slow and unreadable — and the
 * search then only looked at what had already arrived.
 */
const PER_PAGE = 25;

/** The rows currently on screen, so the detail view can open one without a refetch. */
let rows = [];

/** The khata page the dialog is showing, so the payment form knows its target. */
let openCustomer = null;

/** The statement rows behind the open dialog — the printed sheet reuses them. */
let openEntries = [];

let editingId = null;

function el(id) {
  return document.getElementById(id);
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('customers.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const box = el('customers-alert');
  if (!box) return;
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearAlert() {
  const box = el('customers-alert');
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
}

/**
 * Every button class in this stylesheet sets `display`, and an author rule
 * outranks the browser's `[hidden] { display: none }` — so setting `hidden` on
 * a styled button leaves it sitting there. Both are set here rather than adding
 * a stylesheet rule for one button.
 */
function setHidden(node, hidden) {
  if (!node) return;
  node.hidden = hidden;
  node.style.display = hidden ? 'none' : '';
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text ?? '';
  // The text-node serialiser escapes &, < and > but leaves quotes alone, and
  // these values also land inside attributes (title, aria-label). A customer
  // named `x" onmouseover="…` would otherwise break straight out of one.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** PKR, formatted exactly as the receipt formats it — the two are read together. */
function formatRs(amount) {
  return `Rs ${receiptNum(amount)}`;
}

/** "12 Mar 2026" — a debt's age is a matter of days, never of minutes. */
function formatDay(iso) {
  if (!iso) return '—';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '—';
  return date.toLocaleDateString('en-PK', { day: '2-digit', month: 'short', year: 'numeric' });
}

/** Whole days since `iso`, for the "… days old" hint beside the oldest debt. */
function daysSince(iso) {
  if (!iso) return null;
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return null;
  return Math.max(0, Math.floor((Date.now() - date.getTime()) / 86400000));
}

/* ---------------------------------------------------------------- summary */

function renderSummary(data) {
  const grid = el('khata-summary');
  if (!grid) return;

  const debtors = Number(data?.debtors_count) || 0;
  const aging = data?.aging || {};

  const tiles = [
    `<div class="sales-stat">
       <span class="sales-stat-label">Total outstanding</span>
       <span class="sales-stat-value">${escapeHtml(formatRs(data?.total_outstanding))}</span>
       <span class="sales-stat-count">${debtors} customer${debtors === 1 ? '' : 's'}</span>
     </div>`,
    ...AGING_BUCKETS.map(
      ([key, label]) => `
      <div class="sales-stat">
        <span class="sales-stat-label">${escapeHtml(label)}</span>
        <span class="sales-stat-value">${escapeHtml(formatRs(aging[key]))}</span>
      </div>`
    ),
  ];

  grid.innerHTML = tiles.join('');
}

/* ------------------------------------------------------------------- list */

/**
 * The one number on the row the owner has to act on, plus how close the
 * customer is to a ceiling the shop set. A page with no ceiling says so rather
 * than showing a blank, because "no limit" is a decision too.
 */
function limitCellHTML(customer) {
  if (customer.credit_limit === null || customer.credit_limit === undefined) {
    return '<span class="admin-badge admin-badge-muted">No limit</span>';
  }

  const left = Number(customer.credit_available) || 0;
  const badge = left <= 0 ? 'admin-badge-danger' : 'admin-badge-info';

  return `<span class="admin-badge ${badge}">${escapeHtml(formatRs(left))}</span>`;
}

function oldestCellHTML(customer) {
  const days = daysSince(customer.oldest_debt_at);

  if (days === null) return '—';

  return `${escapeHtml(formatDay(customer.oldest_debt_at))} <small>${days} day${days === 1 ? '' : 's'} old</small>`;
}

/* ------------------------------------------------------------- reminders */

/**
 * A WhatsApp nudge is only as good as the number on the page — no number, no
 * button to press, just the reason why sitting on its tooltip.
 */
function remindButtonHTML(customer, dataAttr = '') {
  if (!whatsappNumber(customer.phone)) {
    return '<button type="button" class="btn btn-ghost pos-mini" disabled title="No phone number on this khata page">Remind</button>';
  }

  const attr = dataAttr ? ` ${dataAttr}="${escapeHtml(String(customer.id))}"` : '';
  return `<button type="button" class="btn btn-ghost pos-mini"${attr} title="Send a WhatsApp reminder">Remind</button>`;
}

/** Opens the shop's own WhatsApp with the message already typed — no API. */
function remindCustomer(customer) {
  if (!customer) return;

  const url = reminderUrl(
    customer.phone,
    reminderText({
      name: customer.name,
      shop: storedShop()?.name,
      balance: formatRs(Number(customer.balance) || 0),
    })
  );

  if (!url) {
    showAlert('This khata page has no phone number to remind.');
    return;
  }

  window.open(url, '_blank', 'noopener');
}

function renderRows() {
  const body = el('khata-body');
  if (!body) return;

  if (rows.length === 0) {
    body.innerHTML = `<tr><td colspan="7" class="admin-table-empty">${
      view === 'owing' ? 'Nobody owes the shop anything.' : 'No customers on the khata yet.'
    }</td></tr>`;
    return;
  }

  body.innerHTML = rows
    .map((customer) => {
      const owed = Number(customer.balance) || 0;
      const count = Number(customer.open_sales_count);

      return `
      <tr class="sales-row" data-customer-id="${escapeHtml(String(customer.id))}">
        <td>
          <button type="button" class="sales-ref">${escapeHtml(customer.name)}</button>
          ${customer.phone ? `<small>${escapeHtml(customer.phone)}</small>` : ''}
          ${customer.is_active ? '' : '<small>Inactive</small>'}
        </td>
        <td class="sales-num${owed > 0 ? ' is-due' : ''}">${owed > 0 ? escapeHtml(formatRs(owed)) : '—'}</td>
        <td class="sales-num">${Number.isFinite(count) ? escapeHtml(String(count)) : '—'}</td>
        <td class="sales-when">${oldestCellHTML(customer)}</td>
        <td class="sales-num">${limitCellHTML(customer)}</td>
        <td class="sales-status">${
          owed > 0
            ? '<span class="admin-badge admin-badge-warning">Owes</span>'
            : '<span class="admin-badge admin-badge-success">Clear</span>'
        }</td>
        <td>${remindButtonHTML(customer, 'data-remind')}</td>
      </tr>`;
    })
    .join('');
}

function searchTerm() {
  return el('customer-search').value.trim();
}

function renderPagination(meta) {
  const container = el('khata-pagination');
  if (!container) return;

  const page = Number(meta?.current_page) || 1;
  const lastPage = Number(meta?.last_page) || 1;
  const perPage = Number(meta?.per_page) || PER_PAGE;
  const total = Number(meta?.total) || 0;
  const first = total === 0 ? 0 : (page - 1) * perPage + 1;
  const noun = view === 'owing' ? 'with an open balance' : 'on the khata';

  el('khata-range').textContent =
    total === 0
      ? `No customers ${noun}`
      : `${first}–${Math.min(page * perPage, total)} of ${total} customer${total === 1 ? '' : 's'} ${noun}`;

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
    `<span class="admin-pagination-info">Page ${page} of ${lastPage} (${total} customers)</span>` +
    button(page < lastPage ? page + 1 : 0, 'Next');
}

/**
 * "Owes money" is the default because it is the question the page exists to
 * answer; "Everyone" is the address book behind it. Only the first view has
 * aging to show, so the summary is loaded with it and left alone otherwise —
 * the totals are shop-wide either way.
 *
 * The search is a parameter on both, never a filter over what came back: on a
 * paged list, filtering the page on screen would hide every match that happens
 * to sit on another one.
 */
async function loadList() {
  const term = searchTerm();
  const params = new URLSearchParams({ page: String(currentPage), per_page: String(PER_PAGE) });
  if (term) params.set('search', term);

  const path = view === 'owing' ? '/api/customers/outstanding' : '/api/customers';

  try {
    const data = await apiGet(`${path}?${params.toString()}`);

    if (view === 'owing') renderSummary(data);
    rows = data.customers || [];

    clearAlert();
    renderRows();
    renderPagination(data.meta);
  } catch (err) {
    showAlert(err.message || 'Could not load the khata');
  }
}

/** The summary is shop-wide, so it is refreshed after a write in either view. */
async function loadSummary() {
  try {
    renderSummary(await apiGet('/api/customers/outstanding'));
  } catch {
    // The list already reported whatever went wrong; a stale total is not
    // worth a second alert over the same failure.
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

function renderFacts(customer) {
  const box = el('khata-facts');
  if (!box) return;

  const owed = Number(customer.balance) || 0;

  box.innerHTML =
    factHTML('Owed', owed > 0 ? `<span class="is-due">${escapeHtml(formatRs(owed))}</span>` : '—') +
    factHTML(
      'Limit',
      customer.credit_limit === null || customer.credit_limit === undefined
        ? 'No limit'
        : escapeHtml(formatRs(customer.credit_limit))
    ) +
    (customer.credit_available === null || customer.credit_available === undefined
      ? ''
      : factHTML('Limit left', escapeHtml(formatRs(customer.credit_available)))) +
    (customer.phone ? factHTML('Phone', escapeHtml(customer.phone)) : '') +
    (customer.address ? factHTML('Address', escapeHtml(customer.address), 'sale-fact-wide') : '') +
    (customer.notes ? factHTML('Note', escapeHtml(customer.notes), 'sale-fact-wide') : '');
}

function renderLedger(entries) {
  const body = el('khata-ledger-body');
  if (!body) return;

  if (!entries || entries.length === 0) {
    body.innerHTML = '<tr><td colspan="4" class="admin-table-empty">Nothing on this page yet.</td></tr>';
    return;
  }

  body.innerHTML = entries
    .map((entry) => {
      // The API's own wording where it has one — "Deposit taken at the till"
      // says more than "Payment" about why that row is there.
      const label = entry.description || ENTRY_LABELS[entry.type] || entry.type;

      // Which ticket the money went on, how it arrived, and the wallet
      // transaction id if there is one to reconcile against.
      const meta = [entry.reference, entry.method ? paymentLabel(entry.method) : '', entry.payment_reference]
        .filter(Boolean)
        .join(' · ');

      const balance = Number(entry.balance) || 0;

      // One money column rather than the notebook's two: at this dialog's width
      // a charge/paid split squeezed the entry text into three wrapped lines.
      // The sign carries the direction, and the label beside it says why.
      const charged = entry.charge > 0;
      const amount = charged ? entry.charge : entry.credit;

      // A reversed payment still occupies its line — struck through — so the
      // page can answer "where did that payment go" instead of hiding it.
      const reversed = entry.reversed === true;
      const detail = reversed
        ? `${label} — reversed${entry.reversed_by ? ` by ${entry.reversed_by}` : ''}`
        : label;

      return `
      <tr${reversed ? ' class="is-reversed"' : ''}>
        <td class="sales-when">${escapeHtml(receiptDateTime(entry.at))}</td>
        <td>
          ${escapeHtml(detail)}
          ${meta ? `<small>${escapeHtml(meta)}</small>` : ''}
        </td>
        <td class="sales-num${charged ? ' is-due' : ''}">${escapeHtml(`${charged ? '+' : '−'} ${formatRs(amount)}`)}</td>
        <td class="sales-num${balance > 0 ? ' is-due' : ''}">${escapeHtml(formatRs(balance))}</td>
      </tr>`;
    })
    .join('');
}

/**
 * What has actually been paid against this page, newest first.
 *
 * The statement below it is the whole story; this is the answer to the one
 * question asked across the counter — "I paid you two thousand last week, what
 * is left?" — so the amount and the balance it left behind sit side by side,
 * with the name of whoever took the money next to them.
 */
function renderPayments(payments) {
  const body = el('khata-payments-body');
  if (!body) return;

  if (!payments || payments.length === 0) {
    body.innerHTML =
      '<tr><td colspan="5" class="admin-table-empty">Nothing paid against this khata yet.</td></tr>';
    return;
  }

  body.innerHTML = payments
    .map((payment) => {
      const left = Number(payment.balance_after) || 0;
      const reversed = payment.reversed === true;

      // A lump sum arrives as one line naming every ticket it cleared, which is
      // what gets read back: "that clears the 14th and half of the 20th".
      const tickets = (payment.allocations || []).map((a) => a.reference).filter(Boolean);

      const meta = [
        paymentLabel(payment.method),
        payment.reference,
        // The API's own wording where it says more than "payment" does.
        payment.note && payment.note !== 'Khata payment' ? payment.note : '',
        tickets.length ? `on ${tickets.join(', ')}` : '',
        payment.recorded_by ? `entered by ${payment.recorded_by}` : '',
        reversed && payment.reversed_by ? `reversed by ${payment.reversed_by}` : '',
      ]
        .filter(Boolean)
        .join(' · ');

      return `
      <tr${reversed ? ' class="is-reversed"' : ''}>
        <td class="sales-when">${escapeHtml(receiptDateTime(payment.at))}</td>
        <td>
          ${escapeHtml(payment.received_by || 'Not recorded')}
          ${meta ? `<small>${escapeHtml(meta)}</small>` : ''}
        </td>
        <td class="sales-num">${escapeHtml(formatRs(payment.amount))}</td>
        <td class="sales-num${left > 0 && !reversed ? ' is-due' : ''}">${
          reversed ? '—' : left > 0 ? escapeHtml(formatRs(left)) : 'Clear'
        }</td>
        <td>${
          reversed
            ? '<span class="admin-badge admin-badge-muted">Reversed</span>'
            : `<button type="button" class="btn btn-ghost pos-mini" data-reverse-payment="${escapeHtml(
                String(payment.id)
              )}" title="Take this payment back — the amount goes onto the khata again">Reverse</button>`
        }</td>
      </tr>`;
    })
    .join('');
}

function showPaymentAlert(message, type = 'error') {
  const box = el('khata-payment-alert');
  if (!box) return;
  box.hidden = false;
  box.textContent = message;
  box.dataset.type = type;
}

function clearPaymentAlert() {
  const box = el('khata-payment-alert');
  if (!box) return;
  box.hidden = true;
  box.textContent = '';
}

/** Only the methods that carry a transaction id ask for one. */
function toggleReferenceField() {
  const field = el('khata-reference-field');
  if (!field) return;
  field.hidden = !REFERENCE_METHODS.includes(el('khata-method').value);
}

/**
 * The arithmetic of a part payment, spelled out before it is submitted: what is
 * owed, what is being handed over, and what the customer will still owe when
 * they walk away. Typing a smaller number is the whole point of the form, so
 * the consequence of doing it has to be visible while it is being typed.
 */
function renderRemainder() {
  const line = el('khata-payment-left');
  if (!line || !openCustomer) return;

  const owed = Number(openCustomer.balance) || 0;
  const amount = Number(el('khata-amount').value);

  if (!Number.isFinite(amount) || amount <= 0) {
    line.textContent = 'Part payments are fine — enter whatever the customer hands over.';
    return;
  }

  // Said here rather than left to the 422: the cashier is still holding the
  // notes, and "that is more than they owe" is what they need to hear.
  if (amount > owed + 0.001) {
    line.textContent = `That is more than the ${formatRs(owed)} owed on this khata.`;
    return;
  }

  const left = Math.round((owed - amount) * 100) / 100;

  line.textContent =
    left > 0
      ? `Paying ${formatRs(amount)} of ${formatRs(owed)} — ${formatRs(left)} will still be owed.`
      : `Paying ${formatRs(amount)} — this clears the khata.`;
}

function renderPaymentForm(customer) {
  const form = el('khata-payment-form');
  if (!form) return;

  const owed = Number(customer.balance) || 0;

  form.reset();
  toggleReferenceField();

  // Nothing owed, nothing to collect — and the API would reject the attempt
  // anyway, so the form is not offered.
  form.hidden = owed <= 0;
  if (form.hidden) return;

  el('khata-payment-due').textContent = `${formatRs(owed)} outstanding on this khata. Payments clear the oldest sales first.`;

  // Prefilled with the balance: settling in full is the common case, and it is
  // also the largest amount the API will accept.
  const amount = el('khata-amount');
  amount.max = owed.toFixed(2);
  amount.value = owed.toFixed(2);

  renderRemainder();
}

function renderDetail(customer, entries, payments) {
  openCustomer = customer;
  openEntries = entries || [];

  el('khata-detail-title').textContent = customer.name;

  // Remind is only as useful as the number on the page — disabled, with the
  // reason on the tooltip, when there is none.
  const remind = el('khata-remind');
  if (remind) {
    remind.disabled = !whatsappNumber(customer.phone);
    remind.title = remind.disabled
      ? 'No phone number on this khata page'
      : 'Send a WhatsApp reminder';
  }

  renderFacts(customer);
  renderPayments(payments);
  renderLedger(openEntries);
  renderPaymentForm(customer);
}

function openModal() {
  const modal = el('khata-detail');
  if (!modal) return;
  modal.hidden = false;
  document.body.classList.add('sales-modal-open');
}

function closeModal() {
  const modal = el('khata-detail');
  if (!modal || modal.hidden) return;
  modal.hidden = true;
  document.body.classList.remove('sales-modal-open');
  openCustomer = null;
  openEntries = [];
}

async function openDetail(id) {
  try {
    const data = await apiGet(`/api/customers/${id}/ledger`);
    clearPaymentAlert();
    renderDetail(data.customer, data.entries || [], data.payments || []);
    openModal();
  } catch (err) {
    showAlert(err.message || 'Could not open that khata page');
  }
}

/** Reload the open page in place after money has moved against it. */
async function refreshDetail() {
  if (!openCustomer) return;

  const data = await apiGet(`/api/customers/${openCustomer.id}/ledger`);
  renderDetail(data.customer, data.entries || [], data.payments || []);
}

/* ---------------------------------------------------------------- payment */

/**
 * The response says which tickets the money landed on, and that is what gets
 * read back over the counter — "that clears the 14th and half of the 20th" is
 * the sentence the customer is waiting for.
 */
function allocationMessage(data) {
  const allocations = data.allocations || [];

  const message = data.message || 'Payment recorded.';
  const cleared = allocations.filter((a) => a.payment_status === 'paid').length;

  // A part payment settles nothing outright, and "0 sales settled in full" is a
  // worse answer than the API's own "Rs 1,500 still owed".
  if (cleared === 0) return message;

  return `${message} ${cleared} sale${cleared === 1 ? '' : 's'} settled in full.`;
}

async function submitPayment(event) {
  event.preventDefault();
  if (!openCustomer) return;

  const amount = Number(el('khata-amount').value);
  const method = el('khata-method').value;
  const reference = el('khata-reference').value.trim();
  const receivedBy = el('khata-received-by').value.trim();

  if (!Number.isFinite(amount) || amount <= 0) {
    showPaymentAlert('Enter an amount greater than zero.');
    return;
  }

  const button = el('khata-payment-submit');
  button.disabled = true;

  try {
    const payload = { amount, method };
    if (REFERENCE_METHODS.includes(method) && reference) payload.reference = reference;
    // Left off entirely when blank: an empty string would be stored as a name
    // nobody wrote down.
    if (receivedBy) payload.received_by_name = receivedBy;

    const data = await apiPost(`/api/customers/${openCustomer.id}/payments`, payload);

    await refreshDetail();
    showPaymentAlert(allocationMessage(data), 'success');

    // The row's balance, the aging tiles and the totals all moved.
    await Promise.all([loadList(), loadSummary()]);
  } catch (err) {
    // The 422 names the exact balance ("Only Rs 500.00 is owed…"), which is the
    // only thing that tells the cashier what to type instead.
    showPaymentAlert(err.body?.errors?.amount?.[0] || err.message || 'Could not record the payment');
  } finally {
    button.disabled = false;
  }
}

/**
 * Taking a payment back. A lump sum is one line on this page but several rows
 * on the server — the DELETE voids the whole group, and the line stays on the
 * page marked reversed rather than vanishing mid-argument.
 */
async function reversePayment(paymentId) {
  if (!openCustomer) return;

  if (!window.confirm('Reverse this payment? The amount goes back onto the khata.')) return;

  try {
    const data = await apiDelete(`/api/customers/${openCustomer.id}/payments/${paymentId}`);

    await refreshDetail();
    showPaymentAlert(data.message || 'Payment reversed.', 'success');

    // The row's balance, the aging tiles and the totals all moved back.
    await Promise.all([loadList(), loadSummary()]);
  } catch (err) {
    showPaymentAlert(err.body?.errors?.payment?.[0] || err.message || 'Could not reverse the payment');
  }
}

/* ------------------------------------------------------------ statement */

/**
 * The paper the customer is handed: the shop's letterhead, their name, and the
 * same running statement the dialog shows — charges down one side, payments
 * down the other, and what is left at the bottom. Built from the rows already
 * on screen so the print can never disagree with the page it came from.
 */
function statementHTML(customer, entries) {
  const shop = storedShop();
  const shopName = shop?.name || 'PK Galla';
  const shopLine = [shop?.address, shop?.phone].filter(Boolean).join(' · ');

  const rows = (entries || [])
    .map((entry) => {
      const meta = [
        entry.description || ENTRY_LABELS[entry.type] || entry.type,
        entry.method ? paymentLabel(entry.method) : '',
        entry.payment_reference || '',
        entry.reversed ? 'reversed' : '',
      ]
        .filter(Boolean)
        .join(' · ');

      return `
      <tr${entry.reversed ? ' class="is-reversed"' : ''}>
        <td>${escapeHtml(formatDay(entry.at))}</td>
        <td>${escapeHtml(entry.reference || '—')}</td>
        <td>${escapeHtml(meta)}</td>
        <td class="stmt-num">${entry.charge > 0 ? escapeHtml(receiptNum(entry.charge)) : ''}</td>
        <td class="stmt-num">${entry.credit > 0 ? escapeHtml(receiptNum(entry.credit)) : ''}</td>
        <td class="stmt-num">${escapeHtml(receiptNum(entry.balance))}</td>
      </tr>`;
    })
    .join('');

  const owed = Number(customer.balance) || 0;

  return `
    <p class="stmt-shop">${escapeHtml(shopName)}</p>
    ${shopLine ? `<p class="stmt-line">${escapeHtml(shopLine)}</p>` : ''}
    <p class="stmt-title">Khata statement</p>
    <p class="stmt-line">
      ${escapeHtml(customer.name)}${customer.phone ? ` · ${escapeHtml(customer.phone)}` : ''}
      · printed ${escapeHtml(formatDay(new Date().toISOString()))}
    </p>
    <table class="stmt-table">
      <thead>
        <tr><th>Date</th><th>Ref</th><th>Detail</th><th class="stmt-num">Charge</th><th class="stmt-num">Paid</th><th class="stmt-num">Balance</th></tr>
      </thead>
      <tbody>${rows || '<tr><td colspan="6">No entries.</td></tr>'}</tbody>
      <tfoot>
        <tr><td colspan="5">Total outstanding</td><td class="stmt-num">${escapeHtml(formatRs(owed))}</td></tr>
      </tfoot>
    </table>
    <p class="stmt-foot">Generated by PK Galla</p>`;
}

/**
 * `khata-printing` is the print-time switch: the sheet lives inside the open
 * dialog, and the flag tells the print stylesheet to show the sheet and hide
 * the rest of the modal around it.
 */
function printStatement() {
  const sheet = el('khata-statement');
  if (!openCustomer || !sheet) return;

  sheet.innerHTML = statementHTML(openCustomer, openEntries);
  document.body.classList.add('khata-printing');
  window.print();
}

/* ------------------------------------------------------- add / edit a page */

function resetForm() {
  editingId = null;
  el('customer-form').reset();
  el('customer-id').value = '';
  el('customer-active').checked = true;
  el('customer-form-title').textContent = 'Add customer';
  el('customer-submit').textContent = 'Add customer';
  setHidden(el('customer-cancel'), true);
}

function startEdit(customer) {
  if (!customer) return;

  editingId = customer.id;
  el('customer-id').value = customer.id;
  el('customer-name').value = customer.name ?? '';
  el('customer-phone').value = customer.phone ?? '';
  el('customer-address').value = customer.address ?? '';
  el('customer-limit').value = customer.credit_limit ?? '';
  el('customer-notes').value = customer.notes ?? '';
  el('customer-active').checked = customer.is_active !== false;

  el('customer-form-title').textContent = `Edit ${customer.name}`;
  el('customer-submit').textContent = 'Save changes';
  setHidden(el('customer-cancel'), false);

  el('customer-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function formValues() {
  const limit = el('customer-limit').value.trim();

  return {
    name: el('customer-name').value.trim(),
    phone: el('customer-phone').value.trim() || null,
    address: el('customer-address').value.trim() || null,
    // Empty clears the ceiling. Zero is left as zero on purpose: "no more
    // credit for this one" is a real instruction, not a missing value.
    credit_limit: limit === '' ? null : Number(limit),
    notes: el('customer-notes').value.trim() || null,
    is_active: el('customer-active').checked,
  };
}

async function submitCustomer(event) {
  event.preventDefault();
  clearAlert();

  const payload = formValues();

  if (!payload.name) {
    showAlert('A khata page needs a name.');
    return;
  }

  if (payload.credit_limit !== null && !Number.isFinite(payload.credit_limit)) {
    showAlert('Enter a credit limit in rupees, or leave it empty for no limit.');
    return;
  }

  const wasEditing = Boolean(editingId);

  try {
    if (editingId) {
      await apiPut(`/api/customers/${editingId}`, payload);
    } else {
      await apiPost('/api/customers', payload);
    }

    resetForm();
    await Promise.all([loadList(), loadSummary()]);
    showAlert(wasEditing ? 'Customer updated.' : 'Customer added.', 'success');
  } catch (err) {
    showAlert(err.body?.errors?.phone?.[0] || err.message || 'Could not save the customer');
  }
}

/* ----------------------------------------------------------------- wiring */

function setView(next) {
  view = next;
  // The two views are different lists; page three of one is not page three of
  // the other, and is often past the end of it.
  currentPage = 1;

  document.querySelectorAll('[data-view]').forEach((button) => {
    button.classList.toggle('active', button.dataset.view === next);
  });

  loadList();
}

function wireList() {
  document.querySelectorAll('[data-view]').forEach((button) => {
    button.addEventListener('click', () => setView(button.dataset.view));
  });

  el('customer-search').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      currentPage = 1;
      loadList();
    }, 250);
  });

  el('customer-search-clear').addEventListener('click', () => {
    el('customer-search').value = '';
    currentPage = 1;
    loadList();
  });

  // Delegated: the pager is re-rendered on every load, and per-button
  // listeners would leak one set each time.
  el('khata-pagination').addEventListener('click', (event) => {
    const button = event.target.closest('[data-page]');
    if (!button) return;
    currentPage = Number(button.dataset.page) || 1;
    loadList();
  });

  // Delegated: the list is re-rendered on every load, and per-row listeners
  // would leak one set each time.
  el('khata-body').addEventListener('click', (event) => {
    // A Remind press is not an open-the-page press.
    const remind = event.target.closest('[data-remind]');
    if (remind) {
      event.stopPropagation();
      remindCustomer(rows.find((c) => String(c.id) === remind.dataset.remind));
      return;
    }

    const row = event.target.closest('tr[data-customer-id]');
    if (!row) return;
    openDetail(Number(row.dataset.customerId));
  });
}

function wireModal() {
  el('khata-detail-close').addEventListener('click', closeModal);
  el('khata-detail-overlay').addEventListener('click', closeModal);

  el('khata-edit').addEventListener('click', () => {
    const customer = openCustomer;
    closeModal();
    startEdit(customer);
  });

  el('khata-remind').addEventListener('click', () => remindCustomer(openCustomer));
  el('khata-print').addEventListener('click', printStatement);
  window.addEventListener('afterprint', () => document.body.classList.remove('khata-printing'));

  // Delegated: the payment history re-renders on every refresh.
  el('khata-payments-body').addEventListener('click', (event) => {
    const button = event.target.closest('[data-reverse-payment]');
    if (button) reversePayment(Number(button.dataset.reversePayment));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeModal();
  });

  el('khata-method').addEventListener('change', toggleReferenceField);
  el('khata-amount').addEventListener('input', renderRemainder);
  el('khata-payment-form').addEventListener('submit', submitPayment);
}

/* -------------------------------------------------------------------- boot */

async function boot() {
  initTheme();
  initShell({ current: 'customers' });
  if (!requireAuth()) return;

  wireList();
  wireModal();

  // The statement and the reminder both carry the shop's name — keep the same
  // cache the receipt prints from warm.
  apiGet('/api/shop')
    .then((data) => rememberShop(data?.shop))
    .catch(() => {});

  el('customer-form').addEventListener('submit', submitCustomer);
  el('customer-cancel').addEventListener('click', resetForm);
  resetForm();

  await loadList();
}

boot();
