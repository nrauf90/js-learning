/**
 * POS Till — offline-capable point of sale.
 */
import { apiGet, apiPost, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import {
  cacheProducts,
  generateId,
  getCachedProducts,
  getLocalSales,
  saveLocalSale,
} from './pos-db.js';
import { enqueueSale, initOutboxSync } from './pos-outbox.js';

const THEME_KEY = 'tax-calculator-theme';

/** @type {Array<{product: object, quantity: number, client_line_id: string}>} */
let cart = [];
/** @type {Array<object>} */
let products = [];
/** @type {Array<object>} */
let recentSales = [];

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  const toggle = document.getElementById('theme-toggle');
  if (toggle) {
    toggle.setAttribute('aria-pressed', String(theme === 'light'));
  }
}

function initTheme() {
  const stored = localStorage.getItem(THEME_KEY);
  const theme = stored === 'light' || stored === 'dark'
    ? stored
    : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  applyTheme(theme);
  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    applyTheme(next);
    localStorage.setItem(THEME_KEY, next);
  });
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('pos.html')}`);
  return false;
}

function formatRs(n) {
  return `Rs ${Math.round(n || 0).toLocaleString('en-PK')}`;
}

function setStatus(text, online) {
  const el = document.getElementById('pos-status');
  if (!el) return;
  el.textContent = text;
  el.classList.toggle('pos-status-online', online);
  el.classList.toggle('pos-status-offline', !online);
}

function cartTotal() {
  return cart.reduce((sum, item) => sum + item.product.price * item.quantity, 0);
}

function renderProducts() {
  const grid = document.getElementById('pos-products');
  if (!grid) return;
  if (!products.length) {
    grid.innerHTML = '<p class="pos-empty">No products. Connect online to load catalog.</p>';
    return;
  }
  grid.innerHTML = products.map((p) => `
    <button type="button" class="pos-product-btn" data-id="${p.id}">
      <span class="pos-product-name">${escapeHtml(p.name)}</span>
      <span class="pos-product-price">${formatRs(p.price)}</span>
    </button>
  `).join('');

  grid.querySelectorAll('.pos-product-btn').forEach((btn) => {
    btn.addEventListener('click', () => addToCart(Number(btn.dataset.id)));
  });
}

function renderCart() {
  const list = document.getElementById('pos-cart');
  const totalEl = document.getElementById('pos-total');
  if (!list) return;

  if (!cart.length) {
    list.innerHTML = '<li class="pos-cart-empty">Tap products to add items</li>';
  } else {
    list.innerHTML = cart.map((item, idx) => `
      <li class="pos-cart-item">
        <div class="pos-cart-item-info">
          <strong>${escapeHtml(item.product.name)}</strong>
          <span>${formatRs(item.product.price)} × ${item.quantity}</span>
        </div>
        <div class="pos-cart-item-actions">
          <button type="button" class="pos-qty-btn" data-action="dec" data-idx="${idx}">−</button>
          <span>${item.quantity}</span>
          <button type="button" class="pos-qty-btn" data-action="inc" data-idx="${idx}">+</button>
          <button type="button" class="pos-remove-btn" data-idx="${idx}">×</button>
        </div>
      </li>
    `).join('');

    list.querySelectorAll('[data-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const idx = Number(btn.dataset.idx);
        if (btn.dataset.action === 'inc') cart[idx].quantity++;
        if (btn.dataset.action === 'dec') cart[idx].quantity = Math.max(1, cart[idx].quantity - 1);
        renderCart();
      });
    });
    list.querySelectorAll('.pos-remove-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        cart.splice(Number(btn.dataset.idx), 1);
        renderCart();
      });
    });
  }

  if (totalEl) totalEl.textContent = formatRs(cartTotal());
  const checkoutBtn = document.getElementById('pos-checkout');
  if (checkoutBtn) checkoutBtn.disabled = cart.length === 0;
}

function addToCart(productId) {
  const product = products.find((p) => p.id === productId);
  if (!product) return;
  const existing = cart.find((c) => c.product.id === productId);
  if (existing) {
    existing.quantity++;
  } else {
    cart.push({ product, quantity: 1, client_line_id: generateId() });
  }
  renderCart();
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

async function loadProducts() {
  try {
    const data = await apiGet('/api/pos/products');
    products = data.products || [];
    await cacheProducts(products);
    setStatus('Online — catalog synced', true);
  } catch {
    products = await getCachedProducts();
    setStatus(products.length ? 'Offline — using cached catalog' : 'Offline — no catalog', false);
  }
  renderProducts();
}

async function completeSale() {
  if (!cart.length) return;

  const clientSaleId = generateId();
  const subtotal = cartTotal();
  const salePayload = {
    client_sale_id: clientSaleId,
    idempotency_key: `sale-${clientSaleId}`,
    subtotal,
    tax: 0,
    total: subtotal,
    payment_method: document.getElementById('pos-payment')?.value || 'cash',
    sold_at: new Date().toISOString(),
    sync_source: navigator.onLine ? 'online' : 'offline',
    lines: cart.map((item) => ({
      client_line_id: item.client_line_id,
      pos_product_id: item.product.id,
      product_name: item.product.name,
      sku: item.product.sku,
      unit_price: item.product.price,
      quantity: item.quantity,
      line_total: item.product.price * item.quantity,
    })),
  };

  await saveLocalSale({ ...salePayload, status: 'pending' });

  if (navigator.onLine) {
    try {
      await apiPost('/api/pos/sales/sync', { sales: [salePayload] });
      setStatus('Sale completed & synced', true);
    } catch {
      await enqueueSale(salePayload);
      setStatus('Sale saved — will sync when online', false);
    }
  } else {
    await enqueueSale(salePayload);
    setStatus('Sale saved offline — queued for sync', false);
  }

  cart = [];
  renderCart();
  await loadRecentSales();
}

async function loadRecentSales() {
  const list = document.getElementById('pos-recent');
  if (!list) return;

  try {
    const data = await apiGet('/api/pos/sales');
    recentSales = data.sales || [];
  } catch {
    recentSales = await getLocalSales();
  }

  if (!recentSales.length) {
    list.innerHTML = '<li class="pos-empty">No sales yet</li>';
    return;
  }

  list.innerHTML = recentSales.slice(0, 5).map((s) => `
    <li class="pos-recent-item">
      <span>${formatRs(s.total)}</span>
      <span class="pos-recent-meta">${s.payment_method || 'cash'} · ${s.status || 'pending'}</span>
    </li>
  `).join('');
}

function registerServiceWorker() {
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  }
}

async function init() {
  if (!requireAuth()) return;
  initTheme();
  initShell({ current: 'pos' });
  registerServiceWorker();

  setStatus(navigator.onLine ? 'Online' : 'Offline', navigator.onLine);
  window.addEventListener('online', () => setStatus('Online', true));
  window.addEventListener('offline', () => setStatus('Offline', false));

  document.getElementById('pos-checkout')?.addEventListener('click', completeSale);
  document.getElementById('pos-clear')?.addEventListener('click', () => {
    cart = [];
    renderCart();
  });

  initOutboxSync((result) => {
    if (result.synced > 0) {
      setStatus(`Synced ${result.synced} offline sale(s)`, true);
      loadRecentSales();
    }
  });

  await loadProducts();
  renderCart();
  await loadRecentSales();
}

init();
