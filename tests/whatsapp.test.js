import { describe, it } from 'node:test';
import assert from 'node:assert/strict';

import { reminderText, reminderUrl, whatsappNumber } from '../js/whatsapp.js';

describe('whatsappNumber', () => {
  it('converts a local mobile to international format', () => {
    assert.equal(whatsappNumber('0300-1234567'), '923001234567');
    assert.equal(whatsappNumber('0300 1234567'), '923001234567');
  });

  it('keeps numbers that are already international', () => {
    assert.equal(whatsappNumber('+92 300 1234567'), '923001234567');
    assert.equal(whatsappNumber('923001234567'), '923001234567');
  });

  it('strips the 00 international dialling prefix', () => {
    assert.equal(whatsappNumber('0092 300 1234567'), '923001234567');
  });

  it('accepts a mobile typed without its leading zero', () => {
    assert.equal(whatsappNumber('3001234567'), '923001234567');
  });

  it('returns null for blank and fragment numbers', () => {
    assert.equal(whatsappNumber(null), null);
    assert.equal(whatsappNumber(''), null);
    assert.equal(whatsappNumber('0300'), null);
    assert.equal(whatsappNumber('not a number'), null);
  });
});

describe('reminderUrl', () => {
  it('builds a wa.me link with the message encoded', () => {
    const url = reminderUrl('0300-1234567', 'You owe Rs 100.00');
    assert.equal(
      url,
      `https://wa.me/923001234567?text=${encodeURIComponent('You owe Rs 100.00')}`
    );
  });

  it('returns null when there is no number to send to', () => {
    assert.equal(reminderUrl(null, 'hello'), null);
    assert.equal(reminderUrl('abc', 'hello'), null);
  });
});

describe('reminderText', () => {
  it('names the customer, the shop and the balance', () => {
    const text = reminderText({ name: 'Bilal', shop: 'Al-Madina Kiryana', balance: 'Rs 1,240.00' });
    assert.match(text, /Bilal/);
    assert.match(text, /Al-Madina Kiryana/);
    assert.match(text, /Rs 1,240\.00/);
  });

  it('still reads sensibly when the shop has no name', () => {
    assert.match(reminderText({ name: 'Bilal', shop: null, balance: 'Rs 10.00' }), /the shop/);
  });
});
