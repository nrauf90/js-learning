/**
 * Writes a bug markdown file under docs/issues/<MILESTONE>/.
 * Pure Node — no AI tokens required.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ISSUES_ROOT = path.join(ROOT, 'docs', 'issues');

function slugify(text) {
  return String(text)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 50) || 'failure';
}

function nextBugId(milestoneDir) {
  fs.mkdirSync(milestoneDir, { recursive: true });
  const existing = fs
    .readdirSync(milestoneDir)
    .filter((f) => /^BUG-\d+/i.test(f));
  const nums = existing.map((f) => {
    const m = f.match(/^BUG-(\d+)/i);
    return m ? Number(m[1]) : 0;
  });
  const next = (nums.length ? Math.max(...nums) : 0) + 1;
  return `BUG-${String(next).padStart(3, '0')}`;
}

function milestoneDocLinks(milestone) {
  const map = {
    M1: ['docs/milestones/M1-foundation.md', 'docs/tasks/M1-tasks.md'],
    M2: ['docs/milestones/M2-authentication.md', 'docs/tasks/M2-tasks.md'],
    M3: ['docs/milestones/M3-cashflow.md', 'docs/tasks/M3-tasks.md'],
    M4: ['docs/milestones/M4-reports.md', 'docs/tasks/M4-tasks.md'],
    M5: ['docs/milestones/M5-tax-calculator.md', 'docs/tasks/M5-tasks.md'],
    M6: ['docs/milestones/M6-billing.md', 'docs/tasks/M6-tasks.md'],
    M7: ['docs/milestones/M7-subscription-gate.md', 'docs/tasks/M7-tasks.md'],
    M8: ['docs/milestones/M8-receipt-addon.md', 'docs/tasks/M8-tasks.md'],
  };
  return map[milestone] || [];
}

function updateIndex(entryLine) {
  const indexPath = path.join(ISSUES_ROOT, 'README.md');
  let content = fs.existsSync(indexPath)
    ? fs.readFileSync(indexPath, 'utf8')
    : '# QA issues\n\nOffline Playwright failures land here.\n\n| Bug | Milestone | Status | Title |\n|-----|-----------|--------|-------|\n';

  if (!content.includes('| Bug |')) {
    content += '\n| Bug | Milestone | Status | Title |\n|-----|-----------|--------|-------|\n';
  }
  if (!content.includes(entryLine.split('|')[1]?.trim() || '___')) {
    content = content.trimEnd() + '\n' + entryLine + '\n';
    fs.writeFileSync(indexPath, content, 'utf8');
  }
}

/**
 * @param {{
 *   milestone: string,
 *   title: string,
 *   testFile?: string,
 *   steps?: string[],
 *   expected?: string,
 *   actual?: string,
 *   screenshotRel?: string | null,
 * }} opts
 * @returns {{ id: string, filePath: string }}
 */
export function writeBug(opts) {
  const milestone = (opts.milestone || 'M1').toUpperCase();
  const milestoneDir = path.join(ISSUES_ROOT, milestone);
  const id = nextBugId(milestoneDir);
  const slug = slugify(opts.title);
  const fileName = `${id}-${slug}.md`;
  const filePath = path.join(milestoneDir, fileName);
  const date = new Date().toISOString().slice(0, 10);
  const links = milestoneDocLinks(milestone);
  const steps =
    opts.steps?.length > 0
      ? opts.steps
      : [
          'Start API: `npm run dev:api`',
          'Start UI: `npm start`',
          'Re-run: `npm run qa:milestone -- ' + milestone + '`',
          `Open failing test: \`${opts.testFile || 'e2e/tests'}\``,
        ];

  const md = `# ${id} - ${opts.title}

- **Milestone:** ${milestone}
- **Found during:** regression up to ${milestone}
- **Date:** ${date}
- **Status:** open

## How to reproduce

${steps.map((s, i) => `${i + 1}. ${s}`).join('\n')}

## Expected

${opts.expected || 'Test assertions pass (see Playwright expect in the listed test file).'}

## Actual

\`\`\`
${(opts.actual || 'Unknown failure').replace(/\u001b\[[0-9;]*m/g, '')}
\`\`\`

## Evidence

- Screenshot: ${opts.screenshotRel ? `\`${opts.screenshotRel}\`` : 'n/a'}
- Test: \`${opts.testFile || 'n/a'}\`

## Links

${links.map((l) => `- \`${l}\``).join('\n') || '- (no milestone docs linked)'}
- Issues index: \`docs/issues/README.md\`
`;

  fs.writeFileSync(filePath, md, 'utf8');

  const rel = path.relative(ROOT, filePath).replace(/\\/g, '/');
  updateIndex(`| [${id}](./${milestone}/${fileName}) | ${milestone} | open | ${opts.title.replace(/\|/g, '/')} |`);

  return { id, filePath: rel };
}

export { ISSUES_ROOT, ROOT };
