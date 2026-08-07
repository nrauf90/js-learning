import { test, expect } from '@playwright/test';
import { API, registerSubscribedUser } from '../helpers/qa-auth.js';

/**
 * M35 — the kiryana pack.
 *
 * The through-line of every test here is that a Pakistani grocery does not sell
 * in whole units. Half the shelf is scooped out of a sack, priced by the kilo
 * and asked for either by weight ("ek pao daal") or by money ("pachaas ka daal
 * do"). The keypad tests are the ones that matter most — they are the only
 * place the two directions of that conversion are exercised through real DOM.
 */

const auth = (token) => ({
  Authorization: `Bearer ${token}`,
  Accept: 'application/json',
});

async function createProduct(request, token, product) {
  const res = await request.post(`${API}/api/products`, {
    headers: auth(token),
    data: product,
  });
  expect(res.status()).toBe(201);
  return (await res.json()).product;
}

/** Daal Chana at Rs 250/kg with 20 kg in the sack. */
async function createDaal(request, token, over = {}) {
  return createProduct(request, token, {
    name: 'Daal Chana',
    unit_type: 'weight',
    price_unit: 'kg',
    price: 250,
    cost: 180,
    stock_quantity: 20,
    ...over,
  });
}

async function signIn(page, token) {
  await page.addInitScript((t) => {
    localStorage.setItem('cashflow_auth_token', t);
  }, token);
}

/** The till refuses to sell into a day nobody opened. */
async function openDay(request, token, openingAmount = 0) {
  const res = await request.post(`${API}/api/day-book/open`, {
    headers: auth(token),
    data: { opening_amount: openingAmount },
  });
  expect([200, 201]).toContain(res.status());
}

/** Tap a catalogue tile at the till and wait for the weigh keypad to come up. */
async function openKeypad(page, name) {
  await expect(page.locator('#pos-products')).toContainText(name, { timeout: 15_000 });
  await page.locator('.pos-product', { hasText: name }).first().click();
  await expect(page.locator('#pos-weigh')).toBeVisible();
}

async function pressKeys(page, digits) {
  for (const key of String(digits).split('')) {
    await page.locator(`.pos-weigh-key[data-key="${key}"]`).click();
  }
}

test.describe('M35 — Loose goods at the till', () => {
  test('a weighed product shows its quoted rate, not the per-gram price', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_rate');
    await createDaal(request, token);
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');

    // Stored per gram (0.25) but never shown that way — "Rs 0.25" on a tile
    // reads as a pricing bug to the shopkeeper.
    const tile = page.locator('.pos-product', { hasText: 'Daal Chana' }).first();
    await expect(tile).toContainText('Rs 250 / kg', { timeout: 15_000 });
    await expect(tile).toContainText('20 kg in stock');
  });

  test('"ek pao daal" — 250 g is priced at Rs 62.50', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_weight');
    await createDaal(request, token);
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await openKeypad(page, 'Daal Chana');

    // The quick chips carry the words a counter actually uses.
    await page.locator('.pos-weigh-chip', { hasText: 'Pao' }).first().click();
    await expect(page.locator('#pos-weigh-derived')).toContainText('62.5');

    await page.locator('#pos-weigh-add').click();
    await expect(page.locator('#pos-weigh')).toBeHidden();

    await expect(page.locator('#pos-lines')).toContainText('250 g');
    await expect(page.locator('#pos-lines')).toContainText('Rs 250 / kg');
  });

  test('"pachaas ka daal" — Rs 50 works back to 200 g', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_amount');
    await createDaal(request, token);
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await openKeypad(page, 'Daal Chana');

    // The direction no till here handles well: the customer names the money and
    // the shopkeeper needs to know what to put on the scale.
    await page.locator('.pos-weigh-tab[data-mode="amount"]').click();
    await pressKeys(page, '50');

    await expect(page.locator('#pos-weigh-derived')).toContainText('200 g');

    await page.locator('#pos-weigh-add').click();
    await expect(page.locator('#pos-lines')).toContainText('200 g');
  });

  test('the keypad switches between grams and kilos without losing the weight', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_units');
    await createDaal(request, token);
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await openKeypad(page, 'Daal Chana');

    await pressKeys(page, '500');
    await expect(page.locator('#pos-weigh-derived')).toContainText('125');

    // A cashier who realises the scale is in kilos meant the same bag either way.
    await page.locator('.pos-weigh-unit[data-unit="kg"]').click();
    await expect(page.locator('#pos-weigh-typed')).toHaveText('0.5');
    await expect(page.locator('#pos-weigh-derived')).toContainText('125');
  });

  test('a counted product still takes one tap and no keypad', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_counted');
    await createProduct(request, token, {
      name: 'Cola 500ml',
      price: 120,
      stock_quantity: 10,
    });
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await expect(page.locator('#pos-products')).toContainText('Cola 500ml', { timeout: 15_000 });
    await page.locator('.pos-product', { hasText: 'Cola 500ml' }).first().click();

    // No keypad for packets — the old flow is untouched.
    await expect(page.locator('#pos-weigh')).toBeHidden();
    await expect(page.locator('#pos-lines')).toContainText('Cola 500ml');
    await expect(page.locator('#pos-lines')).toContainText('Rs 120 each');
  });

  test('a weighed sale completes and takes the weight off the sack', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_sell');
    const daal = await createDaal(request, token);
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await openKeypad(page, 'Daal Chana');
    await page.locator('.pos-weigh-chip', { hasText: 'Adha kg' }).first().click();
    await page.locator('#pos-weigh-add').click();

    await page.locator('#pos-complete').click();
    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#pos-receipt-body')).toContainText('500 g');

    const res = await request.get(`${API}/api/products/${daal.id}`, { headers: auth(token) });
    expect(res.ok()).toBeTruthy();
    // 20 kg less half a kilo, held in grams.
    expect((await res.json()).product.stock_quantity).toBe(19500);
  });
});

test.describe('M35 — Stock in, khata and wastage', () => {
  test('a delivery raises stock and blends the cost', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_buy');
    const daal = await createDaal(request, token, { cost: 180, stock_quantity: 10 });
    await signIn(page, token);

    // 10 kg already on the shelf at Rs 180; 10 kg more at Rs 220 averages to 200.
    const res = await request.post(`${API}/api/purchases`, {
      headers: auth(token),
      data: {
        purchase_date: new Date().toISOString().slice(0, 10),
        items: [{ product_id: daal.id, quantity: 10, unit_cost: 220 }],
      },
    });
    expect([200, 201]).toContain(res.status());

    const after = await request.get(`${API}/api/products/${daal.id}`, { headers: auth(token) });
    const product = (await after.json()).product;
    expect(product.stock_quantity).toBe(20000);
    // Rs 200/kg is Rs 0.20/g — the weighted average, not the latest van's price.
    expect(Number(product.cost)).toBeCloseTo(0.2, 4);

    // The list is one row per delivery — reference and money, not a product
    // breakdown, which lives behind the row.
    await page.goto('/purchases.html');
    await expect(page.locator('#purchases-body')).toContainText('2,200', { timeout: 15_000 });
  });

  test('a credit sale opens a khata page and shows what is owed', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_khata');
    const daal = await createDaal(request, token);
    await openDay(request, token);

    const sale = await request.post(`${API}/api/sales`, {
      headers: auth(token),
      data: {
        items: [{ product_id: daal.id, quantity: 1000 }],
        payment_method: 'credit',
        customer_name: 'Ali Raza',
        customer_phone: '03001234567',
        paid_amount: 100,
      },
    });
    expect(sale.status()).toBe(201);

    await signIn(page, token);
    await page.goto('/customers.html');

    // Rs 250 for the kilo, Rs 100 handed over, Rs 150 still on the book.
    await expect(page.locator('#khata-body')).toContainText('Ali Raza', { timeout: 15_000 });
    await expect(page.locator('#khata-body')).toContainText('150');
    await expect(page.locator('#khata-summary')).toContainText('150');
  });

  /**
   * The bug this covers: a khata page could be opened from the customers screen
   * but there was no way to ring a sale against it, and a ticket completed with
   * an empty "Amount received" was filed as paid. Udhaar now has its own button,
   * and a ticket saved with no deposit has to come back `pending` — the moment
   * it comes back `paid` the shop has silently written off the debt.
   */
  test('an udhaar sale with a new customer is pending and lands on the khata', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_udhaar');
    await createDaal(request, token);
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await openKeypad(page, 'Daal Chana');
    await page.locator('.pos-weigh-chip', { hasText: '1 kg' }).first().click();
    await page.locator('#pos-weigh-add').click();

    // Credit is deliberately not reachable from the payment dropdown.
    await expect(page.locator('#pos-method option[value="credit"]')).toHaveCount(0);

    await page.locator('#pos-credit').click();
    await expect(page.locator('#pos-udhaar')).toBeVisible();
    await expect(page.locator('#pos-udhaar-book')).toContainText('250');

    // Nobody on the khata yet, so the dropdown has nothing to offer and stays
    // out of the way — the name typed into it is the new page's name.
    await page.locator('#pos-udhaar-search').fill('Bilal Ahmed');
    await expect(page.locator('#pos-udhaar-results')).toBeHidden();
    await expect(page.locator('#pos-udhaar-new-note')).toContainText('Bilal Ahmed');

    await page.locator('#pos-udhaar-save').click();

    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });
    // The cashier is told what happened, not left to read it off the slip.
    await expect(page.locator('#pos-receipt-state')).toContainText('Nothing taken');
    await expect(page.locator('#pos-receipt-state')).toContainText('Bilal Ahmed');
    await expect(page.locator('#pos-receipt-body')).toContainText('UNPAID');

    const sales = await request.get(`${API}/api/sales`, { headers: auth(token) });
    const [sale] = (await sales.json()).sales;
    expect(sale.payment_status).toBe('pending');
    expect(sale.payment_method).toBe('credit');
    expect(sale.customer_name).toBe('Bilal Ahmed');
    expect(sale.outstanding_amount).toBe(250);

    // SaleService opened the khata page itself — the till never posted one.
    const khata = await request.get(`${API}/api/customers/outstanding`, { headers: auth(token) });
    const owing = await khata.json();
    expect(owing.customers.map((c) => c.name)).toContain('Bilal Ahmed');
    expect(owing.total_outstanding).toBe(250);
  });

  test('a deposit at the till leaves the rest on the book as partial', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_deposit');
    await createDaal(request, token);
    await openDay(request, token);
    await signIn(page, token);

    await page.goto('/pos.html');
    await openKeypad(page, 'Daal Chana');
    await page.locator('.pos-weigh-chip', { hasText: '1 kg' }).first().click();
    await page.locator('#pos-weigh-add').click();

    await page.locator('#pos-credit').click();
    await expect(page.locator('#pos-udhaar')).toBeVisible();

    await page.locator('#pos-udhaar-search').fill('Sana Khan');
    await page.locator('#pos-udhaar-phone').fill('03007654321');
    await page.locator('#pos-udhaar-deposit').fill('100');
    // Rs 250 for the kilo less the Rs 100 handed over.
    await expect(page.locator('#pos-udhaar-book')).toContainText('150');

    await page.locator('#pos-udhaar-save').click();

    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('#pos-receipt-state')).toContainText('150');
    // The slip has to name the deposit; "On credit 100" reads as the opposite.
    await expect(page.locator('#pos-receipt-body')).toContainText('Deposit paid');
    await expect(page.locator('#pos-receipt-body')).toContainText('Balance due');

    const sales = await request.get(`${API}/api/sales`, { headers: auth(token) });
    const [sale] = (await sales.json()).sales;
    expect(sale.payment_status).toBe('partial');
    expect(sale.paid_amount).toBe(100);
    expect(sale.outstanding_amount).toBe(150);
  });

  /**
   * A till often runs on a laptop and the cashier's hands are already on the
   * keys, so the dropdown has to be drivable without the mouse — and the first
   * Escape has to belong to the list, not to the dialog, or putting the names
   * away throws the whole ticket back at the counter.
   */
  test('a khata page already on the book can be picked at the till', async ({ page, request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_pick');
    await createDaal(request, token);
    await openDay(request, token);

    // Registered on the customers screen — the case the shop owner reported as
    // unreachable from the till.
    const created = await request.post(`${API}/api/customers`, {
      headers: auth(token),
      data: { name: 'Imran Qureshi', phone: '03111234567', credit_limit: 5000 },
    });
    expect(created.status()).toBe(201);
    const customer = (await created.json()).customer;

    await signIn(page, token);
    await page.goto('/pos.html');
    await openKeypad(page, 'Daal Chana');
    await page.locator('.pos-weigh-chip', { hasText: 'Pao' }).first().click();
    await page.locator('#pos-weigh-add').click();

    await page.locator('#pos-credit').click();
    const panel = page.locator('#pos-udhaar-results');
    const search = page.locator('#pos-udhaar-search');

    await search.fill('Imr');
    await expect(panel).toBeVisible();
    await expect(search).toHaveAttribute('aria-expanded', 'true');

    // The first Escape puts the list away and leaves the ticket alone.
    await page.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(page.locator('#pos-udhaar')).toBeVisible();

    await page.keyboard.press('ArrowDown');
    await expect(panel).toBeVisible();
    await page.keyboard.press('ArrowDown');
    await expect(search).toHaveAttribute('aria-activedescendant', 'pos-udhaar-option-0');
    await page.keyboard.press('Enter');

    // Picking names the page the sale posts against, and shows what it carries.
    await expect(panel).toBeHidden();
    await expect(page.locator('#pos-udhaar-picked-name')).toHaveText('Imran Qureshi');
    await expect(page.locator('#pos-udhaar-customer')).toContainText('Khata limit');
    await expect(page.locator('#pos-udhaar-customer')).toContainText('5,000');

    await page.locator('#pos-udhaar-save').click();
    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });

    // One page, not a second one under the same name.
    const khata = await request.get(`${API}/api/customers`, { headers: auth(token) });
    const list = (await khata.json()).customers;
    expect(list).toHaveLength(1);
    expect(list[0].id).toBe(customer.id);
    // Rs 62.50 for the pao, settled in whole rupees — paisa do not circulate.
    expect(list[0].balance).toBe(63);
  });

  /**
   * The shop owner's complaint: the khata arrived as a permanently expanded
   * wall of names that pushed the deposit box and the save button off the
   * dialog. It is a dropdown now, and the two things that change must not break
   * are that a real click reaches an option — the panel is out of the card's
   * flex flow precisely because in it the list collapsed to nothing while still
   * counting as visible — and that the pick lands on the page that already
   * exists rather than opening a second one under the same name.
   */
  test('the khata dropdown filters as you type, and picking collapses it', async ({
    page,
    request,
  }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_combo');
    await createDaal(request, token);
    await openDay(request, token);

    // Two pages, so the search has something to narrow down.
    for (const data of [
      { name: 'Imran Qureshi', phone: '03111234567', credit_limit: 5000 },
      { name: 'Nadia Aslam', phone: '03119876543' },
    ]) {
      const res = await request.post(`${API}/api/customers`, { headers: auth(token), data });
      expect(res.status()).toBe(201);
    }

    await signIn(page, token);
    await page.goto('/pos.html');
    await openKeypad(page, 'Daal Chana');
    await page.locator('.pos-weigh-chip', { hasText: 'Pao' }).first().click();
    await page.locator('#pos-weigh-add').click();

    await page.locator('#pos-credit').click();
    const panel = page.locator('#pos-udhaar-results');

    // Nothing typed and nothing picked: the dialog opens at its shortest.
    await expect(panel).toBeHidden();
    await expect(page.locator('#pos-udhaar-search')).toHaveAttribute('aria-expanded', 'false');

    await page.locator('#pos-udhaar-search').fill('Imr');
    await expect(panel).toBeVisible();
    await expect(panel.locator('.pos-udhaar-option')).toHaveCount(1);
    // A panel squeezed to zero height still reads as visible to the DOM, and
    // the click would land on whatever was behind it.
    expect((await panel.boundingBox()).height).toBeGreaterThan(20);

    await panel.locator('.pos-udhaar-option', { hasText: 'Imran Qureshi' }).click();

    // Collapsed, and the search box has given way to the chosen page.
    await expect(panel).toBeHidden();
    await expect(page.locator('#pos-udhaar-search')).toBeHidden();
    await expect(page.locator('#pos-udhaar-picked-name')).toHaveText('Imran Qureshi');
    await expect(page.locator('#pos-udhaar-picked-phone')).toHaveText('03111234567');
    await expect(page.locator('#pos-udhaar-customer')).toContainText('Already owes');

    await page.locator('#pos-udhaar-save').click();
    await expect(page.locator('#pos-receipt')).toBeVisible({ timeout: 15_000 });

    // Still two pages: the sale went onto Imran's, not onto a duplicate.
    const khata = await request.get(`${API}/api/customers`, { headers: auth(token) });
    const list = (await khata.json()).customers;
    expect(list).toHaveLength(2);
    expect(list.find((c) => c.name === 'Imran Qureshi').balance).toBe(63);
    expect(list.find((c) => c.name === 'Nadia Aslam').balance).toBe(0);
  });

  test('spoiled stock needs a reason and is valued in rupees', async ({ request }) => {
    const { token } = await registerSubscribedUser(request, 'qa_m35_waste');
    const daal = await createDaal(request, token);

    // A write-off with no reason is refused — "adjustment" answers nothing.
    const bare = await request.post(`${API}/api/products/${daal.id}/stock`, {
      headers: auth(token),
      data: { quantity_delta: -1, type: 'adjustment' },
    });
    expect(bare.status()).toBe(422);

    const spoiled = await request.post(`${API}/api/products/${daal.id}/stock`, {
      headers: auth(token),
      data: { quantity_delta: -1, type: 'adjustment', reason: 'spoiled' },
    });
    expect(spoiled.ok()).toBeTruthy();

    const after = await request.get(`${API}/api/products/${daal.id}`, { headers: auth(token) });
    expect((await after.json()).product.stock_quantity).toBe(19000);
  });
});
