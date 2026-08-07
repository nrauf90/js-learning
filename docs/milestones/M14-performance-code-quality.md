# M14 — Performance & code-quality pass

**Phase:** 4 (post-launch hardening) · **Depends on:** M13

**Goal:** Fix the concrete performance and duplication issues found in the
review before the feature backlog adds more surface area to maintain.

## Scope

- `ReportAggregator`: aggregate with SQL `SUM`/`GROUP BY` instead of
  loading every row into PHP.
- Paginate `CashEntryController::index`.
- `js/dashboard.js` calls `/api/reports/weekly` instead of fetching a
  user's entire entry history and filtering client-side.
- Add missing composite indexes: `payments(status, created_at)`,
  `subscriptions(status, ends_at)`, `users(is_admin, created_at)`.
- Extract shared `js/format-utils.js` (`escapeHtml`, `formatDate`,
  `formatRs`) and dedupe it out of `js/admin*.js`, `dashboard.js`,
  `cashflow.js`, `reports.js`, `billing.js`, `profile.js`.
- Add `defer` to Chart.js/CDN script tags.

## Tasks

See [M14 tasks](../tasks/M14-tasks.md).

## Exit criteria

- [ ] Reports/dashboard aggregate in SQL, not PHP loops over full result sets
- [ ] `GET /api/cash-entries` is paginated
- [ ] Dashboard no longer fetches full entry history
- [ ] New indexes present in a migration
- [ ] Shared format/theme helpers used everywhere; no duplicated copies remain
