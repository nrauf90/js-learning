# Feature documentation

What each part of the app **is** and **how it works, as it stands today** — one
document per functional area.

This is deliberately different from the rest of `docs/`:

| Folder | Answers |
|---|---|
| [`milestones/`](../milestones/) | *why and when* something was built, and its exit criteria |
| [`tasks/`](../tasks/) | the checkbox-by-checkbox record of building it |
| **`features/`** | *what it does now*, the domain rules behind it, and where it is half-built |

Every document follows the same template: what it is · how it works · screens and
files · API endpoints · permissions and gating · edge cases and known limits. The
last section is honest — where a feature is a scaffold or a screen is missing, it
says so.

## The shop

| Document | What it covers |
|---|---|
| [pos-till.md](./pos-till.md) | The till: tiles, the weigh keypad, the ticket, settling in cash or a wallet, the "Save as Udhaar" button, the day gate |
| [sales-history.md](./sales-history.md) | Every ticket, filters, per-sale profit, refunds, collecting against one sale, reprints |
| [khata-udhaar.md](./khata-udhaar.md) | The credit notebook: who owes what, 30/60/90 aging, credit limits, oldest-first payment allocation, `received_by_name` |
| [products-catalogue.md](./products-catalogue.md) | Products, units and per-gram pricing, stock, wastage, expiry, pack size |
| [product-categories.md](./product-categories.md) | The shop's own category list — not the cash-flow one |
| [catalogue-images.md](./catalogue-images.md) | Pictures on products and categories, and the upload safety checks |
| [stock-and-wastage.md](./stock-and-wastage.md) | The audited `stock_movements` ledger, typed write-off reasons, and how a loss is valued |
| [purchases-stock-in.md](./purchases-stock-in.md) | Suppliers, deliveries, weighted-average cost, invoice payments, "what I owe suppliers" |
| [day-book.md](./day-book.md) | Opening float, closing count, expected cash and variance |

## Money and reporting

| Document | What it covers |
|---|---|
| [cash-flow.md](./cash-flow.md) | Manual income and expense entries, and the shared category list behind them |
| [reports.md](./reports.md) | Weekly/monthly/yearly ledger views, profit & loss, cash position, PDF export |
| [dashboard.md](./dashboard.md) | The seven-day summary screen (and the bug that currently breaks it) |
| [units-and-money.md](./units-and-money.md) | Base units, per-gram pricing, whole-rupee settlement, PKR vs USD |
| [receipts-printing.md](./receipts-printing.md) | The thermal slip, 58mm and 80mm rolls, and what is and is not on it |

## Accounts and platform

| Document | What it covers |
|---|---|
| [users-and-auth.md](./users-and-auth.md) | Signup, login, Google OAuth, tokens, profile, password change |
| [shop-and-staff.md](./shop-and-staff.md) | The shop record, staff logins, the three roles and `dataOwnerId()` |
| [billing-subscriptions.md](./billing-subscriptions.md) | The 7-day trial, Paddle checkout, webhooks, the subscription gate |
| [receipt-addon.md](./receipt-addon.md) | The paid receipt-scanner add-on — sold, entitled, not built |
| [admin-panel.md](./admin-panel.md) | The platform operator's back office |
| [activity-log.md](./activity-log.md) | Who changed what in the catalogue, and who may read it |

## Shared and public

| Document | What it covers |
|---|---|
| [app-shell-and-theme.md](./app-shell-and-theme.md) | The sidebar, mobile drawer, user cache and light/dark theme |
| [offline-sync.md](./offline-sync.md) | Offline selling and replay — server-complete, browser side not wired up |
| [landing-and-legal.md](./landing-and-legal.md) | `index.html`, Privacy and Terms |

## Where to start

- Reading the code for the first time: [units-and-money.md](./units-and-money.md),
  then [pos-till.md](./pos-till.md). Almost everything else is downstream of those
  two.
- Working out why a number looks wrong: [reports.md](./reports.md) and
  [sales-history.md](./sales-history.md) both explain how an *unknown* margin is
  kept separate from a zero one.
- Adding a feature that touches stock: [stock-and-wastage.md](./stock-and-wastage.md)
  first — there are exactly four paths and none of them is a plain `UPDATE`.
