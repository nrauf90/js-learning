import { expect, test } from '@playwright/test';
import { API, registerSubscribedUser } from '../helpers/qa-auth.js';

/**
 * M36 — proof of money taken against the khata.
 *
 * The customer transfers on JazzCash and shows the screenshot at the counter.
 * Three weeks later they say they paid and the notebook says otherwise; this is
 * the file that settles it.
 *
 * Driven through the real dialog rather than the API, for the reason the
 * purchase-receipt spec gives: these files are private, so a thumbnail only
 * appears if the page fetched it as a blob with the bearer token attached. A
 * plain `<img src>` would 401 and leave an empty box that no API test notices.
 */

const auth = (token) => ({
  Authorization: `Bearer ${token}`,
  Accept: 'application/json',
});

const PNG_BYTES = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

function fakeScreenshot(name = 'jazzcash.png') {
  return { name, mimeType: 'image/png', buffer: PNG_BYTES };
}

async function signIn(page, token) {
  await page.addInitScript((t) => {
    localStorage.setItem('cashflow_auth_token', t);
  }, token);
}

async function createProduct(request, token) {
  const res = await request.post(`${API}/api/products`, {
    headers: auth(token),
    data: { name: 'Cola 500ml', price: 120, cost: 90, stock_quantity: 500 },
  });
  expect(res.status()).toBe(201);
  return (await res.json()).product;
}

/** Put a ticket on the customer's page. Returns the sale. */
async function sellOnCredit(request, token, productId, quantity = 2) {
  const res = await request.post(`${API}/api/sales`, {
    headers: auth(token),
    data: {
      items: [{ product_id: productId, quantity }],
      payment_method: 'credit',
      customer_name: 'Bilal Traders',
    },
  });
  expect(res.status()).toBe(201);
  return (await res.json()).sale;
}

async function openKhata(page) {
  await page.goto('/customers.html');
  await expect(page.locator('#khata-body')).not.toContainText('Loading', { timeout: 15_000 });
  await page.locator('#khata-body tr[data-customer-id]').first().click();
  await expect(page.locator('#khata-detail')).toBeVisible();
}

test.describe('M36 — receipts on the khata', () => {
  test('a payment recorded with a screenshot keeps it on the page', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'khata');
    const product = await createProduct(request, token);
    await sellOnCredit(request, token, product.id);

    await signIn(page, token);
    await openKhata(page);

    await page.selectOption('#khata-method', 'jazzcash');
    await page.fill('#khata-amount', '100');
    await page.fill('#khata-reference', 'TRX-4471');
    await page.fill('#khata-received-by', 'Bilal');
    await page.setInputFiles('#khata-receipt-input', fakeScreenshot());
    await expect(page.locator('#khata-receipt-staged')).toContainText('jazzcash.png');

    await page.click('#khata-payment-submit');

    await expect(page.locator('#khata-payment-alert')).toContainText(/1 screenshot attached/i, {
      timeout: 15_000,
    });

    // The proof cell carries it, and it has genuinely loaded — the <img> only
    // appears once the blob has arrived.
    const proof = page.locator('#khata-payments-body .attachment-thumb-mini').first();
    await expect(proof).toBeVisible({ timeout: 15_000 });
    await expect(proof.locator('img')).toBeVisible({ timeout: 15_000 });

    // And the picker is empty again, so the next payment does not re-upload it.
    await expect(page.locator('#khata-receipt-staged')).toBeEmpty();
  });

  /**
   * The case the whole grouping exists for. Rs 300 against two Rs 240 tickets is
   * spread oldest-first and writes two instalment rows; the page shows one
   * payment, so the screenshot has to appear on that one line.
   */
  test('one transfer split across two tickets shows its proof on a single row', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'khata');
    const product = await createProduct(request, token);
    await sellOnCredit(request, token, product.id);
    await sellOnCredit(request, token, product.id);

    await signIn(page, token);
    await openKhata(page);

    await page.selectOption('#khata-method', 'jazzcash');
    await page.fill('#khata-amount', '300');
    await page.setInputFiles('#khata-receipt-input', fakeScreenshot());
    await page.click('#khata-payment-submit');

    await expect(page.locator('#khata-payment-alert')).toContainText(/1 screenshot attached/i, {
      timeout: 15_000,
    });

    // One payment row, naming both tickets, carrying one thumbnail.
    const rows = page.locator('#khata-payments-body tr');
    await expect(rows).toHaveCount(1);
    await expect(page.locator('#khata-payments-body .attachment-thumb-mini')).toHaveCount(1);
    await expect(page.locator('#khata-payments-body .attachment-thumb-mini img')).toBeVisible({
      timeout: 15_000,
    });
  });

  /**
   * A screenshot picked for one customer and then abandoned must not be
   * uploaded against the next customer's payment — the staged list is plain JS
   * state that form.reset() does not touch.
   */
  test('a screenshot abandoned on one khata does not follow you to another', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'khata');
    const product = await createProduct(request, token);

    // Two customers, each owing something.
    await sellOnCredit(request, token, product.id);
    const second = await request.post(`${API}/api/sales`, {
      headers: auth(token),
      data: {
        items: [{ product_id: product.id, quantity: 1 }],
        payment_method: 'credit',
        customer_name: 'Zainab Kirana',
      },
    });
    expect(second.status()).toBe(201);

    await signIn(page, token);
    await page.goto('/customers.html');
    await expect(page.locator('#khata-body')).not.toContainText('Loading', { timeout: 15_000 });

    // Stage against the first, then walk away.
    await page.locator('#khata-body tr[data-customer-id]').first().click();
    await expect(page.locator('#khata-detail')).toBeVisible();
    await page.setInputFiles('#khata-receipt-input', fakeScreenshot('abandoned.png'));
    await expect(page.locator('#khata-receipt-staged')).toContainText('abandoned.png');
    await page.click('#khata-detail-close');

    // The second customer's dialog opens with nothing staged.
    await page.locator('#khata-body tr[data-customer-id]').nth(1).click();
    await expect(page.locator('#khata-detail')).toBeVisible();
    await expect(page.locator('#khata-receipt-staged')).toBeEmpty();
  });

  test('a file that is not an image is refused before it costs a round trip', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'khata');
    const product = await createProduct(request, token);
    await sellOnCredit(request, token, product.id);

    await signIn(page, token);
    await openKhata(page);

    await page.setInputFiles('#khata-receipt-input', {
      name: 'statement.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4 not really a pdf'),
    });

    await expect(page.locator('#khata-payment-alert')).toContainText(/JPG, PNG or WebP/i);
    await expect(page.locator('#khata-receipt-staged')).toBeEmpty();
  });

  test('another shop cannot fetch your khata proof', async ({ request }) => {
    const { token } = await registerSubscribedUser(request, 'khata');
    const product = await createProduct(request, token);
    await sellOnCredit(request, token, product.id);

    const customers = await request.get(`${API}/api/customers`, { headers: auth(token) });
    const customerId = (await customers.json()).customers[0].id;

    const paid = await request.post(`${API}/api/customers/${customerId}/payments`, {
      headers: auth(token),
      data: { amount: 100, method: 'jazzcash' },
    });
    expect(paid.status()).toBe(200);
    const paymentId = (await paid.json()).payment_id;

    const upload = await request.post(`${API}/api/sale-payments/${paymentId}/attachments`, {
      headers: auth(token),
      multipart: { image: fakeScreenshot() },
    });
    expect(upload.status()).toBe(201);
    const attachmentId = (await upload.json()).attachment.id;

    // The shop that took the money reads it back.
    const mine = await request.get(`${API}/api/attachments/${attachmentId}`, {
      headers: auth(token),
    });
    expect(mine.status()).toBe(200);
    expect(mine.headers()['content-type']).toContain('image/');

    // Nobody else does.
    const { token: strangerToken } = await registerSubscribedUser(request, 'stranger');
    const theirs = await request.get(`${API}/api/attachments/${attachmentId}`, {
      headers: auth(strangerToken),
    });
    expect(theirs.status()).toBe(403);

    const guest = await request.get(`${API}/api/attachments/${attachmentId}`, {
      headers: { Accept: 'application/json' },
    });
    expect(guest.status()).toBe(401);
  });
});
