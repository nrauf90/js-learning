import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import {
  TYPE_EACH,
  TYPE_VOLUME,
  TYPE_WEIGHT,
  amountForQuantity,
  codesForType,
  formatQuantity,
  formatUnitPrice,
  fromBase,
  isMeasured,
  priceFromBase,
  priceToBase,
  quantityForAmount,
  stepFor,
  toBase,
} from '../js/units.js';

describe('toBase / fromBase', () => {
  it('converts kilos to grams and back', () => {
    assert.equal(toBase(1.5, 'kg'), 1500);
    assert.equal(fromBase(1500, 'kg'), 1.5);
    assert.equal(fromBase(toBase(0.25, 'kg'), 'kg'), 0.25);
  });

  it('converts litres to millilitres and back', () => {
    assert.equal(toBase(2, 'l'), 2000);
    assert.equal(fromBase(750, 'l'), 0.75);
    assert.equal(fromBase(toBase(1.25, 'l'), 'l'), 1.25);
  });

  it('converts dozens to pieces and back', () => {
    assert.equal(toBase(2, 'dozen'), 24);
    assert.equal(fromBase(24, 'dozen'), 2);
    assert.equal(fromBase(6, 'dozen'), 0.5);
  });

  it('leaves base units alone', () => {
    assert.equal(toBase(250, 'g'), 250);
    assert.equal(fromBase(250, 'g'), 250);
    assert.equal(toBase(3, 'pc'), 3);
  });

  it('treats an unknown unit as one base unit', () => {
    assert.equal(toBase(5, 'bushel'), 5);
    assert.equal(fromBase(5, undefined), 5);
  });

  it('treats non-numbers as zero', () => {
    assert.equal(toBase('abc', 'kg'), 0);
    assert.equal(fromBase(null, 'kg'), 0);
  });
});

describe('priceToBase / priceFromBase', () => {
  it('turns Rs 250 per kg into 0.25 per gram', () => {
    assert.equal(priceToBase(250, 'kg'), 0.25);
    assert.equal(priceFromBase(0.25, 'kg'), 250);
  });

  it('keeps cheap goods accurate to the fourth decimal', () => {
    // Rs 40/kg is 0.04/g — at 2dp it would round to 0.04 anyway, but Rs 4/kg
    // would collapse to 0.00 and the line would be free.
    assert.equal(priceToBase(40, 'kg'), 0.04);
    assert.equal(priceFromBase(0.04, 'kg'), 40);
    assert.equal(priceToBase(4, 'kg'), 0.004);
    assert.equal(priceFromBase(0.004, 'kg'), 4);
  });

  it('round-trips a litre price', () => {
    assert.equal(priceToBase(560, 'l'), 0.56);
    assert.equal(priceFromBase(priceToBase(560, 'l'), 'l'), 560);
  });

  it('round-trips a dozen price', () => {
    assert.equal(priceToBase(360, 'dozen'), 30);
    assert.equal(priceFromBase(30, 'dozen'), 360);
  });

  it('leaves a per-piece price untouched', () => {
    assert.equal(priceToBase(120, 'pc'), 120);
    assert.equal(priceFromBase(120, 'pc'), 120);
  });
});

describe('quantityForAmount', () => {
  it('gives exactly 200 g for Rs 50 of daal at Rs 250 per kg', () => {
    assert.equal(quantityForAmount(50, priceToBase(250, 'kg')), 200);
  });

  it('carries the remainder in the weight, not the money', () => {
    // Rs 100 of atta at Rs 165/kg — the customer named the money, so the
    // scale takes the awkward figure.
    assert.equal(quantityForAmount(100, priceToBase(165, 'kg')), 606.061);
  });

  it('returns zero for a price of zero or less', () => {
    assert.equal(quantityForAmount(50, 0), 0);
    assert.equal(quantityForAmount(50, -5), 0);
    assert.equal(quantityForAmount(50, 'abc'), 0);
  });

  it('returns zero when no money was named', () => {
    assert.equal(quantityForAmount(0, 0.25), 0);
  });
});

describe('amountForQuantity', () => {
  it('prices a weighed line', () => {
    assert.equal(amountForQuantity(250, 0.25), 62.5);
    assert.equal(amountForQuantity(1500, 0.25), 375);
  });

  it('round-trips against quantityForAmount', () => {
    const basePrice = priceToBase(250, 'kg');
    assert.equal(amountForQuantity(quantityForAmount(50, basePrice), basePrice), 50);
    assert.equal(amountForQuantity(quantityForAmount(200, basePrice), basePrice), 200);
  });

  it('treats non-numbers as zero', () => {
    assert.equal(amountForQuantity('', 0.25), 0);
    assert.equal(amountForQuantity(250, null), 0);
  });
});

describe('formatQuantity', () => {
  it('says grams below a kilo and kilos above', () => {
    assert.equal(formatQuantity(250, TYPE_WEIGHT), '250 g');
    assert.equal(formatQuantity(999, TYPE_WEIGHT), '999 g');
    assert.equal(formatQuantity(1000, TYPE_WEIGHT), '1 kg');
    assert.equal(formatQuantity(1500, TYPE_WEIGHT), '1.5 kg');
    assert.equal(formatQuantity(2000, TYPE_WEIGHT), '2 kg');
  });

  it('trims trailing zeros', () => {
    assert.equal(formatQuantity(1250, TYPE_WEIGHT), '1.25 kg');
    assert.equal(formatQuantity(250.5, TYPE_WEIGHT), '250.5 g');
  });

  it('says millilitres below a litre and litres above', () => {
    assert.equal(formatQuantity(500, TYPE_VOLUME), '500 ml');
    assert.equal(formatQuantity(1000, TYPE_VOLUME), '1 L');
    assert.equal(formatQuantity(1500, TYPE_VOLUME), '1.5 L');
  });

  it('says pieces for counted goods', () => {
    assert.equal(formatQuantity(1, TYPE_EACH), '1');
    assert.equal(formatQuantity(12, TYPE_EACH), '12');
    assert.equal(formatQuantity(1500, TYPE_EACH), '1500');
  });

  it('assumes counted goods when no type is given', () => {
    assert.equal(formatQuantity(3), '3');
  });

  it('handles zero and rubbish', () => {
    assert.equal(formatQuantity(0, TYPE_WEIGHT), '0 g');
    assert.equal(formatQuantity('abc', TYPE_WEIGHT), '0 g');
  });
});

describe('formatUnitPrice', () => {
  it('quotes the price the way the shopkeeper does', () => {
    assert.equal(formatUnitPrice(0.25, 'kg'), 'Rs 250 / kg');
    assert.equal(formatUnitPrice(0.56, 'l'), 'Rs 560 / L');
    assert.equal(formatUnitPrice(120, 'pc'), 'Rs 120 / pc');
    assert.equal(formatUnitPrice(30, 'dozen'), 'Rs 360 / dz');
  });

  it('trims trailing zeros', () => {
    assert.equal(formatUnitPrice(0.0405, 'kg'), 'Rs 40.5 / kg');
  });
});

describe('codesForType', () => {
  it('lists only the units that suit the goods', () => {
    assert.deepEqual(codesForType(TYPE_WEIGHT), ['g', 'kg']);
    assert.deepEqual(codesForType(TYPE_VOLUME), ['ml', 'l']);
    assert.deepEqual(codesForType(TYPE_EACH), ['pc', 'dozen']);
  });

  it('lists nothing for an unknown type', () => {
    assert.deepEqual(codesForType('length'), []);
  });
});

describe('isMeasured', () => {
  it('is true for anything not sold one at a time', () => {
    assert.equal(isMeasured({ unit_type: TYPE_WEIGHT }), true);
    assert.equal(isMeasured({ unit_type: TYPE_VOLUME }), true);
  });

  it('is false for counted goods and for a product with no type', () => {
    assert.equal(isMeasured({ unit_type: TYPE_EACH }), false);
    assert.equal(isMeasured({}), false);
    assert.equal(isMeasured(null), false);
  });
});

describe('stepFor', () => {
  it('moves weighed and poured goods by the smallest amount anyone asks for', () => {
    assert.equal(stepFor(TYPE_WEIGHT), 50);
    assert.equal(stepFor(TYPE_VOLUME), 50);
  });

  it('moves counted goods one piece at a time', () => {
    assert.equal(stepFor(TYPE_EACH), 1);
    assert.equal(stepFor(undefined), 1);
  });
});
