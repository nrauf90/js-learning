/**
 * Pakistan FBR income tax slabs for salaried individuals.
 * Progressive slab formula: fixedAmount + rate * (income - threshold)
 */

/** @typedef {{ min: number, max: number | null, rate: number, fixed: number, threshold: number, label: string }} TaxSlab */

/** @typedef {{ id: string, label: string, period: string, regime: 'new' | 'old', slabs: TaxSlab[], surcharge?: { threshold: number, rate: number } }} TaxYear */ 

/** @type {TaxYear[]} */
export const TAX_YEARS = [
  // ── New tax regime ──────────────────────────────────────────────
  {
    id: 'fy2026-27',
    label: 'FY 2026-27',
    period: 'Jul 2026 – Jun 2027',
    regime: 'new',
    slabs: [
      { min: 0, max: 600_000, rate: 0, fixed: 0, threshold: 0, label: 'Exempt' },
      { min: 600_001, max: 1_200_000, rate: 0.01, fixed: 0, threshold: 600_000, label: '1%' },
      { min: 1_200_001, max: 2_200_000, rate: 0.11, fixed: 6_000, threshold: 1_200_000, label: '11%' },
      { min: 2_200_001, max: 3_200_000, rate: 0.20, fixed: 116_000, threshold: 2_200_000, label: '20%' },
      { min: 3_200_001, max: 4_100_000, rate: 0.25, fixed: 316_000, threshold: 3_200_000, label: '25%' },
      { min: 4_100_001, max: 5_600_000, rate: 0.29, fixed: 541_000, threshold: 4_100_000, label: '29%' },
      { min: 5_600_001, max: 7_000_000, rate: 0.32, fixed: 976_000, threshold: 5_600_000, label: '32%' },
      { min: 7_000_001, max: null, rate: 0.35, fixed: 1_424_000, threshold: 7_000_000, label: '35%' },
    ],
    surcharge: { threshold: 10_000_000, rate: 0.09 },
  },
  {
    id: 'fy2025-26',
    label: 'FY 2025-26',
    period: 'Jul 2025 – Jun 2026',
    regime: 'new',
    slabs: [
      { min: 0, max: 600_000, rate: 0, fixed: 0, threshold: 0, label: 'Exempt' },
      { min: 600_001, max: 1_200_000, rate: 0.01, fixed: 0, threshold: 600_000, label: '1%' },
      { min: 1_200_001, max: 2_200_000, rate: 0.11, fixed: 6_000, threshold: 1_200_000, label: '11%' },
      { min: 2_200_001, max: 3_200_000, rate: 0.23, fixed: 116_000, threshold: 2_200_000, label: '23%' },
      { min: 3_200_001, max: 4_100_000, rate: 0.30, fixed: 346_000, threshold: 3_200_000, label: '30%' },
      { min: 4_100_001, max: null, rate: 0.35, fixed: 616_000, threshold: 4_100_000, label: '35%' },
    ],
    surcharge: { threshold: 10_000_000, rate: 0.09 },
  },

  // ── Old tax regime (previous 5 years) ───────────────────────────
  {
    id: 'fy2024-25',
    label: 'FY 2024-25',
    period: 'Jul 2024 – Jun 2025',
    regime: 'old',
    slabs: [
      { min: 0, max: 600_000, rate: 0, fixed: 0, threshold: 0, label: 'Exempt' },
      { min: 600_001, max: 1_200_000, rate: 0.05, fixed: 0, threshold: 600_000, label: '5%' },
      { min: 1_200_001, max: 2_200_000, rate: 0.15, fixed: 30_000, threshold: 1_200_000, label: '15%' },
      { min: 2_200_001, max: 3_200_000, rate: 0.25, fixed: 180_000, threshold: 2_200_000, label: '25%' },
      { min: 3_200_001, max: 4_100_000, rate: 0.30, fixed: 430_000, threshold: 3_200_000, label: '30%' },
      { min: 4_100_001, max: null, rate: 0.35, fixed: 700_000, threshold: 4_100_000, label: '35%' },
    ],
    surcharge: { threshold: 10_000_000, rate: 0.10 },
  },
  {
    id: 'fy2023-24',
    label: 'FY 2023-24',
    period: 'Jul 2023 – Jun 2024',
    regime: 'old',
    slabs: [
      { min: 0, max: 600_000, rate: 0, fixed: 0, threshold: 0, label: 'Exempt' },
      { min: 600_001, max: 1_200_000, rate: 0.025, fixed: 0, threshold: 600_000, label: '2.5%' },
      { min: 1_200_001, max: 2_400_000, rate: 0.125, fixed: 15_000, threshold: 1_200_000, label: '12.5%' },
      { min: 2_400_001, max: 3_600_000, rate: 0.225, fixed: 165_000, threshold: 2_400_000, label: '22.5%' },
      { min: 3_600_001, max: 6_000_000, rate: 0.275, fixed: 435_000, threshold: 3_600_000, label: '27.5%' },
      { min: 6_000_001, max: null, rate: 0.35, fixed: 1_095_000, threshold: 6_000_000, label: '35%' },
    ],
  },
  {
    id: 'fy2022-23',
    label: 'FY 2022-23',
    period: 'Jul 2022 – Jun 2023',
    regime: 'old',
    slabs: [
      { min: 0, max: 600_000, rate: 0, fixed: 0, threshold: 0, label: 'Exempt' },
      { min: 600_001, max: 1_200_000, rate: 0.025, fixed: 0, threshold: 600_000, label: '2.5%' },
      { min: 1_200_001, max: 2_400_000, rate: 0.125, fixed: 15_000, threshold: 1_200_000, label: '12.5%' },
      { min: 2_400_001, max: 3_600_000, rate: 0.20, fixed: 165_000, threshold: 2_400_000, label: '20%' },
      { min: 3_600_001, max: 6_000_000, rate: 0.25, fixed: 405_000, threshold: 3_600_000, label: '25%' },
      { min: 6_000_001, max: 12_000_000, rate: 0.325, fixed: 1_005_000, threshold: 6_000_000, label: '32.5%' },
      { min: 12_000_001, max: null, rate: 0.35, fixed: 2_955_000, threshold: 12_000_000, label: '35%' },
    ],
  },
  {
    id: 'fy2021-22',
    label: 'FY 2021-22',
    period: 'Jul 2021 – Jun 2022',
    regime: 'old',
    slabs: [
      { min: 0, max: 600_000, rate: 0, fixed: 0, threshold: 0, label: 'Exempt' },
      { min: 600_001, max: 1_200_000, rate: 0.05, fixed: 0, threshold: 600_000, label: '5%' },
      { min: 1_200_001, max: 1_800_000, rate: 0.10, fixed: 30_000, threshold: 1_200_000, label: '10%' },
      { min: 1_800_001, max: 2_500_000, rate: 0.15, fixed: 90_000, threshold: 1_800_000, label: '15%' },
      { min: 2_500_001, max: 3_500_000, rate: 0.175, fixed: 195_000, threshold: 2_500_000, label: '17.5%' },
      { min: 3_500_001, max: 5_000_000, rate: 0.20, fixed: 370_000, threshold: 3_500_000, label: '20%' },
      { min: 5_000_001, max: 8_000_000, rate: 0.225, fixed: 670_000, threshold: 5_000_000, label: '22.5%' },
      { min: 8_000_001, max: 12_000_000, rate: 0.25, fixed: 1_345_000, threshold: 8_000_000, label: '25%' },
      { min: 12_000_001, max: 30_000_000, rate: 0.275, fixed: 2_345_000, threshold: 12_000_000, label: '27.5%' },
      { min: 30_000_001, max: 50_000_000, rate: 0.30, fixed: 7_295_000, threshold: 30_000_000, label: '30%' },
      { min: 50_000_001, max: 75_000_000, rate: 0.325, fixed: 13_295_000, threshold: 50_000_000, label: '32.5%' },
      { min: 75_000_001, max: null, rate: 0.35, fixed: 21_420_000, threshold: 75_000_000, label: '35%' },
    ],
  },
  {
    id: 'fy2020-21',
    label: 'FY 2020-21',
    period: 'Jul 2020 – Jun 2021',
    regime: 'old',
    slabs: [
      { min: 0, max: 600_000, rate: 0, fixed: 0, threshold: 0, label: 'Exempt' },
      { min: 600_001, max: 1_200_000, rate: 0.05, fixed: 0, threshold: 600_000, label: '5%' },
      { min: 1_200_001, max: 1_800_000, rate: 0.10, fixed: 30_000, threshold: 1_200_000, label: '10%' },
      { min: 1_800_001, max: 2_500_000, rate: 0.15, fixed: 90_000, threshold: 1_800_000, label: '15%' },
      { min: 2_500_001, max: 3_500_000, rate: 0.175, fixed: 195_000, threshold: 2_500_000, label: '17.5%' },
      { min: 3_500_001, max: 5_000_000, rate: 0.20, fixed: 370_000, threshold: 3_500_000, label: '20%' },
      { min: 5_000_001, max: 8_000_000, rate: 0.225, fixed: 670_000, threshold: 5_000_000, label: '22.5%' },
      { min: 8_000_001, max: 12_000_000, rate: 0.25, fixed: 1_345_000, threshold: 8_000_000, label: '25%' },
      { min: 12_000_001, max: 30_000_000, rate: 0.275, fixed: 2_345_000, threshold: 12_000_000, label: '27.5%' },
      { min: 30_000_001, max: 50_000_000, rate: 0.30, fixed: 7_295_000, threshold: 30_000_000, label: '30%' },
      { min: 50_000_001, max: 75_000_000, rate: 0.325, fixed: 13_295_000, threshold: 50_000_000, label: '32.5%' },
      { min: 75_000_001, max: null, rate: 0.35, fixed: 21_420_000, threshold: 75_000_000, label: '35%' },
    ],
  },
];

export function getYearsByRegime(regime) {
  return TAX_YEARS.filter((y) => y.regime === regime);
}

export function getYearById(id) {
  return TAX_YEARS.find((y) => y.id === id);
}
