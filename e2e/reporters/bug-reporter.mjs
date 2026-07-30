/**
 * Playwright reporter: on each failed test, write docs/issues/<M>/BUG-xxx.md
 * Token-free / offline.
 */

import fs from 'node:fs';
import path from 'node:path';
import { writeBug, ROOT } from '../../scripts/write-bug.mjs';

class BugReporter {
  constructor() {
    this.milestone = (process.env.QA_MILESTONE || 'M1').toUpperCase();
    this.filed = [];
  }

  onTestEnd(test, result) {
    if (result.status !== 'failed' && result.status !== 'timedOut') return;

    const title = test.title;
    const testFile = path.relative(ROOT, test.location.file).replace(/\\/g, '/');
    const error = result.errors?.[0];
    const actual = error?.message || String(error) || 'Test failed';

    let screenshotRel = null;
    const shot = result.attachments?.find(
      (a) => a.name === 'screenshot' || a.contentType?.startsWith('image/')
    );
    if (shot?.path) {
      const destDir = path.join(ROOT, 'docs', 'issues', this.milestone);
      fs.mkdirSync(destDir, { recursive: true });
      const destName = `shot-${Date.now()}.png`;
      const dest = path.join(destDir, destName);
      try {
        fs.copyFileSync(shot.path, dest);
        screenshotRel = path.relative(ROOT, dest).replace(/\\/g, '/');
      } catch {
        screenshotRel = path.relative(ROOT, shot.path).replace(/\\/g, '/');
      }
    }

    const { id, filePath } = writeBug({
      milestone: this.milestone,
      title,
      testFile,
      actual,
      screenshotRel,
      expected: 'See assertions in the Playwright test file.',
      steps: [
        'Start API: `npm run dev:api` (port 8000)',
        'Start UI: `npm start` (port 3000)',
        `Re-run harness: \`npm run qa:milestone -- ${this.milestone}\``,
        `Inspect test: \`${testFile}\` → "${title}"`,
      ],
    });

    this.filed.push({ id, filePath, title });
    console.log(`\n🐛 Filed ${id} → ${filePath}\n`);
  }

  onEnd() {
    if (this.filed.length === 0) return;
    console.log('\n── QA issues filed ──');
    for (const f of this.filed) {
      console.log(`  ${f.id}: ${f.filePath}`);
    }
    console.log(`See docs/issues/${this.milestone}/ and docs/issues/README.md\n`);
  }
}

export default BugReporter;
