import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { DEDUCTION_PRESETS, createDeductionsManager } from '../js/deductions.js';

/** Minimal list element stub — render() only needs innerHTML + empty querySelectorAll. */
function createListEl() {
  return {
    innerHTML: '',
    querySelectorAll() {
      return [];
    },
  };
}

describe('DEDUCTION_PRESETS', () => {
  it('includes the expected preset ids', () => {
    assert.deepEqual(
      DEDUCTION_PRESETS.map((p) => p.id),
      ['sehat-card', 'provident-fund', 'zakat', 'donations', 'other']
    );
  });
});

describe('createDeductionsManager', () => {
  /** @type {ReturnType<typeof createDeductionsManager>} */
  let manager;
  /** @type {number} */
  let changeCount;

  beforeEach(() => {
    changeCount = 0;
    manager = createDeductionsManager(createListEl(), () => {
      changeCount += 1;
    });
  });

  it('starts empty with zero total', () => {
    assert.deepEqual(manager.getRows(), []);
    assert.equal(manager.getTotal(), 0);
    assert.equal(manager.serialize(), '');
  });

  it('adds rows and notifies onChange', () => {
    const before = changeCount;
    manager.addRow('zakat', '25000');
    assert.equal(changeCount, before + 1);
    assert.equal(manager.getRows().length, 1);
    assert.equal(manager.getRows()[0].type, 'zakat');
    assert.equal(manager.getRows()[0].amount, '25000');
  });

  it('defaults type to first preset when omitted', () => {
    manager.addRow();
    assert.equal(manager.getRows()[0].type, DEDUCTION_PRESETS[0].id);
    assert.equal(manager.getRows()[0].amount, '');
  });

  it('sums numeric amounts and ignores invalid ones', () => {
    manager.addRow('zakat', '10000');
    manager.addRow('donations', '5000');
    manager.addRow('other', 'abc');
    manager.addRow('other', '');
    assert.equal(manager.getTotal(), 15_000);
  });

  it('serializes only rows with positive amounts', () => {
    manager.addRow('zakat', '10000');
    manager.addRow('donations', '0');
    manager.addRow('other', '');
    manager.addRow('provident-fund', '2500');
    assert.equal(manager.serialize(), 'zakat:10000|provident-fund:2500');
  });

  it('restores rows from a serialized string', () => {
    manager.loadFromSerialized('zakat:15000|donations:3000');
    assert.equal(manager.getRows().length, 2);
    assert.equal(manager.getTotal(), 18_000);
    assert.equal(manager.serialize(), 'zakat:15000|donations:3000');
  });

  it('falls back to first preset for unknown types', () => {
    manager.loadFromSerialized('unknown-type:4000');
    assert.equal(manager.getRows()[0].type, DEDUCTION_PRESETS[0].id);
    assert.equal(manager.getRows()[0].amount, '4000');
  });

  it('ignores empty serialized input', () => {
    manager.addRow('zakat', '1000');
    manager.loadFromSerialized('');
    assert.equal(manager.getRows().length, 1);
    manager.loadFromSerialized(null);
    assert.equal(manager.getRows().length, 1);
  });

  it('getRows returns a shallow copy', () => {
    manager.addRow('zakat', '1000');
    const rows = manager.getRows();
    rows[0].amount = '999';
    assert.equal(manager.getRows()[0].amount, '1000');
  });
});
