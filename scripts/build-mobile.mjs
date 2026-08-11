/**
 * Assembles `www/` — the directory Capacitor copies into the native projects.
 *
 * Why a build step at all, in a project that deliberately has no bundler:
 * Capacitor copies `webDir` wholesale into android/ and ios/. Pointing it at the
 * repo root would drag `backend/`, `node_modules/`, `docs/` and `.git` into
 * every APK. This copies only what the app actually loads.
 *
 * It is a plain copy — no transpiling, no minifying. The mobile screens are
 * hand-written ES modules and the webviews Capacitor targets support them
 * natively.
 *
 * Run: npm run mobile:build
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const out = path.join(root, 'www');

/**
 * Sources, as [from, to] relative to the repo root and www/ respectively.
 *
 * `js/api.js` is copied from the *web app* rather than duplicated under
 * mobile/: it is the shared API contract, and a second copy would drift from
 * the first time either side changed. It lands beside the mobile modules so
 * their `./api.js` imports resolve with no path juggling.
 */
const FILES = [
  ['mobile/index.html', 'index.html'],
  ['mobile/login.html', 'login.html'],
  ['mobile/more.html', 'more.html'],
  ['mobile/sell.html', 'sell.html'],

  ['mobile/css/mobile.css', 'css/mobile.css'],

  ['mobile/js/config.js', 'js/config.js'],
  ['mobile/js/theme-boot.js', 'js/theme-boot.js'],
  ['mobile/js/native.js', 'js/native.js'],
  ['mobile/js/theme.js', 'js/theme.js'],
  ['mobile/js/shell.js', 'js/shell.js'],
  ['mobile/js/boot.js', 'js/boot.js'],
  ['mobile/js/login.js', 'js/login.js'],
  ['mobile/js/more.js', 'js/more.js'],
  ['mobile/js/sell.js', 'js/sell.js'],

  ['js/api.js', 'js/api.js'],
  ['js/units.js', 'js/units.js'],
  ['js/cart.js', 'js/cart.js'],

  ['mobile/assets/logo.png', 'assets/logo.png'],
  ['mobile/assets/favicon.svg', 'assets/favicon.svg'],
];

function copy(from, to) {
  const src = path.join(root, from);
  const dest = path.join(out, to);

  if (!fs.existsSync(src)) {
    throw new Error(
      `Missing source: ${from}\n` +
        `  Every entry in FILES must exist — a silently skipped file becomes a ` +
        `404 inside the APK, which is far harder to diagnose there than here.`
    );
  }

  fs.mkdirSync(path.dirname(dest), { recursive: true });
  fs.copyFileSync(src, dest);
  return to;
}

/* A clean rebuild, so a file removed from FILES also leaves the output. A stale
   asset in www/ would keep shipping in every APK with nothing referencing it. */
fs.rmSync(out, { recursive: true, force: true });
fs.mkdirSync(out, { recursive: true });

const copied = FILES.map(([from, to]) => copy(from, to));

console.log(`www/ built — ${copied.length} files`);
for (const file of copied) console.log(`  ${file}`);
