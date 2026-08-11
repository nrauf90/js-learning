#!/usr/bin/env node
/**
 * Syntax-checks every ES module under js/ and mobile/js/.
 *
 * Replaces the hand-maintained `node --check a.js && node --check b.js && …`
 * chain in package.json, which had to be edited by hand whenever a module was
 * added or removed — and silently stopped covering anything anyone forgot.
 *
 * mobile/js/ is listed for that same reason: it is a second source root that a
 * js/-only scan would quietly skip, and a syntax error there does not surface
 * until the file is opened inside a native webview with no console attached.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/* Relative so failures print a path that can be pasted straight into an editor. */
const DIRS = ['js', 'mobile/js'];

const files = DIRS.flatMap((dir) => {
  const abs = path.join(ROOT, dir);
  if (!fs.existsSync(abs)) return [];
  return fs
    .readdirSync(abs)
    .filter((f) => f.endsWith('.js'))
    .sort()
    .map((f) => `${dir}/${f}`);
});

if (files.length === 0) {
  console.error(`lint: no JS modules found under ${DIRS.join(', ')}`);
  process.exit(1);
}

let failed = 0;

for (const file of files) {
  try {
    execFileSync(process.execPath, ['--check', path.join(ROOT, file)], { stdio: 'pipe' });
  } catch (err) {
    failed += 1;
    console.error(`✗ ${file}`);
    console.error(String(err.stderr || err.message).trim());
  }
}

if (failed > 0) {
  console.error(`\nlint: ${failed} of ${files.length} file(s) failed`);
  process.exit(1);
}

console.log(`lint: ${files.length} module(s) OK`);
