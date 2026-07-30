# M8 — Receipt addon scaffold tasks

Depends on M6/M7; no AI/OCR in this milestone.

- [x] **M8-T1** Migration: `subscription_addons` (user_id, addon_key, status, ends_at)
- [x] **M8-T2** `receipt_path` column already on `cash_entries` from M3 — confirm nullable
- [x] **M8-T3** `POST /api/receipts/upload` stub — returns 501 `{ "message": "Coming soon" }`
- [x] **M8-T4** Configure `storage/app/receipts` disk (private)
- [x] **M8-T5** Cash-flow form: “Attach receipt (Premium)” upsell when addon inactive
- [x] **M8-T6** Billing page: addon card “Receipt AI — PKR 500/month — Coming soon”

## Completion log

### M8-T1 — done 2026-07-30

**Modified files**
- `backend/database/migrations/2026_07_30_200000_create_subscription_addons_table.php`
- `backend/app/Models/SubscriptionAddon.php`
- `backend/app/Models/User.php` — `subscriptionAddons()` relation
- `backend/app/Services/Billing/SubscriptionService.php` — `activateAddon()`, `addonStatuses()`

**QA notes**
1. `php artisan migrate` — `subscription_addons` table created.
2. Sandbox checkout for `receipt_addon` creates active addon row.

### M8-T2 — done 2026-07-30

**Modified files**
- (none — confirmed existing M3 migration)

**QA notes**
1. `ReceiptAddonTest::test_cash_entries_receipt_path_is_nullable` — entry saves with `receipt_path` null.

### M8-T3 — done 2026-07-30

**Modified files**
- `backend/app/Http/Controllers/Api/ReceiptController.php`
- `backend/routes/api.php` — `POST /api/receipts/upload` (auth + subscribed)

**QA notes**
1. Subscribed user → `POST /api/receipts/upload` → **501** `{ "message": "Coming soon" }`.

### M8-T4 — done 2026-07-30

**Modified files**
- `backend/config/filesystems.php` — private `receipts` disk
- `backend/storage/app/receipts/.gitignore`

**QA notes**
1. Disk root: `storage/app/receipts`, visibility `private`.

### M8-T5 — done 2026-07-30

**Modified files**
- `cashflow.html` — receipt upsell blocks
- `js/cashflow.js` — loads addon status, shows upsell on expense entries
- `css/styles.css` — `.receipt-upsell` styles

**QA notes**
1. Subscribed user without receipt addon → “Attach receipt (Premium)” with link to billing.

### M8-T6 — done 2026-07-30

**Modified files**
- `billing.html` — Receipt AI addon card section
- `js/billing.js` — renders addon card; shows Active when addon enabled
- `backend/app/Http/Controllers/Api/BillingController.php` — subscription response includes `addons`
- `backend/tests/Feature/ReceiptAddonTest.php` — 6 PHPUnit tests
- `e2e/tests/m8-receipt-addon.spec.js` — 3 E2E tests
- `backend/README.md` — upload stub + receipts disk documented
- `package.json` — `qa:m8`

**QA notes**
1. `/billing.html` → Receipt AI card, Rs 500/month, “Coming soon” badge.
2. `npm run qa:milestone -- M8` — 35 tests pass.
