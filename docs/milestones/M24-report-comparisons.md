# M24 — Month-over-month comparison & richer annual summary

**Phase:** 6 (P2 features) · **Depends on:** M23

**Goal:** Reports answer "am I spending more than last month/year?"
instead of only showing isolated period totals.

## Scope

- `ReportAggregator`: add a comparison payload (current vs. previous
  period, by total and by category).
- Richer yearly report: monthly series, best/worst month, category trend
  over the year.
- Frontend: comparison deltas + a monthly trend chart in `reports.js`.

## Tasks

See [M24 tasks](../tasks/M24-tasks.md).

## Exit criteria

- [ ] Weekly/monthly reports show a delta vs. the previous period
- [ ] Yearly report shows a monthly breakdown, not just one annual total
- [ ] PDF export includes the comparison data
- [ ] PHPUnit + E2E coverage
