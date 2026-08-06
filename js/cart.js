/**
 * Till ticket state and money maths.
 *
 * Kept free of DOM and network so the arithmetic that decides what a customer
 * is charged can be unit-tested directly — see tests/cart.test.js.
 */

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
 * @param {Array<{ unitPrice: number, quantity: number }>} lines
 * @param {number} [discount]
 */
export function computeTotals(lines, discount = 0) {
  const subtotal = money(lines.reduce((sum, line) => sum + lineTotal(line), 0));
  // A discount can never create a negative bill, and never exceeds what is
  // actually on the ticket — the server enforces the same rule.
  const applied = money(Math.min(Math.max(Number(discount) || 0, 0), subtotal));

  return {
    subtotal,
    discount: applied,
    total: money(subtotal - applied),
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
  /** @type {Map<number, { productId: number, name: string, unitPrice: number, quantity: number, stock: number|null }>} */
  const lines = new Map();

  return {
    /**
     * Adding a product already on the ticket bumps its quantity instead of
     * creating a second line — the expected behaviour when a barcode is
     * scanned repeatedly.
     */
    add(product, quantity = 1) {
      const existing = lines.get(product.id);
      const stock = product.track_stock ? product.stock_quantity : null;
      const next = (existing?.quantity || 0) + quantity;

      if (stock !== null && next > stock) {
        return { ok: false, reason: `Only ${stock} of ${product.name} in stock.` };
      }

      lines.set(product.id, {
        productId: product.id,
        name: product.name,
        unitPrice: Number(product.price) || 0,
        quantity: next,
        stock,
      });

      return { ok: true };
    },

    setQuantity(productId, quantity) {
      const line = lines.get(productId);
      if (!line) return { ok: false, reason: 'Item is not on the ticket.' };

      const next = Math.floor(Number(quantity) || 0);
      if (next <= 0) {
        lines.delete(productId);
        return { ok: true };
      }
      if (line.stock !== null && next > line.stock) {
        return { ok: false, reason: `Only ${line.stock} of ${line.name} in stock.` };
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

    count() {
      return [...lines.values()].reduce((sum, line) => sum + line.quantity, 0);
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
