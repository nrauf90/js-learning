import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { buildCreditPayload, creditBookAmount } from '../mobile/js/credit.js';

const items = [{ product_id: 1, quantity: 2, unit_price: 50 }];

describe('creditBookAmount', () => {
  it('puts the whole ticket on the book when nothing is taken', () => {
    assert.equal(creditBookAmount(500, 0), 500);
    assert.equal(creditBookAmount(500, ''), 500);
  });

  it('nets the deposit off the ticket', () => {
    assert.equal(creditBookAmount(500, 200), 300);
  });

  it('clamps a deposit above the ticket rather than going negative', () => {
    assert.equal(creditBookAmount(500, 700), 0);
  });
});

describe('buildCreditPayload', () => {
  it('refuses a debt with no name on it', () => {
    const res = buildCreditPayload({ items, name: '', depositRaw: '', total: 500 });
    assert.equal(res.ok, false);
    assert.match(res.error, /name/i);
  });

  it('refuses a deposit that is not a number, or is below zero', () => {
    assert.equal(
      buildCreditPayload({ items, name: 'Bilal', depositRaw: 'abc', total: 500 }).ok,
      false
    );
    assert.equal(
      buildCreditPayload({ items, name: 'Bilal', depositRaw: '-5', total: 500 }).ok,
      false
    );
  });

  it('refuses a deposit above the ticket total', () => {
    const res = buildCreditPayload({ items, name: 'Bilal', depositRaw: '600', total: 500 });
    assert.equal(res.ok, false);
    assert.match(res.error, /more than the ticket total/i);
  });

  it('sends name only when no phone and no deposit — the sale lands pending', () => {
    const res = buildCreditPayload({ items, name: 'Bilal', depositRaw: '', total: 500 });
    assert.equal(res.ok, true);
    assert.deepEqual(res.payload, {
      items,
      payment_method: 'credit',
      customer_name: 'Bilal',
    });
  });

  it('sends the deposit and how it arrived when money is taken', () => {
    const res = buildCreditPayload({
      items,
      name: 'Bilal',
      phone: '03001234567',
      depositRaw: '200',
      depositMethod: 'easypaisa',
      total: 500,
    });
    assert.equal(res.ok, true);
    assert.deepEqual(res.payload, {
      items,
      payment_method: 'credit',
      customer_name: 'Bilal',
      customer_phone: '03001234567',
      paid_amount: 200,
      deposit_method: 'easypaisa',
    });
  });

  it('never carries a customer id — the page is resolved server-side', () => {
    const res = buildCreditPayload({
      items,
      name: 'Bilal',
      phone: '03001234567',
      depositRaw: '',
      total: 500,
    });
    assert.equal('customer_id' in res.payload, false);
  });
});
