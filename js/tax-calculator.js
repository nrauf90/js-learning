/**
 * Pakistan income tax calculation engine.
 */

/**
 * @param {number} income
 * @param {import('./tax-slabs.js').TaxYear} taxYear
 * @param {number} [deductions] Total exemptions/deductions (e.g. Sehat Card premium,
 *   provident fund contribution, Zakat, approved donations) that reduce taxable income.
 *   These do not reduce take-home pay — they only reduce the income the slab tax is
 *   calculated on.
 */
export function calculateTax(income, taxYear, deductions = 0) {
  const safeDeductions = Math.max(0, deductions || 0);
  const taxableIncome = Math.max(0, income - safeDeductions);

  if (taxableIncome <= 0 || !Number.isFinite(taxableIncome)) {
    return emptyResult(income, taxYear, safeDeductions);
  }

  const slab = findApplicableSlab(taxableIncome, taxYear.slabs);
  const slabTax = slab.fixed + slab.rate * (taxableIncome - slab.threshold);
  const baseTax = Math.max(0, slabTax);

  let surcharge = 0;
  if (taxYear.surcharge && taxableIncome > taxYear.surcharge.threshold) {
    surcharge = baseTax * taxYear.surcharge.rate;
  }

  const totalTax = baseTax + surcharge;
  const takeHome = income - totalTax;
  const effectiveRate = income > 0 ? (totalTax / income) * 100 : 0;

  return {
    income,
    deductions: safeDeductions,
    taxableIncome,
    taxYear: taxYear.id,
    label: taxYear.label,
    regime: taxYear.regime,
    slab,
    baseTax,
    surcharge,
    totalTax,
    monthlyTax: totalTax / 12,
    takeHome,
    monthlyTakeHome: takeHome / 12,
    effectiveRate,
  };
}

/**
 * @param {number} income
 * @param {import('./tax-slabs.js').TaxYear[]} years
 * @param {number} [deductions]
 */
export function compareYears(income, years, deductions = 0) {
  return years.map((year) => calculateTax(income, year, deductions));
}

/**
 * @param {number} income
 * @param {import('./tax-slabs.js').TaxYear} taxYear
 */
function findApplicableSlab(income, slabs) {
  for (const slab of slabs) {
    if (income >= slab.min && (slab.max === null || income <= slab.max)) {
      return slab;
    }
  }
  return slabs[slabs.length - 1];
}

/**
 * @param {number} income
 * @param {import('./tax-slabs.js').TaxYear} taxYear
 * @param {number} [deductions]
 */
function emptyResult(income, taxYear, deductions = 0) {
  return {
    income,
    deductions,
    taxableIncome: Math.max(0, income - deductions),
    taxYear: taxYear.id,
    label: taxYear.label,
    regime: taxYear.regime,
    slab: taxYear.slabs[0],
    baseTax: 0,
    surcharge: 0,
    totalTax: 0,
    monthlyTax: 0,
    takeHome: Math.max(0, income),
    monthlyTakeHome: Math.max(0, income) / 12,
    effectiveRate: 0,
  };
}

/**
 * @param {number} amount
 */
export function formatPKR(amount) {
  return new Intl.NumberFormat('en-PK', {
    style: 'currency',
    currency: 'PKR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Math.round(amount));
}

/**
 * @param {number} rate
 */
export function formatPercent(rate) {
  return `${rate.toFixed(2)}%`;
}