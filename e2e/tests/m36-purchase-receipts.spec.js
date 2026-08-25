import { expect, test } from '@playwright/test';
import { API, registerSubscribedUser } from '../helpers/qa-auth.js';

/**
 * M36 — the paperwork behind stock coming in and money going out.
 *
 * A wholesaler's boy leaves a hand-written bill at the back door; three weeks
 * later the wholesaler says an instalment never arrived. Before this the shop's
 * answer was whether anyone still had the paper.
 *
 * The tests below drive the Stock In screen itself rather than the API, because
 * the interesting part is entirely in the browser: these files are private, so
 * every thumbnail has to be fetched as a blob with the bearer token attached —
 * a plain <img src="/api/attachments/1"> gets a 401. If that ever regresses the
 * gallery renders empty placeholders and only a real page catches it.
 */

const auth = (token) => ({
  Authorization: `Bearer ${token}`,
  Accept: 'application/json',
});

/**
 * A 1x1 PNG. Small on purpose — the point is exercising the upload path, and
 * pushing a real 3 MB screenshot through it would only make the suite slower.
 */
const PNG_BYTES = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
  'base64'
);

function fakeReceipt(name = 'bill.png') {
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
    data: {
      name: 'Atta',
      unit_type: 'weight',
      price_unit: 'kg',
      price: 250,
      stock_quantity: 0,
    },
  });
  expect(res.status()).toBe(201);
  return (await res.json()).product;
}

/** Books a delivery through the API, for tests that start from the invoice. */
async function bookDelivery(request, token, productId) {
  const res = await request.post(`${API}/api/purchases`, {
    headers: auth(token),
    data: {
      items: [{ product_id: productId, quantity: 200, unit_cost: 200 }],
    },
  });
  expect(res.status()).toBe(201);
  return (await res.json()).purchase;
}

async function openInvoice(page, purchaseId) {
  await page.goto('/purchases.html');
  await expect(page.locator('#purchases-body')).not.toContainText('Loading', { timeout: 15_000 });
  await page.locator(`tr[data-purchase-id="${purchaseId}"]`).first().click();
  await expect(page.locator('#purchase-detail')).toBeVisible();
}

test.describe('M36 — receipts on stock in', () => {
  test('a delivery booked in with a photo of the bill keeps it on the invoice', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'receipt');
    const product = await createProduct(request, token);
    await signIn(page, token);

    await page.goto('/purchases.html');
    await expect(page.locator('#purchase-product')).toContainText('Atta', { timeout: 15_000 });

    // Type the delivery up the way the shopkeeper does.
    // By value, not label: the option text carries the unit price too
    // ('Atta — Rs 250 / kg'), so matching on the name alone would not select.
    await page.selectOption('#purchase-product', String(product.id));
    await page.click('#purchase-add-line');

    const line = page.locator('#purchase-lines .pos-line').first();
    await expect(line).toBeVisible();
    await line.locator('[data-field="quantity"]').fill('200');
    await line.locator('[data-field="unitCost"]').fill('200');

    // The bill, photographed at the back door.
    await page.setInputFiles('#purchase-receipt-input', fakeReceipt('wholesaler-bill.png'));
    await expect(page.locator('#purchase-receipt-staged')).toContainText('wholesaler-bill.png');

    await page.click('#purchase-submit');

    // The success line says the receipt went with it — the upload happens after
    // the delivery is saved, because the attachment needs its id.
    await expect(page.locator('#purchases-alert')).toContainText(/1 receipt attached/i, {
      timeout: 15_000,
    });

    // The picker is cleared, so the next delivery does not re-upload this bill.
    await expect(page.locator('#purchase-receipt-staged')).toBeEmpty();

    // And it is on the invoice, with its bytes actually fetched and rendered.
    await page.locator('#purchases-body tr[data-purchase-id]').first().click();
    await expect(page.locator('#purchase-detail')).toBeVisible();

    const thumb = page.locator('#purchase-detail-attachments .attachment-thumb').first();
    await expect(thumb).toBeVisible();
    await expect(thumb.locator('img')).toBeVisible({ timeout: 15_000 });
  });

  test('a staged file can be dropped again before the delivery is saved', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'receipt');
    await createProduct(request, token);
    await signIn(page, token);

    await page.goto('/purchases.html');
    await expect(page.locator('#purchase-product')).toContainText('Atta', { timeout: 15_000 });

    await page.setInputFiles('#purchase-receipt-input', fakeReceipt('wrong-bill.png'));
    await expect(page.locator('#purchase-receipt-staged')).toContainText('wrong-bill.png');

    await page.locator('#purchase-receipt-staged .attachment-remove').first().click();
    await expect(page.locator('#purchase-receipt-staged')).toBeEmpty();
  });

  test('a payment screenshot is filed against the instalment it paid', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'receipt');
    const product = await createProduct(request, token);
    const purchase = await bookDelivery(request, token, product.id);

    await signIn(page, token);
    await openInvoice(page, purchase.id);

    // Pay part of it, with the transfer screenshot attached.
    await page.selectOption('#purchase-payment-method', 'jazzcash');
    await page.fill('#purchase-payment-amount', '15000');
    await page.fill('#purchase-payment-reference', 'TRX-99881');
    await page.setInputFiles('#purchase-payment-receipt-input', fakeReceipt('jazzcash.png'));
    await expect(page.locator('#purchase-payment-receipt-staged')).toContainText('jazzcash.png');

    await page.click('#purchase-payment-submit');

    await expect(page.locator('#purchase-payment-alert')).toContainText(/1 receipt attached/i, {
      timeout: 15_000,
    });

    // The proof column on the history row carries the screenshot, and it has
    // genuinely loaded — an <img> only appears once the blob has arrived.
    const proof = page.locator('#purchase-payments-body .attachment-thumb-mini').first();
    await expect(proof).toBeVisible({ timeout: 15_000 });
    await expect(proof.locator('img')).toBeVisible({ timeout: 15_000 });
  });

  test('a bill can be added to a delivery after the fact, and taken off again', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'receipt');
    const product = await createProduct(request, token);
    const purchase = await bookDelivery(request, token, product.id);

    await signIn(page, token);
    await openInvoice(page, purchase.id);

    await expect(page.locator('#purchase-detail-attachments')).toContainText(
      /No bill photographed/i
    );

    await page.setInputFiles('#purchase-detail-receipt-input', fakeReceipt('late-bill.png'));

    const thumb = page.locator('#purchase-detail-attachments .attachment-thumb').first();
    await expect(thumb.locator('img')).toBeVisible({ timeout: 15_000 });

    // And off again. The confirm is what stands between a mis-tap and a
    // deleted receipt, so it is answered rather than suppressed.
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('#purchase-detail-attachments .attachment-remove').first().click();

    await expect(page.locator('#purchase-detail-attachments')).toContainText(
      /No bill photographed/i,
      { timeout: 15_000 }
    );
  });

  test('a file that is not an image is refused before it costs a round trip', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'receipt');
    await createProduct(request, token);
    await signIn(page, token);

    await page.goto('/purchases.html');
    await expect(page.locator('#purchase-product')).toContainText('Atta', { timeout: 15_000 });

    await page.setInputFiles('#purchase-receipt-input', {
      name: 'invoice.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF-1.4 not really a pdf'),
    });

    await expect(page.locator('#purchases-alert')).toContainText(/JPG, PNG or WebP/i);
    await expect(page.locator('#purchase-receipt-staged')).toBeEmpty();
  });

  test('another shop cannot fetch your receipt', async ({ request }) => {
    const { token } = await registerSubscribedUser(request, 'receipt');
    const product = await createProduct(request, token);
    const purchase = await bookDelivery(request, token, product.id);

    const upload = await request.post(`${API}/api/purchases/${purchase.id}/attachments`, {
      headers: auth(token),
      multipart: { image: fakeReceipt('bill.png') },
    });
    expect(upload.status()).toBe(201);
    const attachmentId = (await upload.json()).attachment.id;

    // The owner reads it back.
    const mine = await request.get(`${API}/api/attachments/${attachmentId}`, {
      headers: auth(token),
    });
    expect(mine.status()).toBe(200);
    expect(mine.headers()['content-type']).toContain('image/');

    // A different shop does not.
    const { token: strangerToken } = await registerSubscribedUser(request, 'stranger');
    const theirs = await request.get(`${API}/api/attachments/${attachmentId}`, {
      headers: auth(strangerToken),
    });
    expect(theirs.status()).toBe(403);

    // Neither does a passer-by with no token at all.
    const guest = await request.get(`${API}/api/attachments/${attachmentId}`, {
      headers: { Accept: 'application/json' },
    });
    expect(guest.status()).toBe(401);
  });
});
