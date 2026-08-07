/**
 * Till ticket state and money maths.
 *
 * Kept free of DOM and network so the arithmetic that decides what a customer
 * is charged can be unit-tested directly — see tests/cart.test.js.
 *
 * Quantities are floats in the product's *base* unit — 250 means 250 grams of
 * daal, not 250 packets — and `unitPrice` is the price of one base unit, so a
 * line total is still just price × quantity. See js/units.js.
 */

import { TYPE_EACH, baseFor, formatQuantity, formatUnitPrice, stepFor } from './units.js';

/** Scales lie in the last decimal; a stock check must not fail on 999.9999 g. */
const STOCK_EPSILON = 0.0005;

/** Grams and millilitres are never asked for in fractions finer than this. */
function quantize(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return 0;
  return Math.round((n + Number.EPSILON) * 1000) / 1000;
}

/**
 * Rounds to 2dp, half-up.
 *
 * The epsilon nudge is load-bearing: prices like 1.005 are stored as
 * 1.00499999… so both `toFixed(2)` and a plain `Math.round(n * 100) / 100`
 * round them *down*, quietly under-charging by a paisa on every such line.
 */
export function money(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return 0;
  return Math.round((n + Number.EPSILON) * 100) / 100;
}

/**
 * @param {{ unitPrice: number, quantity: number }} line
 */
export function lineTotal(line) {
  return money((Number(line.unitPrice) || 0) * (Number(line.quantity) || 0));
}

/**
 * How a line reads under its name: "250 g @ Rs 250 / kg", or "Rs 120 each".
 *
 * @param {{ unitPrice: number, quantity: number, unitType?: string, priceUnit?: string }} line
 */
export function lineUnitLabel(line) {
  const unitType = line?.unitType ?? TYPE_EACH;
  const unitPrice = Number(line?.unitPrice) || 0;

  if (unitType === TYPE_EACH) return `Rs ${money(unitPrice)} each`;

  const priceUnit = line?.priceUnit || baseFor(unitType);

  return `${formatQuantity(line?.quantity, unitType)} @ ${formatUnitPrice(unitPrice, priceUnit)}`;
}

/**
 * @param {Array<{ unitPrice: number, quantity: number }>} lines
 * @param {number} [discount]
 */
export function computeTotals(lines, discount = 0) {
  const subtotal = money(lines.reduce((sum, line) => sum + lineTotal(line), 0));
  // A discount can never create a negative bill, and never exceeds what is
  // actually on the ticket — the server enforces the same rule.
  const applied = money(Math.min(Math.max(Number(discount) || 0, 0), subtotal));
  const exact = money(subtotal - applied);

  // Whole rupees. Paisa coins do not circulate in Pakistan, so a ticket of Rs
  // 62.50 is settled at Rs 63 and the drawer only reconciles if the till agrees.
  // `Math.round` is half-up and matches PHP's `round()` on the server, which is
  // what stops the screen and the receipt disagreeing by a rupee.
  const total = Math.round(exact);

  return {
    subtotal,
    discount: applied,
    exactTotal: exact,
    rounding: money(total - exact),
    total,
  };
}

/**
 * Change owed for a cash payment. Returns null when not enough was tendered,
 * which the caller renders as "short" rather than as negative change.
 */
export function changeDue(total, tendered) {
  const paid = Number(tendered);
  if (!Number.isFinite(paid)) return null;
  const owed = money(paid - money(total));
  return owed < 0 ? null : owed;
}

export function createCart() {
  /** @type {Map<number, { productId: number, name: string, unitPrice: number, quantity: number, stock: number|null, unitType: string, baseUnit: string, priceUnit: string }>} */
  const lines = new Map();

  return {
    /**
     * Adding a product already on the ticket bumps its quantity instead of
     * creating a second line — the expected behaviour when a barcode is
     * scanned repeatedly.
     *
     * Weighed goods are normally added with an explicit quantity off the
     * keypad; the default is only what a bare tap on the tile is worth.
     */
    add(product, quantity = stepFor(product?.unit_type)) {
      const existing = lines.get(product.id);
      const stock = product.track_stock ? Number(product.stock_quantity) || 0 : null;
      const unitType = product.unit_type ?? TYPE_EACH;
      const next = quantize((existing?.quantity || 0) + (Number(quantity) || 0));

      if (stock !== null && next > stock + STOCK_EPSILON) {
        return {
          ok: false,
          reason: `Only ${formatQuantity(stock, unitType)} of ${product.name} in stock.`,
        };
      }

      lines.set(product.id, {
        productId: product.id,
        name: product.name,
        unitPrice: Number(product.price) || 0,
        quantity: next,
        stock,
        unitType,
        baseUnit: product.base_unit ?? 'pc',
        priceUnit: product.price_unit ?? 'pc',
      });

      return { ok: true };
    },

    setQuantity(productId, quantity) {
      const line = lines.get(productId);
      if (!line) return { ok: false, reason: 'Item is not on the ticket.' };

      const next = quantize(quantity);
      if (next <= 0) {
        lines.delete(productId);
        return { ok: true };
      }
      if (line.stock !== null && next > line.stock + STOCK_EPSILON) {
        return {
          ok: false,
          reason: `Only ${formatQuantity(line.stock, line.unitType)} of ${line.name} in stock.`,
        };
      }

      line.quantity = next;
      return { ok: true };
    },

    remove(productId) {
      lines.delete(productId);
    },

    clear() {
      lines.clear();
    },

    isEmpty() {
      return lines.size === 0;
    },

    /**
     * What the "N items" label should say. Base quantities cannot be summed
     * across a mixed ticket — one packet plus 250 g of daal is not 251 of
     * anything — so measured goods count as one item per line and only
     * counted goods contribute their pieces.
     */
    count() {
      return [...lines.values()].reduce(
        (sum, line) => sum + (line.unitType === TYPE_EACH ? line.quantity : 1),
        0,
      );
    },

    toArray() {
      return [...lines.values()].map((line) => ({ ...line, lineTotal: lineTotal(line) }));
    },

    /** Payload shape the POST /api/sales endpoint expects. */
    toPayloadItems() {
      return [...lines.values()].map((line) => ({
        product_id: line.productId,
        quantity: line.quantity,
      }));
    },
  };
}
