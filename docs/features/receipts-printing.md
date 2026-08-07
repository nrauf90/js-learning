# Receipts and printing

## What it is

The paper slip handed over the counter. Sized for the two thermal roll widths a
Pakistani shop actually owns — 80mm and 58mm — and printed straight from the
browser, so no driver, app or hardware integration is needed beyond whatever the
printer already presents to the OS.

## How it works

### One slip, two places

`js/receipt.js` owns the markup. It is a module rather than markup inside
`js/pos.js` because a reprinted receipt has to be byte-identical to the one the
customer was handed at the counter; two copies would drift the first time either
side changed. The till renders it after a sale, and the sales screen renders it
again from the sale detail with `showPayments: true` and a "Sales Receipt (Copy)"
label.

### What is on it

Shop name, "Sales Receipt", then:

| block | contents |
|---|---|
| Header | receipt reference (`S-000123`), date/time, customer name if there is one |
| Items | one block per line — see below |
| Totals | item count, subtotal, discount, refunded, rounding, TOTAL |
| Money | what was paid and how, change due, balance due |
| Payments | instalment history (reprints only) |
| Status | `PAID` / `PART PAID` / `UNPAID` |
| Footer | "Thank you for shopping!" and "No exchange without this receipt" |

Amounts print as bare numbers with no currency mark. Thermal rolls are 32–48
characters wide, so repeating "Rs" on every line costs the item names; the
currency is stated once, on the total.

### Counted vs measured lines

A counted line uses three columns — quantity, unit price, amount — so the customer
can check the arithmetic on the paper without redoing it.

A measured line cannot. Its stored figures are per gram, so it would print as
`250 @ 0.25`, which no customer can check. It prints the spoken form instead —
`250 g @ Rs 250 / kg` — and because that will not sit in the 4-character quantity
column of a 58mm roll, it takes the full width under the name with only the amount
left in the column the eye is already following down the slip.

The API sends `quantity_label` and `unit_price_label` ready-made; `receipt.js`
falls back to deriving them with `formatQuantity()` / `formatUnitPrice()` for a
sale that was built at this till and carries no labels.

The item count is not a sum of quantities: a measured line has no piece count to
add up — its quantity is 250 grams, not 250 things — so weighed and poured goods
count as the one line they are.

### The money line

On a settled sale it is the method and the amount. On an udhaar sale it is the
**deposit**: "On credit 100.00" reads as "a hundred rupees of credit" to the
customer holding the slip, when what happened is that a hundred was paid and the
rest went on the book. A deposit of nothing prints no line at all — the balance
due below is the whole story, and a row of zeroes only invites the question of
what it was for.

`amount_tendered` is null for anything but cash, where "what the customer handed
over" is just the total, so the slip substitutes the total rather than printing a
blank.

The instalment history is rendered **only on a reprint**. At the counter the sale
has at most the one payment that just happened, and printing a one-row "history"
of it reads as a duplicate charge.

### Paper width

A `<select>` on both the till and the sales screen offers 80mm and 58mm. The
choice is persisted in `localStorage` under `cashflow_receipt_paper` and
reapplied on every page that can print, because roll width is a property of the
till's printer and not of any one sale.

`applyPaperWidth(mm)` does two things:

1. Sets `document.body.dataset.paper`, which the CSS reads —
   `.receipt-slip` defaults to `--slip-width: 80mm` and
   `body[data-paper='58'] .receipt-slip` overrides it to 58mm with more padding,
   because 58mm rolls actually print about 48mm wide.
2. Rewrites a `<style id="receipt-print-page">` element with
   `@page { size: <mm>mm auto; margin: 0 }`. `@page` cannot be selected by class,
   so the rule is rewritten rather than toggled — and without an exact size and
   zero margins the driver falls back to A4 and the till spits out one near-empty
   page per sale.

### Printing

The Print button calls `window.print()`. A `@media print` block in
`css/styles.css` strips everything that is not the slip: the sidebar, the topbar,
the modals' chrome and every `.no-print` element. The slip is monospaced, black on
white, with `.slip-rule` dividers rendered as dashed borders.

## Screens / files

| Layer | File |
|---|---|
| Slip markup | `js/receipt.js` |
| Till | `pos.html` `#pos-receipt`, `#pos-paper`, `#pos-print`; `js/pos.js` |
| Reprint | `sales.html` `#sale-receipt`, `#sale-paper`, `#sale-print`; `js/sales.js` |
| Styles | `css/styles.css` — `.receipt-slip` / `.slip-*` rules and the `@media print` blocks |
| Units | `js/units.js` |

Exported helpers: `receiptSlipHTML(sale, options)`, `paymentLabel(method)`,
`receiptNum(amount)`, `receiptDateTime(iso)`, `applyPaperWidth(mm)`,
`initPaperSelect(select)`. The last four are reused by the sales, khata and
purchases screens for consistent money and date formatting.

## API endpoints

None. The slip is rendered entirely in the browser from a sale payload
(`POST /api/sales` or `GET /api/sales/{sale}`).

## Permissions & gating

Whatever gates the page it is rendered on — the till and the sales list both need
auth plus the subscription gate. There is no server-side receipt rendering and
nothing to authorise separately.

## Edge cases & known limits

- **The heading is the logged-in user's name, not the shop's.**
  `storedShopName()` reads `cashflow_auth_user.name` from `localStorage`. A shop
  called "Al-Madina Kiryana Store" run by a user called "Bilal" prints "Bilal".
  `GET /api/shop` returns `name`, `logo_url` and `receipt_footer`, and the Shop
  screen lets an owner set all three — **none of them reach the slip.**
- **`receipt_footer` is never printed.** The footer lines are hard-coded in
  `receipt.js`.
- **No logo.** `shops.logo_path` has no upload endpoint and the slip has no image
  slot.
- **No tax, GST or NTN block.** There is no tax handling anywhere in the app.
- **No barcode or QR on the slip**, and no reprint from a scanned code — a reprint
  goes through the sales list.
- **`window.print()` only.** There is no ESC/POS output, no direct printer
  integration, and no way to print without the browser's dialog.
- **A refunded sale reprints with a "Refunded" line** but there is no separate
  credit note.
- The paper choice is per browser, so a shop with a till and an office laptop sets
  it twice.
