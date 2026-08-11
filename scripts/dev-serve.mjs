#!/usr/bin/env node
/**
 * Starts a dev server on this checkout's assigned port.
 *
 * A node wrapper rather than the port baked into package.json, because the port
 * now depends on the worktree slot (see agent-env.mjs) and npm scripts have no
 * portable way to read a value and fall back to a default across cmd.exe and sh.
 *
 * Usage: node scripts/dev-serve.mjs web|mobile|api
 */

import { spawn } from 'node:child_process';
import path from 'node:path';
import { ROOT, agentEnv } from './agent-env.mjs';

const target = process.argv[2];
const env = agentEnv();

const RUNNERS = {
  web: () => ({
    label: `web  http://127.0.0.1:${env.web}`,
    cmd: 'npx',
    args: ['--yes', 'serve', '-l', String(env.web), '.'],
    cwd: ROOT,
  }),
  mobile: () => ({
    label: `mobile  http://127.0.0.1:${env.mobile}`,
    cmd: 'npx',
    args: ['--yes', 'serve', '-l', String(env.mobile), 'www'],
    cwd: ROOT,
  }),
  api: () => ({
    /* php -S from backend/public with server.php as the router, matching the
       dev:api script this replaces — artisan serve is slower per request. */
    label: `api  http://127.0.0.1:${env.api}`,
    cmd: 'php',
    args: [
      '-d', 'opcache.enable_cli=1',
      '-d', 'opcache.memory_consumption=192',
      '-d', 'opcache.max_accelerated_files=20000',
      '-d', 'opcache.revalidate_freq=0',
      '-S', `127.0.0.1:${env.api}`,
      '../server.php',
    ],
    cwd: path.join(ROOT, 'backend', 'public'),
  }),
};

if (!RUNNERS[target]) {
  console.error(`Usage: node scripts/dev-serve.mjs web|mobile|api   (got ${target || 'nothing'})`);
  process.exit(1);
}

const { label, cmd, args, cwd } = RUNNERS[target]();
console.log(`[slot ${env.slot}] ${label}`);

const child = spawn(cmd, args, { cwd, stdio: 'inherit', shell: process.platform === 'win32' });
child.on('exit', (code) => process.exit(code ?? 0));
