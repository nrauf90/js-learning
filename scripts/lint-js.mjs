#!/usr/bin/env node
/**
 * Syntax-checks every ES module under js/.
 *
 * Replaces the hand-maintained `node --check a.js && node --check b.js && …`
 * chain in package.json, which had to be edited by hand whenever a module was
 * added or removed — and silently stopped covering anything anyone forgot.
 */
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const JS_DIR = path.join(ROOT, 'js');

const files = fs
  .readdirSync(JS_DIR)
  .filter((f) => f.endsWith('.js'))
  .sort();

if (files.length === 0) {
  console.error('lint: no JS modules found under js/');
  process.exit(1);
}

let failed = 0;

for (const file of files) {
  try {
    execFileSync(process.execPath, ['--check', path.join(JS_DIR, file)], { stdio: 'pipe' });
  } catch (err) {
    failed += 1;
    console.error(`✗ js/${file}`);
    console.error(String(err.stderr || err.message).trim());
  }
}

if (failed > 0) {
  console.error(`\nlint: ${failed} of ${files.length} file(s) failed`);
  process.exit(1);
}

console.log(`lint: ${files.length} module(s) OK`);
