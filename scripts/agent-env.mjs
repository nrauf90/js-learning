/**
 * Resolves which ports this checkout should use.
 *
 * Every checkout — the main one and each agent worktree — gets a numbered
 * "slot". Slot 0 keeps the historic ports so nothing about working in the main
 * checkout changes; slot N shifts each port by N so two agents can run their
 * servers, their QA harness and their mobile preview at the same time without
 * fighting over a socket.
 *
 *   slot 0   web 3000   mobile 3100   api 8000     (main checkout)
 *   slot 1   web 3001   mobile 3101   api 8001
 *   slot 2   web 3002   mobile 3102   api 8002
 *
 * Resolution order, most specific first:
 *   1. an explicit PKG_* / QA_* environment variable
 *   2. .agent.json in the checkout root, written by scripts/worktree.mjs
 *   3. slot 0
 *
 * .agent.json is gitignored on purpose: it describes *this working copy*, not
 * the project, and committing it would hand every checkout the same ports again.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const BASE = { web: 3000, mobile: 3100, api: 8000 };

function readSlot() {
  if (process.env.PKG_SLOT) return Number(process.env.PKG_SLOT) || 0;
  try {
    const raw = fs.readFileSync(path.join(ROOT, '.agent.json'), 'utf8');
    return Number(JSON.parse(raw).slot) || 0;
  } catch {
    return 0;
  }
}

export function agentEnv() {
  const slot = readSlot();

  const web = Number(process.env.PKG_WEB_PORT) || BASE.web + slot;
  const mobile = Number(process.env.PKG_MOBILE_PORT) || BASE.mobile + slot;
  const api = Number(process.env.PKG_API_PORT) || BASE.api + slot;

  return {
    slot,
    web,
    mobile,
    api,
    /* The QA harness and Playwright already read these two, so setting them
       here is all it takes for the whole test run to follow the slot. */
    feUrl: process.env.QA_FRONTEND_URL || `http://127.0.0.1:${web}`,
    apiUrl: process.env.QA_API_URL || `http://127.0.0.1:${api}`,
  };
}

/** `node scripts/agent-env.mjs` prints the resolved slot — handy when a run
    fails and the first question is "which ports was that agent even on?". */
if (process.argv[1] && process.argv[1].endsWith('agent-env.mjs')) {
  const e = agentEnv();
  console.log(`slot ${e.slot}`);
  console.log(`  web     http://127.0.0.1:${e.web}`);
  console.log(`  mobile  http://127.0.0.1:${e.mobile}`);
  console.log(`  api     http://127.0.0.1:${e.api}`);
  console.log(`  root    ${ROOT}`);
}
