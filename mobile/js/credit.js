/**
 * The udhaar sale's arithmetic and payload, kept DOM-free so the same rules the
 * desktop till applies in js/pos.js (submitUdhaar) can be unit-tested here
 * instead of only being reachable through a tap.
 *
 * What the module deliberately does NOT do is decide who the customer is. The
 * till sends a name and a phone number; SaleService::resolveCustomer resolves
 * the khata page server-side — a customer id posted from here would give the
 * till a second way to decide who a debt belongs to, and the two would
 * eventually disagree.
 */

/**
 * What lands on the khata once the deposit is taken. The deposit is clamped to
 * the ticket rather than trusted: a keypad slip must not put a negative debt on
 * the book.
 */
export function creditBookAmount(total, deposit) {
  const t = Number(total) || 0;
  const d = Number(deposit) || 0;
  return Math.round((t - Math.min(Math.max(d, 0), t)) * 100) / 100;
}

/**
 * Validate the dialog and shape the POST /api/sales body.
 *
 * Returns { ok: true, payload } or { ok: false, error } — the caller decides
 * where the error is shown; on mobile that is the sheet's own alert strip,
 * where the name and deposit that caused it are still editable.
 *
 * @param {object} args
 * @param {Array}  args.items         cart.toPayloadItems()
 * @param {string} args.name          customer name — picked page's or typed
 * @param {string} [args.phone]       optional; matched server-side first
 * @param {string} args.depositRaw    the deposit box, raw — '' is a real answer
 * @param {string} args.depositMethod how the deposit arrived (the sale's own
 *                                    payment_method is 'credit' by then)
 * @param {number} args.total         ticket total
 */
export function buildCreditPayload({ items, name, phone, depositRaw, depositMethod, total }) {
  // A debt with no name on it is uncollectable — the server refuses it too, but
  // saying so here keeps the goods on the counter rather than on the book.
  if (!name) {
    return { ok: false, error: 'Write the customer name — an unnamed debt cannot be collected.' };
  }

  const deposit = Number(depositRaw);

  if (depositRaw !== '' && (!Number.isFinite(deposit) || deposit < 0)) {
    return {
      ok: false,
      error: 'Enter the deposit in rupees, or leave it empty for nothing paid.',
    };
  }

  if (deposit > total) {
    return { ok: false, error: 'The deposit is more than the ticket total.' };
  }

  const payload = {
    items,
    payment_method: 'credit',
    customer_name: name,
  };

  if (phone) payload.customer_phone = phone;

  // An empty box means the whole ticket goes on the book, which is a normal
  // udhaar sale — the sale must record as pending, never as paid.
  if (depositRaw !== '' && deposit > 0) {
    payload.paid_amount = deposit;
    payload.deposit_method = depositMethod;
  }

  return { ok: true, payload };
}
