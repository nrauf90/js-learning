import { describe, it, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import {
  readStateFromURL,
  updateURL,
  copyShareLink,
  initPrintExport,
} from '../js/share-export.js';

describe('share-export URL helpers', () => {
  /** @type {string} */
  let currentSearch;
  /** @type {string} */
  let currentPath;
  /** @type {string | null} */
  let lastReplacedUrl;
  let originalWindow;

  beforeEach(() => {
    currentSearch = '';
    currentPath = '/';
    lastReplacedUrl = null;
    originalWindow = globalThis.window;

    globalThis.window = {
      location: {
        get search() {
          return currentSearch;
        },
        get pathname() {
          return currentPath;
        },
        get href() {
          return `http://localhost${currentPath}${currentSearch}`;
        },
      },
      history: {
        replaceState(_state, _title, url) {
          lastReplacedUrl = url;
          if (typeof url === 'string' && url.includes('?')) {
            const [, query = ''] = url.split('?');
            currentSearch = query ? `?${query}` : '';
            currentPath = url.split('?')[0] || '/';
          } else {
            currentSearch = '';
            currentPath = typeof url === 'string' ? url : '/';
          }
        },
      },
      print() {},
    };
  });

  afterEach(() => {
    globalThis.window = originalWindow;
  });

  it('readStateFromURL returns null when query is empty', () => {
    currentSearch = '';
    assert.equal(readStateFromURL(), null);
  });

  it('readStateFromURL restores all known params', () => {
    currentSearch =
      '?income=2500000&type=monthly&regime=old&year=fy2024-25&format=lakh_crore&ded=zakat:10000';
    assert.deepEqual(readStateFromURL(), {
      income: '2500000',
      incomeType: 'monthly',
      regime: 'old',
      year: 'fy2024-25',
      format: 'lakh_crore',
      deductions: 'zakat:10000',
    });
  });

  it('updateURL writes non-empty state into the query string', () => {
    updateURL({
      income: 1_200_000,
      incomeType: 'annual',
      regime: 'new',
      year: 'fy2025-26',
      format: 'international',
      deductions: 'sehat-card:5000',
    });

    assert.ok(lastReplacedUrl.includes('income=1200000'));
    assert.ok(lastReplacedUrl.includes('type=annual'));
    assert.ok(lastReplacedUrl.includes('regime=new'));
    assert.ok(lastReplacedUrl.includes('year=fy2025-26'));
    assert.ok(lastReplacedUrl.includes('format=international'));
    assert.ok(lastReplacedUrl.includes('ded=sehat-card%3A5000') || lastReplacedUrl.includes('ded=sehat-card:5000'));
  });

  it('updateURL omits blank income and empty optional fields', () => {
    updateURL({
      income: '',
      incomeType: 'annual',
      regime: 'new',
      year: 'fy2025-26',
    });

    assert.ok(!lastReplacedUrl.includes('income='));
    assert.ok(!lastReplacedUrl.includes('format='));
    assert.ok(!lastReplacedUrl.includes('ded='));
  });

  it('updateURL clears the query when state has nothing useful', () => {
    currentSearch = '?income=1';
    updateURL({ income: '', incomeType: '', regime: '', year: '' });
    assert.equal(lastReplacedUrl, '/');
  });
});

describe('copyShareLink', () => {
  let originalWindow;
  let originalNavigator;
  let originalDocument;

  beforeEach(() => {
    originalWindow = globalThis.window;
    originalNavigator = globalThis.navigator;
    originalDocument = globalThis.document;

    globalThis.window = {
      location: { href: 'http://localhost/?income=100' },
    };
  });

  afterEach(() => {
    globalThis.window = originalWindow;
    globalThis.navigator = originalNavigator;
    globalThis.document = originalDocument;
  });

  it('uses the Clipboard API when available', async () => {
    let written = '';
    globalThis.navigator = {
      clipboard: {
        async writeText(text) {
          written = text;
        },
      },
    };

    const success = await new Promise((resolve) => {
      copyShareLink(resolve);
    });

    assert.equal(success, true);
    assert.equal(written, 'http://localhost/?income=100');
  });

  it('falls back to execCommand when clipboard write fails', async () => {
    globalThis.navigator = {
      clipboard: {
        async writeText() {
          throw new Error('denied');
        },
      },
    };

    const appended = [];
    globalThis.document = {
      createElement() {
        return {
          value: '',
          style: {},
          select() {},
        };
      },
      body: {
        appendChild(el) {
          appended.push(el);
        },
        removeChild() {},
      },
      execCommand() {
        return true;
      },
    };

    const success = await new Promise((resolve) => {
      copyShareLink(resolve);
    });

    assert.equal(success, true);
    assert.equal(appended.length, 1);
    assert.equal(appended[0].value, 'http://localhost/?income=100');
  });
});

describe('initPrintExport', () => {
  it('runs beforePrint then window.print on click', () => {
    const clicks = [];
    const buttonEl = {
      addEventListener(event, handler) {
        if (event === 'click') clicks.push(handler);
      },
    };

    let printed = 0;
    let prepared = 0;
    const originalWindow = globalThis.window;
    globalThis.window = {
      print() {
        printed += 1;
      },
    };

    try {
      initPrintExport(buttonEl, () => {
        prepared += 1;
      });
      assert.equal(clicks.length, 1);
      clicks[0]();
      assert.equal(prepared, 1);
      assert.equal(printed, 1);
    } finally {
      globalThis.window = originalWindow;
    }
  });

  it('works without a beforePrint callback', () => {
    let handler;
    const buttonEl = {
      addEventListener(_event, fn) {
        handler = fn;
      },
    };

    let printed = 0;
    const originalWindow = globalThis.window;
    globalThis.window = {
      print() {
        printed += 1;
      },
    };

    try {
      initPrintExport(buttonEl);
      handler();
      assert.equal(printed, 1);
    } finally {
      globalThis.window = originalWindow;
    }
  });
});
