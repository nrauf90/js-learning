/**
 * The mobile khata — the credit notebook, read from behind the counter.
 *
 * Same three rules as the desktop customers.html:
 *
 * 1. "Who owes me" comes first and is totalled. This screen exists to answer
 *    how much of the shop is sitting on other people's shelves.
 * 2. Age is shown next to the amount. Rs 5,000 owed since last week and
 *    Rs 5,000 owed since March are completely different problems.
 * 3. A payment is a lump sum, not a line item. The API spreads it across the
 *    customer's oldest sales and answers with what it cleared — that answer is
 *    what gets read back over the counter.
 *
 * Names, phone numbers and notes are typed by hand at the till and played
 * straight back into this DOM, so everything interpolated goes through
 * escapeHtml().
 */

import { apiGet, apiPost } from './api.js';
import { initShell } from './shell.js';

/* ─────────────────────────────────────────────────────────────── state ── */

/* "Owes money" is the default because it is the question the page exists to
   answer; "all" is the address book behind it. */
let view = 'owing';
let page = 1;
let lastPage = 1;
let rows = [];
let searchTimer = null;

/* Search responses can land out of order once typing outruns the network;
   only the newest request may render. */
let listSeq = 0;

/* The khata page open in the sheet, so the payment form knows its target. */
let openCustomer = null;

const PER_PAGE = 25;

/* Wallet and bank settlements carry a transaction id the shop reconciles
   against; cash does not ask for one. */
const REFERENCE_METHODS = ['easypaisa', 'jazzcash', 'bank_transfer'];

/* The aging bands the API reports, in the order they are read. */
const AGING_BUCKETS = [
  ['days_0_30', '0–30d'],
  ['days_31_60', '31–60d'],
  ['days_61_90', '61–90d'],
  ['days_90_plus', '90d+'],
];

const ENTRY_LABELS = { sale: 'Credit sale', payment: 'Payment', refund: 'Goods returned' };

const METHOD_LABELS = {
  cash: 'Cash',
  card: 'Card',
  easypaisa: 'EasyPaisa',
  jazzcash: 'JazzCash',
  bank_transfer: 'Bank transfer',
  other: 'Other',
  credit: 'Udhaar',
};

/* ───────────────────────────────────────────────────────────── elements ── */

const $ = (id) => document.getElementById(id);
const el = {
  alert: $('khata-alert'),
  total: $('khata-total'),
  count: $('khata-count'),
  aging: $('khata-aging'),
  q: $('khata-q'),
  qClear: $('khata-q-clear'),
  list: $('khata-list'),
  empty: $('khata-empty'),
  more: $('khata-more'),
  sheet: $('khata-sheet'),
  close: $('khata-close'),
  name: $('khata-name'),
  sub: $('khata-sub'),
  owed: $('khata-owed'),
  facts: $('khata-facts'),
  payForm: $('khata-pay'),
  payDue: $('khata-pay-due'),
  payAmount: $('kp-amount'),
  payMethod: $('kp-method'),
  payRefField: $('kp-ref-field'),
  payRef: $('kp-ref'),
  payReceived: $('kp-received'),
  payLeft: $('kp-left'),
  payAlert: $('kp-alert'),
  paySubmit: $('kp-submit'),
  entries: $('khata-entries'),
  payments: $('khata-payments'),
};

/* ─────────────────────────────────────────────────────────────── helpers ── */

const rs = (n) => `Rs ${Number(n).toLocaleString('en-PK')}`;

function escapeHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
  );
}

function showAlert(message) {
  el.alert.hidden = false;
  el.alert.textContent = message;
}

function clearAlert() {
  el.alert.hidden = true;
  el.alert.textContent = '';
}

function errorText(err) {
  const body = err?.body;
  if (body?.errors) {
    const msgs = Object.values(body.errors).flat();
    if (msgs.length) return msgs.join(' ');
  }
  if (body?.message) return body.message;
  return 'Could not reach the server. Please try again.';
}

function methodLabel(method) {
  return METHOD_LABELS[method] || 'Paid';
}

/* "12 Mar, 2:30 pm" — the statement is read against the customer's memory of
   the visit, so it keeps the time the day-only list drops. */
function formatWhen(iso) {
  const date = iso ? new Date(iso) : null;
  if (!date || Number.isNaN(date.getTime())) return '';
  return date.toLocaleString('en-PK', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/* Whole days since `iso`, for the "… days old" hint beside the oldest debt. */
function daysSince(iso) {
  if (!iso) return null;
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return null;
  return Math.max(0, Math.floor((Date.now() - date.getTime()) / 86400000));
}

function tick() {
  try {
    navigator.vibrate?.(12);
  } catch {
    /* not supported — purely additive */
  }
}

function openSheet(node) {
  node.hidden = false;
  document.body.classList.add('sheet-open');
}

function closeSheet(node) {
  node.hidden = true;
  document.body.classList.remove('sheet-open');
}

function figureRow(label, value) {
  return `<div class="figure-row"><span>${escapeHtml(label)}</span><strong class="mono">${escapeHtml(value)}</strong></div>`;
}

/* ──────────────────────────────────────────────────────────── the list ── */

function renderSummary(data) {
  const debtors = Number(data?.debtors_count) || 0;
  el.total.textContent = rs(data?.total_outstanding ?? 0);
  el.count.textContent = `${debtors} customer${debtors === 1 ? '' : 's'} owe the shop`;

  const aging = data?.aging || {};
  el.aging.innerHTML = '';
  let any = false;
  for (const [key, label] of AGING_BUCKETS) {
    const amount = Number(aging[key]) || 0;
    if (amount <= 0) continue;
    any = true;
    const li = document.createElement('li');
    li.innerHTML = `<span>${escapeHtml(label)}</span><strong class="mono">${escapeHtml(rs(amount))}</strong>`;
    el.aging.appendChild(li);
  }
  el.aging.hidden = !any;
}

/* The one number on the row the owner has to act on, plus how long the oldest
   of it has been sitting — the two facts that decide the tap. */
function renderList() {
  el.list.innerHTML = '';

  for (const customer of rows) {
    const owed = Number(customer.balance) || 0;
    const days = daysSince(customer.oldest_debt_at);

    const li = document.createElement('li');
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'khata-row';
    b.innerHTML = `
      <span class="khata-row-who">
        <strong>${escapeHtml(customer.name)}</strong>
        <span>${escapeHtml(
          [
            customer.phone || 'No number',
            days !== null ? `oldest ${days} day${days === 1 ? '' : 's'}` : '',
            customer.is_active === false ? 'Inactive' : '',
          ]
            .filter(Boolean)
            .join(' · ')
        )}</span>
      </span>
      <span class="khata-row-owed${owed > 0 ? ' is-due' : ''}">${
        owed > 0 ? escapeHtml(rs(owed)) : 'Clear'
      }</span>`;
    b.addEventListener('click', () => openDetail(customer.id));
    li.appendChild(b);
    el.list.appendChild(li);
  }

  const noun = view === 'owing' ? 'Nobody owes the shop anything.' : 'No customers on the khata yet.';
  el.empty.hidden = rows.length > 0;
  el.empty.textContent = noun;

  /* "Load more" rather than page numbers: a thumb scrolls down, it does not
     aim at a pager. */
  el.more.hidden = page >= lastPage;
}

/* The search is a parameter on both views, never a filter over what came
   back: on a paged list, filtering the page on screen would hide every match
   that happens to sit on another one. */
async function loadList({ append = false } = {}) {
  const seq = ++listSeq;
  const nextPage = append ? page + 1 : 1;
  const term = el.q.value.trim();
  const params = new URLSearchParams({ page: String(nextPage), per_page: String(PER_PAGE) });
  if (term) params.set('search', term);

  const path = view === 'owing' ? '/api/customers/outstanding' : '/api/customers';

  try {
    const data = await apiGet(`${path}?${params.toString()}`);
    if (seq !== listSeq) return;

    if (view === 'owing') renderSummary(data);

    rows = append ? [...rows, ...(data.customers || [])] : data.customers || [];
    page = Number(data.meta?.current_page) || nextPage;
    lastPage = Number(data.meta?.last_page) || 1;

    clearAlert();
    renderList();
  } catch (err) {
    if (seq !== listSeq) return;
    showAlert(errorText(err));
  }
}

/* The totals are shop-wide, so they are refreshed after a write in either
   view — "all" rows do not carry them. */
async function loadSummary() {
  try {
    renderSummary(await apiGet('/api/customers/outstanding?per_page=1'));
  } catch {
    /* The list already reported whatever went wrong; a stale total is not
       worth a second alert over the same failure. */
  }
}

function setView(next) {
  view = next;
  rows = [];
  page = 1;
  lastPage = 1;

  document.querySelectorAll('[data-view]').forEach((button) => {
    const active = button.dataset.view === next;
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-pressed', String(active));
  });

  loadList();
}

/* ────────────────────────────────────────────────────────── the detail ── */

function renderFacts(customer) {
  const owed = Number(customer.balance) || 0;

  el.name.textContent = customer.name || '';
  el.sub.textContent = [customer.phone || 'No number on this page', customer.address]
    .filter(Boolean)
    .join(' · ');
  el.owed.textContent = rs(owed);

  el.facts.innerHTML = [
    figureRow(
      'Khata limit',
      customer.credit_limit === null || customer.credit_limit === undefined
        ? 'No limit'
        : rs(customer.credit_limit)
    ),
    customer.credit_available === null || customer.credit_available === undefined
      ? ''
      : figureRow('Limit left', rs(customer.credit_available)),
  ].join('');
}

/* One money column rather than the notebook's two: at this sheet's width a
   charge/paid split squeezes the entry text into wrapped lines. The sign
   carries the direction, and the label beside it says why. */
function renderStatement(entries) {
  el.entries.innerHTML = '';

  if (!entries.length) {
    const li = document.createElement('li');
    li.className = 'khata-entry-empty';
    li.textContent = 'No credit sales on this page yet.';
    el.entries.appendChild(li);
    return;
  }

  for (const entry of entries) {
    /* The API's own wording where it has one — "Deposit taken at the till"
       says more than "Payment" about why that row is there. */
    const label = entry.description || ENTRY_LABELS[entry.type] || entry.type;
    const meta = [entry.reference, entry.method ? methodLabel(entry.method) : '', entry.payment_reference]
      .filter(Boolean)
      .join(' · ');
    const charged = Number(entry.charge) > 0;
    const amount = charged ? entry.charge : entry.credit;

    const li = document.createElement('li');
    li.className = 'khata-entry';
    li.innerHTML = `
      <span class="khata-entry-what">
        <strong>${escapeHtml(label)}</strong>
        <span>${escapeHtml([formatWhen(entry.at), meta].filter(Boolean).join(' · '))}</span>
      </span>
      <span class="khata-entry-amt${charged ? ' is-due' : ''}">
        ${escapeHtml(`${charged ? '+' : '−'} ${rs(amount)}`)}
        <small class="mono">${escapeHtml(rs(entry.balance))}</small>
      </span>`;
    el.entries.appendChild(li);
  }
}

/* What has actually been paid against this page, newest first — the answer to
   "I paid you two thousand last week, what is left?", with the balance each
   payment left behind taken from the same pass as the statement so the two
   can never disagree. */
function renderPayments(payments) {
  el.payments.innerHTML = '';

  if (!payments.length) {
    const li = document.createElement('li');
    li.className = 'khata-entry-empty';
    li.textContent = 'Nothing paid against this khata yet.';
    el.payments.appendChild(li);
    return;
  }

  for (const payment of payments) {
    const left = Number(payment.balance_after) || 0;
    const tickets = (payment.allocations || []).map((a) => a.reference).filter(Boolean);
    const meta = [
      methodLabel(payment.method),
      payment.reference,
      payment.note && payment.note !== 'Khata payment' ? payment.note : '',
      tickets.length ? `on ${tickets.join(', ')}` : '',
    ]
      .filter(Boolean)
      .join(' · ');

    const li = document.createElement('li');
    li.className = 'khata-entry';
    li.innerHTML = `
      <span class="khata-entry-what">
        <strong>${escapeHtml(payment.received_by || 'Payment received')}</strong>
        <span>${escapeHtml([formatWhen(payment.at), meta].filter(Boolean).join(' · '))}</span>
      </span>
      <span class="khata-entry-amt">
        ${escapeHtml(rs(payment.amount))}
        <small class="mono">${escapeHtml(left > 0 ? rs(left) : 'Clear')}</small>
      </span>`;
    el.payments.appendChild(li);
  }
}

function paymentAlert(message, type = 'error') {
  el.payAlert.hidden = !message;
  el.payAlert.textContent = message || '';
  el.payAlert.dataset.type = type;
}

/* Only the methods that carry a transaction id ask for one. */
function syncReferenceField() {
  el.payRefField.hidden = !REFERENCE_METHODS.includes(el.payMethod.value);
}

/* The arithmetic of a part payment, spelled out before it is submitted: what
   is owed, what is being handed over, and what will still be owed when the
   customer walks away. */
function renderRemainder() {
  if (!openCustomer) return;
  const owed = Number(openCustomer.balance) || 0;
  const amount = Number(el.payAmount.value);

  if (!Number.isFinite(amount) || amount <= 0) {
    el.payLeft.textContent = 'Part payments are fine — enter whatever the customer hands over.';
    return;
  }

  /* Said here rather than left to the 422: the cashier is still holding the
     notes, and "that is more than they owe" is what they need to hear. */
  if (amount > owed + 0.001) {
    el.payLeft.textContent = `That is more than the ${rs(owed)} owed on this khata.`;
    return;
  }

  const left = Math.round((owed - amount) * 100) / 100;
  el.payLeft.textContent =
    left > 0
      ? `Paying ${rs(amount)} of ${rs(owed)} — ${rs(left)} will still be owed.`
      : `Paying ${rs(amount)} — this clears the khata.`;
}

function renderPaymentForm(customer) {
  const owed = Number(customer.balance) || 0;

  el.payForm.hidden = owed <= 0;
  if (el.payForm.hidden) return;

  el.payForm.reset();
  el.payDue.textContent = `${rs(owed)} outstanding. Payments clear the oldest sales first.`;

  /* Prefilled with the balance: settling in full is the common case, and it is
     also the largest amount the API will accept. */
  el.payAmount.value = String(owed);
  syncReferenceField();
  paymentAlert('');
  renderRemainder();
}

async function openDetail(id) {
  try {
    const data = await apiGet(`/api/customers/${id}/ledger`);
    openCustomer = data.customer;
    renderFacts(data.customer);
    renderStatement(data.entries || []);
    renderPayments(data.payments || []);
    renderPaymentForm(data.customer);
    openSheet(el.sheet);
  } catch (err) {
    showAlert(errorText(err));
  }
}

/* Reload the open page in place after money has moved against it. */
async function refreshDetail() {
  if (!openCustomer) return;
  const data = await apiGet(`/api/customers/${openCustomer.id}/ledger`);
  openCustomer = data.customer;
  renderFacts(data.customer);
  renderStatement(data.entries || []);
  renderPayments(data.payments || []);
  renderPaymentForm(data.customer);
}

/* The response says which tickets the money landed on, and that is what gets
   read back over the counter — "that clears the 14th and half of the 20th" is
   the sentence the customer is waiting for. */
function allocationMessage(data) {
  const allocations = data.allocations || [];
  const message = data.message || 'Payment recorded.';
  const cleared = allocations.filter((a) => a.payment_status === 'paid').length;

  if (cleared === 0) return message;
  return `${message} ${cleared} sale${cleared === 1 ? '' : 's'} settled in full.`;
}

async function submitPayment(event) {
  event.preventDefault();
  if (!openCustomer) return;

  const amount = Number(el.payAmount.value);
  const method = el.payMethod.value;
  const reference = el.payRef.value.trim();
  const receivedBy = el.payReceived.value.trim();

  if (!Number.isFinite(amount) || amount <= 0) {
    paymentAlert('Enter an amount greater than zero.');
    return;
  }

  el.paySubmit.disabled = true;
  el.paySubmit.dataset.busy = 'true';
  el.paySubmit.setAttribute('aria-busy', 'true');

  try {
    const payload = { amount, method };
    if (REFERENCE_METHODS.includes(method) && reference) payload.reference = reference;
    /* Left off entirely when blank: an empty string would be stored as a name
       nobody wrote down. */
    if (receivedBy) payload.received_by_name = receivedBy;

    const data = await apiPost(`/api/customers/${openCustomer.id}/payments`, payload);

    tick();
    await refreshDetail();
    paymentAlert(allocationMessage(data), 'success');

    /* The row's balance, the aging tiles and the totals all moved. */
    rows = [];
    await loadList();
  } catch (err) {
    /* The 422 names the exact balance ("Only Rs 500.00 is owed…"), which is
       the only thing that tells the cashier what to type instead. */
    paymentAlert(err.body?.errors?.amount?.[0] || errorText(err));
  } finally {
    el.paySubmit.disabled = false;
    el.paySubmit.dataset.busy = 'false';
    el.paySubmit.setAttribute('aria-busy', 'false');
  }
}

/* ──────────────────────────────────────────────────────────────── boot ── */

if (initShell({ current: 'khata' })) {
  document.querySelectorAll('[data-view]').forEach((button) => {
    button.addEventListener('click', () => setView(button.dataset.view));
  });

  el.q.addEventListener('input', () => {
    el.qClear.hidden = !el.q.value.trim();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadList(), 250);
  });
  el.qClear.addEventListener('click', () => {
    el.q.value = '';
    el.qClear.hidden = true;
    loadList();
    el.q.focus();
  });
  el.more.addEventListener('click', () => loadList({ append: true }));

  el.close.addEventListener('click', () => closeSheet(el.sheet));
  el.payMethod.addEventListener('change', syncReferenceField);
  el.payAmount.addEventListener('input', renderRemainder);
  el.payForm.addEventListener('submit', submitPayment);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeSheet(el.sheet);
  });

  loadList();
}
