/**
 * Offline milestone QA harness (no AI tokens).
 *
 * Usage:
 *   npm run qa:milestone -- M2
 *
 * Starts (if needed) API :8000 + frontend :3000, then runs Playwright
 * for all milestones up to and including the one you pass (regression).
 * Failures write docs/issues/<M>/BUG-xxx.md via the bug reporter.
 */

import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { agentEnv } from './agent-env.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// M5 (tax calculator) was removed from the product; its spec file went with it.
const MILESTONE_ORDER = ['M1', 'M2', 'M3', 'M4', 'M6', 'M7', 'M8', 'M9', 'M10', 'M11', 'M12', 'M13', 'M23', 'M35'];

/** Spec globs relative to e2e/tests — only include files that exist when running. */
const SPEC_BY_MILESTONE = {
  M1: 'm1-*.spec.js',
  M2: 'm2-*.spec.js',
  M3: 'm3-*.spec.js',
  M4: 'm4-*.spec.js',
  M6: 'm6-*.spec.js',
  M7: 'm7-*.spec.js',
  M8: 'm8-*.spec.js',
  M9: 'm9-*.spec.js',
  M10: 'm10-*.spec.js',
  M11: 'm11-*.spec.js',
  // M12 (security hardening) has no dedicated frontend E2E behavior of its
  // own — it's covered by backend PHPUnit tests instead. This just lets
  // `qa:milestone -- M12` regress M1-M11's E2E suite.
  M12: 'm12-*.spec.js',
  M13: 'm13-*.spec.js',
  M23: 'm23-*.spec.js',
  M35: 'm35-*.spec.js',
};

/*
 * Ports follow this checkout's slot, so a QA run in an agent worktree starts
 * its own servers instead of finding the main checkout's already listening and
 * silently testing that copy of the code instead of its own.
 */
const PORTS = agentEnv();
const API_URL = PORTS.apiUrl;
const FE_URL = PORTS.feUrl;

function parseMilestone(argv) {
  const raw = (argv[2] || process.env.QA_MILESTONE || '').toUpperCase();
  if (!MILESTONE_ORDER.includes(raw)) {
    console.error(`Usage: npm run qa:milestone -- <${MILESTONE_ORDER.join('|')}>`);
    console.error(`Got: ${argv[2] || '(empty)'}`);
    process.exit(1);
  }
  return raw;
}

function milestonesUpTo(target) {
  const idx = MILESTONE_ORDER.indexOf(target);
  return MILESTONE_ORDER.slice(0, idx + 1);
}

async function isUp(url, timeoutMs = 1500) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await fetch(url, { signal: ctrl.signal });
    return res.ok || res.status < 500;
  } catch {
    return false;
  } finally {
    clearTimeout(t);
  }
}

async function waitFor(url, label, attempts = 40) {
  for (let i = 0; i < attempts; i++) {
    if (await isUp(url)) {
      console.log(`✓ ${label} ready (${url})`);
      return;
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`Timed out waiting for ${label} at ${url}`);
}

function startProcess(command, args, cwd, name, extraEnv = {}) {
  const child = spawn(command, args, {
    cwd,
    shell: true,
    stdio: ['ignore', 'pipe', 'pipe'],
    env: { ...process.env, ...extraEnv },
  });
  child.stdout.on('data', (d) => {
    if (process.env.QA_VERBOSE) process.stdout.write(`[${name}] ${d}`);
  });
  child.stderr.on('data', (d) => {
    if (process.env.QA_VERBOSE) process.stderr.write(`[${name}] ${d}`);
  });
  child.on('exit', (code) => {
    if (process.env.QA_VERBOSE) console.log(`[${name}] exited ${code}`);
  });
  return child;
}

function killTree(child) {
  if (!child?.pid) return;
  try {
    if (process.platform === 'win32') {
      spawn('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
    } else {
      child.kill('SIGTERM');
    }
  } catch {
    // ignore
  }
}

function runPlaywright(milestone) {
  return new Promise((resolve) => {
    // `shell: true` concatenates argv without escaping, so a checkout under a
    // path containing spaces ("E:\Ciklum Latop Data\...") reached Playwright
    // as two arguments and it looked for a config named "E:\Ciklum".
    const args = [
      'playwright',
      'test',
      '--config',
      JSON.stringify(path.join(ROOT, 'e2e/playwright.config.js')),
    ];

    const child = spawn('npx', args, {
      cwd: ROOT,
      shell: true,
      stdio: 'inherit',
      env: {
        ...process.env,
        QA_MILESTONE: milestone,
        QA_API_URL: API_URL,
        QA_FRONTEND_URL: FE_URL,
      },
    });

    child.on('exit', (code) => resolve(code ?? 1));
  });
}

async function main() {
  const milestone = parseMilestone(process.argv);
  const toRun = milestonesUpTo(milestone);
  console.log(`\n══ Offline QA harness (token-free) ══`);
  console.log(`Target milestone: ${milestone}`);
  console.log(`Regression suite: ${toRun.join(' → ')}\n`);

  const started = [];
  let apiChild = null;
  let feChild = null;

  try {
    if (!(await isUp(`${API_URL}/api/health`))) {
      console.log(`Starting API on :${PORTS.api} …`);
      // Force local billing test mode: M6 drives the admin-only checkout,
      // which otherwise calls Paddle for real. Shop fixtures are subscribed by
      // an admin grant and do not need it.
      apiChild = startProcess(
        'php',
        ['backend/artisan', 'serve', `--port=${PORTS.api}`, '--host=127.0.0.1'],
        ROOT,
        'api',
        { BILLING_SANDBOX: 'true' }
      );
      started.push(apiChild);
      await waitFor(`${API_URL}/api/health`, 'API');
    } else {
      console.log(`✓ API already up (${API_URL})`);
    }

    if (!(await isUp(FE_URL))) {
      console.log(`Starting frontend on :${PORTS.web} …`);
      feChild = startProcess('npx', ['--yes', 'serve', '-l', String(PORTS.web), '.'], ROOT, 'fe');
      started.push(feChild);
      await waitFor(FE_URL, 'Frontend');
    } else {
      console.log(`✓ Frontend already up (${FE_URL})`);
    }

    const testFiles = toRun.flatMap((m) => {
      const prefix = SPEC_BY_MILESTONE[m].replace('*.spec.js', '');
      const dir = path.join(ROOT, 'e2e/tests');
      if (!fs.existsSync(dir)) return [];
      return fs
        .readdirSync(dir)
        .filter((f) => f.startsWith(prefix) && f.endsWith('.spec.js'));
    });

    if (testFiles.length === 0) {
      console.error('No e2e specs found for the requested milestones.');
      process.exitCode = 1;
      return;
    }

    console.log(`Running tests:\n  ${testFiles.join('\n  ')}`);
    const code = await runPlaywright(milestone);

    if (code === 0) {
      console.log(`\n✓ QA PASSED for regression through ${milestone}`);
      console.log(`  Update docs/CONTEXT.md if you want to note QA-passed.\n`);
    } else {
      console.log(`\n✗ QA FAILED — check docs/issues/${milestone}/ and docs/issues/README.md\n`);
    }

    process.exitCode = code;
  } catch (err) {
    console.error(err.message || err);
    process.exitCode = 1;
  } finally {
    if (apiChild) killTree(apiChild);
    if (feChild) killTree(feChild);
  }
}

main();
