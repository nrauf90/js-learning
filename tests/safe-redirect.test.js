import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { DEFAULT_LANDING, safeNext } from '../js/safe-redirect.js';

describe('safeNext', () => {
  it('lets the app send someone back to the page they were on', () => {
    assert.equal(safeNext('purchases.html'), 'purchases.html');
    assert.equal(safeNext('pos.html'), 'pos.html');
    assert.equal(safeNext('reset-password.html'), 'reset-password.html');
  });

  it('keeps a query string on the page it belongs to', () => {
    assert.equal(safeNext('sales.html?page=3'), 'sales.html?page=3');
  });

  it('falls back to the dashboard when nothing was asked for', () => {
    assert.equal(safeNext(null), DEFAULT_LANDING);
    assert.equal(safeNext(undefined), DEFAULT_LANDING);
    assert.equal(safeNext(''), DEFAULT_LANDING);
  });

  /*
   * The reason this module exists. Each of these, handed to location.href,
   * walks the shopkeeper off our origin the moment they finish typing their
   * password — with our domain in the address bar the whole way there.
   */
  it('refuses to leave the origin', () => {
    for (const hostile of [
      'https://evil.example',
      'http://evil.example/login',
      '//evil.example',
      '/\\evil.example',
      '\\\\evil.example',
      'javascript:alert(document.cookie)',
      'data:text/html,<script>alert(1)</script>',
      'HTTPS://EVIL.EXAMPLE',
    ]) {
      assert.equal(safeNext(hostile), DEFAULT_LANDING, hostile);
    }
  });

  it('refuses to climb out of the directory', () => {
    assert.equal(safeNext('../../etc/passwd'), DEFAULT_LANDING);
    assert.equal(safeNext('../admin.html'), DEFAULT_LANDING);
    assert.equal(safeNext('/admin.html'), DEFAULT_LANDING);
  });

  it('refuses anything that is not one of our own pages', () => {
    assert.equal(safeNext('dashboard'), DEFAULT_LANDING);
    assert.equal(safeNext('sub/dir/page.html'), DEFAULT_LANDING);
    assert.equal(safeNext('page.html#<script>'), DEFAULT_LANDING);
    assert.equal(safeNext(42), DEFAULT_LANDING);
  });
});
