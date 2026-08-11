# POS + Khata audit — 2026-08-11

Scope: what a Pakistani kiryana shop still cannot do, from the till and the
credit notebook outward. Written against `main` at the PK Galla rebrand merge
(`2fa0d00`).

## Method

Read every `docs/features/*.md` — each already carries an honest "edge cases and
known limits" section — then **verified the load-bearing claims against the
code** rather than trusting the prose. Everything marked *confirmed* below was
checked directly:

| Claim | Check | Result |
|---|---|---|
| Offline modules are dead code | grep for imports of `offline-db.js` / `sync.js` | confirmed — zero importers |
| No PWA | `ls sw.js manifest.json …` | confirmed — neither exists |
| No stock-movement read API | grep `routes/api.php` | confirmed — no `GET` route |
| No day-book history screen | grep `js/*.js` for the list endpoint | confirmed — only `pos.js`, only `/current` |
| Receipt prints the user, not the shop | `js/receipt.js:67,204` | confirmed — reads `cashflow_auth_user.name` |
| Shop branding never reaches the slip | grep `receipt.js` for `logo_url`/`receipt_footer` | confirmed — no references |
| No held tickets / misc item / price override | grep `pos.js`, `cart.js` | confirmed — none exist |
| No camera barcode scanning | grep for `BarcodeDetector`/`getUserMedia`/`zxing` | confirmed — keyboard-wedge only |
| No cashier on the sale header | `sales` migration vs `sale_payments` | confirmed — only payments carry `recorded_by` |

The feature docs are accurate and current. That is unusual and worth saying: the
audit found no case where the prose overstated what was built.

---

## Verdict

The **transaction core is genuinely strong** — stronger than most POS code. Row
locks on every stock path, idempotent sale creation, derived-not-stored balances,
frozen cost snapshots, whole-rupee settlement, honest unknown-vs-zero margin
handling. The domain modelling around udhaar and weighed goods is the work of
someone who has watched a real counter.

The gaps are almost entirely at the **edges**: what happens when the internet
drops, what the customer is handed on paper, and what the owner sees when they
open the app. Three of those are severe.

---

## P0 — blocking real daily use

### 1. The shop cannot sell without internet · `offline-sync.md`

The single most serious gap. A till with no connection **cannot ring up a sale at
all** — the first failed request surfaces in the alert strip and the ticket sits
there with a customer waiting.

What makes this sting: the server side is *finished and tested* (idempotent
`client_uuid`, unique `(user_id, client_uuid)` index, negative-stock tolerance on
replay, credit-limit skip on replay). And the browser side exists too —
`js/offline-db.js` (IndexedDB, three stores) and `js/sync.js` (drain loop with a
tested retry-classification policy) are both written.

**Nothing imports either file.** `pos.js` never mints a uuid. There is no service
worker, so the app does not even load offline.

This is wiring, not construction. Backlog milestone M33.

> **Directly relevant to the mobile app.** A Capacitor build makes this worse, not
> better: users will expect an installed app to work on a dead connection, and
> shop owners on mobile data in a concrete-walled shop lose signal constantly.

### 2. The receipt has no shop identity · `receipts-printing.md`

The slip handed across the counter prints **the logged-in user's personal name**.
A shop called "Al-Madina Kiryana Store" run by a user called Bilal hands the
customer a receipt that says **Bilal**.

The data all exists — `shops.name`, `phone`, `address`, `logo_path`,
`receipt_footer` are real columns, `GET /api/shop` returns them, and the Shop
screen lets an owner edit them. **None of it reaches the slip.** The footer lines
are hard-coded in `receipt.js`; there is no image slot for the logo.

Small fix, disproportionate impact — this is the shop's only physical branding,
and right now it is wrong on every sale.

### 3. The dashboard is blind to the till · `dashboard.md`

The first screen after login is the **oldest screen in the app** and never got
reworked for the POS. It reads `cash_entries`, but sales stopped writing those
rows — so the "income" it shows is the day book's closing count, not the day's
takings.

It also reads only the **first page** (50 entries) and filters the week client-side,
so a busy week is silently under-counted — while `data.totals`, which the API
already returns correctly summed over every row, is ignored. The card is still
headed "Last 7 days" when it shows Monday–Sunday.

An owner opening the app sees a number that is wrong three different ways.

---

## P1 — significant

### Till

| Gap | Why it matters |
|---|---|
| **No held / parked tickets** | A customer sends a child back for something; the counter stops dead. Every real till has "suspend". |
| **No misc / open-price item** | Anything not in the catalogue cannot be sold at all — no way to ring up a one-off. |
| **No price override** | Bargaining is normal; the only lever is a ticket-level discount. |
| **No cashier on the sale header** | `sale_payments.recorded_by` records who took an *instalment*, but nothing records who **rang up the sale**. "Who sold this?" is unanswerable. |
| **Catalogue loads once, caps at 5,000** | 25 pages × 200. A larger catalogue silently truncates; a product added on another device is invisible until reload. |
| **Keyboard-wedge scanners only** | No camera scanning — see the mobile note below. |

### Khata

| Gap | Why it matters |
|---|---|
| **Payments cannot be reversed** | No endpoint voids a `sale_payments` row. A mis-typed amount is permanent and there is no correcting path. |
| **Only `credit` sales appear on the statement** | A sale part-paid by another route counts toward the balance via `OUTSTANDING_SQL` but is **invisible on the ledger page** — the page can show a balance it cannot explain. |
| **No page merge** | Matching is deliberately conservative (correctly — merging two men's debt is worse), so duplicate pages *will* accumulate. There is no way to tidy them up. |
| **No reminders** | No SMS, no WhatsApp, no call list. Chasing udhaar is the entire point of a khata and the app offers no outbound step. |
| **No statement to hand over or send** | The ledger renders on screen; there is no print or share. The customer arguing about a balance cannot be given the page. |

### Visibility

- **Stock movement history is written but never shown.** Every movement records a
  running `balance_after` specifically so drift can be traced — and there is no
  screen *and no `GET` API*. "Where did ten kilos of atta go" still has no answer
  in the UI.
- **Day book history is written but never shown.** `GET /api/day-book` returns
  every past day with its variance; no page renders it. The till only shows today.
- **The variance goes nowhere else** — not to reports, not to the dashboard, not to
  the activity log.
- **A day cannot be reopened or corrected**, there is no note field to explain a
  variance, and nothing auto-closes a forgotten day — it stays open forever with no
  `expected_amount`.

---

## P2 — worth doing

- **Sales list**: no search by customer name or receipt reference; filters are
  period/category/status only. No export of any kind.
- **Ledger reports still cannot see takings** (`cash_entries` only). Only the
  profit view reads `sale_items`. Two report modes on one screen answer from two
  different sources.
- **A purchase writes nothing to the cash ledger** — paying a supplier is tracked
  in `purchase_payments` but never hits the drawer's expense side, so money out is
  in two places.
- **Deliveries cannot be edited or deleted**, and a bad cost is already blended
  into the weighted average with no way to unwind it.
- **Wastage can be double-counted** if a shop both adjusts stock and files a cash
  expense for the same spoiled goods — the report adds both sources.
- **Permissions inconsistency**: cash entries are per-**account** while everything
  else is per-**shop**, so the ledger reports and the profit reports scope
  differently on the same screen. The docs call this out as a real inconsistency.
- **Receipt add-on is sold but not built** — billing, entitlement and upsell are
  live; the endpoint behind it is a stub.

---

## P3 — backlog, already scoped

CSV/Excel export (M18), report comparisons (M24), soft delete/trash (M22),
notifications (M25), multi-currency (M30), dashboard customisation (M32),
keyboard shortcuts (M34). M14 (performance/code quality) is the one marked
in-progress.

---

## What is genuinely done

Worth stating so effort does not go where it is not needed:

- Weighed goods end to end — base-unit storage, bidirectional keypad (weight↔rupees),
  `decimal(12,4)` money so per-gram prices do not collapse, quick chips in the
  weights a counter actually calls out.
- Udhaar at the till — its own button (not a dropdown entry), a proper combobox with
  sequenced search responses and no pre-armed highlight, conservative page
  resolution owned solely by `SaleService::resolveCustomer()`.
- Khata mechanics — derived balances, 30/60/90 aging from one grouped aggregate,
  oldest-first allocation under a lock, `received_by_name` distinct from
  `recorded_by`, over-payment refused with the exact figure named.
- Purchases — weighted-average cost, `amount_paid` re-derived from `SUM(amount)`
  rather than incremented, supplier payables.
- Profit reporting — unknown cost kept separate from zero cost throughout, one
  shared margin definition (`App\Support\SaleProfit`) across list, stats and reports.
- Day book — server-derived business date, frozen `expected_amount`, live variance
  preview, float excluded from the P&L expenses line.
- Stock integrity — four audited paths and no fifth; `PUT /products/{id}` drops
  `stock_quantity` on purpose.

---

## Recommended order

1. **Receipt shop identity** — smallest change, wrong on every sale today.
2. **Dashboard reads the till** — first screen, currently misleading.
3. **Offline + PWA (M33)** — largest, and the prerequisite for the mobile app being
   worth installing. Mostly wiring: both browser modules already exist.
4. **Held tickets + misc item** — the two till gaps that stop a queue.
5. **Cashier on the sale header** — one column, and accountability is currently absent.
6. **Stock movement + day book history screens** — the data is already there.
7. **Khata: payment reversal, then statement share/print.**

## Notes for the Capacitor build

- **Camera barcode scanning** is the one feature a phone does better than the
  existing till, and it does not exist yet. `@capacitor-mlkit/barcode-scanning`
  covers it.
- **Offline matters more on mobile**, not less — see P0.1.
- **Receipt printing is `window.print()` only.** There is no ESC/POS path, so
  Bluetooth thermal printers — the normal mobile setup — are unsupported.
- The catalogue-in-memory approach (up to 5,000 products) needs a second look
  under a phone's memory budget.
