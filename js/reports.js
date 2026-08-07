import { apiGet, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme as initSharedTheme } from './theme.js';

let mode = 'weekly';
let lastReport = null;

/** Chart.js instances by canvas id, so any view can own as many as it needs. */
const charts = {};

const CHART_COLORS = [
  '#14b8a6',
  '#6366f1',
  '#f59e0b',
  '#ef4444',
  '#22c55e',
  '#ec4899',
  '#8b5cf6',
  '#0ea5e9',
  '#f97316',
  '#64748b',
];

/** The cash-ledger views; the other two ask about sales instead. */
const LEDGER_MODES = ['weekly', 'monthly', 'yearly'];

const MODE_LABELS = {
  weekly: 'Weekly',
  monthly: 'Monthly',
  yearly: 'Yearly',
  profit: 'Profit',
  cash: 'Cash position',
};

const PAYMENT_LABELS = {
  cash: 'Cash',
  card: 'Card',
  easypaisa: 'EasyPaisa',
  jazzcash: 'JazzCash',
  bank_transfer: 'Bank transfer',
  credit: 'Udhaar (credit)',
  other: 'Other',
};

function initTheme() {
  initSharedTheme(() => {
    loadReport().catch(() => {});
  });
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('reports.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('reports-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
}

function clearAlert() {
  const el = document.getElementById('reports-alert');
  if (!el) return;
  el.hidden = true;
  el.textContent = '';
}

/** Category and product names are shop data, so they never go into innerHTML raw. */
function escapeHtml(value) {
  return String(value ?? '').replace(
    /[&<>"']/g,
    (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]
  );
}

function formatRs(amount) {
  const n = Number(amount) || 0;
  return `Rs ${Math.round(n).toLocaleString('en-PK')}`;
}

/**
 * A statement subtracts, so the minus belongs in front of the rupees rather
 * than being left for the reader to work out from a column heading.
 */
function formatSigned(amount) {
  const n = Number(amount) || 0;
  return n < 0 ? `− ${formatRs(-n)}` : formatRs(n);
}

function formatPct(value) {
  return `${(Number(value) || 0).toFixed(1)}%`;
}

function formatDisplayDate(iso) {
  const d = new Date(`${iso}T12:00:00`);
  return d.toLocaleDateString('en-PK', { month: 'short', day: 'numeric', year: 'numeric' });
}

function todayISO() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${m}-${day}`;
}

function monthStartISO() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  return `${d.getFullYear()}-${m}-01`;
}

function currentMonthValue() {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  return `${d.getFullYear()}-${m}`;
}

function currentYear() {
  return String(new Date().getFullYear());
}

function chartColors(count) {
  return Array.from({ length: count }, (_, i) => CHART_COLORS[i % CHART_COLORS.length]);
}

function getChartTextColor() {
  return getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim() || '#8b9cb3';
}

function destroyCharts() {
  Object.keys(charts).forEach((id) => {
    charts[id].destroy();
    delete charts[id];
  });
}

function renderDoughnut(canvasId, labels, values) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  charts[canvasId] = new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [
        {
          data: values,
          backgroundColor: chartColors(labels.length),
          borderWidth: 0,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          position: 'bottom',
          labels: { color: getChartTextColor(), boxWidth: 12, padding: 12 },
        },
      },
    },
  });
}

/**
 * One row shape for every list on the page: a label, an optional muted note
 * under it, and the money on the right.
 *
 * @param {string} listId
 * @param {Array<{label: string, note?: string, value: string}>} rows
 */
function renderRows(listId, rows) {
  const list = document.getElementById(listId);
  if (!list) return;

  list.innerHTML = rows
    .map(
      (row) => `
      <li class="reports-category-row">
        <span>${escapeHtml(row.label)}${
          row.note ? `<span class="dashboard-stat-label">${escapeHtml(row.note)}</span>` : ''
        }</span>
        <span>${escapeHtml(row.value)}</span>
      </li>`
    )
    .join('');
}

/**
 * A doughnut and its list, from `{category, amount}` rows. `rowMapper` lets a
 * caller print more than the bare amount without a second list renderer.
 */
function renderCategoryBlock(rows, canvasId, emptyId, listId, rowMapper = categoryRow) {
  const empty = document.getElementById(emptyId);
  const canvas = document.getElementById(canvasId);

  if (!rows.length) {
    empty.hidden = false;
    canvas.hidden = true;
    document.getElementById(listId).innerHTML = '';
    return;
  }

  empty.hidden = true;
  canvas.hidden = false;

  renderRows(listId, rows.map(rowMapper));
  renderDoughnut(
    canvasId,
    rows.map((row) => row.category),
    rows.map((row) => row.amount)
  );
}

function categoryRow(row) {
  return { label: row.category, value: formatRs(row.amount) };
}

/* ------------------------------------------------------------ cash ledger */

function renderLedger(data) {
  document.getElementById('report-income').textContent = formatRs(data.total_income);
  document.getElementById('report-expense').textContent = formatRs(data.total_expense);
  document.getElementById('report-net').textContent = formatRs(data.net);

  renderCategoryBlock(data.income_by_category || [], 'income-chart', 'income-empty', 'income-list');
  renderCategoryBlock(
    data.expense_by_category || data.by_category || [],
    'expense-chart',
    'expense-empty',
    'expense-list'
  );
}

/* ---------------------------------------------------------------- profit */

/**
 * The trading account as the owner says it out loud, and the only definition
 * of it — the PDF renders these same rows rather than rebuilding the sums.
 */
function pnlRows(data) {
  return [
    { label: 'Sales (net of refunds)', value: formatSigned(data.sales_net) },
    { label: 'Cost of goods sold', value: formatSigned(-data.cogs) },
    {
      label: 'Gross profit',
      note: `${formatPct(data.gross_margin_pct)} margin`,
      value: formatSigned(data.gross_profit),
    },
    {
      label: 'Wastage',
      note: data.wastage_uncosted
        ? `${data.wastage_uncosted} write-off${data.wastage_uncosted === 1 ? '' : 's'} had no cost price`
        : undefined,
      value: formatSigned(-data.wastage),
    },
    { label: 'Expenses', value: formatSigned(-data.expenses) },
    { label: 'Net profit', value: formatSigned(data.net_profit) },
  ];
}

/**
 * The gap between "sales" and "gross profit" whenever a line was sold without
 * a cost price. Spelled out rather than folded in, because folding it in would
 * report the whole sale price as profit.
 */
function unknownCostNote(data) {
  if (!data.unknown_cost_lines) return '';
  return `⚠ ${formatRs(data.unknown_cost_revenue)} of sales had no cost recorded — not counted in profit. Add cost prices on the Products page.`;
}

function renderProfit(data) {
  document.getElementById('profit-sales').textContent = formatRs(data.sales_net);
  document.getElementById('profit-gross').textContent = formatRs(data.gross_profit);
  document.getElementById('profit-net').textContent = formatRs(data.net_profit);

  renderRows('pnl-list', pnlRows(data));

  const note = document.getElementById('profit-unknown');
  const message = unknownCostNote(data);
  note.hidden = !message;
  note.textContent = message;

  renderCategoryBlock(
    data.by_category || [],
    'profit-category-chart',
    'profit-category-empty',
    'profit-category-list',
    marginRow
  );

  const products = data.by_product || [];
  document.getElementById('profit-product-empty').hidden = products.length > 0;
  renderRows('profit-product-list', products.map(marginRow));
}

/** "Rs 12,000 sales · 18.2% margin" — the line every breakdown row carries. */
function marginRow(row) {
  const margin = row.has_unknown_cost ? 'cost not recorded' : `${formatPct(row.margin_pct)} margin`;
  return {
    label: row.name || row.category,
    note: `${formatRs(row.revenue)} sales · ${margin}`,
    value: formatRs(row.profit),
  };
}

/* --------------------------------------------------------- cash position */

function cashRows(data) {
  const drawer = data.drawer || {};
  return [
    {
      label: 'Cash in drawer',
      note: `${drawer.date || ''} · float ${formatRs(drawer.opening)} + takings ${formatRs(
        drawer.cash_in
      )} − refunds ${formatRs(drawer.cash_refunds)}`,
      value: formatRs(data.cash_in_drawer),
    },
    {
      label: 'Udhaar owed to you',
      note: `${data.receivable_count} unsettled sale${data.receivable_count === 1 ? '' : 's'}`,
      value: formatRs(data.receivable),
    },
    { label: 'Stock at cost', value: formatRs(data.stock_at_cost) },
    { label: 'Takings this period', value: formatRs(data.takings_total) },
  ];
}

function paymentRows(data) {
  return Object.entries(data.by_payment_method || {})
    .filter(([, amount]) => Number(amount) !== 0)
    .sort((a, b) => b[1] - a[1])
    .map(([method, amount]) => ({
      category: PAYMENT_LABELS[method] || method,
      amount,
    }));
}

function renderCash(data) {
  document.getElementById('cash-drawer').textContent = formatRs(data.cash_in_drawer);
  document.getElementById('cash-receivable').textContent = formatRs(data.receivable);
  document.getElementById('cash-stock').textContent = formatRs(data.stock_at_cost);

  renderRows('cash-position-list', cashRows(data));

  const note = document.getElementById('cash-unknown');
  const missing = data.stock_uncosted_products;
  note.hidden = !missing;
  note.textContent = missing
    ? `⚠ ${missing} product${missing === 1 ? '' : 's'} in stock have no cost price, so the stock value above is incomplete.`
    : '';

  renderCategoryBlock(paymentRows(data), 'cash-method-chart', 'cash-method-empty', 'cash-method-list');
}

/* ------------------------------------------------------------- loading */

function rangeQuery() {
  const from = document.getElementById('range-from').value || monthStartISO();
  const to = document.getElementById('range-to').value || todayISO();
  return `from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
}

function reportPath() {
  if (mode === 'weekly') {
    const start = document.getElementById('week-start').value || todayISO();
    return `/api/reports/weekly?start=${encodeURIComponent(start)}`;
  }
  if (mode === 'monthly') {
    const monthVal = document.getElementById('report-month').value || currentMonthValue();
    const [year, month] = monthVal.split('-');
    return `/api/reports/monthly?year=${year}&month=${Number(month)}`;
  }
  if (mode === 'yearly') {
    const year = document.getElementById('report-year').value || currentYear();
    return `/api/reports/yearly?year=${year}`;
  }
  if (mode === 'profit') return `/api/reports/profit?${rangeQuery()}`;
  return `/api/reports/cash-position?${rangeQuery()}`;
}

async function loadReport() {
  clearAlert();

  const data = await apiGet(reportPath());
  lastReport = data;

  document.getElementById('report-period').textContent =
    `${formatDisplayDate(data.period.start)} – ${formatDisplayDate(data.period.end)}`;

  // Rebuilt from scratch on every load: Chart.js keeps a live handle on the
  // canvas, and a second chart on the same one renders on top of the first.
  destroyCharts();

  if (mode === 'profit') renderProfit(data);
  else if (mode === 'cash') renderCash(data);
  else renderLedger(data);
}

function reload() {
  loadReport().catch((err) => showAlert(err.message || 'Failed to load report'));
}

function setMode(next) {
  mode = next;
  document.querySelectorAll('.reports-mode-btn').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.mode === mode);
  });

  const isRange = mode === 'profit' || mode === 'cash';
  document.getElementById('picker-weekly').hidden = mode !== 'weekly';
  document.getElementById('picker-monthly').hidden = mode !== 'monthly';
  document.getElementById('picker-yearly').hidden = mode !== 'yearly';
  document.getElementById('picker-range').hidden = !isRange;
  document.getElementById('picker-range-to').hidden = !isRange;

  document.getElementById('summary-ledger').hidden = !LEDGER_MODES.includes(mode);
  document.getElementById('summary-profit').hidden = mode !== 'profit';
  document.getElementById('summary-cash').hidden = mode !== 'cash';
  document.getElementById('view-ledger').hidden = !LEDGER_MODES.includes(mode);
  document.getElementById('view-profit').hidden = mode !== 'profit';
  document.getElementById('view-cash').hidden = mode !== 'cash';

  reload();
}

/* ----------------------------------------------------------------- PDF */

/**
 * What the current view puts in the PDF. Built from the rendered rows, so the
 * export cannot say something the screen does not.
 *
 * @returns {{stats: Array<[string, string]>, charts: Array<[string, string]>,
 *   tables: Array<{title: string, rows: Array<{label: string, value: string}>}>, note: string}}
 */
function pdfPlan() {
  if (mode === 'profit') {
    return {
      stats: [
        ['Sales (net of refunds)', formatRs(lastReport.sales_net)],
        ['Gross profit', formatRs(lastReport.gross_profit)],
        ['Net profit', formatRs(lastReport.net_profit)],
      ],
      charts: [['Sales by category', 'profit-category-chart']],
      tables: [
        { title: 'Profit & loss', rows: pnlRows(lastReport) },
        { title: 'By category', rows: (lastReport.by_category || []).map(marginRow) },
        { title: 'Top sellers', rows: (lastReport.by_product || []).map(marginRow) },
      ],
      note: unknownCostNote(lastReport),
    };
  }

  if (mode === 'cash') {
    return {
      stats: [
        ['Cash in drawer', formatRs(lastReport.cash_in_drawer)],
        ['Udhaar owed to you', formatRs(lastReport.receivable)],
        ['Stock at cost', formatRs(lastReport.stock_at_cost)],
      ],
      charts: [['Takings by payment method', 'cash-method-chart']],
      tables: [
        { title: 'Where the money is', rows: cashRows(lastReport) },
        {
          title: 'Takings by payment method',
          rows: paymentRows(lastReport).map((row) => ({
            label: row.category,
            value: formatRs(row.amount),
          })),
        },
      ],
      note: '',
    };
  }

  const toRows = (rows) => rows.map((row) => ({ label: row.category, value: formatRs(row.amount) }));

  return {
    stats: [
      ['Total income', formatRs(lastReport.total_income)],
      ['Total expenses', formatRs(lastReport.total_expense)],
      ['Net balance', formatRs(lastReport.net)],
    ],
    charts: [
      ['Income by source', 'income-chart'],
      ['Expenses by category', 'expense-chart'],
    ],
    tables: [
      { title: 'Income by source', rows: toRows(lastReport.income_by_category || []) },
      {
        title: 'Expenses by category',
        rows: toRows(lastReport.expense_by_category || lastReport.by_category || []),
      },
    ],
    note: '',
  };
}

/** Build and save a PDF of the current report (totals, charts, tables). */
function downloadPdf() {
  if (!window.jspdf?.jsPDF) {
    showAlert('PDF library failed to load. Check your connection and reload.');
    return;
  }
  if (!lastReport) {
    showAlert('Load a report before downloading.');
    return;
  }

  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({ unit: 'pt', format: 'a4' });
  const pageWidth = doc.internal.pageSize.getWidth();
  const margin = 48;
  let y = 56;

  const plan = pdfPlan();
  const period = lastReport.period;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(20);
  doc.setTextColor(16, 24, 38);
  doc.text(`Cashflow — ${MODE_LABELS[mode]} report`, margin, y);
  y += 22;

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(11);
  doc.setTextColor(91, 107, 128);
  doc.text(`${formatDisplayDate(period.start)} – ${formatDisplayDate(period.end)}`, margin, y);
  y += 30;

  const statWidth = (pageWidth - margin * 2 - 24) / 3;
  plan.stats.forEach(([label, value], i) => {
    const x = margin + i * (statWidth + 12);
    doc.setDrawColor(221, 227, 236);
    doc.setFillColor(245, 247, 250);
    doc.roundedRect(x, y, statWidth, 54, 8, 8, 'FD');
    doc.setFontSize(9);
    doc.setTextColor(91, 107, 128);
    doc.text(label, x + 12, y + 20);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(13);
    doc.setTextColor(16, 24, 38);
    doc.text(value, x + 12, y + 40);
    doc.setFont('helvetica', 'normal');
  });
  y += 84;

  if (plan.note) {
    doc.setFontSize(10);
    doc.setTextColor(180, 83, 9);
    doc.splitTextToSize(plan.note, pageWidth - margin * 2).forEach((line) => {
      doc.text(line, margin, y);
      y += 14;
    });
    y += 12;
  }

  // Charts, captured from the live canvases.
  const chartWidth = (pageWidth - margin * 2 - 24) / 2;
  const chartHeight = chartWidth * 0.75;
  let drewChart = false;
  plan.charts.forEach(([title, canvasId], i) => {
    const canvas = document.getElementById(canvasId);
    if (!canvas || canvas.hidden) return;
    const x = margin + i * (chartWidth + 24);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.setTextColor(16, 24, 38);
    doc.text(title, x, y);
    try {
      doc.addImage(canvas.toDataURL('image/png'), 'PNG', x, y + 10, chartWidth, chartHeight);
      drewChart = true;
    } catch {
      // canvas capture can fail in odd browser states; skip the image
    }
  });
  if (drewChart) y += chartHeight + 46;

  const writeRows = (title, rows) => {
    if (!rows.length) return;
    if (y > doc.internal.pageSize.getHeight() - 120) {
      doc.addPage();
      y = 56;
    }
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(12);
    doc.setTextColor(16, 24, 38);
    doc.text(title, margin, y);
    y += 16;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    rows.forEach((row) => {
      if (y > doc.internal.pageSize.getHeight() - 60) {
        doc.addPage();
        y = 56;
      }
      doc.setTextColor(91, 107, 128);
      doc.text(String(row.label), margin, y);
      doc.setTextColor(16, 24, 38);
      doc.text(String(row.value), pageWidth - margin, y, { align: 'right' });
      y += 16;
    });
    y += 14;
  };

  plan.tables.forEach((table) => writeRows(table.title, table.rows));

  doc.setFontSize(8);
  doc.setTextColor(139, 156, 179);
  doc.text(
    `Generated by Cashflow on ${new Date().toLocaleDateString('en-PK')}`,
    margin,
    doc.internal.pageSize.getHeight() - 32
  );

  doc.save(`cashflow-${mode}-report-${period.start}.pdf`);
}

function wireControls() {
  document.querySelectorAll('.reports-mode-btn').forEach((btn) => {
    btn.addEventListener('click', () => setMode(btn.dataset.mode));
  });

  document.getElementById('download-pdf')?.addEventListener('click', downloadPdf);

  ['week-start', 'report-month', 'report-year', 'range-from', 'range-to'].forEach((id) => {
    document.getElementById(id)?.addEventListener('change', reload);
  });
}

async function boot() {
  initTheme();
  initShell({ current: 'reports' });
  if (!requireAuth()) return;

  document.getElementById('week-start').value = todayISO();
  document.getElementById('report-month').value = currentMonthValue();
  document.getElementById('report-year').value = currentYear();
  // Month to date: the window an owner checking "how are we doing" means.
  document.getElementById('range-from').value = monthStartISO();
  document.getElementById('range-to').value = todayISO();

  wireControls();

  try {
    await loadReport();
  } catch (err) {
    showAlert(err.message || 'Failed to load report');
  }
}

boot();
