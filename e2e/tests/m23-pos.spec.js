import { test, expect } from '@playwright/test';
import { API, registerSubscribedUser } from '../helpers/qa-auth.js';

async function createProduct(request, token, product) {
  const res = await request.post(`${API}/api/products`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    data: product,
  });
  expect(res.status()).toBe(201);
  return (await res.json()).product;
}

async function signIn(page, token) {
  await page.addInitScript((t) => {
    localStorage.setItem('cashflow_auth_token', t);
  }, token);
}

/**
 * The till refuses to sell into a day nobody opened, so every test that rings
 * something up brackets its day the way a real shift would. Done over the API
 * rather than through the prompt: the prompt has its own test below.
 */
async function openDay(request, token, openingAmount = 0) {
  const res = await request.post(`${API}/api/day-book/open`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    data: { opening_amount: openingAmount },
  });
  expect([200, 201]).toContain(res.status());
  return (await res.json()).day_book;
}

test.describe('M23 — Point of sale', () => {
  test('guest is redirected from the till to login', async ({ page }) => {
    await page.goto('/pos.html');
    await page.evaluate(() => localStorage.clear());
    await page.goto('/pos.html');
    await expect(page).toHaveURL(/\/login(\.html)?(\?|$)/, { timeout: 15_000 });
  });

  test('a product can be added and then appears at the till', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_add');
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/products.html');
    await page.fill('#product-name', 'Playwright Cola');
    await page.fill('#product-price', '120');
    await page.fill('#product-stock', '10');
    await page.click('#product-submit');

    await expect(page.locator('#products-list')).toContainText('Playwright Cola', {
      timeout: 15_000,
    });
    await expect(page.locator('#products-list')).toContainText('10 in stock');

    await page.goto('/pos.html');
    await expect(page.locator('#pos-products')).toContainText('Playwright Cola', {
      timeout: 15_000,
    });
  });

  test('completing a sale prints a receipt and reduces stock', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_sell');
    const product = await createProduct(request, token, {
      name: 'Ticket Item',
      price: 250,
      stock_quantity: 8,
    });
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await page.click(`[data-product-id="${product.id}"]`);
    await page.click(`[data-product-id="${product.id}"]`);

    await expect(page.locator('#pos-subtotal')).toHaveText(/500/);
    await expect(page.locator('#pos-total')).toHaveText(/500/);

    await page.fill('#pos-tendered', '1000');
    await expect(page.locator('#pos-change')).toContainText(/Change due/i);

    await page.click('#pos-complete');

    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#pos-receipt-body')).toContainText(/S-\d{6}/);
    await expect(page.locator('#pos-lines-empty')).toBeVisible();

    // Stock came down by the two units sold.
    await page.goto('/products.html');
    await expect(page.locator('#products-list')).toContainText('6 in stock', { timeout: 15_000 });
  });

  test('a discount reduces the total and the till refuses short cash', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_disc');
    const product = await createProduct(request, token, {
      name: 'Discountable',
      price: 200,
      stock_quantity: 5,
    });
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await page.click(`[data-product-id="${product.id}"]`);

    await page.fill('#pos-discount', '50');
    await expect(page.locator('#pos-total')).toHaveText(/150/);

    await page.fill('#pos-tendered', '100');
    await expect(page.locator('#pos-change')).toContainText(/Short/i);

    await page.click('#pos-complete');
    await expect(page.locator('#pos-alert')).toContainText(/less than the total/i);
    await expect(page.locator('#pos-receipt')).toBeHidden();
  });

  test('the till will not oversell tracked stock', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_stock');
    const product = await createProduct(request, token, {
      name: 'Last One',
      price: 90,
      stock_quantity: 1,
    });
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await page.click(`[data-product-id="${product.id}"]`);
    await page.click(`[data-product-id="${product.id}"]`);

    await expect(page.locator('#pos-alert')).toContainText(/Only 1/i);
    await expect(page.locator('#pos-total')).toHaveText(/90/);
  });

  // Sales deliberately no longer post into the cash ledger. The day is
  // bracketed by an opening float and a closing count instead, so the ledger
  // records the drawer once a day rather than once a sale — see the day-book
  // endpoints and DayBookTest.
  test('a sale stays out of the cash flow ledger', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_ledger');
    const product = await createProduct(request, token, {
      name: 'Ledger Item',
      price: 300,
      stock_quantity: 4,
    });
    // A zero float posts no ledger row of its own (there is no money to book),
    // so the drawer stays out of the way of what this test is measuring.
    await openDay(request, token, 0);
    await signIn(page, token);

    await page.goto('/pos.html');
    await page.click(`[data-product-id="${product.id}"]`);
    await page.click('#pos-complete');
    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });

    // The sale itself is recorded...
    const sales = await request
      .get(`${API}/api/sales`, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.json());
    expect(sales.sales.length).toBe(1);
    expect(Number(sales.sales[0].total)).toBe(300);

    // ...but it leaves the ledger untouched.
    const entries = await request
      .get(`${API}/api/cash-entries`, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => r.json());
    expect(entries.entries).toHaveLength(0);
  });

  test('the till will not sell until the day is opened', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_open');
    const product = await createProduct(request, token, {
      name: 'Gated Item',
      price: 400,
      stock_quantity: 3,
    });
    await signIn(page, token);

    // No openDay() here: this is the cold-start state a shop actually opens in.
    await page.goto('/pos.html');
    await expect(page.locator('#pos-day-gate')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#pos-day-summary')).toContainText('Day not opened');
    await expect(page.locator('#pos-complete')).toBeDisabled();

    await page.fill('#pos-open-amount', '5000');
    await page.click('#pos-open-submit');

    await expect(page.locator('#pos-day-gate')).toBeHidden({ timeout: 15_000 });
    await expect(page.locator('#pos-day-summary')).toContainText('Day open');
    await expect(page.locator('#pos-day-summary')).toContainText(/Float:\s*Rs\s*5,000/);
    await expect(page.locator('#pos-day-summary')).toContainText(/In drawer:\s*Rs\s*5,000/);

    // Selling is live again, and the drawer figure follows the cash taken.
    await page.click(`[data-product-id="${product.id}"]`);
    await page.click('#pos-complete');
    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#pos-day-summary')).toContainText(/In drawer:\s*Rs\s*5,400/);
  });

  test('closing the day reports a short drawer and locks the till', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_close');
    const product = await createProduct(request, token, {
      name: 'Closing Item',
      price: 250,
      stock_quantity: 3,
    });
    await openDay(request, token, 1000);
    await signIn(page, token);

    await page.goto('/pos.html');
    await page.click(`[data-product-id="${product.id}"]`);
    await page.click('#pos-complete');
    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });
    await page.click('#pos-new-sale');

    await page.click('#pos-close-day');
    await expect(page.locator('#pos-day-close')).toBeVisible();
    // Float 1,000 + 250 taken in cash.
    await expect(page.locator('#pos-close-expected')).toContainText(/Rs\s*1,250/);

    // The variance is previewed while the count is still editable.
    await page.fill('#pos-close-amount', '1200');
    await expect(page.locator('#pos-close-preview')).toContainText(/Short by\s*Rs\s*50/);

    await page.click('#pos-close-submit');
    await expect(page.locator('#pos-close-variance')).toContainText(/Short by\s*Rs\s*50/, {
      timeout: 15_000,
    });
    await expect(page.locator('#pos-close-summary')).toContainText(/Rs\s*1,200/);

    // Acknowledging the count hands the screen to the closed-day gate, and the
    // till stops selling until tomorrow.
    await page.click('#pos-close-done');
    await expect(page.locator('#pos-day-gate')).toBeVisible();
    await expect(page.locator('#pos-gate-title')).toHaveText('Day closed');
    await expect(page.locator('#pos-closed-variance')).toContainText(/Short by\s*Rs\s*50/);
    await expect(page.locator('#pos-day-summary')).toContainText('Day closed');
    await expect(page.locator('#pos-complete')).toBeDisabled();

    // ...and it is still locked after a reload — the bar is the server's, not
    // this tab's.
    await page.reload();
    await expect(page.locator('#pos-day-gate')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#pos-complete')).toBeDisabled();
  });
});
