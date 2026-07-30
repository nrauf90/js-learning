import { defineConfig, devices } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.join(root, '..');

const MILESTONE_ORDER = ['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8'];
const target = (process.env.QA_MILESTONE || 'M1').toUpperCase();
const idx = Math.max(0, MILESTONE_ORDER.indexOf(target));
const milestones = MILESTONE_ORDER.slice(0, idx + 1);

const testsDir = path.join(root, 'tests');
const testMatch = milestones.flatMap((m) => {
  const prefix = m.toLowerCase() + '-';
  if (!fs.existsSync(testsDir)) return [];
  return fs
    .readdirSync(testsDir)
    .filter((f) => f.startsWith(prefix) && f.endsWith('.spec.js'))
    .map((f) => f);
});

export default defineConfig({
  testDir: testsDir,
  testMatch: testMatch.length ? testMatch : /NO_TESTS_MATCH/,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  timeout: 30_000,
  expect: { timeout: 10_000 },
  reporter: [
    ['list'],
    [path.join(root, 'reporters/bug-reporter.mjs')],
  ],
  use: {
    baseURL: process.env.QA_FRONTEND_URL || 'http://127.0.0.1:3000',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  metadata: { repoRoot, targetMilestone: target, milestones },
});
