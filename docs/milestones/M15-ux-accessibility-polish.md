# M15 — UX & accessibility polish

**Phase:** 4 (post-launch hardening) · **Depends on:** M14

**Goal:** Fix the concrete UX/accessibility gaps from the review: modal and
sidebar focus handling, empty/loading state bugs, a nav visibility bug, and
formatting/consistency drift across pages.

## Scope

- Focus trap + `role="dialog"`/`aria-modal` + return-focus for admin
  modals and the mobile sidebar drawer; replace `confirm()` on cashflow
  delete with the app's own modal pattern.
- Fix cashflow's empty-state flash (visible before the first fetch
  resolves) and the dashboard's duplicate empty-state-plus-zero-rows bug.
- `js/nav.js`: hide the Cash Flow link for logged-out users (it's shown
  today even though the page redirects them to login).
- Standardize currency formatting on `js/number-format.js` across
  dashboard/cashflow/reports instead of each having its own `formatRs`;
  standardize date formatting and button classes (`.btn`/`.btn-primary`).
- Contrast pass on `--text-muted`; add a skip link; visible focus ring on
  `.sidebar-toggle`.
- Add an "add your first entry" onboarding CTA to cashflow's empty state
  (dashboard already has one).

## Tasks

See [M15 tasks](../tasks/M15-tasks.md).

## Exit criteria

- [ ] Admin modals and mobile sidebar trap focus and restore it on close
- [ ] No incorrect empty-state flash on cashflow load
- [ ] Cash Flow nav link hidden for guests
- [ ] Currency/date formatting consistent across all app pages
- [ ] Contrast/focus-visible fixes verified
