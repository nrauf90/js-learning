# M13 — Theme default & FOUC fix tasks

Depends on M12 complete.

- [x] **M13-T1** Swap `css/styles.css` so the unattributed `:root` holds light values; `:root[data-theme='dark']` becomes the override
- [x] **M13-T2** Create shared `js/theme.js` (`applyTheme`/`initTheme`) defaulting to light unless the OS explicitly prefers dark, always respecting an explicit stored choice
- [x] **M13-T3** Migrate all pages (`app.js`, `landing.js`, `auth.js`, `dashboard.js`, `cashflow.js`, `reports.js`, `billing.js`, `profile.js`, `legal.js`, all `admin*.js`) to import the shared module instead of their own copy
- [x] **M13-T4** Manual + E2E verification that there's no dark-then-light flash; update/add tests

## Completion log

### M13-T1 — done 2026-07-31

**Modified files**
- `css/styles.css` — swapped the unattributed `:root` block to hold the light theme's CSS custom properties (was dark); `:root[data-theme='dark']` is now the explicit override block. First paint (before any JS executes) is now correct for a user with no stored preference.

**QA notes**
1. Clear `localStorage` (`tax-calculator-theme` key) and open any page in a browser with OS set to light mode.
2. Confirm the page renders light immediately — no flash of dark before switching to light.
3. Covered automatically by `e2e/tests/m13-theme.spec.js` ("root paints light theme before any script runs").

### M13-T2 — done 2026-07-31

**Modified files**
- `js/theme.js` — new shared module exporting `applyTheme(theme)` and `initTheme(onToggle)`. Fallback logic changed from "light only if OS explicitly prefers light" to "dark only if OS explicitly prefers dark" (`matchMedia('(prefers-color-scheme: dark)')`), so the default is light. `initTheme` accepts an optional `onToggle(nextTheme)` callback for pages that need to react to a theme change (e.g. re-render charts).

**QA notes**
1. `node --check js/theme.js` passes.
2. Set OS/browser to `prefers-color-scheme: dark` with no stored choice → page loads dark.
3. Set OS to dark but store `tax-calculator-theme=light` → page stays light (explicit choice wins). Covered by `e2e/tests/m13-theme.spec.js` ("explicit light choice persists even when OS prefers dark").

### M13-T3 — done 2026-07-31

**Modified files**
- `js/app.js`, `js/landing.js`, `js/auth.js`, `js/dashboard.js`, `js/cashflow.js`, `js/reports.js`, `js/billing.js`, `js/profile.js`, `js/legal.js`, `js/admin.js`, `js/admin-users.js`, `js/admin-categories.js`, `js/admin-entries.js`, `js/admin-payments.js`, `js/admin-subscriptions.js` — removed each file's own duplicated `THEME_KEY`/`applyTheme`/`initTheme` definitions and replaced with `import { initTheme } from './theme.js'` (dashboard.js and reports.js alias it to `initSharedTheme` and wrap it locally so they can pass a chart-rerender callback).
- `package.json` — added `js/theme.js` to the `lint` script's `node --check` chain; added `qa:m12`/`qa:m13` convenience scripts.

**QA notes**
1. `npm run lint` — all files (including `js/theme.js`) pass `node --check`.
2. Manually toggle theme on `dashboard.html` and `reports.html` — charts re-render with theme-correct colors on toggle.
3. `Grep` confirms no remaining local `function initTheme` definitions outside `js/theme.js`.

### M13-T4 — done 2026-07-31

**Modified files**
- `e2e/tests/m13-theme.spec.js` — new spec: (1) blocks all `.js` requests and asserts `html` has no `data-theme="dark"` and the computed `--bg` custom property equals the light value, proving the CSS-only first paint is already correct; (2) toggles theme and confirms it persists across reload + `localStorage`; (3) confirms an explicit stored `light` choice beats an OS `prefers-color-scheme: dark` setting.
- `e2e/playwright.config.js`, `scripts/qa-milestone.mjs` — added `'M13'` to `MILESTONE_ORDER` and `SPEC_BY_MILESTONE` so `npm run qa:milestone -- M13` runs the new spec plus the full M1–M12 regression suite.

**QA notes**
1. `npm run qa:milestone -- M13` → 66/66 passed (full regression M1–M13).
2. `npm test` (Node unit tests) → 96/96 passed.
3. `npm run lint` → all JS files pass.
