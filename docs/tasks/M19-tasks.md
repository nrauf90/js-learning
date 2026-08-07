# M19 — Recurring / scheduled transactions tasks

Depends on M18 complete.

- [ ] **M19-T1** Migration + `RecurringEntry` model (template fields + `frequency` + `next_run_at`)
- [ ] **M19-T2** `RecurringEntryController` CRUD API
- [ ] **M19-T3** Scheduled artisan command: generate due `cash_entries` from active rules, advance `next_run_at`
- [ ] **M19-T4** Frontend: create/pause/edit/delete recurring rules, with next-run preview
- [ ] **M19-T5** Tests (incl. the generator command) + `npm run qa:milestone -- M19`

## Completion log

_(populated as each task is finished)_
