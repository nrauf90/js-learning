#!/usr/bin/env node
/**
 * Creates a ready-to-run git worktree for a parallel agent.
 *
 * `git worktree add` alone is not enough here. It gives you the tracked files
 * and nothing else, and four things this project needs to run are gitignored,
 * so a bare worktree cannot install, migrate, serve or test:
 *
 *   node_modules/        linked from the main checkout
 *   backend/vendor/      linked from the main checkout
 *   backend/.env         copied, with its port rewritten for this slot
 *   backend/database/database.sqlite   copied, so the agent starts with data
 *
 * It also assigns a **port slot**, which is the part that actually stops two
 * agents colliding. The SQLite database is per-checkout and PHPUnit runs in
 * :memory:, so data is already isolated — but ports 3000/3100/8000 are not, and
 * the second agent to start a server would simply fail to bind.
 *
 * Usage:
 *   node scripts/worktree.mjs new <name>    create and provision
 *   node scripts/worktree.mjs list          show worktrees and their slots
 *   node scripts/worktree.mjs rm <name>     remove one (refuses if dirty)
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { ROOT } from './agent-env.mjs';

const WORKTREE_DIR = path.join(ROOT, '.worktrees');

/* Linked rather than copied: node_modules is hundreds of megabytes and
   backend/vendor is not much better. A junction on Windows needs no admin
   rights, unlike a directory symlink. */
const LINKED = ['node_modules', path.join('backend', 'vendor')];

/* Copied, because each checkout must be able to diverge: an agent that migrates
   its database or edits its .env must not touch anyone else's. */
const COPIED = [path.join('backend', '.env')];
const COPIED_IF_PRESENT = [path.join('backend', 'database', 'database.sqlite')];

function git(args, cwd = ROOT) {
  return execFileSync('git', args, { cwd, encoding: 'utf8' }).trim();
}

function worktreePaths() {
  return git(['worktree', 'list', '--porcelain'])
    .split('\n')
    .filter((l) => l.startsWith('worktree '))
    .map((l) => l.slice('worktree '.length));
}

function slotOf(dir) {
  try {
    return Number(JSON.parse(fs.readFileSync(path.join(dir, '.agent.json'), 'utf8')).slot);
  } catch {
    return dir === ROOT ? 0 : null;
  }
}

/** Lowest slot not already claimed; the main checkout always holds 0. */
function nextFreeSlot() {
  const taken = new Set(worktreePaths().map(slotOf).filter((s) => s !== null));
  taken.add(0);
  let slot = 1;
  while (taken.has(slot)) slot += 1;
  return slot;
}

function link(from, to) {
  if (fs.existsSync(to)) return 'exists';
  fs.mkdirSync(path.dirname(to), { recursive: true });
  fs.symlinkSync(from, to, process.platform === 'win32' ? 'junction' : 'dir');
  return 'linked';
}

function cmdNew(name) {
  if (!name) throw new Error('Usage: node scripts/worktree.mjs new <name>');
  if (!/^[A-Za-z0-9._-]+$/.test(name)) {
    throw new Error('Name may contain letters, digits, dots, underscores and dashes only.');
  }

  const dest = path.join(WORKTREE_DIR, name);
  if (fs.existsSync(dest)) throw new Error(`${dest} already exists`);

  const branch = `agent/${name}`;
  const slot = nextFreeSlot();

  console.log(`Creating worktree "${name}" on branch ${branch} (slot ${slot})…`);
  /* Branch from the current main so the agent starts from what is already
     merged, not from whatever this checkout happens to have uncommitted. */
  git(['worktree', 'add', '-b', branch, dest, 'main']);

  for (const rel of LINKED) {
    const from = path.join(ROOT, rel);
    if (!fs.existsSync(from)) {
      console.log(`  ! ${rel} missing in the main checkout — skipped`);
      continue;
    }
    console.log(`  ${link(from, path.join(dest, rel))} ${rel}`);
  }

  for (const rel of [...COPIED, ...COPIED_IF_PRESENT]) {
    const from = path.join(ROOT, rel);
    if (!fs.existsSync(from)) {
      if (COPIED.includes(rel)) console.log(`  ! ${rel} missing — the agent must create it`);
      continue;
    }
    const to = path.join(dest, rel);
    fs.mkdirSync(path.dirname(to), { recursive: true });
    fs.copyFileSync(from, to);
    console.log(`  copied ${rel}`);
  }

  /* Point the copied .env at this slot's API port, or Laravel keeps generating
     absolute URLs against the main checkout's server. */
  const envPath = path.join(dest, 'backend', '.env');
  if (fs.existsSync(envPath)) {
    const apiPort = 8000 + slot;
    let env = fs.readFileSync(envPath, 'utf8');
    env = env.replace(/^APP_URL=.*$/m, `APP_URL=http://localhost:${apiPort}`);
    fs.writeFileSync(envPath, env);
    console.log(`  rewrote APP_URL -> http://localhost:${apiPort}`);
  }

  fs.writeFileSync(
    path.join(dest, '.agent.json'),
    `${JSON.stringify({ name, slot, branch, createdFrom: 'main' }, null, 2)}\n`
  );

  console.log(`\nReady. Point the agent at:\n  ${dest}\n`);
  console.log('Its ports:');
  console.log(`  web     http://127.0.0.1:${3000 + slot}`);
  console.log(`  mobile  http://127.0.0.1:${3100 + slot}`);
  console.log(`  api     http://127.0.0.1:${8000 + slot}`);
  console.log('\nnpm start / mobile:serve / dev:api / qa:* all follow the slot automatically.');
  console.log(`Merge back with:  git merge ${branch}`);
}

function cmdList() {
  for (const dir of worktreePaths()) {
    const slot = slotOf(dir);
    let branch = '?';
    try {
      branch = git(['rev-parse', '--abbrev-ref', 'HEAD'], dir);
    } catch {
      /* a worktree can exist on disk with a broken HEAD; still worth listing */
    }
    let dirty = '';
    try {
      dirty = git(['status', '--porcelain'], dir) ? '  (uncommitted changes)' : '';
    } catch {
      dirty = '  (unreadable)';
    }
    const label = dir === ROOT ? 'main checkout' : path.basename(dir);
    console.log(
      `slot ${slot === null ? '?' : slot}  ${label.padEnd(20)} ${branch.padEnd(28)}${dirty}`
    );
    if (slot !== null) {
      console.log(`         web ${3000 + slot}  mobile ${3100 + slot}  api ${8000 + slot}`);
    }
  }
}

function cmdRemove(name) {
  if (!name) throw new Error('Usage: node scripts/worktree.mjs rm <name>');
  const dest = path.join(WORKTREE_DIR, name);
  if (!fs.existsSync(dest)) throw new Error(`No worktree at ${dest}`);

  /* Refuse rather than force. Uncommitted work in an agent worktree is exactly
     the "changes missed" case this tooling exists to prevent.
     .agent.json is excluded because this script writes it — the tool's own
     bookkeeping must not block the tool's own cleanup. It is gitignored on
     main, but a worktree branched before that landed will still see it. */
  const dirty = git(['status', '--porcelain'], dest)
    .split('\n')
    .filter((l) => l.trim() && !l.endsWith('.agent.json'))
    .join('\n');
  if (dirty) {
    console.error(`Refusing to remove "${name}" — it has uncommitted changes:\n`);
    console.error(dirty);
    console.error(`\nCommit or discard them in ${dest} first.`);
    process.exit(1);
  }

  const branch = git(['rev-parse', '--abbrev-ref', 'HEAD'], dest);
  const unmerged = git(['log', '--oneline', `main..${branch}`]);
  if (unmerged) {
    console.error(`Refusing to remove "${name}" — ${branch} has commits not on main:\n`);
    console.error(unmerged);
    console.error(`\nMerge it first:  git merge ${branch}`);
    process.exit(1);
  }

  /* Remove our own bookkeeping first. `git worktree remove` refuses on any
     untracked file, and .agent.json is untracked in worktrees branched from
     before it was gitignored — so the tool has to clear it rather than reach
     for --force, which would also discard real work. */
  fs.rmSync(path.join(dest, '.agent.json'), { force: true });

  git(['worktree', 'remove', dest]);
  try {
    git(['branch', '-d', branch]);
  } catch {
    console.log(`(branch ${branch} kept — delete it by hand if you meant to)`);
  }
  console.log(`Removed ${name}.`);
}

const [, , cmd, arg] = process.argv;
try {
  if (cmd === 'new') cmdNew(arg);
  else if (cmd === 'list') cmdList();
  else if (cmd === 'rm') cmdRemove(arg);
  else {
    console.log('Usage:');
    console.log('  node scripts/worktree.mjs new <name>   create and provision');
    console.log('  node scripts/worktree.mjs list         show worktrees and slots');
    console.log('  node scripts/worktree.mjs rm <name>    remove (refuses if dirty/unmerged)');
    process.exit(cmd ? 1 : 0);
  }
} catch (err) {
  console.error(err.message);
  process.exit(1);
}
