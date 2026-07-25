import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import {
  NUMBER_FORMATS,
  formatPKR,
  formatCompactPKR,
  getStoredFormat,
  storeFormat,
} from '../js/number-format.js';

describe('formatPKR', () => {
  it('formats international grouping by default', () => {
    assert.equal(formatPKR(1_200_000), 'Rs 1,200,000');
    assert.equal(formatPKR(12_00_000, NUMBER_FORMATS.INTERNATIONAL), 'Rs 1,200,000');
  });

  it('formats lakh/crore grouping', () => {
    assert.equal(formatPKR(1_200_000, NUMBER_FORMATS.LAKH_CRORE), 'Rs 12,00,000');
    assert.equal(formatPKR(12_34_567, NUMBER_FORMATS.LAKH_CRORE), 'Rs 12,34,567');
    assert.equal(formatPKR(999, NUMBER_FORMATS.LAKH_CRORE), 'Rs 999');
  });

  it('preserves sign and rounds', () => {
    assert.equal(formatPKR(-1_250.6), '-Rs 1,251');
    assert.equal(formatPKR(-12_00_000, NUMBER_FORMATS.LAKH_CRORE), '-Rs 12,00,000');
  });

  it('treats non-finite values as zero', () => {
    assert.equal(formatPKR(NaN), 'Rs 0');
    assert.equal(formatPKR(Infinity), 'Rs 0');
  });
});

describe('formatCompactPKR', () => {
  it('uses K/M suffixes for international format', () => {
    assert.equal(formatCompactPKR(500), 'Rs 500');
    assert.equal(formatCompactPKR(12_500), 'Rs 12.5K');
    assert.equal(formatCompactPKR(1_200_000), 'Rs 1.20M');
  });

  it('uses Lakh/Crore for South Asian format', () => {
    assert.equal(formatCompactPKR(50_000, NUMBER_FORMATS.LAKH_CRORE), 'Rs 50,000');
    assert.equal(formatCompactPKR(2_50_000, NUMBER_FORMATS.LAKH_CRORE), '2.50 Lakh');
    assert.equal(formatCompactPKR(1_50_00_000, NUMBER_FORMATS.LAKH_CRORE), '1.50 Crore');
  });

  it('treats non-finite values as zero', () => {
    assert.equal(formatCompactPKR(NaN), 'Rs 0');
  });
});

describe('getStoredFormat / storeFormat', () => {
  /** @type {Map<string, string>} */
  let store;
  let originalLocalStorage;

  beforeEach(() => {
    store = new Map();
    originalLocalStorage = globalThis.localStorage;
    globalThis.localStorage = {
      getItem: (key) => (store.has(key) ? store.get(key) : null),
      setItem: (key, value) => {
        store.set(key, String(value));
      },
      removeItem: (key) => {
        store.delete(key);
      },
    };
  });

  afterEach(() => {
    globalThis.localStorage = originalLocalStorage;
  });

  it('defaults to international when nothing stored', () => {
    assert.equal(getStoredFormat(), NUMBER_FORMATS.INTERNATIONAL);
  });

  it('persists and restores lakh/crore preference', () => {
    storeFormat(NUMBER_FORMATS.LAKH_CRORE);
    assert.equal(getStoredFormat(), NUMBER_FORMATS.LAKH_CRORE);
  });

  it('ignores unknown stored values', () => {
    store.set('tax-calculator-number-format', 'weird');
    assert.equal(getStoredFormat(), NUMBER_FORMATS.INTERNATIONAL);
  });
});
