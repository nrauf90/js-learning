/**
 * Shareable permalink + print/PDF export helpers.
 *
 * Permalinks encode the current calculator state (income, income type,
 * regime, fiscal year) into the URL's query string so a result can be
 * bookmarked or sent to someone else and reopen in the same state.
 *
 * PDF export is handled via the browser's native print dialog ("Save as
 * PDF" is a built-in destination in every modern browser/OS), driven by
 * a dedicated print stylesheet that hides interactive chrome and keeps
 * only the calculation summary, comparison table, and slab reference.
 */

const PARAM_KEYS = {
    income: 'income',
    type: 'type',
    regime: 'regime',
    year: 'year',
  };
  
  /**
   * @param {{ income: string|number, incomeType: string, regime: string, year: string }} state
   * @returns {URLSearchParams}
   */
  function encodeStateToParams(state) {
    const params = new URLSearchParams();
    if (state.income !== '' && state.income != null) {
      params.set(PARAM_KEYS.income, String(state.income));
    }
    if (state.incomeType) params.set(PARAM_KEYS.type, state.incomeType);
    if (state.regime) params.set(PARAM_KEYS.regime, state.regime);
    if (state.year) params.set(PARAM_KEYS.year, state.year);
    return params;
  }
  
  /**
   * Reads calculator state out of the current URL's query string.
   * Returns null if there's nothing relevant to restore.
   */
  export function readStateFromURL() {
    const params = new URLSearchParams(window.location.search);
    const hasAny = [PARAM_KEYS.income, PARAM_KEYS.type, PARAM_KEYS.regime, PARAM_KEYS.year].some(
      (key) => params.has(key)
    );
    if (!hasAny) return null;
  
    return {
      income: params.get(PARAM_KEYS.income),
      incomeType: params.get(PARAM_KEYS.type),
      regime: params.get(PARAM_KEYS.regime),
      year: params.get(PARAM_KEYS.year),
    };
  }
  
  /**
   * Pushes the current state into the URL without reloading the page or
   * polluting browser history (uses replaceState, not pushState).
   */
  export function updateURL(state) {
    const params = encodeStateToParams(state);
    const query = params.toString();
    const newUrl = query
      ? `${window.location.pathname}?${query}`
      : window.location.pathname;
    window.history.replaceState(null, '', newUrl);
  }
  
  /**
   * Copies the current page URL to the clipboard.
   * Falls back to a hidden-input + execCommand for browsers/contexts
   * where the async Clipboard API isn't available.
   * @param {(success: boolean) => void} callback
   */
  export async function copyShareLink(callback) {
    const url = window.location.href;
  
    if (navigator.clipboard && navigator.clipboard.writeText) {
      try {
        await navigator.clipboard.writeText(url);
        callback(true);
        return;
      } catch {
        // fall through to legacy fallback below
      }
    }
  
    try {
      const temp = document.createElement('textarea');
      temp.value = url;
      temp.style.position = 'fixed';
      temp.style.opacity = '0';
      document.body.appendChild(temp);
      temp.select();
      document.execCommand('copy');
      document.body.removeChild(temp);
      callback(true);
    } catch {
      callback(false);
    }
  }
  
  /**
   * Wires a button to trigger the browser print dialog. Optionally runs
   * a `beforePrint` callback first (e.g. to refresh a print-only summary
   * with the latest figures).
   * @param {HTMLElement} buttonEl
   * @param {() => void} [beforePrint]
   */
  export function initPrintExport(buttonEl, beforePrint) {
    buttonEl.addEventListener('click', () => {
      if (typeof beforePrint === 'function') beforePrint();
      window.print();
    });
  }