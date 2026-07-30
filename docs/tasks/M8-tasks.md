# M8 — Receipt addon scaffold tasks

Depends on M6/M7; no AI/OCR in this milestone.

- [ ] **M8-T1** Migration: `subscription_addons` (user_id, addon_key, status, ends_at)
- [ ] **M8-T2** `receipt_path` column already on `cash_entries` from M3 — confirm nullable
- [ ] **M8-T3** `POST /api/receipts/upload` stub — returns 501 `{ "message": "Coming soon" }`
- [ ] **M8-T4** Configure `storage/app/receipts` disk (private)
- [ ] **M8-T5** Cash-flow form: “Attach receipt (Premium)” upsell when addon inactive
- [ ] **M8-T6** Billing page: addon card “Receipt AI — PKR 500/month — Coming soon”
