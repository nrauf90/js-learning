import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { changeDue, computeTotals, createCart, lineTotal, money } from '../js/cart.js';

const product = (over = {}) => ({
  id: 1,
  name: 'Cola 500ml',
  price: 120,
  track_stock: true,
  stock_quantity: 10,
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

  it('sums the lines', () => {
    assert.deepEqual(computeTotals(lines), { subtotal: 295.5, discount: 0, total: 295.5 });
  });

  it('applies a discount', () => {
    assert.deepEqual(computeTotals(lines, 45.5), { subtotal: 295.5, discount: 45.5, total: 250 });
  });

  it('never lets a discount push the total negative', () => {
    assert.deepEqual(computeTotals(lines, 10_000), { subtotal: 295.5, discount: 295.5, total: 0 });
  });

  it('ignores a negative discount', () => {
    assert.equal(computeTotals(lines, -50).total, 295.5);
  });

  it('handles an empty ticket', () => {
    assert.deepEqual(computeTotals([]), { subtotal: 0, discount: 0, total: 0 });
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
