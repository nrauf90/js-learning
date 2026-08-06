# M13 — Theme default & flash-of-dark fix

**Phase:** 4 (post-launch hardening) · **Depends on:** M12

**Goal:** Light theme is the true default everywhere (dark only via an
explicit user choice or an OS that explicitly prefers dark), and the page
never visibly flashes from dark to light on load.

## Scope

- `css/styles.css`: swap so the unattributed `:root` holds the light
  values; `:root[data-theme='dark']` becomes the override.
- Flip every `initTheme()` fallback from "light only if the OS says so"
  to "dark only if the OS explicitly says so" (`prefers-color-scheme: dark`).
- Extract the 18 duplicated `initTheme`/`applyTheme` copies (`app.js`,
  `landing.js`, `auth.js`, `dashboard.js`, `cashflow.js`, `reports.js`,
  `billing.js`, `profile.js`, `legal.js`, all `admin*.js`) into one shared
  `js/theme.js` module.

## Tasks

See [M13 tasks](../tasks/M13-tasks.md).

## Exit criteria

- [x] Loading any page with no stored preference shows light theme immediately, no flash
- [x] A device set to prefer dark still gets dark by default (until toggled)
- [x] Explicit toggle choice always wins and persists
- [x] Every page imports the shared `js/theme.js` instead of a local copy

## Status: done (2026-07-31)

All 4 tasks complete — see [M13 tasks](../tasks/M13-tasks.md) for the
completion log. `npm run qa:milestone -- M13` passes (66/66, full regression
M1–M13).
