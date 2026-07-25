/**
 * Deductions / exemptions manager.
 *
 * Lets a user add line items (Sehat Card premium, provident fund
 * contribution, Zakat, approved donations, or a custom "other" entry)
 * that reduce taxable income for a more accurate net-tax figure.
 *
 * This module owns the row state and its own DOM rendering; it notifies
 * the caller via `onChange` whenever the total changes so the host page
 * can re-run the tax calculation.
 */

export const DEDUCTION_PRESETS = [
    { id: 'sehat-card', label: 'Sehat Card / Health Insurance Premium' },
    { id: 'provident-fund', label: 'Provident Fund Contribution' },
    { id: 'zakat', label: 'Zakat Paid' },
    { id: 'donations', label: 'Approved Donations (u/s 61)' },
    { id: 'other', label: 'Other Exemption' },
  ];
  
  let counter = 0;
  
  /**
   * @param {HTMLElement} listEl container the rows render into
   * @param {() => void} onChange called after any add/edit/remove
   */
  export function createDeductionsManager(listEl, onChange) {
    /** @type {{ id: string, type: string, amount: string }[]} */
    let rows = [];
  
    function render() {
      if (rows.length === 0) {
        listEl.innerHTML = `<p class="deductions-empty">No deductions added yet.</p>`;
        return;
      }
  
      listEl.innerHTML = rows
        .map(
          (r) => `
          <div class="deduction-row" data-row-id="${r.id}">
            <select class="deduction-type" data-row-id="${r.id}" aria-label="Deduction type">
              ${DEDUCTION_PRESETS.map(
                (p) => `<option value="${p.id}"${p.id === r.type ? ' selected' : ''}>${p.label}</option>`
              ).join('')}
            </select>
            <input
              type="number"
              class="deduction-amount"
              data-row-id="${r.id}"
              placeholder="Amount (PKR)"
              min="0"
              step="1000"
              value="${r.amount}"
              aria-label="Deduction amount"
            />
            <button type="button" class="deduction-remove" data-row-id="${r.id}" aria-label="Remove this deduction">&times;</button>
          </div>`
        )
        .join('');
  
      listEl.querySelectorAll('.deduction-type').forEach((el) => {
        el.addEventListener('change', (e) => {
          const row = rows.find((r) => r.id === e.target.dataset.rowId);
          if (row) row.type = e.target.value;
          onChange();
        });
      });
  
      listEl.querySelectorAll('.deduction-amount').forEach((el) => {
        el.addEventListener('input', (e) => {
          const row = rows.find((r) => r.id === e.target.dataset.rowId);
          if (row) row.amount = e.target.value;
          onChange();
        });
      });
  
      listEl.querySelectorAll('.deduction-remove').forEach((el) => {
        el.addEventListener('click', (e) => {
          rows = rows.filter((r) => r.id !== e.target.dataset.rowId);
          render();
          onChange();
        });
      });
    }
  
    function addRow(type = DEDUCTION_PRESETS[0].id, amount = '') {
      rows.push({ id: `d${counter++}`, type, amount: String(amount ?? '') });
      render();
      onChange();
    }
  
    function getTotal() {
      return rows.reduce((sum, r) => sum + (parseFloat(r.amount) || 0), 0);
    }
  
    function getRows() {
      return rows.map((r) => ({ ...r }));
    }
  
    /** Encodes non-empty rows as "type:amount|type:amount" for permalinks. */
    function serialize() {
      return rows
        .filter((r) => parseFloat(r.amount) > 0)
        .map((r) => `${r.type}:${r.amount}`)
        .join('|');
    }
  
    /** Restores rows from a string produced by serialize(). */
    function loadFromSerialized(str) {
      if (!str) return;
      rows = str
        .split('|')
        .filter(Boolean)
        .map((part) => {
          const [type, amount] = part.split(':');
          const preset = DEDUCTION_PRESETS.some((p) => p.id === type) ? type : DEDUCTION_PRESETS[0].id;
          return { id: `d${counter++}`, type: preset, amount: amount || '' };
        });
      render();
    }
  
    render();
  
    return { addRow, getTotal, getRows, serialize, loadFromSerialized };
  }