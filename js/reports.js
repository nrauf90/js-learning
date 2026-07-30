import { apiGet, getAuthToken } from './api.js';
import { initShell } from './shell.js';

const THEME_KEY = 'tax-calculator-theme';

let mode = 'weekly';
let incomeChart = null;
let expenseChart = null;
let lastReport = null;

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

function applyTheme(theme) {
  const root = document.documentElement;
  const toggle = document.getElementById('theme-toggle');
  root.setAttribute('data-theme', theme);
  if (!toggle) return;
  toggle.setAttribute('aria-pressed', String(theme === 'light'));
  toggle.setAttribute(
    'aria-label',
    theme === 'light' ? 'Switch to dark theme' : 'Switch to light theme'
  );
}

function initTheme() {
  const stored = localStorage.getItem(THEME_KEY);
  if (stored === 'light' || stored === 'dark') {
    applyTheme(stored);
  } else {
    applyTheme(window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
  }
  document.getElementById('theme-toggle')?.addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    applyTheme(next);
    localStorage.setItem(THEME_KEY, next);
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

function formatRs(amount) {
  const n = Number(amount) || 0;
  return `Rs ${Math.round(n).toLocaleString('en-PK')}`;
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
  if (incomeChart) {
    incomeChart.destroy();
    incomeChart = null;
  }
  if (expenseChart) {
    expenseChart.destroy();
    expenseChart = null;
  }
}

function renderCategoryBlock(rows, canvasId, emptyId, listId, chartRef) {
  const empty = document.getElementById(emptyId);
  const canvas = document.getElementById(canvasId);
  const list = document.getElementById(listId);

  if (chartRef === 'income' && incomeChart) {
    incomeChart.destroy();
    incomeChart = null;
  }
  if (chartRef === 'expense' && expenseChart) {
    expenseChart.destroy();
    expenseChart = null;
  }

  if (!rows.length) {
    empty.hidden = false;
    canvas.hidden = true;
    list.innerHTML = '';
    return;
  }

  empty.hidden = true;
  canvas.hidden = false;

  list.innerHTML = rows
    .map(
      (row) => `
      <li class="reports-category-row">
        <span>${row.category}</span>
        <span>${formatRs(row.amount)}</span>
      </li>`
    )
    .join('');

  const chart = new Chart(canvas, {
    type: 'doughnut',
    data: {
      labels: rows.map((c) => c.category),
      datasets: [
        {
          data: rows.map((c) => c.amount),
          backgroundColor: chartColors(rows.length),
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

  if (chartRef === 'income') incomeChart = chart;
  else expenseChart = chart;
}

function renderReport(data) {
  lastReport = data;
  const period = data.period;
  document.getElementById('report-period').textContent =
    `${formatDisplayDate(period.start)} – ${formatDisplayDate(period.end)}`;

  document.getElementById('report-income').textContent = formatRs(data.total_income);
  document.getElementById('report-expense').textContent = formatRs(data.total_expense);
  document.getElementById('report-net').textContent = formatRs(data.net);

  destroyCharts();

  renderCategoryBlock(
    data.income_by_category || [],
    'income-chart',
    'income-empty',
    'income-list',
    'income'
  );
  renderCategoryBlock(
    data.expense_by_category || data.by_category || [],
    'expense-chart',
    'expense-empty',
    'expense-list',
    'expense'
  );
}

async function loadReport() {
  clearAlert();

  let path;
  if (mode === 'weekly') {
    const start = document.getElementById('week-start').value || todayISO();
    path = `/api/reports/weekly?start=${encodeURIComponent(start)}`;
  } else if (mode === 'monthly') {
    const monthVal = document.getElementById('report-month').value || currentMonthValue();
    const [year, month] = monthVal.split('-');
    path = `/api/reports/monthly?year=${year}&month=${Number(month)}`;
  } else {
    const year = document.getElementById('report-year').value || currentYear();
    path = `/api/reports/yearly?year=${year}`;
  }

  const data = await apiGet(path);
  renderReport(data);
}

function setMode(next) {
  mode = next;
  document.querySelectorAll('.reports-mode-btn').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.mode === mode);
  });
  document.getElementById('picker-weekly').hidden = mode !== 'weekly';
  document.getElementById('picker-monthly').hidden = mode !== 'monthly';
  document.getElementById('picker-yearly').hidden = mode !== 'yearly';
  loadReport().catch((err) => showAlert(err.message || 'Failed to load report'));
}

/** Build and save a PDF of the current report (totals, charts, category tables). */
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

  const modeLabel = mode.charAt(0).toUpperCase() + mode.slice(1);
  const period = lastReport.period;

  doc.setFont('helvetica', 'bold');
  doc.setFontSize(20);
  doc.setTextColor(16, 24, 38);
  doc.text(`Cashflow — ${modeLabel} report`, margin, y);
  y += 22;

  doc.setFont('helvetica', 'normal');
  doc.setFontSize(11);
  doc.setTextColor(91, 107, 128);
  doc.text(`${formatDisplayDate(period.start)} – ${formatDisplayDate(period.end)}`, margin, y);
  y += 30;

  const stats = [
    ['Total income', formatRs(lastReport.total_income)],
    ['Total expenses', formatRs(lastReport.total_expense)],
    ['Net balance', formatRs(lastReport.net)],
  ];
  const statWidth = (pageWidth - margin * 2 - 24) / 3;
  stats.forEach(([label, value], i) => {
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

  // Charts, captured from the live canvases.
  const chartWidth = (pageWidth - margin * 2 - 24) / 2;
  const chartHeight = chartWidth * 0.75;
  const charts = [
    ['Income by source', document.getElementById('income-chart')],
    ['Expenses by category', document.getElementById('expense-chart')],
  ];
  let drewChart = false;
  charts.forEach(([title, canvas], i) => {
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
      doc.text(String(row.category), margin, y);
      doc.setTextColor(16, 24, 38);
      doc.text(formatRs(row.amount), pageWidth - margin, y, { align: 'right' });
      y += 16;
    });
    y += 14;
  };

  writeRows('Income by source', lastReport.income_by_category || []);
  writeRows(
    'Expenses by category',
    lastReport.expense_by_category || lastReport.by_category || []
  );

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

  document.getElementById('week-start').addEventListener('change', () => {
    loadReport().catch((err) => showAlert(err.message || 'Failed to load report'));
  });

  document.getElementById('report-month').addEventListener('change', () => {
    loadReport().catch((err) => showAlert(err.message || 'Failed to load report'));
  });

  document.getElementById('report-year').addEventListener('change', () => {
    loadReport().catch((err) => showAlert(err.message || 'Failed to load report'));
  });
}

async function boot() {
  initTheme();
  initShell({ current: 'reports' });
  if (!requireAuth()) return;

  document.getElementById('week-start').value = todayISO();
  document.getElementById('report-month').value = currentMonthValue();
  document.getElementById('report-year').value = currentYear();

  wireControls();

  try {
    await loadReport();
  } catch (err) {
    showAlert(err.message || 'Failed to load report');
  }
}

boot();
