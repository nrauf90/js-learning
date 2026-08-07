import { apiGet, apiPost, getAuthToken } from './api.js';
import { initTheme } from './theme.js';
import { changeDue, computeTotals, createCart } from './cart.js';
import { initPaperSelect, paymentLabel, receiptSlipHTML } from './receipt.js';
import {
  QUICK_AMOUNTS,
  QUICK_VOLUMES,
  QUICK_WEIGHTS,
  TYPE_VOLUME,
  TYPE_WEIGHT,
  amountForQuantity,
  formatQuantity,
  formatUnitPrice,
  fromBase,
  isMeasured,
  quantityForAmount,
  toBase,
} from './units.js';

const USER_KEY = 'cashflow_auth_user';

const cart = createCart();

/** Whole active catalogue, held in memory so search never touches the network. */
let products = [];
/** One entry per rendered tile — the thing search actually toggles. */
let productTiles = [];

let categories = [];
let categoryButtons = [];
/** null means "All". */
let activeCategoryId = null;

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

/**
 * Text-node escaping leaves quotes alone — harmless between tags, but enough
 * to break out of an attribute. Anything interpolated into an attribute value
 * (a category image URL, a searchable name) goes through this instead.
 */
function escapeAttr(text) {
  return escapeHtml(text).replace(/"/g, '&quot;');
}

function el(id) {
  return document.getElementById(id);
}

/* --------------------------------------------------------------- categories */

function categoryLetter(name) {
  return (String(name || '').trim()[0] || '?').toUpperCase();
}

/**
 * The image slot is always drawn, with or without a picture, so the rail keeps
 * the same rhythm today (no `image_url` yet) and once the field starts coming
 * back from the API. A broken URL falls back to the letter tile as well — see
 * the `error` listener in renderCategories().
 */
function categoryThumbHTML(category) {
  const letter = escapeHtml(categoryLetter(category.name));
  const attrs = `class="pos-category-thumb" data-letter="${escapeAttr(categoryLetter(category.name))}"`;

  if (!category.image_url) {
    return `<span ${attrs} aria-hidden="true">${letter}</span>`;
  }
  return `<span ${attrs} aria-hidden="true"><img src="${escapeAttr(category.image_url)}" alt="" loading="lazy" /></span>`;
}

function categoryButtonHTML(category) {
  const id = category.id === null ? '' : String(category.id);
  return `
    <button type="button" class="pos-category-btn" data-category-id="${id}"
            data-name="${escapeAttr(category.name)}" aria-pressed="false">
      ${categoryThumbHTML(category)}
      <span class="pos-category-label">${escapeHtml(category.name)}</span>
      <span class="pos-rail-count">${category.count}</span>
    </button>`;
}

/**
 * Counts come from the in-memory catalogue rather than the API's
 * `products_count`, which also counts inactive products the till never shows.
 */
function countIn(categoryId) {
  return products.filter((p) => p.product_category_id === categoryId).length;
}

function renderCategories() {
  const list = el('pos-categories');
  if (!list) return;

  const entries = [
    { id: null, name: 'All', image_url: null, count: products.length },
    ...categories.map((c) => ({
      id: c.id,
      name: c.name,
      image_url: c.image_url || null,
      count: countIn(c.id),
    })),
  ];

  list.innerHTML = entries.map(categoryButtonHTML).join('');

  categoryButtons = [...list.querySelectorAll('.pos-category-btn')].map((node) => ({
    node,
    id: node.dataset.categoryId === '' ? null : Number(node.dataset.categoryId),
    label: (node.dataset.name || '').toLowerCase(),
  }));

  categoryButtons.forEach((btn) => {
    btn.node.addEventListener('click', () => selectCategory(btn.id));
  });

  list.querySelectorAll('.pos-category-thumb img').forEach((img) => {
    img.addEventListener('error', () => {
      const thumb = img.parentElement;
      img.remove();
      if (thumb) thumb.textContent = thumb.dataset.letter || '?';
    });
  });

  // A category can disappear while it is selected (deleted on another tab).
  if (activeCategoryId !== null && !categoryButtons.some((b) => b.id === activeCategoryId)) {
    activeCategoryId = null;
  }

  markActiveCategory();
  filterCategories();
}

function markActiveCategory() {
  categoryButtons.forEach((btn) => {
    const active = btn.id === activeCategoryId;
    btn.node.classList.toggle('is-active', active);
    btn.node.setAttribute('aria-pressed', String(active));
  });
}

function selectCategory(categoryId) {
  activeCategoryId = categoryId;
  markActiveCategory();
  filterProducts();
}

/** Filters the rail itself. "All" always stays — it is the way back. */
function filterCategories() {
  const term = (el('pos-category-search')?.value || '').trim().toLowerCase();
  let shown = 0;

  for (const btn of categoryButtons) {
    const match = btn.id === null || term === '' || btn.label.includes(term);
    btn.node.hidden = !match;
    if (match) shown += 1;
  }

  const empty = el('pos-categories-empty');
  if (empty) empty.hidden = term === '' || shown > 1;
}

async function loadCategories() {
  const data = await apiGet('/api/product-categories');
  categories = data.categories || [];
  renderCategories();
  return categories;
}

/* ------------------------------------------------------------------ catalog */

/** The API caps `per_page` at 200, so a big shop is paged through on boot. */
const PRODUCT_PAGE_SIZE = 200;
const MAX_PRODUCT_PAGES = 25;

/**
 * The labels come from the server when it answered, and are recomputed here
 * when it did not — the offline cache replays a catalogue that predates them,
 * and a tile reading "Rs 0.25" for daal would look like a pricing bug rather
 * than a per-gram figure the shopkeeper was never meant to see.
 */
function priceLabel(p) {
  return p.unit_price_label || formatUnitPrice(p.price, p.price_unit || 'pc');
}

function stockLabelFor(p) {
  if (!p.track_stock) return 'Not tracked';
  return `${p.stock_label || formatQuantity(p.stock_quantity, p.unit_type)} in stock`;
}

function productTileHTML(p) {
  const out = p.track_stock && p.stock_quantity <= 0;
  // Match on the same three fields the barcode lookup accepts, lower-cased
  // once here so every keystroke is a plain substring test.
  const haystack = [p.name, p.sku, p.barcode].filter(Boolean).join(' ').toLowerCase();

  return `
    <button type="button" class="pos-product${out ? ' is-out' : ''}${isMeasured(p) ? ' is-measured' : ''}"
            data-product-id="${p.id}"
            data-category-id="${p.product_category_id ?? ''}"
            data-search="${escapeAttr(haystack)}" ${out ? 'disabled' : ''}>
      <span class="pos-product-name">${escapeHtml(p.name)}</span>
      <span class="pos-product-price">${escapeHtml(priceLabel(p))}</span>
      <span class="pos-product-stock${p.low_stock ? ' is-low' : ''}">${out ? 'Out of stock' : escapeHtml(stockLabelFor(p))}</span>
    </button>`;
}

function renderProducts() {
  const grid = el('pos-products');
  if (!grid) return;

  grid.innerHTML = products.map(productTileHTML).join('');

  productTiles = [...grid.querySelectorAll('.pos-product')].map((node) => ({
    node,
    id: Number(node.dataset.productId),
    categoryId: node.dataset.categoryId === '' ? null : Number(node.dataset.categoryId),
    haystack: node.dataset.search || '',
  }));

  productTiles.forEach((tile) => {
    tile.node.addEventListener('click', () => addToCart(tile.id));
  });

  filterProducts();
}

/**
 * The whole point of holding the catalogue in memory: this runs on every
 * keystroke and only flips the `hidden` property on tiles that already exist,
 * so there is no request, no re-render and no debounce to wait out.
 */
function filterProducts() {
  const term = (el('pos-search')?.value || '').trim().toLowerCase();
  let shown = 0;

  for (const tile of productTiles) {
    const match =
      (activeCategoryId === null || tile.categoryId === activeCategoryId) &&
      (term === '' || tile.haystack.includes(term));
    tile.node.hidden = !match;
    if (match) shown += 1;
  }

  const empty = el('pos-products-empty');
  if (!empty) return;

  empty.hidden = shown > 0;
  empty.innerHTML =
    products.length === 0
      ? 'No products yet. <a href="products.html">Add your first product</a> to start selling.'
      : 'Nothing here matches that.';
}

async function loadProducts() {
  const collected = [];
  let page = 1;
  let lastPage = 1;

  do {
    const params = new URLSearchParams({
      active: '1',
      per_page: String(PRODUCT_PAGE_SIZE),
      page: String(page),
    });
    const data = await apiGet(`/api/products?${params.toString()}`);
    collected.push(...(data.products || []));
    lastPage = Number(data.meta?.last_page) || 1;
    page += 1;
  } while (page <= lastPage && page <= MAX_PRODUCT_PAGES);

  products = collected;
  renderProducts();
  // Per-category counts are derived from this list, so the rail follows it.
  if (categoryButtons.length) renderCategories();
  return products;
}

/* ---------------------------------------------------------------- day book */

/**
 * Latest `GET /api/day-book/current`. `null` means the till has never managed
 * to ask — see applyDayGate() for why that one state stays unlocked.
 */
let dayBook = null;

/**
 * Laravel answers a bad open/close with a 422 keyed on the field, and the
 * message under that key is the one written for a cashier ("The till was never
 * opened today.", "Ali has a khata limit of Rs 5,000 and already owes…").
 * Prefer it over the envelope so the dialog says what actually went wrong
 * instead of "Request failed (422)".
 *
 * Several fields are accepted because one refusal can arrive under any of
 * them — a credit sale is turned down for the limit, the name or the deposit —
 * and the cashier needs whichever one names the figures.
 */
function fieldError(err, ...fields) {
  for (const field of fields) {
    const messages = err?.body?.errors?.[field];
    if (Array.isArray(messages) && messages.length) return messages[0];
  }
  return err?.message || 'Something went wrong. Please try again.';
}

/** Barred whenever a day book exists but is not open — never opened, or closed. */
function sellingBlocked() {
  return dayBook !== null && !dayBook.is_open;
}

function round2(n) {
  return Math.round(n * 100) / 100;
}

function figureRow(label, value, modifier = '') {
  return `<div class="${modifier}"><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`;
}

/** Positive means the drawer holds more than the till accounted for. */
function varianceState(variance) {
  if (Math.abs(variance) < 0.005) return 'balanced';
  return variance > 0 ? 'over' : 'short';
}

/**
 * Spelled out rather than signed: "Rs -450" on a screen read at speed is easy
 * to take for another total, and the sign is the entire message.
 */
function varianceText(variance) {
  const state = varianceState(variance);
  if (state === 'balanced') return 'Balanced — the count matches what the till expected.';
  if (state === 'over') return `Over by ${formatRs(variance)} — more cash than expected.`;
  return `Short by ${formatRs(Math.abs(variance))} — less cash than expected.`;
}

function paintVariance(node, variance) {
  if (!node) return;
  node.textContent = varianceText(variance);
  node.dataset.state = varianceState(variance);
}

function setDialogError(id, message) {
  const box = el(id);
  if (!box) return;
  box.hidden = !message;
  box.textContent = message || '';
}

/**
 * The topbar carries the whole day: open or not, the float it started on, and
 * what should be in the drawer right now. Takings come from the same payload as
 * the drawer figures, so the two can never drift apart the way two separate
 * endpoints could.
 */
function renderDaySummary() {
  const box = el('pos-day-summary');
  const closeBtn = el('pos-close-day');
  if (closeBtn) closeBtn.hidden = !dayBook?.is_open;
  if (!box) return;

  if (!dayBook) {
    box.textContent = '';
    return;
  }

  const day = dayBook.day_book;
  const count = Number(dayBook.sales_count) || 0;
  const state = dayBook.is_closed ? 'closed' : dayBook.is_open ? 'open' : 'none';
  const label = { closed: 'Day closed', open: 'Day open', none: 'Day not opened' }[state];

  let drawer = '';
  if (state === 'open') {
    drawer = `<span>In drawer: <strong>${formatRs(dayBook.expected_cash)}</strong></span>`;
  } else if (state === 'closed') {
    drawer = `<span>Counted: <strong>${formatRs(day?.closing_amount ?? 0)}</strong></span>`;
  }

  box.innerHTML = `
    <span class="pos-day-state" data-state="${state}">${label}</span>
    <span>Today: <strong>${count}</strong> sale${count === 1 ? '' : 's'}</span>
    <span>Taken: <strong>${formatRs(dayBook.sales_total)}</strong></span>
    ${day ? `<span>Float: <strong>${formatRs(dayBook.opening_amount)}</strong></span>` : ''}
    ${drawer}`;
}

function renderClosedNotice() {
  const day = dayBook?.day_book;
  const list = el('pos-closed-figures');

  if (list) {
    list.innerHTML = day
      ? [
          figureRow('Opening float', formatRs(day.opening_amount)),
          figureRow('Expected in drawer', formatRs(day.expected_amount ?? 0)),
          figureRow('Counted', formatRs(day.closing_amount ?? 0), 'is-total'),
        ].join('')
      : '';
  }

  paintVariance(el('pos-closed-variance'), Number(day?.variance) || 0);
}

function showGate(mode) {
  const gate = el('pos-day-gate');
  const form = el('pos-open-form');
  const closed = el('pos-gate-closed');
  if (!gate) return;

  form.hidden = mode !== 'open';
  closed.hidden = mode !== 'closed';
  el('pos-gate-title').textContent = mode === 'closed' ? 'Day closed' : 'Open the till';

  if (mode === 'closed') renderClosedNotice();
  else el('pos-gate-date').textContent = dayBook?.date || 'today';

  // Already up. The gate is re-applied after every sale, and re-focusing on
  // each pass would yank the caret out of the box being typed into.
  if (!gate.hidden) return;

  gate.hidden = false;
  syncModalOpen();
  (mode === 'closed' ? closed.querySelector('.pos-gate-back') : el('pos-open-amount'))?.focus();
}

function hideGate() {
  const gate = el('pos-day-gate');
  if (!gate || gate.hidden) return;
  gate.hidden = true;
  syncModalOpen();
}

/**
 * The gate is hard rather than advisory. A sale rung into a day nobody opened
 * cannot be reconciled against a drawer count, so the till refuses it instead
 * of warning and letting it through — a warning a cashier can click past is
 * exactly how a shift ends up unreconcilable. A closed day is barred the same
 * way: reopening it would strand the count that was already filed.
 *
 * The single exception is a lookup that never answered. If the endpoint is
 * unreachable there are still customers at the counter, so `dayBook` stays null,
 * the gate stays down and the failure is reported in the alert strip — losing a
 * day's reconciliation beats shutting the shop.
 */
function applyDayGate() {
  if (!sellingBlocked()) {
    hideGate();
  } else if (el('pos-day-close')?.hidden !== false) {
    showGate(dayBook.is_closed ? 'closed' : 'open');
  }
  // else: the close dialog is still showing the variance it just produced, so
  // the gate waits behind it until the cashier acknowledges the count.

  // "Complete sale" follows the gate, so a stale tab cannot post into a day
  // that was closed on the terminal next to it.
  renderTotals();
}

async function loadDayBook() {
  try {
    dayBook = await apiGet('/api/day-book/current');
  } catch {
    // Mid-shift, keep the last good figures rather than blanking the topbar.
    // Cold, there is nothing to say except that the drawer figures are missing.
    if (dayBook === null) {
      showAlert('Could not read the day book — the drawer figures are unavailable.');
    }
  }

  renderDaySummary();
  applyDayGate();
}

async function submitOpenDay(event) {
  event.preventDefault();

  const input = el('pos-open-amount');
  const button = el('pos-open-submit');
  const amount = Number(input.value);

  if (input.value.trim() === '' || !Number.isFinite(amount) || amount < 0) {
    setDialogError('pos-open-error', 'Enter the cash you are starting with. Zero is fine for an empty drawer.');
    return;
  }

  button.disabled = true;
  try {
    const data = await apiPost('/api/day-book/open', { opening_amount: amount });
    setDialogError('pos-open-error', '');
    // `already_open` means another terminal opened the shop first and its float
    // is the one that counts, so the figures come from a fresh read rather than
    // from the number just typed.
    await loadDayBook();
    if (data.already_open) showAlert(data.message, 'success');
    el('pos-search')?.focus();
  } catch (err) {
    const message = fieldError(err, 'opening_amount');
    // A 422 here means the day was closed underneath the prompt. Re-reading
    // swaps the float form for the closed notice instead of leaving the cashier
    // retrying a form that can no longer succeed.
    if (err.status === 422) await loadDayBook();
    if (el('pos-open-form').hidden) showAlert(message);
    else setDialogError('pos-open-error', message);
  } finally {
    button.disabled = false;
  }
}

function renderExpectedCash() {
  const list = el('pos-close-expected');
  if (!list || !dayBook) return;

  list.innerHTML = [
    figureRow('Opening float', formatRs(dayBook.opening_amount)),
    figureRow('Cash takings', formatRs(dayBook.cash_takings)),
    dayBook.cash_refunds > 0 ? figureRow('Cash refunds', `− ${formatRs(dayBook.cash_refunds)}`) : '',
    figureRow('Expected in drawer', formatRs(dayBook.expected_cash), 'is-total'),
  ].join('');
}

/**
 * The variance is shown while the count is still editable: a transposed digit
 * costs nothing to fix here and is unarguable once the day is filed.
 */
function renderClosePreview() {
  const note = el('pos-close-preview');
  const raw = el('pos-close-amount')?.value ?? '';
  if (!note || !dayBook) return;

  if (raw.trim() === '' || !Number.isFinite(Number(raw))) {
    note.textContent = '';
    note.dataset.state = '';
    return;
  }

  paintVariance(note, round2(Number(raw) - dayBook.expected_cash));
}

function renderCloseResult(day) {
  if (!day) return;

  el('pos-close-form').hidden = true;
  el('pos-close-result').hidden = false;
  el('pos-day-close-title').textContent = 'Day closed';

  el('pos-close-summary').innerHTML = [
    figureRow('Opening float', formatRs(day.opening_amount)),
    figureRow('Expected in drawer', formatRs(day.expected_amount ?? 0)),
    figureRow('Counted', formatRs(day.closing_amount ?? 0), 'is-total'),
  ].join('');

  paintVariance(el('pos-close-variance'), Number(day.variance) || 0);
  el('pos-close-done').focus();
}

async function openCloseDialog() {
  if (!dayBook?.is_open) return;

  // Re-read first: the expected figure is what the cashier's count is judged
  // against, so it has to include anything rung up on another terminal.
  await loadDayBook();
  if (!dayBook?.is_open) return;

  el('pos-day-close-title').textContent = 'Close the day';
  el('pos-close-form').hidden = false;
  el('pos-close-result').hidden = true;
  el('pos-close-amount').value = '';
  setDialogError('pos-close-error', '');
  renderExpectedCash();
  renderClosePreview();

  el('pos-day-close').hidden = false;
  syncModalOpen();
  el('pos-close-amount').focus();
}

function closeCloseDialog() {
  const modal = el('pos-day-close');
  if (!modal || modal.hidden) return;
  modal.hidden = true;
  syncModalOpen();
  // The day may have closed while this was up — the gate takes over from here.
  applyDayGate();
}

async function submitCloseDay(event) {
  event.preventDefault();

  const input = el('pos-close-amount');
  const button = el('pos-close-submit');
  const amount = Number(input.value);

  if (input.value.trim() === '' || !Number.isFinite(amount) || amount < 0) {
    setDialogError('pos-close-error', 'Enter the cash you counted out of the drawer.');
    return;
  }

  button.disabled = true;
  try {
    const data = await apiPost('/api/day-book/close', { closing_amount: amount });
    setDialogError('pos-close-error', '');
    // Locks the till behind this dialog; the gate is what stops the next sale.
    await loadDayBook();
    renderCloseResult(data.day_book);
  } catch (err) {
    setDialogError('pos-close-error', fieldError(err, 'closing_amount'));
    // Never opened, or closed on another terminal — re-read so the topbar and
    // the gate agree with whatever the server thinks the day is.
    if (err.status === 422) await loadDayBook();
  } finally {
    button.disabled = false;
  }
}

/* ------------------------------------------------------------------- cart */

function addToCart(productId) {
  const product = products.find((p) => p.id === productId);
  if (!product) return;

  // There is no such thing as "one" of a sack of daal, so loose goods collect a
  // weight — or the money the customer named — before they reach the ticket.
  if (isMeasured(product)) {
    openWeighPad(product);
    return;
  }

  const result = cart.add(product);
  if (!result.ok) {
    showAlert(result.reason);
    return;
  }

  clearAlert();
  renderTicket();
}

/* ---------------------------------------------------------------- weigh pad */

/**
 * Open keypad, or null when it is down.
 *
 * `typed` stays the raw string of keys pressed rather than a number, so a
 * half-finished "1." is not helpfully rounded away before the cashier has
 * typed the digit after the point.
 */
let weigh = null;

/** The two settings a shop scale here is ever on. */
function weighUnits(product) {
  return product.unit_type === TYPE_VOLUME ? ['ml', 'l'] : ['g', 'kg'];
}

function weighQuickQuantities(product) {
  return product.unit_type === TYPE_VOLUME ? QUICK_VOLUMES : QUICK_WEIGHTS;
}

function weighTyped() {
  const n = Number(weigh?.typed);
  return Number.isFinite(n) ? n : 0;
}

/** What is currently on the pad, always in base units. */
function weighBaseQuantity() {
  if (!weigh) return 0;

  return weigh.mode === 'amount'
    ? quantityForAmount(weighTyped(), weigh.product.price)
    : toBase(weighTyped(), weigh.unit);
}

function openWeighPad(product, { quantity = null, replacing = false } = {}) {
  const [small] = weighUnits(product);

  weigh = {
    product,
    replacing,
    mode: 'quantity',
    unit: small,
    typed: quantity ? String(fromBase(quantity, small)) : '',
  };

  setDialogError('pos-weigh-error', '');
  renderWeighPad();

  el('pos-weigh').hidden = false;
  syncModalOpen();
}

function closeWeighPad() {
  const modal = el('pos-weigh');
  if (!modal || modal.hidden) return;

  weigh = null;
  modal.hidden = true;
  syncModalOpen();
  el('pos-search')?.focus();
}

function setWeighMode(mode) {
  if (!weigh || weigh.mode === mode) return;
  // The digits meant grams a moment ago and rupees now — carrying them over
  // would silently reprice the line, so the pad starts clean.
  weigh.mode = mode;
  weigh.typed = '';
  setDialogError('pos-weigh-error', '');
  renderWeighPad();
}

/**
 * Switching g→kg keeps the weight rather than the digits: a cashier who typed
 * 250 g and then realises the scale is in kilos meant the same bag either way.
 */
function setWeighUnit(unit) {
  if (!weigh || weigh.unit === unit || weigh.mode !== 'quantity') return;

  const base = weighBaseQuantity();
  weigh.unit = unit;
  weigh.typed = base > 0 ? String(fromBase(base, unit)) : '';
  renderWeighPad();
}

function pressWeighKey(key) {
  if (!weigh) return;

  if (key === 'back') weigh.typed = weigh.typed.slice(0, -1);
  else if (key === '.') {
    if (!weigh.typed.includes('.')) weigh.typed = `${weigh.typed || '0'}.`;
  } else if (weigh.typed.replace('.', '').length < 8) {
    // Leading zeros read as a typo on a price pad ("050"), but "0." is the
    // start of a real fraction and has to survive.
    weigh.typed = weigh.typed === '0' ? key : weigh.typed + key;
  }

  setDialogError('pos-weigh-error', '');
  renderWeighPad();
}

function renderWeighPad() {
  if (!weigh) return;

  const { product, mode, unit } = weigh;
  const measuring = product.unit_type === TYPE_WEIGHT ? 'weight' : 'volume';
  const base = weighBaseQuantity();

  el('pos-weigh-title').textContent = measuring === 'weight' ? 'Weigh item' : 'Measure item';
  el('pos-weigh-name').textContent = product.name;
  el('pos-weigh-rate').textContent = priceLabel(product);

  el('pos-weigh-typed').textContent = weigh.typed || '0';
  el('pos-weigh-prefix').hidden = mode !== 'amount';

  el('pos-weigh').querySelectorAll('.pos-weigh-tab').forEach((tab) => {
    const active = tab.dataset.mode === mode;
    tab.classList.toggle('is-active', active);
    tab.setAttribute('aria-selected', String(active));
    if (tab.dataset.mode === 'quantity') {
      tab.textContent = measuring === 'weight' ? 'By weight' : 'By volume';
    }
  });

  el('pos-weigh-units').innerHTML =
    mode === 'amount'
      ? ''
      : weighUnits(product)
          .map(
            (u) =>
              `<button type="button" class="pos-weigh-unit${u === unit ? ' is-active' : ''}" data-unit="${u}">${escapeHtml(u === 'l' ? 'L' : u)}</button>`
          )
          .join('');

  const quick =
    mode === 'amount'
      ? QUICK_AMOUNTS.map((a) => ({ value: a, label: `Rs ${a}` }))
      : weighQuickQuantities(product).map((q) => ({
          value: fromBase(q.base, unit),
          label: q.label,
        }));

  el('pos-weigh-quick').innerHTML = quick
    .map(
      (q) =>
        `<button type="button" class="pos-weigh-chip" data-value="${q.value}">${escapeHtml(q.label)}</button>`
    )
    .join('');

  // The half the cashier did not type is the half that matters: entering a
  // weight has to show the money, and entering money has to show what to put
  // on the scale.
  const derived = el('pos-weigh-derived');
  if (base <= 0) {
    derived.textContent = '';
  } else if (mode === 'amount') {
    derived.textContent = `Weigh out ${formatQuantity(base, product.unit_type)}`;
  } else {
    derived.textContent = `= ${formatRs(amountForQuantity(base, product.price))}`;
  }

  el('pos-weigh-add').disabled = base <= 0;
  el('pos-weigh-add').textContent = weigh.replacing ? 'Update line' : 'Add to ticket';
}

function commitWeigh() {
  if (!weigh) return;

  const base = weighBaseQuantity();
  if (base <= 0) {
    setDialogError('pos-weigh-error', 'Enter a weight or an amount first.');
    return;
  }

  const { product, replacing } = weigh;
  // Re-weighing an existing line means "it is this much", not "add this much
  // again" — so an edit replaces where a fresh tap accumulates.
  const result = replacing ? cart.setQuantity(product.id, base) : cart.add(product, base);

  if (!result.ok) {
    setDialogError('pos-weigh-error', result.reason);
    return;
  }

  closeWeighPad();
  clearAlert();
  renderTicket();
}

function wireWeighPad() {
  const modal = el('pos-weigh');
  if (!modal) return;

  // Delegated: the quick chips and unit switches are rebuilt on every keystroke,
  // so binding them per render would leak a listener per digit pressed.
  modal.addEventListener('click', (e) => {
    const key = e.target.closest('.pos-weigh-key');
    if (key) return pressWeighKey(key.dataset.key);

    const chip = e.target.closest('.pos-weigh-chip');
    if (chip) {
      weigh.typed = String(chip.dataset.value);
      setDialogError('pos-weigh-error', '');
      return renderWeighPad();
    }

    const unit = e.target.closest('.pos-weigh-unit');
    if (unit) return setWeighUnit(unit.dataset.unit);

    const tab = e.target.closest('.pos-weigh-tab');
    if (tab) return setWeighMode(tab.dataset.mode);

    return undefined;
  });

  el('pos-weigh-add').addEventListener('click', commitWeigh);
  el('pos-weigh-dismiss').addEventListener('click', closeWeighPad);
  el('pos-weigh-overlay').addEventListener('click', closeWeighPad);

  // A hardware keyboard is faster than tapping when the till runs on a laptop,
  // and the scanner gun's Enter is harmless here because the pad has focus.
  document.addEventListener('keydown', (e) => {
    if (!weigh) return;
    if (e.key === 'Escape') return closeWeighPad();
    if (e.key === 'Enter') {
      e.preventDefault();
      return commitWeigh();
    }
    if (e.key === 'Backspace') {
      e.preventDefault();
      return pressWeighKey('back');
    }
    if (/^[0-9.]$/.test(e.key)) {
      e.preventDefault();
      return pressWeighKey(e.key);
    }
    return undefined;
  });
}

function lineIsMeasured(line) {
  return (line.unitType || 'each') !== 'each';
}

/**
 * Loose goods get the keypad back rather than a spinner. Nudging 250 g up a
 * gram at a time is not something anyone would do, and what usually needs
 * correcting is the whole weight — the bag went back on the scale.
 */
function lineQtyHTML(line) {
  if (lineIsMeasured(line)) {
    return `
      <div class="pos-line-qty is-measured">
        <button type="button" class="pos-qty-weigh"
                aria-label="Change quantity for ${escapeAttr(line.name)}">
          ${escapeHtml(formatQuantity(line.quantity, line.unitType))}
        </button>
      </div>`;
  }

  return `
    <div class="pos-line-qty">
      <button type="button" class="pos-qty-btn" data-step="-1" aria-label="Reduce quantity">−</button>
      <input class="pos-qty-input" type="number" min="0" step="1" value="${line.quantity}"
             aria-label="Quantity for ${escapeAttr(line.name)}" />
      <button type="button" class="pos-qty-btn" data-step="1" aria-label="Increase quantity">+</button>
    </div>`;
}

function lineUnitText(line) {
  return lineIsMeasured(line)
    ? formatUnitPrice(line.unitPrice, line.priceUnit || 'kg')
    : `${formatRs(line.unitPrice)} each`;
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
          <span class="pos-line-unit">${escapeHtml(lineUnitText(line))}</span>
        </div>
        ${lineQtyHTML(line)}
        <span class="pos-line-total">${formatRs(line.lineTotal)}</span>
        <button type="button" class="pos-line-remove" aria-label="Remove ${escapeAttr(line.name)}">×</button>
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
    input?.addEventListener('change', () => applyQuantity(id, input.value));

    row.querySelector('.pos-qty-weigh')?.addEventListener('click', () => {
      const product = products.find((p) => p.id === id);
      const line = cart.toArray().find((l) => l.productId === id);
      if (product && line) {
        openWeighPad(product, { quantity: line.quantity, replacing: true });
      }
    });

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

/** What the ticket comes to right now — the discount box is part of it. */
function ticketTotals() {
  return computeTotals(cart.toArray(), el('pos-discount').value);
}

function renderTotals() {
  const totals = ticketTotals();

  el('pos-subtotal').textContent = formatRs(totals.subtotal);
  el('pos-total').textContent = formatRs(totals.total);

  // Signed, because the customer is owed the explanation either way: a ticket
  // rounded down is a paisa off the bill, not a discount the shop chose.
  const rounded = Math.abs(totals.rounding) >= 0.005;
  el('pos-rounding-row').hidden = !rounded;
  if (rounded) {
    const sign = totals.rounding > 0 ? '+' : '−';
    el('pos-rounding').textContent = `${sign} ${formatRs(Math.abs(totals.rounding))}`;
  }

  renderChange(totals.total);

  const blocked = cart.isEmpty() || submitting || sellingBlocked();
  el('pos-complete').disabled = blocked;
  el('pos-credit').disabled = blocked;

  // The udhaar dialog quotes the ticket, so a discount typed while it is open
  // has to reach it.
  if (el('pos-udhaar')?.hidden === false) renderUdhaar();
}

function renderChange(total) {
  const method = el('pos-method').value;
  const isCash = method === 'cash';

  el('pos-cash-fields').hidden = !isCash;
  el('pos-reference-field').hidden = isCash;

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

/**
 * The one door to the register. Both buttons come through here so a sale taken
 * on credit and a sale taken in cash cannot drift apart in what they post or in
 * what happens afterwards.
 *
 * Errors are re-thrown: where a refusal belongs on screen differs between the
 * two — the till's alert strip for a cash sale, the udhaar dialog for a credit
 * one, where the name and the deposit that caused it are still editable.
 */
async function submitSale(payload) {
  submitting = true;
  renderTotals();
  clearAlert();

  try {
    const data = await apiPost('/api/sales', payload);
    return data.sale;
  } finally {
    submitting = false;
    renderTotals();
  }
}

async function finishSale(sale) {
  resetTicket();
  renderReceipt(sale);

  // Stock moved server-side, so pull fresh counts before the next sale — and
  // with them the day book, so "In drawer" tracks the cash actually taken.
  await Promise.all([loadProducts(), loadDayBook()]);
}

/**
 * Belt and braces behind the gate: both buttons are already disabled, but a day
 * closed on another terminal only surfaces on the next refresh.
 */
function dayGateMessage() {
  return sellingBlocked()
    ? 'The day book is not open — record the opening float before taking payment.'
    : null;
}

async function completeSale() {
  if (cart.isEmpty() || submitting) return;

  const blocked = dayGateMessage();
  if (blocked) {
    showAlert(blocked);
    return;
  }

  const method = el('pos-method').value;
  const tenderedRaw = el('pos-tendered').value;
  const reference = el('pos-reference').value.trim();
  const totals = ticketTotals();

  if (method === 'cash' && tenderedRaw !== '' && changeDue(totals.total, tenderedRaw) === null) {
    showAlert('Amount received is less than the total due.');
    return;
  }

  try {
    const payload = {
      items: cart.toPayloadItems(),
      discount_amount: totals.discount,
      payment_method: method,
    };
    if (method === 'cash' && tenderedRaw !== '') {
      payload.amount_tendered = Number(tenderedRaw);
    }
    if (method !== 'cash' && reference) {
      payload.payment_reference = reference;
    }

    await finishSale(await submitSale(payload));
  } catch (err) {
    showAlert(err.message || 'Could not complete the sale.');
  }
}

function resetTicket() {
  cart.clear();
  el('pos-discount').value = '0';
  el('pos-tendered').value = '';
  el('pos-reference').value = '';
  renderTicket();
}

/* ------------------------------------------------------------------ udhaar */

/**
 * The khata page picked out of the dropdown, or null while the cashier is
 * writing a name by hand. It is only ever used to show what the customer
 * already owes and to supply the name and number the sale posts — SaleService
 * resolves the page from those. Posting an id from here would give the till a
 * second way to decide who a debt belongs to, and the two would eventually
 * disagree.
 */
let creditCustomer = null;

/** Whatever the last khata search returned, so a pick needs no second request. */
let creditResults = [];

/** The term `creditResults` belongs to; null means nothing has been fetched. */
let creditTerm = null;

/** True when the khata could not be reached — the sale still goes through. */
let creditFailed = false;

/** Whether the dropdown is expanded. Never true while a page is picked. */
let creditOpen = false;

/** Highlighted option, -1 for none. Never pre-armed: see moveCreditActive(). */
let creditActive = -1;

let creditSearchTimer = null;

/**
 * Search responses can land out of order once the cashier types faster than the
 * network answers, and the older one would overwrite the newer list under a
 * cursor that is about to press Enter. Only the newest request may render.
 */
let creditSearchSeq = 0;

function creditOptionId(index) {
  return `pos-udhaar-option-${index}`;
}

function creditOptionHTML(customer, index) {
  const owed = Number(customer.balance) || 0;
  const active = index === creditActive;

  return `
    <button type="button" class="pos-udhaar-option${active ? ' is-active' : ''}"
            id="${creditOptionId(index)}" role="option" aria-selected="${active}"
            data-index="${index}">
      <span class="pos-udhaar-option-who">
        <span class="pos-udhaar-option-name">${escapeHtml(customer.name)}</span>
        <span class="pos-udhaar-option-phone">${escapeHtml(customer.phone || 'No number')}</span>
      </span>
      <span class="pos-udhaar-option-owed${owed > 0 ? ' is-due' : ''}">${
        owed > 0 ? escapeHtml(formatRs(owed)) : 'No dues'
      }</span>
    </button>`;
}

/**
 * The dropdown carries matches and nothing else. "No match" is said in the
 * standing note below instead, so the empty case leaves the dialog compact and
 * nothing floats over the phone box the cashier is about to reach for.
 */
function renderCreditPanel() {
  const input = el('pos-udhaar-search');
  const panel = el('pos-udhaar-results');
  const show = creditOpen && !creditCustomer && creditResults.length > 0;

  panel.innerHTML = creditResults.map(creditOptionHTML).join('');
  panel.hidden = !show;
  input.setAttribute('aria-expanded', String(show));

  const activeId = show && creditActive >= 0 ? creditOptionId(creditActive) : '';
  if (activeId) {
    input.setAttribute('aria-activedescendant', activeId);
    el(activeId)?.scrollIntoView({ block: 'nearest' });
  } else {
    input.removeAttribute('aria-activedescendant');
  }
}

function closeCreditPanel() {
  creditOpen = false;
  creditActive = -1;
  renderCreditPanel();
}

async function searchCredit(term) {
  const seq = ++creditSearchSeq;
  const params = new URLSearchParams({ per_page: '20' });
  if (term) params.set('search', term);

  try {
    const data = await apiGet(`/api/customers?${params.toString()}`);
    if (seq !== creditSearchSeq) return;
    creditResults = data.customers || [];
    creditTerm = term;
    creditFailed = false;
  } catch {
    if (seq !== creditSearchSeq) return;
    // A khata that cannot be reached must not stop the sale: the name in the
    // box still opens or finds the page server-side. Left unrecorded as a term
    // so the next keystroke retries rather than trusting an empty list.
    creditResults = [];
    creditTerm = null;
    creditFailed = true;
  }

  // Deliberately not pre-armed on the first hit: a cashier typing a brand new
  // name and hitting Enter would otherwise file the debt against whichever
  // regular happened to share a prefix.
  creditActive = -1;
  renderCreditPanel();
  renderCreditNote();
}

function openCreditPanel() {
  if (creditCustomer) return;

  creditOpen = true;
  const term = el('pos-udhaar-search').value.trim();
  // Nothing fetched for what is in the box — ask, and let the answer open the
  // panel, rather than flashing an empty one.
  if (creditTerm === term) renderCreditPanel();
  else searchCredit(term);
}

function moveCreditActive(step) {
  const count = creditResults.length;
  if (!creditOpen || count === 0) return;

  creditActive =
    creditActive < 0 ? (step > 0 ? 0 : count - 1) : (creditActive + step + count) % count;
  renderCreditPanel();
}

function pickCreditCustomer(index) {
  const customer = creditResults[index];
  if (!customer) return;

  creditCustomer = customer;
  creditOpen = false;
  creditActive = -1;
  // Kept in the box so "Change" hands the name back to be edited rather than an
  // empty field the cashier has to retype.
  el('pos-udhaar-search').value = customer.name || '';
  setDialogError('pos-udhaar-error', '');
  renderUdhaar();
  el('pos-udhaar-unpick').focus();
}

function unpickCreditCustomer() {
  if (!creditCustomer) return;

  creditCustomer = null;
  creditResults = [];
  creditTerm = null;
  creditOpen = false;
  creditActive = -1;
  renderUdhaar();

  // Selected rather than cleared, so the button does both of the things it is
  // asked for: type over it for somebody new, or take one of the pages the
  // list comes back with.
  const input = el('pos-udhaar-search');
  input.focus();
  input.select();
  openCreditPanel();
}

/**
 * What happens to the name in the box if nobody is picked — said in plain words
 * and in the flow of the dialog rather than in the dropdown, because this is the
 * path most udhaar customers arrive by and it must not depend on a floating
 * panel being open to be readable.
 */
function renderCreditNote() {
  const note = el('pos-udhaar-new-note');
  const term = el('pos-udhaar-search').value.trim();

  if (creditFailed) {
    note.textContent =
      'Could not reach the khata — the name typed above still finds or opens the page.';
    // Never a verdict on text the khata has not been asked about yet: mid-typing
    // it would read "no page for Bil" at a customer who has one under "Bilal".
  } else if (!term || creditTerm !== term) {
    note.textContent = "Type the customer's name. Matching khata pages appear as you type.";
  } else if (creditResults.length) {
    note.textContent = `Pick a page above, or leave it and a new one opens for "${term}".`;
  } else {
    note.textContent = `No khata page for "${term}" — this sale opens a new one.`;
  }
}

/**
 * The picked page standing in for the search box: who the debt lands on, what
 * they already owe, and how much of their limit is left.
 */
function renderCreditPicked() {
  const picked = !!creditCustomer;
  el('pos-udhaar-pick').hidden = picked;
  el('pos-udhaar-new').hidden = picked;
  el('pos-udhaar-picked').hidden = !picked;

  const figures = el('pos-udhaar-customer');
  if (!picked) {
    figures.innerHTML = '';
    renderCreditNote();
    return;
  }

  const limit = creditCustomer.credit_limit;
  const available = creditCustomer.credit_available;

  el('pos-udhaar-picked-name').textContent = creditCustomer.name || '';
  el('pos-udhaar-picked-phone').textContent = creditCustomer.phone || 'No number on this page';
  figures.innerHTML = [
    figureRow('Already owes', formatRs(creditCustomer.balance)),
    figureRow('Khata limit', limit === null || limit === undefined ? 'No limit' : formatRs(limit)),
    available === null || available === undefined ? '' : figureRow('Limit left', formatRs(available)),
  ].join('');
}

/** The name the sale posts: the picked page's, or whatever is in the box. */
function creditName() {
  return creditCustomer
    ? String(creditCustomer.name || '').trim()
    : el('pos-udhaar-search').value.trim();
}

/**
 * The number the sale posts. A picked page supplies its own rather than the
 * phone box, which only ever describes a new page — SaleService matches on the
 * number first, so a stale one left in the box would re-point the debt.
 */
function creditPhone() {
  return creditCustomer
    ? String(creditCustomer.phone || '').trim()
    : el('pos-udhaar-phone').value.trim();
}

function renderUdhaar() {
  const total = ticketTotals().total;
  const deposit = Number(el('pos-udhaar-deposit').value) || 0;
  const onBook = round2(total - Math.min(Math.max(deposit, 0), total));

  el('pos-udhaar-total').textContent = formatRs(total);
  el('pos-udhaar-book').textContent = formatRs(onBook);

  renderCreditPicked();
  renderCreditPanel();
}

function openUdhaar() {
  if (cart.isEmpty() || submitting) return;

  const blocked = dayGateMessage();
  if (blocked) {
    showAlert(blocked);
    return;
  }

  resetCreditSearch();
  el('pos-udhaar-search').value = '';
  el('pos-udhaar-phone').value = '';
  el('pos-udhaar-deposit').value = '';
  el('pos-udhaar-method').value = 'cash';
  setDialogError('pos-udhaar-error', '');
  renderUdhaar();

  el('pos-udhaar').hidden = false;
  syncModalOpen();
  // Nothing typed, nothing picked, no list: the dialog opens at its shortest and
  // the khata is only queried once the cashier says who they are looking for.
  el('pos-udhaar-search').focus();
}

/** Drops every trace of the last ticket's search, including replies in flight. */
function resetCreditSearch() {
  clearTimeout(creditSearchTimer);
  creditSearchSeq += 1;
  creditCustomer = null;
  creditResults = [];
  creditTerm = null;
  creditFailed = false;
  creditOpen = false;
  creditActive = -1;
}

function closeUdhaar() {
  const modal = el('pos-udhaar');
  // Never while the sale is in flight: the dialog is where a refusal is
  // reported, and dismissing it mid-post would swallow the reason.
  if (!modal || modal.hidden || submitting) return;

  resetCreditSearch();
  modal.hidden = true;
  syncModalOpen();
}

async function submitUdhaar(event) {
  event.preventDefault();
  if (cart.isEmpty() || submitting) return;

  const blocked = dayGateMessage();
  if (blocked) {
    setDialogError('pos-udhaar-error', blocked);
    return;
  }

  const name = creditName();
  const phone = creditPhone();
  const depositRaw = el('pos-udhaar-deposit').value.trim();
  const deposit = Number(depositRaw);
  const totals = ticketTotals();

  // A debt with no name on it is uncollectable — the server refuses it too, but
  // saying so here keeps the goods on the counter rather than on the book.
  if (!name) {
    setDialogError('pos-udhaar-error', 'Write the customer name — an unnamed debt cannot be collected.');
    el('pos-udhaar-search').focus();
    return;
  }

  if (depositRaw !== '' && (!Number.isFinite(deposit) || deposit < 0)) {
    setDialogError('pos-udhaar-error', 'Enter the deposit in rupees, or leave it empty for nothing paid.');
    return;
  }

  if (deposit > totals.total) {
    setDialogError('pos-udhaar-error', 'The deposit is more than the ticket total.');
    return;
  }

  const payload = {
    items: cart.toPayloadItems(),
    discount_amount: totals.discount,
    payment_method: 'credit',
    customer_name: name,
  };
  if (phone) payload.customer_phone = phone;
  // An empty box means the whole ticket goes on the book, which is a normal
  // udhaar sale — the sale must record as pending, never as paid.
  if (depositRaw !== '' && deposit > 0) {
    payload.paid_amount = deposit;
    payload.deposit_method = el('pos-udhaar-method').value;
  }

  el('pos-udhaar-save').disabled = true;

  try {
    const sale = await submitSale(payload);
    closeUdhaar();
    await finishSale(sale);
  } catch (err) {
    setDialogError(
      'pos-udhaar-error',
      fieldError(err, 'credit_limit', 'customer_name', 'paid_amount', 'items', 'discount_amount')
    );
  } finally {
    el('pos-udhaar-save').disabled = false;
  }
}

function wireUdhaar() {
  el('pos-credit').addEventListener('click', openUdhaar);
  el('pos-udhaar-dismiss').addEventListener('click', closeUdhaar);
  el('pos-udhaar-overlay').addEventListener('click', closeUdhaar);
  el('pos-udhaar-form').addEventListener('submit', submitUdhaar);
  el('pos-udhaar-deposit').addEventListener('input', renderUdhaar);

  el('pos-udhaar-unpick').addEventListener('click', unpickCreditCustomer);

  const search = el('pos-udhaar-search');

  search.addEventListener('input', () => {
    clearTimeout(creditSearchTimer);
    const term = search.value.trim();
    renderCreditNote();

    // An empty box asks nothing of the khata and shows nothing: the dialog goes
    // back to its shortest rather than listing every page in the shop.
    if (!term) {
      creditResults = [];
      creditTerm = null;
      creditFailed = false;
      return closeCreditPanel();
    }

    creditSearchTimer = setTimeout(() => {
      creditOpen = true;
      searchCredit(term);
    }, 250);
    return undefined;
  });

  search.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (creditOpen) moveCreditActive(e.key === 'ArrowDown' ? 1 : -1);
      else openCreditPanel();
      return;
    }

    if (e.key === 'Enter') {
      // The box sits outside the form so a scanner's Enter cannot post the sale;
      // here it only ever takes the highlighted page.
      e.preventDefault();
      if (creditOpen && creditActive >= 0) pickCreditCustomer(creditActive);
      return;
    }

    // The first Escape puts the list away. The second reaches the dialog's own
    // handler below and closes the whole thing.
    if (e.key === 'Escape' && creditOpen) {
      e.stopPropagation();
      closeCreditPanel();
    }
  });

  // Focus leaving the box always collapses the list — including onto the deposit
  // fields it was floating over.
  search.addEventListener('blur', closeCreditPanel);

  const panel = el('pos-udhaar-results');

  // Without this the box blurs on mousedown, the list collapses, and the click
  // that was aimed at an option lands on whatever the panel was covering.
  panel.addEventListener('mousedown', (e) => e.preventDefault());

  // Delegated: the options are rebuilt on every search.
  panel.addEventListener('click', (e) => {
    const option = e.target.closest('.pos-udhaar-option');
    if (option) pickCreditCustomer(Number(option.dataset.index));
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeUdhaar();
  });
}

/* ---------------------------------------------------------------- receipt */

/**
 * Every dialog shares one body class (it stops a phone scrolling the till
 * behind them), so it is recomputed from what is actually on screen rather than
 * counted up and down — the day-book gate can open while a receipt is still up.
 */
function syncModalOpen() {
  const anyOpen = ['pos-receipt', 'pos-day-gate', 'pos-day-close', 'pos-weigh', 'pos-udhaar'].some(
    (id) => el(id)?.hidden === false
  );
  document.body.classList.toggle('pos-modal-open', anyOpen);
}

function openReceipt() {
  const modal = el('pos-receipt');
  if (!modal) return;
  modal.hidden = false;
  syncModalOpen();
  el('pos-new-sale')?.focus();
}

function closeReceipt() {
  const modal = el('pos-receipt');
  if (!modal || modal.hidden) return;
  modal.hidden = true;
  syncModalOpen();
  // Behind a closed receipt there may be a gate — never steal its focus.
  if (el('pos-day-gate')?.hidden !== false) el('pos-search')?.focus();
}

/**
 * Whether the money actually arrived, said in one line above the slip.
 *
 * A cash sale rung up with the "Amount received" box empty settles in full —
 * that is how a till works, the exact note handed over is rarely typed — but
 * that is a decision the till made on the cashier's behalf, so it is stated
 * rather than assumed. The same line is what stops an udhaar sale being taken
 * for a paid one.
 */
function renderReceiptState(sale) {
  const note = el('pos-receipt-state');
  if (!note) return;

  const outstanding = Number(sale.outstanding_amount) || 0;
  const paid = Number(sale.paid_amount) || 0;
  const status = sale.payment_status || (outstanding > 0 ? 'pending' : 'paid');
  const who = sale.customer_name ? ` by ${sale.customer_name}` : '';

  el('pos-receipt-title').textContent = outstanding > 0 ? 'Saved on the khata' : 'Sale complete';
  note.dataset.state = status;

  if (outstanding <= 0) {
    note.textContent = `Settled in full — ${formatRs(sale.total)} · ${paymentLabel(sale.payment_method)}`;
    return;
  }

  note.textContent =
    paid > 0
      ? `${formatRs(paid)} taken, ${formatRs(outstanding)} still owed${who}.`
      : `Nothing taken — ${formatRs(outstanding)} owed${who}.`;
}

function renderReceipt(sale) {
  const modal = el('pos-receipt');
  const body = el('pos-receipt-body');
  if (!modal || !body || !sale) return;

  renderReceiptState(sale);

  // No payment history at the counter: the sale has at most the one payment
  // that just happened, and printing a one-row history of it reads as a
  // second charge. Reprints from the sales page pass showPayments.
  body.innerHTML = receiptSlipHTML(sale, { shopName: shopName() });

  openReceipt();
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
      if (!products.some((p) => p.id === data.product.id)) {
        products.push(data.product);
        renderProducts();
      }
      addToCart(data.product.id);
      input.value = '';
    } catch {
      // Not a known code — leave it in the box as a plain search term.
    }
    filterProducts();
    input.focus();
  });

  input.addEventListener('input', filterProducts);
}

function wireReceiptModal() {
  el('pos-print').addEventListener('click', () => window.print());
  el('pos-new-sale').addEventListener('click', closeReceipt);
  el('pos-receipt-close').addEventListener('click', closeReceipt);
  el('pos-receipt-overlay').addEventListener('click', closeReceipt);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeReceipt();
  });
}

function wireDayBook() {
  el('pos-open-form').addEventListener('submit', submitOpenDay);
  el('pos-close-form').addEventListener('submit', submitCloseDay);
  el('pos-close-amount').addEventListener('input', renderClosePreview);
  el('pos-close-day').addEventListener('click', openCloseDialog);
  el('pos-close-done').addEventListener('click', closeCloseDialog);
  el('pos-day-close-dismiss').addEventListener('click', closeCloseDialog);
  el('pos-day-close-overlay').addEventListener('click', closeCloseDialog);

  // Only the close dialog answers Escape. The gate has no dismiss and no
  // overlay handler, so there is no keystroke that gets past an unopened day.
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeCloseDialog();
  });
}

function storedUser() {
  try {
    return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
  } catch {
    return null;
  }
}

function shopName() {
  return storedUser()?.name || 'Cashflow';
}

/**
 * The receipt heading is the shop's own name. Without the app shell nothing
 * else on this page fetches the user, so top the cache up once when it's cold.
 */
function ensureShopName() {
  if (storedUser()) return;
  apiGet('/api/user')
    .then((data) => {
      if (data?.user) localStorage.setItem(USER_KEY, JSON.stringify(data.user));
    })
    .catch(() => {});
}

async function boot() {
  initTheme();
  if (!requireAuth()) return;

  wireSearch();
  initPaperSelect(el('pos-paper'));
  wireReceiptModal();
  wireDayBook();
  wireWeighPad();
  wireUdhaar();

  el('pos-category-search').addEventListener('input', filterCategories);
  el('pos-discount').addEventListener('input', renderTotals);
  el('pos-tendered').addEventListener('input', renderTotals);
  el('pos-method').addEventListener('change', renderTotals);
  el('pos-complete').addEventListener('click', completeSale);
  el('pos-clear').addEventListener('click', () => {
    resetTicket();
    clearAlert();
  });

  renderTicket();
  ensureShopName();

  try {
    await Promise.all([
      loadProducts(),
      // A shop with no categories is normal, and must not stop the till.
      loadCategories().catch(() => renderCategories()),
      loadDayBook(),
    ]);
  } catch (err) {
    showAlert(err.message || 'Failed to load products');
  }

  // Focus follows the gate: with the float prompt up, the first thing to type
  // is the float, not a barcode.
  if (el('pos-day-gate').hidden) el('pos-search').focus();
}

boot();
