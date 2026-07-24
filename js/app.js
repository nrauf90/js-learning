import { TAX_YEARS, getYearsByRegime } from './tax-slabs.js';
import { calculateTax, compareYears, formatPKR, formatPercent } from './tax-calculator.js';

const incomeInput = document.getElementById('income');
const incomeTypeRadios = document.querySelectorAll('input[name="income-type"]');
const regimeTabs = document.querySelectorAll('.regime-tab');
const yearSelect = document.getElementById('year-select');
const resultsPanel = document.getElementById('results');
const comparisonPanel = document.getElementById('comparison');
const slabTable = document.getElementById('slab-table');

let activeRegime = 'new';

function getAnnualIncome() {
  const raw = parseFloat(incomeInput.value) || 0;
  const isMonthly = document.querySelector('input[name="income-type"]:checked').value === 'monthly';
  return isMonthly ? raw * 12 : raw;
}

function populateYearSelect() {
  const years = getYearsByRegime(activeRegime);
  yearSelect.innerHTML = years
    .map((y) => `<option value="${y.id}">${y.label} (${y.period})</option>`)
    .join('');
}

function renderSlabTable(taxYear) {
  slabTable.innerHTML = `
    <thead>
      <tr>
        <th>Income Range (PKR)</th>
        <th>Rate</th>
        <th>Fixed + % on Excess</th>
      </tr>
    </thead>
    <tbody>
      ${taxYear.slabs
        .map((s) => {
          const range =
            s.max === null
              ? `Above ${formatPKR(s.min - 1)}`
              : `${formatPKR(s.min)} – ${formatPKR(s.max)}`;
          const formula =
            s.rate === 0
              ? 'Exempt'
              : s.fixed === 0
                ? `${(s.rate * 100).toFixed(1)}% of excess over ${formatPKR(s.threshold)}`
                : `${formatPKR(s.fixed)} + ${(s.rate * 100).toFixed(1)}% of excess over ${formatPKR(s.threshold)}`;
          return `<tr><td>${range}</td><td>${s.label}</td><td>${formula}</td></tr>`;
        })
        .join('')}
    </tbody>
    ${
      taxYear.surcharge
        ? `<tfoot><tr><td colspan="3" class="surcharge-note">Section 4AB surcharge: ${(taxYear.surcharge.rate * 100).toFixed(0)}% on tax payable when income exceeds ${formatPKR(taxYear.surcharge.threshold)}</td></tr></tfoot>`
        : ''
    }
  `;
}

function renderResults(result) {
  const savingsClass =
    result.regime === 'new' ? 'badge-new' : 'badge-old';

  resultsPanel.innerHTML = `
    <div class="result-header">
      <span class="badge ${savingsClass}">${result.regime === 'new' ? 'New Tax' : 'Old Tax'}</span>
      <h2>${result.label}</h2>
    </div>
    <div class="result-grid">
      <div class="result-card highlight">
        <span class="result-label">Annual Tax</span>
        <span class="result-value">${formatPKR(result.totalTax)}</span>
      </div>
      <div class="result-card">
        <span class="result-label">Monthly Tax</span>
        <span class="result-value">${formatPKR(result.monthlyTax)}</span>
      </div>
      <div class="result-card">
        <span class="result-label">Take-Home (Annual)</span>
        <span class="result-value positive">${formatPKR(result.takeHome)}</span>
      </div>
      <div class="result-card">
        <span class="result-label">Take-Home (Monthly)</span>
        <span class="result-value positive">${formatPKR(result.monthlyTakeHome)}</span>
      </div>
      <div class="result-card">
        <span class="result-label">Effective Tax Rate</span>
        <span class="result-value">${formatPercent(result.effectiveRate)}</span>
      </div>
      <div class="result-card">
        <span class="result-label">Applicable Slab</span>
        <span class="result-value slab-rate">${result.slab.label}</span>
      </div>
    </div>
  `;

  if (result.surcharge > 0) {
    resultsPanel.innerHTML += `
      <div class="surcharge-breakdown">
        <span>Base tax: ${formatPKR(result.baseTax)}</span>
        <span>Section 4AB surcharge: ${formatPKR(result.surcharge)}</span>
      </div>
    `;
  }
}

function renderComparison(income) {
  const newResults = compareYears(income, getYearsByRegime('new'));
  const oldResults = compareYears(income, getYearsByRegime('old'));

  const allResults = [...newResults, ...oldResults];
  const minTax = Math.min(...allResults.map((r) => r.totalTax));
  const maxTax = Math.max(...allResults.map((r) => r.totalTax));

  comparisonPanel.innerHTML = `
    <h3>Year-by-Year Comparison</h3>
    <p class="comparison-subtitle">Same income across all fiscal years — green = lowest tax, red = highest</p>
    <div class="comparison-table-wrap">
      <table class="comparison-table">
        <thead>
          <tr>
            <th>Fiscal Year</th>
            <th>Regime</th>
            <th>Annual Tax</th>
            <th>Monthly Tax</th>
            <th>Take-Home</th>
            <th>Effective Rate</th>
          </tr>
        </thead>
        <tbody>
          ${allResults
            .map((r) => {
              let rowClass = '';
              if (r.totalTax === minTax && income > 0) rowClass = 'lowest-tax';
              if (r.totalTax === maxTax && income > 0 && minTax !== maxTax) rowClass = 'highest-tax';
              return `
                <tr class="${rowClass}">
                  <td><strong>${r.label}</strong></td>
                  <td><span class="badge badge-${r.regime}">${r.regime === 'new' ? 'New' : 'Old'}</span></td>
                  <td>${formatPKR(r.totalTax)}</td>
                  <td>${formatPKR(r.monthlyTax)}</td>
                  <td>${formatPKR(r.takeHome)}</td>
                  <td>${formatPercent(r.effectiveRate)}</td>
                </tr>`;
            })
            .join('')}
        </tbody>
      </table>
    </div>
  `;

  if (income > 0 && newResults.length && oldResults.length) {
    const latestNew = newResults[0];
    const latestOld = oldResults[0];
    const diff = latestOld.totalTax - latestNew.totalTax;
    const diffPanel = document.createElement('div');
    diffPanel.className = 'diff-panel';
    if (diff > 0) {
      diffPanel.innerHTML = `
        <strong>New tax saves ${formatPKR(diff)}/year</strong> compared to ${latestOld.label}
        (${formatPKR(diff / 12)}/month less tax)
      `;
    } else if (diff < 0) {
      diffPanel.innerHTML = `
        <strong>New tax costs ${formatPKR(Math.abs(diff))}/year more</strong> compared to ${latestOld.label}
      `;
    } else {
      diffPanel.innerHTML = `<strong>Same tax</strong> under new and old regime for this income.`;
    }
    comparisonPanel.appendChild(diffPanel);
  }
}

function update() {
  const income = getAnnualIncome();
  const yearId = yearSelect.value;
  const taxYear = TAX_YEARS.find((y) => y.id === yearId);
  if (!taxYear) return;

  const result = calculateTax(income, taxYear);
  renderResults(result);
  renderSlabTable(taxYear);
  renderComparison(income);
}

regimeTabs.forEach((tab) => {
  tab.addEventListener('click', () => {
    regimeTabs.forEach((t) => t.classList.remove('active'));
    tab.classList.add('active');
    activeRegime = tab.dataset.regime;
    populateYearSelect();
    update();
  });
});

incomeInput.addEventListener('input', update);
incomeTypeRadios.forEach((r) => r.addEventListener('change', update));
yearSelect.addEventListener('change', update);

populateYearSelect();
incomeInput.value = '1200000';
update();
