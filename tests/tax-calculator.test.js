import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { calculateTax, formatPKR } from '../js/tax-calculator.js';
import { getYearById, TAX_YEARS } from '../js/tax-slabs.js';

describe('tax slab data', () => {
  it('has 2 new and 5 old fiscal years', () => {
    const newYears = TAX_YEARS.filter((y) => y.regime === 'new');
    const oldYears = TAX_YEARS.filter((y) => y.regime === 'old');
    assert.equal(newYears.length, 2);
    assert.equal(oldYears.length, 5);
  });
});

describe('calculateTax', () => {
  it('returns zero tax for income at or below exempt threshold', () => {
    const year = getYearById('fy2025-26');
    const result = calculateTax(600_000, year);
    assert.equal(result.totalTax, 0);
    assert.equal(result.takeHome, 600_000);
  });

  it('calculates FY 2025-26 tax for PKR 1,200,000 (1% slab)', () => {
    const year = getYearById('fy2025-26');
    const result = calculateTax(1_200_000, year);
    // 1% of (1,200,000 - 600,000) = 6,000
    assert.equal(result.totalTax, 6_000);
    assert.equal(result.effectiveRate, 0.5);
  });

  it('calculates FY 2024-25 tax for PKR 1,200,000 (5% slab)', () => {
    const year = getYearById('fy2024-25');
    const result = calculateTax(1_200_000, year);
    // 5% of (1,200,000 - 600,000) = 30,000
    assert.equal(result.totalTax, 30_000);
  });

  it('applies Section 4AB surcharge above PKR 10M', () => {
    const year = getYearById('fy2025-26');
    const result = calculateTax(12_000_000, year);
    assert.ok(result.surcharge > 0);
    assert.equal(result.totalTax, result.baseTax + result.surcharge);
  });

  it('calculates high-income FY 2022-23 with multiple slabs', () => {
    const year = getYearById('fy2022-23');
    const result = calculateTax(8_000_000, year);
    // Slab: 1,005,000 + 32.5% of (8,000,000 - 6,000,000) = 1,005,000 + 650,000 = 1,655,000
    assert.equal(result.totalTax, 1_655_000);
  });

  it('new tax is lower than old tax for middle income', () => {
    const newYear = getYearById('fy2025-26');
    const oldYear = getYearById('fy2024-25');
    const income = 2_000_000;
    const newTax = calculateTax(income, newYear).totalTax;
    const oldTax = calculateTax(income, oldYear).totalTax;
    assert.ok(newTax < oldTax, `New (${newTax}) should be less than old (${oldTax})`);
  });
});

describe('formatPKR', () => {
  it('formats currency without decimals', () => {
    const formatted = formatPKR(1_200_000);
    assert.ok(formatted.includes('1,200,000') || formatted.includes('12,00,000'));
  });
});
