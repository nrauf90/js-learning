/**
 * The mobile till.
 *
 * All the money and measurement logic is reused verbatim from the web app —
 * js/cart.js and js/units.js are free of DOM and network, and carry the unit
 * tests that prove the arithmetic. This file is only the screen.
 *
 * Layout and interaction decisions are recorded in docs/mobile-pos-design.md;
 * the two that matter most here are the inverted layout (§4) and the weigh pad
 * with no mode (§6).
 */

import { apiGet, apiPost } from './api.js';
import { initShell } from './shell.js';
import { changeDue, computeTotals, createCart, lineUnitLabel, money } from './cart.js';
import {
  QUICK_AMOUNTS,
  QUICK_VOLUMES,
  QUICK_WEIGHTS,
  TYPE_VOLUME,
  amountForQuantity,
  formatQuantity,
  formatUnitPrice,
  isMeasured,
  quantityForAmount,
} from './units.js';

/* Boot is started at the *bottom* of this file, not here. `const el = {...}`
   below is in the temporal dead zone until its declaration is evaluated, so
   calling boot() from up here throws a ReferenceError before a single handler
   is wired — and the screen still looks fine, because what renders is the
   static HTML. */

/* ─────────────────────────────────────────────────────────────── state ── */

const cart = createCart();
let products = [];
let dayOpen = false;
let padProduct = null;
let padDigits = '';

/**
 * How often this device has put each product on a ticket.
 *
 * The API has no "most sold" endpoint yet, so rather than fake one — or show an
 * arbitrary slice of the catalogue and call it "frequent" — this counts what
 * actually happens at this till. A kiryana sells roughly the same thirty things,
 * so after a day of use the common sale needs no search at all.
 */
const TALLY_KEY = 'pkgalla_product_tally';

function readTally() {
  try {
    return JSON.parse(localStorage.getItem(TALLY_KEY) || '{}');
  } catch {
    return {};
  }
}

function bumpTally(productId) {
  const t = readTally();
  t[productId] = (t[productId] || 0) + 1;
  try {
    localStorage.setItem(TALLY_KEY, JSON.stringify(t));
  } catch {
    /* Storage full or blocked — the tally is a convenience, never a blocker. */
  }
}

/* ───────────────────────────────────────────────────────────── elements ── */

const $ = (id) => document.getElementById(id);
const el = {
  day: $('till-day'),
  alert: $('till-alert'),
  lines: $('till-lines'),
  empty: $('till-empty'),
  count: $('till-count'),
  amount: $('till-amount'),
  frequent: $('till-frequent'),
  q: $('till-q'),
  qClear: $('till-q-clear'),
  results: $('till-results'),
  pay: $('till-pay'),
  clear: $('till-clear'),
  gate: $('day-gate'),
  gateNote: $('day-gate-note'),
  gateTitle: $('day-gate-title'),
  float: $('day-float'),
  openDay: $('day-open'),
  pad: $('pad'),
  padTitle: $('pad-title'),
  padRate: $('pad-rate'),
  padEntry: $('pad-entry'),
  padQtyFrom: $('pad-qty-from'),
  padQtyTo: $('pad-qty-to'),
  padAmtFrom: $('pad-amt-from'),
  padAmtTo: $('pad-amt-to'),
  padAsQty: $('pad-as-qty'),
  padAsAmount: $('pad-as-amount'),
  padChips: $('pad-chips'),
  padKeys: $('pad-keys'),
  padClose: $('pad-close'),
  paySheet: $('pay'),
  payTotal: $('pay-total'),
  payMethod: $('pay-method'),
  payCashField: $('pay-cash-field'),
  payTendered: $('pay-tendered'),
  payChange: $('pay-change'),
  payRefField: $('pay-ref-field'),
  payRef: $('pay-ref'),
  payAlert: $('pay-alert'),
  payConfirm: $('pay-confirm'),
  payClose: $('pay-close'),
};

/* ─────────────────────────────────────────────────────────────── helpers ── */

const rs = (n) => `Rs ${Number(n).toLocaleString('en-PK')}`;

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

/** Haptic confirmation: a busy shop swallows sound and the shopkeeper is
    looking at the scale, not the screen. */
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

/* ──────────────────────────────────────────────────────────── rendering ── */

function renderTicket() {
  const lines = cart.toArray();
  const totals = computeTotals(lines);

  el.empty.hidden = lines.length > 0;
  el.lines.innerHTML = '';

  for (const line of lines) {
    const li = document.createElement('li');
    li.className = 'till-line';
    li.innerHTML = `
      <span class="till-line-body">
        <strong>${escapeHtml(line.name)}</strong>
        <span class="till-line-sub">${escapeHtml(lineUnitLabel(line))}</span>
      </span>
      <span class="till-line-amt mono">${rs(line.lineTotal)}</span>
      <button type="button" class="till-line-x" aria-label="Remove ${escapeHtml(line.name)}">&times;</button>`;
    li.querySelector('.till-line-x').addEventListener('click', () => {
      cart.remove(line.productId);
      renderTicket();
    });
    /* Tapping the line re-opens the pad for measured goods — the bag went back
       on the scale, so re-weighing replaces rather than adds. */
    if (isMeasured({ unit_type: line.unitType })) {
      li.querySelector('.till-line-body').addEventListener('click', () => {
        const product = products.find((p) => p.id === line.productId);
        if (product) openPad(product, { replace: true });
      });
    }
    el.lines.appendChild(li);
  }

  /* Newest line is appended last and the container is bottom-anchored, so the
     most recent item sits nearest the thumb without a scroll call. */
  el.lines.scrollTop = el.lines.scrollHeight;

  const n = cart.count();
  el.count.textContent = `${Number.isInteger(n) ? n : n.toFixed(0)} ${n === 1 ? 'item' : 'items'}`;
  el.amount.textContent = rs(totals.total);

  const empty = cart.isEmpty();
  el.pay.disabled = empty || !dayOpen;
  el.clear.disabled = empty;
}

function escapeHtml(v) {
  return String(v ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
  );
}

function productButton(product) {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'till-pick';
  const price = isMeasured(product)
    ? formatUnitPrice(product.price, product.price_unit)
    : `${rs(money(product.price))}`;
  b.innerHTML = `<strong>${escapeHtml(product.name)}</strong><span>${escapeHtml(price)}</span>`;
  b.addEventListener('click', () => pick(product));
  return b;
}

const FREQUENT_SLOTS = 6;

function renderFrequent() {
  const tally = readTally();
  const ranked = [...products]
    .filter((p) => tally[p.id])
    .sort((a, b) => tally[b.id] - tally[a.id]);

  /*
   * Ranked items first, then the rest of the catalogue to fill the row.
   *
   * Showing only the ranked ones collapses the shelf to a single tile after the
   * first sale of the day, which is the opposite of useful — the point of this
   * row is that the common items are always one tap away, so it must stay full
   * whether or not there is any history yet.
   */
  const seen = new Set(ranked.map((p) => p.id));
  const list = [...ranked, ...products.filter((p) => !seen.has(p.id))].slice(0, FREQUENT_SLOTS);

  el.frequent.innerHTML = '';
  for (const p of list) {
    const li = document.createElement('li');
    li.appendChild(productButton(p));
    el.frequent.appendChild(li);
  }
}

function renderResults(term) {
  const q = term.trim().toLowerCase();
  el.qClear.hidden = !q;

  if (!q) {
    el.results.hidden = true;
    el.results.innerHTML = '';
    return;
  }

  /* Search never touches the network: the active catalogue is already in
     memory, and a request per keystroke at a counter is unusable. */
  const hits = products
    .filter((p) => p.haystack.includes(q))
    .slice(0, 20);

  el.results.innerHTML = '';
  if (!hits.length) {
    const li = document.createElement('li');
    li.className = 'till-noresult';
    li.textContent = `No product matches "${term}".`;
    el.results.appendChild(li);
  } else {
    for (const p of hits) {
      const li = document.createElement('li');
      li.appendChild(productButton(p));
      el.results.appendChild(li);
    }
  }
  el.results.hidden = false;
}

/* ──────────────────────────────────────────────────────── picking goods ── */

function pick(product) {
  clearAlert();
  if (isMeasured(product)) {
    openPad(product);
    return;
  }
  const res = cart.add(product);
  if (!res.ok) {
    showAlert(res.reason);
    return;
  }
  bumpTally(product.id);
  tick();
  renderTicket();
  renderFrequent();
  resetSearch();
}

function resetSearch() {
  el.q.value = '';
  renderResults('');
}

/* ───────────────────────────────────────────────────────────── the pad ── */

/**
 * One typed number, both readings shown live, and the shopkeeper taps the one
 * they meant. No tab, so there is no mode to be in the wrong one of.
 */
function openPad(product, { replace = false } = {}) {
  padProduct = { product, replace };
  padDigits = '';

  el.padTitle.textContent = product.name;
  el.padRate.textContent = formatUnitPrice(product.price, product.price_unit);

  const chips = product.unit_type === TYPE_VOLUME ? QUICK_VOLUMES : QUICK_WEIGHTS;
  el.padChips.innerHTML = '';
  for (const c of chips) {
    const li = document.createElement('li');
    const b = document.createElement('button');
    b.type = 'button';
    b.textContent = c.label;
    b.addEventListener('click', () => commitQuantity(c.base));
    li.appendChild(b);
    el.padChips.appendChild(li);
  }
  for (const amt of QUICK_AMOUNTS.slice(0, 4)) {
    const li = document.createElement('li');
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'chip-amount';
    b.textContent = `Rs ${amt}`;
    b.addEventListener('click', () => commitQuantity(quantityForAmount(amt, product.price)));
    li.appendChild(b);
    el.padChips.appendChild(li);
  }

  renderPad();
  openSheet(el.pad);
}

function renderPad() {
  const { product } = padProduct;
  const n = Number(padDigits || '0') || 0;
  el.padEntry.textContent = padDigits || '0';

  /* Reading A — the number is a quantity in base units. "250" is 250 grams,
     which is how a counter calls it out ("ek pao"). */
  const asQty = n;
  const asQtyMoney = amountForQuantity(asQty, product.price);
  el.padQtyFrom.textContent = formatQuantity(asQty, product.unit_type);
  el.padQtyTo.textContent = rs(asQtyMoney);

  /* Reading B — the number is rupees. "pachaas ka daal". */
  const asAmountQty = quantityForAmount(n, product.price);
  el.padAmtFrom.textContent = rs(n);
  el.padAmtTo.textContent = formatQuantity(asAmountQty, product.unit_type);

  const usable = n > 0;
  el.padAsQty.disabled = !usable;
  el.padAsAmount.disabled = !usable;
}

function commitQuantity(baseQuantity) {
  const { product, replace } = padProduct;
  const res = replace
    ? cart.setQuantity(product.id, baseQuantity)
    : cart.add(product, baseQuantity);

  if (!res.ok) {
    showAlert(res.reason);
    closeSheet(el.pad);
    return;
  }
  bumpTally(product.id);
  tick();
  closeSheet(el.pad);
  renderTicket();
  renderFrequent();
  resetSearch();
}

/* ──────────────────────────────────────────────────────────── day book ── */

async function loadDay() {
  try {
    const d = await apiGet('/api/day-book/current');
    dayOpen = Boolean(d.is_open);

    if (d.is_closed) {
      /* A closed day cannot be reopened — filing a second count would strand
         the first. Say so rather than offering a float box that will fail. */
      el.gateTitle.textContent = 'Day already closed';
      el.gateNote.textContent =
        'Today’s count has been filed. A new day opens tomorrow morning.';
      el.float.hidden = true;
      el.openDay.hidden = true;
      openSheet(el.gate);
      el.day.textContent = 'Day closed';
    } else if (!dayOpen) {
      openSheet(el.gate);
      el.day.textContent = 'Day not open';
    } else {
      closeSheet(el.gate);
      el.day.textContent = `Day open · ${rs(d.sales_total ?? 0)} today`;
    }
  } catch (err) {
    /* There are still customers at the counter, and losing the day's
       reconciliation beats shutting the shop — so the gate stays down and the
       failure is reported instead. Same call the desktop till makes. */
    dayOpen = true;
    closeSheet(el.gate);
    el.day.textContent = 'Day book unavailable';
    showAlert(`Day book unavailable — ${errorText(err)}`);
  }
  renderTicket();
}

async function openDay() {
  el.openDay.disabled = true;
  el.openDay.setAttribute('aria-busy', 'true');
  try {
    await apiPost('/api/day-book/open', { opening_amount: Number(el.float.value) || 0 });
    clearAlert();
    await loadDay();
  } catch (err) {
    showAlert(errorText(err));
  } finally {
    el.openDay.disabled = false;
    el.openDay.setAttribute('aria-busy', 'false');
  }
}

/* ─────────────────────────────────────────────────────────── catalogue ── */

async function loadProducts() {
  const all = [];
  /* Paged rather than one huge request; the desktop till caps at 25 pages and
     this follows it. A catalogue past that truncates silently — logged in the
     audit as a known limit. */
  for (let page = 1; page <= 25; page += 1) {
    const res = await apiGet(`/api/products?active=1&per_page=200&page=${page}`);
    const rows = res.data ?? res.products ?? [];
    all.push(...rows);
    const last = res.meta?.last_page ?? res.last_page ?? page;
    if (page >= last || rows.length === 0) break;
  }

  products = all.map((p) => ({
    ...p,
    price: Number(p.price) || 0,
    haystack: `${p.name ?? ''} ${p.sku ?? ''} ${p.barcode ?? ''}`.toLowerCase(),
  }));
}

/* ───────────────────────────────────────────────────────────── payment ── */

function syncPayFields() {
  const cash = el.payMethod.value === 'cash';
  el.payCashField.hidden = !cash;
  el.payRefField.hidden = cash;
  renderChange();
}

function renderChange() {
  const total = computeTotals(cart.toArray()).total;
  const raw = el.payTendered.value.trim();
  if (!raw) {
    el.payChange.hidden = true;
    return;
  }
  const back = changeDue(total, Number(raw));
  el.payChange.hidden = false;
  el.payChange.textContent =
    back === null
      ? `Short by Rs ${money(total - Number(raw))}.`
      : `Change: ${rs(back)}`;
  el.payChange.classList.toggle('is-short', back === null);
}

function openPay() {
  el.payAlert.hidden = true;
  el.payTendered.value = '';
  el.payRef.value = '';
  el.payTotal.textContent = rs(computeTotals(cart.toArray()).total);
  syncPayFields();
  openSheet(el.paySheet);
}

async function confirmPay() {
  el.payAlert.hidden = true;
  el.payConfirm.disabled = true;
  el.payConfirm.setAttribute('aria-busy', 'true');

  const method = el.payMethod.value;
  const tendered = el.payTendered.value.trim();

  const payload = {
    items: cart.toPayloadItems(),
    payment_method: method,
  };
  /* Leaving "diye" empty on a cash sale is normal — the exact note handed over
     is rarely typed — and the sale settles in full. */
  if (method === 'cash' && tendered) payload.amount_tendered = Number(tendered);
  if (method !== 'cash' && el.payRef.value.trim()) payload.payment_reference = el.payRef.value.trim();

  try {
    await apiPost('/api/sales', payload);
    cart.clear();
    closeSheet(el.paySheet);
    renderTicket();
    clearAlert();
    tick();
    await loadDay();
  } catch (err) {
    el.payAlert.hidden = false;
    el.payAlert.textContent = errorText(err);
  } finally {
    el.payConfirm.disabled = false;
    el.payConfirm.setAttribute('aria-busy', 'false');
  }
}

/* ──────────────────────────────────────────────────────────────── boot ── */

async function boot() {
  el.openDay.addEventListener('click', openDay);

  el.q.addEventListener('input', () => renderResults(el.q.value));
  el.qClear.addEventListener('click', () => {
    resetSearch();
    el.q.focus();
  });

  el.clear.addEventListener('click', () => {
    cart.clear();
    renderTicket();
  });

  el.padClose.addEventListener('click', () => closeSheet(el.pad));
  el.padKeys.addEventListener('click', (e) => {
    const k = e.target.closest('button')?.dataset.k;
    if (!k) return;
    if (k === 'del') padDigits = padDigits.slice(0, -1);
    else if (k === '.') padDigits = padDigits.includes('.') ? padDigits : `${padDigits || '0'}.`;
    /* Kept as a raw string so a half-typed "1." is not rounded away before the
       next digit lands. */
    else padDigits = (padDigits + k).replace(/^0(?=\d)/, '');
    renderPad();
  });
  el.padAsQty.addEventListener('click', () => commitQuantity(Number(padDigits || 0)));
  el.padAsAmount.addEventListener('click', () =>
    commitQuantity(quantityForAmount(Number(padDigits || 0), padProduct.product.price))
  );

  el.pay.addEventListener('click', openPay);
  el.payClose.addEventListener('click', () => closeSheet(el.paySheet));
  el.payMethod.addEventListener('change', syncPayFields);
  el.payTendered.addEventListener('input', renderChange);
  el.payConfirm.addEventListener('click', confirmPay);

  renderTicket();

  try {
    await loadProducts();
    renderFrequent();
  } catch (err) {
    showAlert(`Could not load products — ${errorText(err)}`);
  }

  await loadDay();
}

/* Everything above is declared by now, so it is safe to start. */
if (initShell({ current: 'sell' })) {
  boot();
}
