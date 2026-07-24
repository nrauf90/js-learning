/**
 * Pakistan income tax calculation engine.
 */

/**
 * @param {number} income
 * @param {import('./tax-slabs.js').TaxYear} taxYear
 */
export function calculateTax(income, taxYear) {
  if (income <= 0 || !Number.isFinite(income)) {
    return emptyResult(income, taxYear);
  }

  const slab = findApplicableSlab(income, taxYear.slabs);
  const slabTax = slab.fixed + slab.rate * (income - slab.threshold);
  const baseTax = Math.max(0, slabTax);

  let surcharge = 0;
  if (taxYear.surcharge && income > taxYear.surcharge.threshold) {
    surcharge = baseTax * taxYear.surcharge.rate;
  }

  const totalTax = baseTax + surcharge;
  const takeHome = income - totalTax;
  const effectiveRate = income > 0 ? (totalTax / income) * 100 : 0;

  return {
    income,
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
 */
export function compareYears(income, years) {
  return years.map((year) => calculateTax(income, year));
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
 */
function emptyResult(income, taxYear) {
  return {
    income,
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
