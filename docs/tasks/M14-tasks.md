# M14 — Performance & code-quality pass tasks

Depends on M13 complete.

- [ ] **M14-T1** `ReportAggregator`: SQL-level `SUM`/`GROUP BY` aggregation instead of PHP loops over full result sets
- [ ] **M14-T2** Paginate `CashEntryController::index`
- [ ] **M14-T3** `js/dashboard.js` calls `/api/reports/weekly` instead of fetching full entry history
- [ ] **M14-T4** Migration: add missing indexes (`payments(status, created_at)`, `subscriptions(status, ends_at)`, `users(is_admin, created_at)`)
- [ ] **M14-T5** Extract shared `js/format-utils.js` (`escapeHtml`, `formatDate`, `formatRs`) and dedupe out of `js/admin*.js`, `dashboard.js`, `cashflow.js`, `reports.js`, `billing.js`, `profile.js`
- [ ] **M14-T6** Add `defer` to Chart.js/CDN script tags
- [ ] **M14-T7** Tests + `npm run qa:milestone -- M14`

## Completion log

_(populated as each task is finished)_
