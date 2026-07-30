import { calculateTax } from './tax-calculator.js';
import { getYearById } from './tax-slabs.js';
import { formatPKR } from './number-format.js';
import { initNav } from './nav.js';
import { initMotion } from './motion.js';

const THEME_KEY = 'tax-calculator-theme';
const DEFAULT_TAX_YEAR = getYearById('fy2025-26');

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
  });
}

function annualIncome() {
  const raw = Number(document.getElementById('hero-income')?.value) || 0;
  const period = document.querySelector('input[name="hero-income-type"]:checked')?.value || 'annual';
  return period === 'monthly' ? raw * 12 : raw;
}

function updateHeroTax() {
  const el = document.getElementById('hero-tax-total');
  if (!el || !DEFAULT_TAX_YEAR) return;
  const result = calculateTax(annualIncome(), DEFAULT_TAX_YEAR);
  el.textContent = formatPKR(result.totalTax);
}

function wireHeroWidget() {
  const incomeInput = document.getElementById('hero-income');
  if (!incomeInput) return;

  incomeInput.addEventListener('input', updateHeroTax);
  document.querySelectorAll('input[name="hero-income-type"]').forEach((radio) => {
    radio.addEventListener('change', updateHeroTax);
  });
  updateHeroTax();
}

function wireContactForm() {
  const form = document.getElementById('contact-form');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const name = document.getElementById('contact-name')?.value.trim() || 'Cashflow user';
    const message = document.getElementById('contact-message')?.value.trim() || '';
    const subject = encodeURIComponent(`Cashflow contact — ${name}`);
    const body = encodeURIComponent(message);
    window.location.href = `mailto:support@cashflow.app?subject=${subject}&body=${body}`;
  });
}

initTheme();
initNav({ current: 'home' });
wireHeroWidget();
wireContactForm();
initMotion();
