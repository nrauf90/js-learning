# AGENTS.md

## Cursor Cloud specific instructions

- **Project**: Pakistan Income Tax Calculator — vanilla HTML/CSS/JS app with FBR tax slab data.
- **Runtime**: Node.js v22 and npm 10 (via nvm). No install step required unless adding new dependencies.
- **Update script**: `npm install` is a no-op (no runtime dependencies). Run it only after adding packages to `package.json`.
- **Lint**: `npm run lint` — syntax-checks all JS files with `node --check`.
- **Test**: `npm test` — runs unit tests via Node's built-in test runner.
- **Run**: `npm start` — serves the app on port 3000 via `npx serve`.
- **Structure**:
  - `index.html` — main UI
  - `css/styles.css` — styling
  - `js/tax-slabs.js` — FBR slab data (new + old 5 years)
  - `js/tax-calculator.js` — calculation engine
  - `js/app.js` — UI logic
  - `tests/tax-calculator.test.js` — unit tests
