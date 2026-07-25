/**
 * Currency/number formatting helpers.
 *
 * Two display styles are supported:
 *  - "international": standard 3-digit grouping (Rs 1,200,000)
 *  - "lakh_crore":    South Asian grouping used in Pakistani finance —
 *                      groups of 2 after the first 3 digits (Rs 12,00,000)
 *
 * This is purely a display concern; all tax math in tax-calculator.js is
 * unaffected and keeps using its own formatPKR for internal/test use.
 */

export const NUMBER_FORMATS = {
    INTERNATIONAL: 'international',
    LAKH_CRORE: 'lakh_crore',
  };
  
  const STORAGE_KEY = 'tax-calculator-number-format';
  
  function groupInternational(digits) {
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
  
  function groupLakhCrore(digits) {
    if (digits.length <= 3) return digits;
    const lastThree = digits.slice(-3);
    const rest = digits.slice(0, -3);
    const groupedRest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',');
    return `${groupedRest},${lastThree}`;
  }
  
  /**
   * @param {number} amount
   * @param {string} format one of NUMBER_FORMATS
   */
  export function formatPKR(amount, format = NUMBER_FORMATS.INTERNATIONAL) {
    const safeAmount = Number.isFinite(amount) ? amount : 0;
    const sign = safeAmount < 0 ? '-' : '';
    const digits = String(Math.round(Math.abs(safeAmount)));
    const grouped =
      format === NUMBER_FORMATS.LAKH_CRORE ? groupLakhCrore(digits) : groupInternational(digits);
    return `${sign}Rs ${grouped}`;
  }
  
  /**
   * Short human-readable form, e.g. "12.5 Lakh" / "1.24 Crore" or "Rs 1.2M".
   * @param {number} amount
   * @param {string} format
   */
  export function formatCompactPKR(amount, format = NUMBER_FORMATS.INTERNATIONAL) {
    const safeAmount = Number.isFinite(amount) ? amount : 0;
    const abs = Math.abs(safeAmount);
  
    if (format === NUMBER_FORMATS.LAKH_CRORE) {
      if (abs >= 1_00_00_000) return `${(safeAmount / 1_00_00_000).toFixed(2)} Crore`;
      if (abs >= 1_00_000) return `${(safeAmount / 1_00_000).toFixed(2)} Lakh`;
      return formatPKR(safeAmount, format);
    }
  
    if (abs >= 1_000_000) return `Rs ${(safeAmount / 1_000_000).toFixed(2)}M`;
    if (abs >= 1_000) return `Rs ${(safeAmount / 1_000).toFixed(1)}K`;
    return formatPKR(safeAmount, format);
  }
  
  export function getStoredFormat() {
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored === NUMBER_FORMATS.LAKH_CRORE ? NUMBER_FORMATS.LAKH_CRORE : NUMBER_FORMATS.INTERNATIONAL;
  }
  
  export function storeFormat(format) {
    localStorage.setItem(STORAGE_KEY, format);
  }