import {
  apiDelete,
  apiGet,
  apiPost,
  apiPut,
  getAuthToken,
} from './api.js';
import { initShell } from './shell.js';
import { initTheme } from './theme.js';

let categories = [];
let entries = [];
let editingId = null;
let receiptAddonActive = false;

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('cashflow.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('cashflow-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
}

function clearAlert() {
  const el = document.getElementById('cashflow-alert');
  if (!el) return;
  el.hidden = true;
  el.textContent = '';
}

function formatRs(amount) {
  const n = Number(amount) || 0;
  return `Rs ${Math.round(n).toLocaleString('en-PK')}`;
}

function todayISO() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${m}-${day}`;
}

function selectedDate() {
  return document.getElementById('entry-date')?.value || todayISO();
}

function selectedEntryType() {
  return document.getElementById('entry-type')?.value || 'expense';
}

function fillCategories() {
  const select = document.getElementById('entry-category');
  if (!select) return;
  if (!categories.length) {
    select.innerHTML = '<option value="">No categories</option>';
    return;
  }
  select.innerHTML = categories
    .map((c) => `<option value="${c.id}">${c.name}</option>`)
    .join('');
}

function resetForm() {
  editingId = null;
  document.getElementById('entry-id').value = '';
  document.getElementById('entry-type').value = 'expense';
  document.getElementById('entry-amount').value = '';
  document.getElementById('entry-note').value = '';
  document.getElementById('entry-submit').textContent = 'Add entry';
  document.getElementById('entry-cancel').hidden = true;
  loadCategories('expense').catch((err) => showAlert(err.message));
}

function updateTotals() {
  let income = 0;
  let expense = 0;
  for (const e of entries) {
    if (e.type === 'income') income += Number(e.amount);
    else expense += Number(e.amount);
  }
  document.getElementById('total-income').textContent = formatRs(income);
  document.getElementById('total-expense').textContent = formatRs(expense);
  document.getElementById('total-net').textContent = formatRs(income - expense);
}

function renderEntries() {
  const list = document.getElementById('entries-list');
  const empty = document.getElementById('entries-empty');
  updateTotals();

  if (!entries.length) {
    empty.hidden = false;
    list.innerHTML = '';
    return;
  }

  empty.hidden = true;
  list.innerHTML = entries
    .map(
      (e) => `
      <li class="entry-row" data-id="${e.id}">
        <div class="entry-main">
          <span class="entry-type entry-type-${e.type}">${e.type}</span>
          <span class="entry-cat">${e.category?.name || '—'}</span>
          <span class="entry-amount">${formatRs(e.amount)}</span>
        </div>
        <div class="entry-meta">${e.note ? escapeHtml(e.note) : '<span class="muted">No note</span>'}</div>
        <div class="entry-actions">
          <button type="button" class="entry-edit" data-id="${e.id}">Edit</button>
          <button type="button" class="entry-delete" data-id="${e.id}">Delete</button>
        </div>
      </li>`
    )
    .join('');

  list.querySelectorAll('.entry-edit').forEach((btn) => {
    btn.addEventListener('click', () => startEdit(Number(btn.dataset.id)));
  });
  list.querySelectorAll('.entry-delete').forEach((btn) => {
    btn.addEventListener('click', () => removeEntry(Number(btn.dataset.id)));
  });
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function startEdit(id) {
  const entry = entries.find((e) => e.id === id);
  if (!entry) return;
  editingId = id;
  document.getElementById('entry-id').value = String(id);
  document.getElementById('entry-type').value = entry.type;
  document.getElementById('entry-amount').value = String(entry.amount);
  document.getElementById('entry-note').value = entry.note || '';
  document.getElementById('entry-submit').textContent = 'Save changes';
  document.getElementById('entry-cancel').hidden = false;
  loadCategories(entry.type)
    .then(() => {
      document.getElementById('entry-category').value = String(entry.category_id);
    })
    .catch((err) => showAlert(err.message));
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function removeEntry(id) {
  if (!window.confirm('Delete this entry?')) return;
  clearAlert();
  try {
    await apiDelete(`/api/cash-entries/${id}`);
    await loadEntries();
    showAlert('Entry deleted.', 'success');
  } catch (err) {
    showAlert(err.message || 'Delete failed');
  }
}

async function loadCategories(kind = selectedEntryType()) {
  const data = await apiGet(`/api/categories?kind=${encodeURIComponent(kind)}`);
  categories = data.categories || [];
  fillCategories();
}

async function loadEntries() {
  const date = selectedDate();
  const data = await apiGet(`/api/cash-entries?date=${encodeURIComponent(date)}`);
  entries = data.entries || [];
  renderEntries();
}

function renderReceiptUpsell() {
  const upsell = document.getElementById('receipt-upsell');
  const active = document.getElementById('receipt-active');
  if (!upsell || !active) return;

  const isExpense = selectedEntryType() === 'expense';
  upsell.hidden = receiptAddonActive || !isExpense;
  active.hidden = !receiptAddonActive || !isExpense;
}

async function loadAddonStatus() {
  const data = await apiGet('/api/billing/subscription');
  receiptAddonActive = data.addons?.receipt?.active === true;
  renderReceiptUpsell();
}

async function onSubmit(e) {
  e.preventDefault();
  clearAlert();

  const payload = {
    type: document.getElementById('entry-type').value,
    category_id: Number(document.getElementById('entry-category').value),
    amount: Number(document.getElementById('entry-amount').value),
    entry_date: selectedDate(),
    note: document.getElementById('entry-note').value.trim() || null,
  };

  const submit = document.getElementById('entry-submit');
  submit.disabled = true;

  try {
    if (editingId) {
      await apiPut(`/api/cash-entries/${editingId}`, payload);
      showAlert('Entry updated.', 'success');
    } else {
      await apiPost('/api/cash-entries', payload);
      showAlert('Entry added.', 'success');
    }
    resetForm();
    await loadEntries();
  } catch (err) {
    const msg =
      err.body?.errors
        ? Object.values(err.body.errors).flat().join(' ')
        : err.message || 'Save failed';
    showAlert(msg);
  } finally {
    submit.disabled = false;
  }
}

async function boot() {
  initTheme();
  initShell({ current: 'cashflow' });
  if (!requireAuth()) return;

  const dateInput = document.getElementById('entry-date');
  dateInput.value = todayISO();
  dateInput.addEventListener('change', () => {
    resetForm();
    loadEntries().catch((err) => showAlert(err.message));
  });

  document.getElementById('entry-form').addEventListener('submit', onSubmit);
  document.getElementById('entry-type').addEventListener('change', () => {
    if (editingId) return;
    loadCategories(selectedEntryType()).catch((err) => showAlert(err.message));
    renderReceiptUpsell();
  });
  document.getElementById('entry-cancel').addEventListener('click', () => {
    resetForm();
    clearAlert();
  });

  try {
    await loadCategories('expense');
    resetForm();
    await loadEntries();
    await loadAddonStatus();
  } catch (err) {
    showAlert(err.message || 'Failed to load cash flow data');
  }
}

boot();
