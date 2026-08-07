# M33 — Offline / PWA support

**Phase:** 7 (P3 polish) · **Depends on:** M32

**Goal:** Let users open the app and log expenses while offline (e.g.
during a commute), syncing once back online.

## Scope

- Web app manifest + icons (installable PWA).
- Service worker: cache the app shell/static assets; offline fallback
  page.
- Offline entry queue: entries created while offline are stored locally
  and synced to the API once connectivity returns, with basic conflict
  handling.

## Tasks

See [M33 tasks](../tasks/M33-tasks.md).

## Exit criteria

- [ ] App is installable and loads its shell while offline
- [ ] Entries created offline sync automatically once back online
- [ ] No data loss or duplicate entries on reconnect in the common case
- [ ] Manual + automated offline/online transition testing
