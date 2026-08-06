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

test.describe('M23 — Point of sale', () => {
  test('guest is redirected from the till to login', async ({ page }) => {
    await page.goto('/pos.html');
    await page.evaluate(() => localStorage.clear());
    await page.goto('/pos.html');
    await expect(page).toHaveURL(/\/login(\.html)?(\?|$)/, { timeout: 15_000 });
  });

  test('a product can be added and then appears at the till', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_add');
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
    await signIn(page, token);

    await page.goto('/pos.html');
    await page.click(`[data-product-id="${product.id}"]`);
    await page.click(`[data-product-id="${product.id}"]`);

    await expect(page.locator('#pos-alert')).toContainText(/Only 1/i);
    await expect(page.locator('#pos-total')).toHaveText(/90/);
  });

  test('a sale posts income into the cash flow ledger', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_pos_ledger');
    const product = await createProduct(request, token, {
      name: 'Ledger Item',
      price: 300,
      stock_quantity: 4,
    });
    await signIn(page, token);

    await page.goto('/pos.html');
    await page.click(`[data-product-id="${product.id}"]`);
    await page.click('#pos-complete');
    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });

    await page.goto('/cashflow.html');
    await expect(page.locator('#entries-list')).toContainText(/POS sale S-\d{6}/, {
      timeout: 15_000,
    });
    await expect(page.locator('#total-income')).toHaveText(/300/);
  });
});
