import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
  calculateTax,
  compareYears,
  formatPKR,
  formatPercent,
} from '../js/tax-calculator.js';
import { getYearById, getYearsByRegime, TAX_YEARS } from '../js/tax-slabs.js';

/** Progressive slab formula used by the calculator. */
function expectedSlabTax(income, year) {
  const slab = year.slabs.find(
    (s) => income >= s.min && (s.max === null || income <= s.max)
  ) ?? year.slabs[year.slabs.length - 1];
  const baseTax = Math.max(0, slab.fixed + slab.rate * (income - slab.threshold));
  let surcharge = 0;
  if (year.surcharge && income > year.surcharge.threshold) {
    surcharge = baseTax * year.surcharge.rate;
  }
  return { slab, baseTax, surcharge, totalTax: baseTax + surcharge };
}

describe('tax slab data', () => {
  it('has exactly 7 fiscal years (2 new, 5 old)', () => {
    assert.equal(TAX_YEARS.length, 7);
    assert.equal(getYearsByRegime('new').length, 2);
    assert.equal(getYearsByRegime('old').length, 5);
  });

  it('exposes every expected year id', () => {
    const ids = TAX_YEARS.map((y) => y.id);
    assert.deepEqual(ids, [
      'fy2026-27',
      'fy2025-26',
      'fy2024-25',
      'fy2023-24',
      'fy2022-23',
      'fy2021-22',
      'fy2020-21',
    ]);
  });

  it('getYearById returns matching year or undefined', () => {
    assert.equal(getYearById('fy2025-26').label, 'FY 2025-26');
    assert.equal(getYearById('missing'), undefined);
  });

  it('slabs are contiguous from 0 with a final open-ended band', () => {
    for (const year of TAX_YEARS) {
      assert.equal(year.slabs[0].min, 0);
      assert.equal(year.slabs[year.slabs.length - 1].max, null);

      for (let i = 1; i < year.slabs.length; i++) {
        assert.equal(
          year.slabs[i].min,
          year.slabs[i - 1].max + 1,
          `${year.id}: gap between slabs ${i - 1} and ${i}`
        );
      }
    }
  });

  it('fixed amounts match tax owed at each slab threshold', () => {
    for (const year of TAX_YEARS) {
      for (const slab of year.slabs) {
        if (slab.threshold === 0) {
          assert.equal(slab.fixed, 0);
          continue;
        }
        const atThreshold = expectedSlabTax(slab.threshold, year);
        assert.equal(
          atThreshold.baseTax,
          slab.fixed,
          `${year.id} ${slab.label}: fixed should equal tax at threshold ${slab.threshold}`
        );
      }
    }
  });
});

describe('calculateTax — result shape', () => {
  it('returns all expected fields for a normal calculation', () => {
    const year = getYearById('fy2025-26');
    const result = calculateTax(1_200_000, year);

    assert.equal(result.income, 1_200_000);
    assert.equal(result.deductions, 0);
    assert.equal(result.taxableIncome, 1_200_000);
    assert.equal(result.taxYear, 'fy2025-26');
    assert.equal(result.label, 'FY 2025-26');
    assert.equal(result.regime, 'new');
    assert.ok(result.slab);
    assert.equal(result.monthlyTax, result.totalTax / 12);
    assert.equal(result.monthlyTakeHome, result.takeHome / 12);
    assert.equal(result.takeHome, result.income - result.totalTax);
    assert.equal(result.effectiveRate, (result.totalTax / result.income) * 100);
  });

  it('returns zero tax for zero and negative taxable income', () => {
    const year = getYearById('fy2025-26');
    assert.equal(calculateTax(0, year).totalTax, 0);
    assert.equal(calculateTax(0, year).takeHome, 0);
    assert.equal(calculateTax(-50_000, year).totalTax, 0);
    assert.equal(calculateTax(-50_000, year).takeHome, 0);
  });

  it('handles non-finite income as empty result', () => {
    const year = getYearById('fy2025-26');
    const nanResult = calculateTax(NaN, year);
    assert.equal(nanResult.totalTax, 0);
    assert.equal(nanResult.effectiveRate, 0);

    const infResult = calculateTax(Infinity, year);
    assert.equal(infResult.totalTax, 0);
  });
});

describe('calculateTax — deductions', () => {
  it('reduces taxable income and tax while leaving gross income for take-home', () => {
    const year = getYearById('fy2025-26');
    const income = 1_200_000;
    const deductions = 200_000;
    const result = calculateTax(income, year, deductions);

    assert.equal(result.income, income);
    assert.equal(result.deductions, deductions);
    assert.equal(result.taxableIncome, 1_000_000);
    // 1% of (1,000,000 - 600,000) = 4,000
    assert.equal(result.totalTax, 4_000);
    assert.equal(result.takeHome, income - 4_000);
  });

  it('clamps negative deductions to zero', () => {
    const year = getYearById('fy2025-26');
    const result = calculateTax(1_200_000, year, -50_000);
    assert.equal(result.deductions, 0);
    assert.equal(result.totalTax, 6_000);
  });

  it('treats nullish deductions as zero', () => {
    const year = getYearById('fy2025-26');
    assert.equal(calculateTax(1_200_000, year, null).deductions, 0);
    assert.equal(calculateTax(1_200_000, year, undefined).deductions, 0);
  });

  it('can zero out tax when deductions cover all taxable income', () => {
    const year = getYearById('fy2025-26');
    const result = calculateTax(1_200_000, year, 1_200_000);
    assert.equal(result.taxableIncome, 0);
    assert.equal(result.totalTax, 0);
    assert.equal(result.takeHome, 1_200_000);
  });

  it('applies surcharge against post-deduction taxable income', () => {
    const year = getYearById('fy2025-26');
    // Gross 12M with 2.5M deductions → taxable 9.5M (below 10M surcharge threshold)
    const below = calculateTax(12_000_000, year, 2_500_000);
    assert.equal(below.taxableIncome, 9_500_000);
    assert.equal(below.surcharge, 0);

    // Gross 12M with 1M deductions → taxable 11M (surcharge applies)
    const above = calculateTax(12_000_000, year, 1_000_000);
    assert.equal(above.taxableIncome, 11_000_000);
    assert.ok(above.surcharge > 0);
  });
});

describe('calculateTax — FY 2026-27 (new)', () => {
  const year = () => getYearById('fy2026-27');

  it('exempt at PKR 600,000', () => {
    assert.equal(calculateTax(600_000, year()).totalTax, 0);
  });

  it('1% slab at PKR 900,000', () => {
    assert.equal(calculateTax(900_000, year()).totalTax, 3_000);
  });

  it('11% slab at PKR 1,700,000', () => {
    // 6,000 + 11% of 500,000 = 61,000
    assert.equal(calculateTax(1_700_000, year()).totalTax, 61_000);
  });

  it('20% slab at PKR 2,700,000', () => {
    // 116,000 + 20% of 500,000 = 216,000
    assert.equal(calculateTax(2_700_000, year()).totalTax, 216_000);
  });

  it('25% slab at PKR 3,650,000', () => {
    // 316,000 + 25% of 450,000 = 428,500
    assert.equal(calculateTax(3_650_000, year()).totalTax, 428_500);
  });

  it('29% slab at PKR 4,850,000', () => {
    // 541,000 + 29% of 750,000 = 758,500
    assert.equal(calculateTax(4_850_000, year()).totalTax, 758_500);
  });

  it('32% slab at PKR 6,300,000', () => {
    // 976,000 + 32% of 700,000 = 1,200,000
    assert.equal(calculateTax(6_300_000, year()).totalTax, 1_200_000);
  });

  it('35% slab at PKR 10,000,000 without surcharge (threshold exclusive)', () => {
    // 1,424,000 + 35% of 3,000,000 = 2,474,000
    const result = calculateTax(10_000_000, year());
    assert.equal(result.baseTax, 2_474_000);
    assert.equal(result.surcharge, 0);
    assert.equal(result.totalTax, 2_474_000);
  });

  it('applies 9% surcharge above PKR 10M', () => {
    const result = calculateTax(12_000_000, year());
    // 1,424,000 + 35% of 5,000,000 = 3,174,000; surcharge 9% = 285,660
    assert.equal(result.baseTax, 3_174_000);
    assert.equal(result.surcharge, 285_660);
    assert.equal(result.totalTax, 3_459_660);
  });
});

describe('calculateTax — FY 2025-26 (new)', () => {
  const year = () => getYearById('fy2025-26');

  it('exempt at or below PKR 600,000', () => {
    assert.equal(calculateTax(600_000, year()).totalTax, 0);
    assert.equal(calculateTax(600_000, year()).takeHome, 600_000);
  });

  it('1% slab at PKR 1,200,000', () => {
    const result = calculateTax(1_200_000, year());
    assert.equal(result.totalTax, 6_000);
    assert.equal(result.effectiveRate, 0.5);
  });

  it('11% slab at PKR 2,000,000', () => {
    // 6,000 + 11% of 800,000 = 94,000
    assert.equal(calculateTax(2_000_000, year()).totalTax, 94_000);
  });

  it('23% slab at PKR 2,700,000', () => {
    // 116,000 + 23% of 500,000 = 231,000
    assert.equal(calculateTax(2_700_000, year()).totalTax, 231_000);
  });

  it('30% slab at PKR 3,650,000', () => {
    // 346,000 + 30% of 450,000 = 481,000
    assert.equal(calculateTax(3_650_000, year()).totalTax, 481_000);
  });

  it('35% slab at PKR 5,000,000', () => {
    // 616,000 + 35% of 900,000 = 931,000
    assert.equal(calculateTax(5_000_000, year()).totalTax, 931_000);
  });

  it('applies Section 4AB 9% surcharge above PKR 10M', () => {
    const result = calculateTax(12_000_000, year());
    // 616,000 + 35% of 7,900,000 = 3,381,000; surcharge 9% = 304,290
    assert.equal(result.baseTax, 3_381_000);
    assert.equal(result.surcharge, 304_290);
    assert.equal(result.totalTax, 3_685_290);
    assert.equal(result.totalTax, result.baseTax + result.surcharge);
  });
});

describe('calculateTax — FY 2024-25 (old)', () => {
  const year = () => getYearById('fy2024-25');

  it('5% slab at PKR 1,200,000', () => {
    assert.equal(calculateTax(1_200_000, year()).totalTax, 30_000);
  });

  it('15% slab at PKR 2,000,000', () => {
    // 30,000 + 15% of 800,000 = 150,000
    assert.equal(calculateTax(2_000_000, year()).totalTax, 150_000);
  });

  it('25% slab at PKR 2,700,000', () => {
    // 180,000 + 25% of 500,000 = 305,000
    assert.equal(calculateTax(2_700_000, year()).totalTax, 305_000);
  });

  it('30% slab at PKR 3,650,000', () => {
    // 430,000 + 30% of 450,000 = 565,000
    assert.equal(calculateTax(3_650_000, year()).totalTax, 565_000);
  });

  it('35% slab at PKR 5,000,000', () => {
    // 700,000 + 35% of 900,000 = 1,015,000
    assert.equal(calculateTax(5_000_000, year()).totalTax, 1_015_000);
  });

  it('applies 10% surcharge above PKR 10M', () => {
    const result = calculateTax(12_000_000, year());
    // 700,000 + 35% of 7,900,000 = 3,465,000; surcharge 10% = 346,500
    assert.equal(result.baseTax, 3_465_000);
    assert.equal(result.surcharge, 346_500);
    assert.equal(result.totalTax, 3_811_500);
  });
});

describe('calculateTax — FY 2023-24 (old)', () => {
  const year = () => getYearById('fy2023-24');

  it('2.5% slab at PKR 900,000', () => {
    assert.equal(calculateTax(900_000, year()).totalTax, 7_500);
  });

  it('12.5% slab at PKR 1,800,000', () => {
    // 15,000 + 12.5% of 600,000 = 90,000
    assert.equal(calculateTax(1_800_000, year()).totalTax, 90_000);
  });

  it('22.5% slab at PKR 3,000,000', () => {
    // 165,000 + 22.5% of 600,000 = 300,000
    assert.equal(calculateTax(3_000_000, year()).totalTax, 300_000);
  });

  it('27.5% slab at PKR 4,800,000', () => {
    // 435,000 + 27.5% of 1,200,000 = 765,000
    assert.equal(calculateTax(4_800_000, year()).totalTax, 765_000);
  });

  it('35% slab at PKR 8,000,000', () => {
    // 1,095,000 + 35% of 2,000,000 = 1,795,000
    assert.equal(calculateTax(8_000_000, year()).totalTax, 1_795_000);
  });

  it('has no surcharge even above PKR 10M', () => {
    const result = calculateTax(12_000_000, year());
    assert.equal(result.surcharge, 0);
    assert.equal(result.totalTax, result.baseTax);
  });
});

describe('calculateTax — FY 2022-23 (old)', () => {
  const year = () => getYearById('fy2022-23');

  it('2.5% slab at PKR 900,000', () => {
    assert.equal(calculateTax(900_000, year()).totalTax, 7_500);
  });

  it('20% slab at PKR 3,000,000', () => {
    // 165,000 + 20% of 600,000 = 285,000
    assert.equal(calculateTax(3_000_000, year()).totalTax, 285_000);
  });

  it('25% slab at PKR 4,800,000', () => {
    // 405,000 + 25% of 1,200,000 = 705,000
    assert.equal(calculateTax(4_800_000, year()).totalTax, 705_000);
  });

  it('32.5% slab at PKR 8,000,000', () => {
    // 1,005,000 + 32.5% of 2,000,000 = 1,655,000
    assert.equal(calculateTax(8_000_000, year()).totalTax, 1_655_000);
  });

  it('35% slab at PKR 15,000,000', () => {
    // 2,955,000 + 35% of 3,000,000 = 4,005,000
    assert.equal(calculateTax(15_000_000, year()).totalTax, 4_005_000);
  });
});

describe('calculateTax — FY 2021-22 (old)', () => {
  const year = () => getYearById('fy2021-22');

  it('5% slab at PKR 900,000', () => {
    // 5% of (900,000 - 600,000) = 15,000
    assert.equal(calculateTax(900_000, year()).totalTax, 15_000);
  });

  it('10% slab at PKR 1,500,000', () => {
    // 30,000 + 10% of 300,000 = 60,000
    assert.equal(calculateTax(1_500_000, year()).totalTax, 60_000);
  });

  it('15% slab at PKR 2,000,000', () => {
    // 90,000 + 15% of 200,000 = 120,000
    assert.equal(calculateTax(2_000_000, year()).totalTax, 120_000);
  });

  it('17.5% slab at PKR 3,000,000', () => {
    // 195,000 + 17.5% of 500,000 = 282,500
    assert.equal(calculateTax(3_000_000, year()).totalTax, 282_500);
  });

  it('20% slab at PKR 4,000,000', () => {
    // 370,000 + 20% of 500,000 = 470,000
    assert.equal(calculateTax(4_000_000, year()).totalTax, 470_000);
  });

  it('22.5% slab at PKR 6,000,000', () => {
    // 670,000 + 22.5% of 1,000,000 = 895,000
    assert.equal(calculateTax(6_000_000, year()).totalTax, 895_000);
  });

  it('25% slab at PKR 10,000,000', () => {
    // 1,345,000 + 25% of 2,000,000 = 1,845,000
    assert.equal(calculateTax(10_000_000, year()).totalTax, 1_845_000);
  });

  it('27.5% slab at PKR 20,000,000', () => {
    // 2,345,000 + 27.5% of 8,000,000 = 4,545,000
    assert.equal(calculateTax(20_000_000, year()).totalTax, 4_545_000);
  });

  it('30% slab at PKR 40,000,000', () => {
    // 7,295,000 + 30% of 10,000,000 = 10,295,000
    assert.equal(calculateTax(40_000_000, year()).totalTax, 10_295_000);
  });

  it('32.5% slab at PKR 60,000,000', () => {
    // 13,295,000 + 32.5% of 10,000,000 = 16,545,000
    assert.equal(calculateTax(60_000_000, year()).totalTax, 16_545_000);
  });

  it('35% slab at PKR 80,000,000', () => {
    // 21,420,000 + 35% of 5,000,000 = 23,170,000
    assert.equal(calculateTax(80_000_000, year()).totalTax, 23_170_000);
  });
});

describe('calculateTax — FY 2020-21 (old)', () => {
  const year = () => getYearById('fy2020-21');

  it('matches FY 2021-22 slab structure for representative incomes', () => {
    const incomes = [
      900_000, 1_500_000, 2_000_000, 3_000_000, 4_000_000, 6_000_000, 10_000_000,
      20_000_000, 40_000_000, 60_000_000, 80_000_000,
    ];
    const fy2122 = getYearById('fy2021-22');
    for (const income of incomes) {
      assert.equal(
        calculateTax(income, year()).totalTax,
        calculateTax(income, fy2122).totalTax,
        `mismatch at income ${income}`
      );
    }
  });

  it('calculates mid-band tax at PKR 2,000,000', () => {
    assert.equal(calculateTax(2_000_000, year()).totalTax, 120_000);
  });
});

describe('calculateTax — cross-year comparisons', () => {
  it('new regime tax is lower than FY 2024-25 for middle income', () => {
    const income = 2_000_000;
    const newTax = calculateTax(income, getYearById('fy2025-26')).totalTax;
    const oldTax = calculateTax(income, getYearById('fy2024-25')).totalTax;
    assert.ok(newTax < oldTax, `New (${newTax}) should be less than old (${oldTax})`);
  });

  it('FY 2026-27 is lower than FY 2025-26 in the 20%/23% band', () => {
    const income = 2_700_000;
    const fy2627 = calculateTax(income, getYearById('fy2026-27')).totalTax;
    const fy2526 = calculateTax(income, getYearById('fy2025-26')).totalTax;
    assert.ok(fy2627 < fy2526);
    assert.equal(fy2627, 216_000);
    assert.equal(fy2526, 231_000);
  });

  it('matches independent formula for every year at several incomes', () => {
    const incomes = [0, 600_000, 900_000, 1_200_000, 2_500_000, 5_000_000, 12_000_000];
    for (const year of TAX_YEARS) {
      for (const income of incomes) {
        const result = calculateTax(income, year);
        const expected = expectedSlabTax(income, year);
        assert.equal(
          result.totalTax,
          expected.totalTax,
          `${year.id} @ ${income}: expected ${expected.totalTax}, got ${result.totalTax}`
        );
      }
    }
  });
});

describe('compareYears', () => {
  it('returns one result per year in order', () => {
    const income = 2_000_000;
    const results = compareYears(income, TAX_YEARS);
    assert.equal(results.length, TAX_YEARS.length);
    results.forEach((r, i) => {
      assert.equal(r.taxYear, TAX_YEARS[i].id);
      assert.equal(r.income, income);
    });
  });

  it('forwards deductions to each year calculation', () => {
    const results = compareYears(2_000_000, [getYearById('fy2025-26')], 500_000);
    assert.equal(results[0].deductions, 500_000);
    assert.equal(results[0].taxableIncome, 1_500_000);
  });
});

describe('formatPKR / formatPercent', () => {
  it('formats currency without decimals', () => {
    const formatted = formatPKR(1_200_000);
    assert.ok(formatted.includes('1,200,000') || formatted.includes('12,00,000'));
  });

  it('rounds fractional amounts', () => {
    const formatted = formatPKR(1_200_000.6);
    assert.ok(formatted.includes('1,200,001') || formatted.includes('12,00,001'));
  });

  it('formats percent to two decimal places', () => {
    assert.equal(formatPercent(12.5), '12.50%');
    assert.equal(formatPercent(0), '0.00%');
    assert.equal(formatPercent(0.5), '0.50%');
  });
});
