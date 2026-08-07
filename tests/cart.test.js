import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import {
  changeDue,
  computeTotals,
  createCart,
  lineTotal,
  lineUnitLabel,
  money,
} from '../js/cart.js';

const product = (over = {}) => ({
  id: 1,
  name: 'Cola 500ml',
  price: 120,
  track_stock: true,
  stock_quantity: 10,
  ...over,
});

/** Atta at Rs 250/kg: Rs 0.25 a gram, 20 kg on the shelf. */
const weighed = (over = {}) => ({
  id: 2,
  name: 'Atta',
  price: 0.25,
  unit_type: 'weight',
  base_unit: 'g',
  price_unit: 'kg',
  track_stock: true,
  stock_quantity: 20_000,
  ...over,
});

describe('money', () => {
  it('rounds to two decimal places', () => {
    assert.equal(money(1.005), 1.01);
    assert.equal(money(0.1 + 0.2), 0.3);
    assert.equal(money(10), 10);
  });

  it('treats non-numbers as zero', () => {
    assert.equal(money('abc'), 0);
    assert.equal(money(null), 0);
    assert.equal(money(Infinity), 0);
  });
});

describe('lineTotal', () => {
  it('multiplies price by quantity', () => {
    assert.equal(lineTotal({ unitPrice: 120, quantity: 3 }), 360);
  });

  it('avoids float drift on fractional prices', () => {
    assert.equal(lineTotal({ unitPrice: 0.1, quantity: 3 }), 0.3);
  });
});

describe('computeTotals', () => {
  const lines = [
    { unitPrice: 120, quantity: 2 },
    { unitPrice: 55.5, quantity: 1 },
  ];

  it('sums the lines and settles in whole rupees', () => {
    // Rs 295.50 is not payable — there is no coin for the half — so the ticket
    // comes to Rs 296 and the half-rupee is named rather than swallowed.
    assert.deepEqual(computeTotals(lines), {
      subtotal: 295.5,
      discount: 0,
      exactTotal: 295.5,
      rounding: 0.5,
      total: 296,
    });
  });

  it('applies a discount', () => {
    assert.deepEqual(computeTotals(lines, 45.5), {
      subtotal: 295.5,
      discount: 45.5,
      exactTotal: 250,
      rounding: 0,
      total: 250,
    });
  });

  it('never lets a discount push the total negative', () => {
    assert.deepEqual(computeTotals(lines, 10_000), {
      subtotal: 295.5,
      discount: 295.5,
      exactTotal: 0,
      rounding: 0,
      total: 0,
    });
  });

  it('ignores a negative discount', () => {
    assert.equal(computeTotals(lines, -50).total, 296);
  });

  it('handles an empty ticket', () => {
    assert.deepEqual(computeTotals([]), {
      subtotal: 0,
      discount: 0,
      exactTotal: 0,
      rounding: 0,
      total: 0,
    });
  });

  it('rounds a weighed ticket down when the paisa fall below half', () => {
    // 150 g of daal at Rs 250/kg is Rs 37.50; a third of a kilo is Rs 83.25.
    const daal = [{ unitPrice: 0.25, quantity: 333 }];
    const totals = computeTotals(daal);

    assert.equal(totals.exactTotal, 83.25);
    assert.equal(totals.total, 83);
    assert.equal(totals.rounding, -0.25);
  });

  it('rounds half a rupee up, matching the server', () => {
    // PHP's round() is half-away-from-zero and so is Math.round for positives.
    // If these two ever disagree the screen and the receipt differ by a rupee.
    assert.equal(computeTotals([{ unitPrice: 0.25, quantity: 250 }]).total, 63);
  });
});

describe('changeDue', () => {
  it('returns the difference when enough was tendered', () => {
    assert.equal(changeDue(250, 500), 250);
    assert.equal(changeDue(250, 250), 0);
  });

  it('returns null when short, rather than negative change', () => {
    assert.equal(changeDue(250, 100), null);
  });

  it('returns null for a non-numeric amount', () => {
    assert.equal(changeDue(250, ''), null);
    assert.equal(changeDue(250, 'abc'), null);
  });
});

describe('createCart', () => {
  it('starts empty', () => {
    const cart = createCart();
    assert.equal(cart.isEmpty(), true);
    assert.equal(cart.count(), 0);
  });

  it('merges repeat scans of the same product into one line', () => {
    const cart = createCart();
    cart.add(product());
    cart.add(product());
    cart.add(product());

    const lines = cart.toArray();
    assert.equal(lines.length, 1);
    assert.equal(lines[0].quantity, 3);
    assert.equal(lines[0].lineTotal, 360);
  });

  it('refuses to add more than the tracked stock', () => {
    const cart = createCart();
    const result = cart.add(product({ stock_quantity: 2 }), 3);

    assert.equal(result.ok, false);
    assert.match(result.reason, /Only 2/);
    assert.equal(cart.isEmpty(), true);
  });

  it('blocks the scan that would tip an existing line over stock', () => {
    const cart = createCart();
    cart.add(product({ stock_quantity: 2 }), 2);
    const result = cart.add(product({ stock_quantity: 2 }));

    assert.equal(result.ok, false);
    assert.equal(cart.count(), 2);
  });

  it('allows any quantity when the product does not track stock', () => {
    const cart = createCart();
    const result = cart.add(product({ track_stock: false, stock_quantity: 0 }), 99);

    assert.equal(result.ok, true);
    assert.equal(cart.count(), 99);
  });

  it('removes the line when quantity is set to zero or below', () => {
    const cart = createCart();
    cart.add(product());

    cart.setQuantity(1, 0);
    assert.equal(cart.isEmpty(), true);

    cart.add(product());
    cart.setQuantity(1, -5);
    assert.equal(cart.isEmpty(), true);
  });

  it('rejects a quantity above stock without changing the line', () => {
    const cart = createCart();
    cart.add(product({ stock_quantity: 4 }));

    const result = cart.setQuantity(1, 9);
    assert.equal(result.ok, false);
    assert.equal(cart.toArray()[0].quantity, 1);
  });

  it('reports an unknown product rather than silently ignoring it', () => {
    const cart = createCart();
    assert.equal(cart.setQuantity(404, 2).ok, false);
  });

  it('builds the API payload from its lines', () => {
    const cart = createCart();
    cart.add(product(), 2);
    cart.add(product({ id: 7, name: 'Chips', price: 60 }));

    assert.deepEqual(cart.toPayloadItems(), [
      { product_id: 1, quantity: 2 },
      { product_id: 7, quantity: 1 },
    ]);
  });

  it('clears every line', () => {
    const cart = createCart();
    cart.add(product());
    cart.clear();
    assert.equal(cart.isEmpty(), true);
  });
});

describe('lineUnitLabel', () => {
  it('shows the weight and the quoted price for measured goods', () => {
    assert.equal(
      lineUnitLabel({ unitPrice: 0.25, quantity: 250, unitType: 'weight', priceUnit: 'kg' }),
      '250 g @ Rs 250 / kg',
    );
    assert.equal(
      lineUnitLabel({ unitPrice: 0.56, quantity: 1500, unitType: 'volume', priceUnit: 'l' }),
      '1.5 L @ Rs 560 / L',
    );
  });

  it('shows only the price for counted goods', () => {
    assert.equal(lineUnitLabel({ unitPrice: 120, quantity: 3, unitType: 'each' }), 'Rs 120 each');
    assert.equal(lineUnitLabel({ unitPrice: 120, quantity: 3 }), 'Rs 120 each');
  });
});

describe('createCart — weighed goods', () => {
  it('adds a pao of atta and prices it per gram', () => {
    const cart = createCart();
    assert.equal(cart.add(weighed(), 250).ok, true);

    const [line] = cart.toArray();
    assert.equal(line.quantity, 250);
    assert.equal(line.lineTotal, 62.5);
    assert.equal(lineUnitLabel(line), '250 g @ Rs 250 / kg');
  });

  it('carries the unit fields onto the line for the ticket to render', () => {
    const cart = createCart();
    cart.add(weighed(), 250);
    cart.add(product());

    const [atta, cola] = cart.toArray();
    assert.equal(atta.unitType, 'weight');
    assert.equal(atta.baseUnit, 'g');
    assert.equal(atta.priceUnit, 'kg');
    assert.deepEqual(
      { unitType: cola.unitType, baseUnit: cola.baseUnit, priceUnit: cola.priceUnit },
      { unitType: 'each', baseUnit: 'pc', priceUnit: 'pc' },
    );
  });

  it('adds one scale step when the tile is tapped with no quantity', () => {
    const cart = createCart();
    cart.add(weighed());

    assert.equal(cart.toArray()[0].quantity, 50);
  });

  it('keeps a fractional quantity instead of flooring it', () => {
    const cart = createCart();
    cart.add(weighed(), 250);

    assert.equal(cart.setQuantity(2, 1.5).ok, true);
    assert.equal(cart.toArray()[0].quantity, 1.5);

    // Rs 50 of atta at Rs 165/kg lands on a figure with a tail.
    assert.equal(cart.setQuantity(2, 303.0303).ok, true);
    assert.equal(cart.toArray()[0].quantity, 303.03);
  });

  it('rounds a quantity to the gram', () => {
    const cart = createCart();
    cart.add(weighed(), 606.0606);

    assert.equal(cart.toArray()[0].quantity, 606.061);
  });

  it('deletes the line when a quantity rounds away to nothing', () => {
    const cart = createCart();
    cart.add(weighed(), 250);

    cart.setQuantity(2, 0.0004);
    assert.equal(cart.isEmpty(), true);
  });

  it('sells the last kilo even when the recorded stock is a hair under it', () => {
    const cart = createCart();
    const result = cart.add(weighed({ stock_quantity: 999.9999 }), 1000);

    assert.equal(result.ok, true);
    assert.equal(cart.toArray()[0].quantity, 1000);
  });

  it('refuses more than the stock, in kilos rather than grams', () => {
    const cart = createCart();
    const result = cart.add(weighed({ stock_quantity: 1500 }), 2000);

    assert.equal(result.ok, false);
    assert.equal(result.reason, 'Only 1.5 kg of Atta in stock.');
    assert.equal(cart.isEmpty(), true);
  });

  it('words a setQuantity rejection the same way', () => {
    const cart = createCart();
    cart.add(weighed({ stock_quantity: 1500 }), 250);

    const result = cart.setQuantity(2, 2000);
    assert.equal(result.ok, false);
    assert.equal(result.reason, 'Only 1.5 kg of Atta in stock.');
    assert.equal(cart.toArray()[0].quantity, 250);
  });

  it('counts a mixed ticket as items, not as grams', () => {
    const cart = createCart();
    cart.add(product(), 2);
    cart.add(weighed(), 250);

    assert.equal(cart.count(), 3);
  });

  it('sends float base quantities to the API', () => {
    const cart = createCart();
    cart.add(weighed(), 250);
    cart.add(product(), 2);

    assert.deepEqual(cart.toPayloadItems(), [
      { product_id: 2, quantity: 250 },
      { product_id: 1, quantity: 2 },
    ]);
  });

  it('totals a mixed ticket', () => {
    const cart = createCart();
    cart.add(weighed(), 1500);
    cart.add(product(), 2);

    assert.deepEqual(computeTotals(cart.toArray()), {
      subtotal: 615,
      discount: 0,
      exactTotal: 615,
      rounding: 0,
      total: 615,
    });
  });
});
