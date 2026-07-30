# M4 — Reports tasks

Depends on M3 complete.

- [ ] **M4-T1** `GET /api/reports/weekly?start=YYYY-MM-DD` — ISO week from start date
- [ ] **M4-T2** `GET /api/reports/monthly?year=YYYY&month=MM`
- [ ] **M4-T3** Response shape: `{ total_income, total_expense, net, by_category: [{ category, amount }] }`
- [ ] **M4-T4** Frontend `reports.html` — week/month toggle, date picker
- [ ] **M4-T5** Frontend `js/reports.js` — fetch and render summary cards
- [ ] **M4-T6** Add Chart.js; doughnut/bar chart for category breakdown
- [ ] **M4-T7** PHPUnit tests for report aggregation logic
