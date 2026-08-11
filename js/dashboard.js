import { apiGet, getAuthToken } from './api.js';
import { initShell } from './shell.js';
import { initTheme as initSharedTheme } from './theme.js';

let trendChart = null;
let netChart = null;
let lastWeekData = null;

function initTheme() {
  initSharedTheme(() => {
    if (lastWeekData) renderCharts(lastWeekData.days, lastWeekData.byDay);
  });
}

function requireAuth() {
  if (getAuthToken()) return true;
  window.location.replace(`login.html?next=${encodeURIComponent('dashboard.html')}`);
  return false;
}

function showAlert(message, type = 'error') {
  const el = document.getElementById('dashboard-alert');
  if (!el) return;
  el.hidden = false;
  el.textContent = message;
  el.dataset.type = type;
}

function formatRs(amount) {
  const n = Number(amount) || 0;
  return `Rs ${Math.round(n).toLocaleString('en-PK')}`;
}

function formatDisplayDate(iso) {
  const d = new Date(`${iso}T12:00:00`);
  return d.toLocaleDateString('en-PK', { weekday: 'short', month: 'short', day: 'numeric' });
}

function shortDay(iso) {
  const d = new Date(`${iso}T12:00:00`);
  return d.toLocaleDateString('en-PK', { weekday: 'short' });
}

function toISO(d) {
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${d.getFullYear()}-${m}-${day}`;
}

function todayISO() {
  const now = new Date();
  now.setHours(12, 0, 0, 0);
  return toISO(now);
}

/**
 * The shop's current week, Monday to Sunday.
 *
 * Deliberately the same week `ReportController::weekly` uses, rather than "the
 * last seven days": the dashboard and the weekly report get read side by side,
 * and two different definitions of "this week" showing two different totals is
 * how an owner learns to distrust both.
 *
 * Noon rather than midnight, like todayISO(), so a daylight-saving shift cannot
 * roll the date back a day.
 *
 * @returns {{ start: string, end: string, startDate: Date }}
 */
function weekRangeISO() {
  const start = new Date();
  start.setHours(12, 0, 0, 0);
  // getDay() calls Sunday 0, so Sunday belongs to the week that began six days
  // earlier rather than starting a new one.
  start.setDate(start.getDate() - ((start.getDay() + 6) % 7));

  const end = new Date(start);
  end.setDate(end.getDate() + 6);

  return { start: toISO(start), end: toISO(end), startDate: start };
}

/** `YYYY-MM-DD` sorts lexicographically, so the bounds need no date parsing. */
function inWeek(iso, start, end) {
  return typeof iso === 'string' && iso >= start && iso <= end;
}

function cssVar(name, fallback) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

function renderCharts(days, byDay) {
  if (typeof Chart === 'undefined') return;

  const textMuted = cssVar('--text-muted', '#8b9cb3');
  const gridColor = 'rgba(139, 156, 179, 0.14)';
  const incomeColor = cssVar('--new', '#22c55e');
  const expenseColor = cssVar('--danger', '#ef4444');
  const accent = cssVar('--accent', '#0a55d8');

  const labels = days.map(shortDay);
  const incomeData = days.map((iso) => (byDay.get(iso) || { income: 0 }).income);
  const expenseData = days.map((iso) => (byDay.get(iso) || { expense: 0 }).expense);
  const netData = days.map((iso) => {
    const d = byDay.get(iso) || { income: 0, expense: 0 };
    return d.income - d.expense;
  });

  const scales = {
    x: {
      grid: { display: false },
      ticks: { color: textMuted, font: { family: "'DM Sans', sans-serif" } },
    },
    y: {
      grid: { color: gridColor },
      border: { display: false },
      ticks: {
        color: textMuted,
        font: { family: "'DM Sans', sans-serif" },
        callback: (v) => `Rs ${Number(v).toLocaleString('en-PK')}`,
      },
    },
  };

  const tooltip = {
    backgroundColor: cssVar('--surface-2', '#243044'),
    titleColor: cssVar('--text', '#e8edf4'),
    bodyColor: textMuted,
    borderColor: gridColor,
    borderWidth: 1,
    padding: 10,
    cornerRadius: 10,
    callbacks: {
      label: (ctx) => `${ctx.dataset.label || 'Net'}: ${formatRs(ctx.parsed.y)}`,
    },
  };

  if (trendChart) trendChart.destroy();
  trendChart = new Chart(document.getElementById('trend-chart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Income',
          data: incomeData,
          borderColor: incomeColor,
          backgroundColor: `${incomeColor}26`,
          fill: true,
          tension: 0.4,
          borderWidth: 2.5,
          pointRadius: 3,
          pointHoverRadius: 6,
          pointBackgroundColor: incomeColor,
        },
        {
          label: 'Expenses',
          data: expenseData,
          borderColor: expenseColor,
          backgroundColor: `${expenseColor}1f`,
          fill: true,
          tension: 0.4,
          borderWidth: 2.5,
          pointRadius: 3,
          pointHoverRadius: 6,
          pointBackgroundColor: expenseColor,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          position: 'top',
          align: 'end',
          labels: { color: textMuted, usePointStyle: true, boxWidth: 8, padding: 16 },
        },
        tooltip,
      },
      scales,
    },
  });

  if (netChart) netChart.destroy();
  netChart = new Chart(document.getElementById('net-chart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Net',
          data: netData,
          backgroundColor: netData.map((v) => (v >= 0 ? `${accent}cc` : `${expenseColor}cc`)),
          borderRadius: 8,
          maxBarThickness: 34,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip },
      scales,
    },
  });
}

function renderWeek(entries, range) {
  let income = 0;
  let expense = 0;
  let count = 0;
  const byDay = new Map();

  for (const e of entries) {
    if (!inWeek(e.entry_date, range.start, range.end)) continue;
    const amount = Number(e.amount) || 0;
    if (e.type === 'income') income += amount;
    else expense += amount;
    count += 1;

    const day = byDay.get(e.entry_date) || { income: 0, expense: 0, count: 0 };
    if (e.type === 'income') day.income += amount;
    else day.expense += amount;
    day.count += 1;
    byDay.set(e.entry_date, day);
  }

  document.getElementById('week-range').textContent =
    `${formatDisplayDate(range.start)} – ${formatDisplayDate(range.end)}`;
  document.getElementById('week-income').textContent = formatRs(income);
  document.getElementById('week-expense').textContent = formatRs(expense);
  document.getElementById('week-net').textContent = formatRs(income - expense);
  const countEl = document.getElementById('week-count');
  if (countEl) countEl.textContent = String(count);

  const list = document.getElementById('week-days');
  const empty = document.getElementById('week-empty');
  const days = [];

  for (let i = 0; i < 7; i += 1) {
    const d = new Date(range.startDate);
    d.setDate(d.getDate() + i);
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    days.push(`${d.getFullYear()}-${m}-${day}`);
  }

  const hasEntries = byDay.size > 0;
  empty.hidden = hasEntries;

  list.innerHTML = days
    .map((iso) => {
      const stats = byDay.get(iso) || { income: 0, expense: 0, count: 0 };
      const net = stats.income - stats.expense;
      const muted = stats.count === 0 ? ' dashboard-day-muted' : '';
      return `
        <li class="dashboard-day${muted}">
          <span class="dashboard-day-date">${formatDisplayDate(iso)}</span>
          <span class="dashboard-day-stats">
            <span class="income">+${formatRs(stats.income)}</span>
            <span class="expense">−${formatRs(stats.expense)}</span>
            <span class="dashboard-day-net">${formatRs(net)}</span>
          </span>
        </li>`;
    })
    .join('');

  lastWeekData = { days, byDay };
  renderCharts(days, byDay);
}

async function boot() {
  initTheme();
  initShell({ current: 'dashboard' });
  if (!requireAuth()) return;

  try {
    const billing = await apiGet('/api/billing/subscription');
    const hasAccess = billing.subscription?.active || billing.trial?.active;

    if (!hasAccess) {
      // No buy button: shops are activated by the platform admin, so the only
      // useful thing this screen can say is who to ask and what to quote.
      const account = billing.account || {};
      const quote = [account.shop?.name, account.email].filter(Boolean).join(' · ');

      document.getElementById('week-range').textContent = billing.trial?.expired
        ? 'Free trial ended'
        : 'Subscription ended';
      const empty = document.getElementById('week-empty');
      empty.hidden = false;
      empty.textContent =
        `${billing.trial?.expired ? 'Your 7-day free trial has ended.' : 'Your subscription has ended.'} ` +
        'Please contact the administrator to have it renewed.' +
        (quote ? ` Quote: ${quote}` : '');
      return;
    }

    if (billing.trial?.active) {
      const days = billing.trial.days_remaining ?? 0;
      showAlert(
        `Free trial: ${days} day${days === 1 ? '' : 's'} remaining. ` +
          'Contact the administrator before it ends to keep access.',
        'success'
      );
    }

    const data = await apiGet('/api/cash-entries');
    const range = weekRangeISO();
    renderWeek(data.entries || [], range);
  } catch (err) {
    showAlert(err.message || 'Failed to load dashboard');
  }
}

boot();
