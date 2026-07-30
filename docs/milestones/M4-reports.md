# M4 — Reports, landing & legal

**Phase:** 1  
**Goal:** Public marketing home with a quick tax widget; weekly/monthly expense reports for logged-in users.

## Deliverables

### Landing & legal

- `index.html` — hero, about us, how it works, contact, mini tax widget
- `calculator.html` — full free FBR tax calculator
- `privacy.html`, `terms.html`
- Shared nav across all pages

### Reports

- `GET /api/reports/weekly?start=YYYY-MM-DD`
- `GET /api/reports/monthly?year=YYYY&month=MM`
- `reports.html` + `js/reports.js` with Chart.js breakdown

## Tasks

See [M4 tasks](../tasks/M4-tasks.md).

## Exit criteria

- [x] Landing page with hero, about, how it works, contact sections
- [x] Mini tax widget: monthly or yearly income → total tax only
- [x] Full calculator at `calculator.html` (unchanged behaviour, `npm test` green)
- [x] Privacy policy and terms pages linked from footer
- [x] Weekly report shows total income, total expense, net, by-category totals
- [x] Monthly report shows same aggregates for calendar month
- [x] Chart renders category breakdown on the frontend
