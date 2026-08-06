# M23 — Finish the receipt-upload addon

**Phase:** 6 (P2 features) · **Depends on:** M22

**Goal:** Ship real receipt upload/view for the addon that's already sold
via billing (M8) but still returns 501 — no OCR/AI enhancement yet, just
upload, storage, and viewing.

## Scope

- `ReceiptController::upload`: accept an image/PDF, validate MIME/size,
  store outside the web root with a random filename, set
  `cash_entries.receipt_path`.
- View/download endpoint with an ownership check (only the entry's owner
  can fetch it).
- Frontend: upload control on the entry form (gated by the existing addon
  check) and a thumbnail/link to view an attached receipt.

## Tasks

See [M23 tasks](../tasks/M23-tasks.md).

## Exit criteria

- [ ] Subscribed-addon users can upload a receipt image/PDF to an entry
- [ ] Only the entry's owner can view/download it
- [ ] Upload validates file type/size and stores outside the web root
- [ ] PHPUnit + E2E coverage; `docs/milestones/M8-receipt-addon.md` cross-referenced
