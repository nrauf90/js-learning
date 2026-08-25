/**
 * Where a `?next=` is allowed to send someone.
 *
 * `next` is only ever written by this app's own redirects — js/api.js on a 401,
 * and each page's requireAuth() — but it arrives through the query string, which
 * anyone can write. Handed to `location.href` unchecked it is an open redirect:
 * a link to our real login page ending `?next=https://evil.example` puts the
 * shopkeeper on somebody else's site the instant they type their password, with
 * our domain in the address bar the whole way there. That is the exact shape of
 * a credential-phishing lure, and the fact that the app never *writes* such a
 * value does not stop anyone else mailing one.
 *
 * A pure module rather than a helper inside js/auth.js so it can be unit tested:
 * auth.js wires up the DOM at import time and cannot be loaded under `node
 * --test`. See tests/safe-redirect.test.js.
 */

/** Where an unannotated login goes, and the fallback for anything refused. */
export const DEFAULT_LANDING = 'dashboard.html';

/**
 * @param {string|null|undefined} raw the `next` query parameter as it arrived
 * @returns {string} a same-origin page in this directory, or DEFAULT_LANDING
 */
export function safeNext(raw) {
  if (!raw || typeof raw !== 'string') return DEFAULT_LANDING;

  // "http:" and "javascript:" are absolute; "//evil.example" is protocol
  // relative. Both navigate off this origin. The backslash is in the second
  // test because browsers normalise it to a forward slash, so "/\evil.example"
  // is the same trick spelled differently.
  if (/^[a-z][a-z0-9+.-]*:/i.test(raw) || /^[/\\]/.test(raw) || raw.includes('..')) {
    return DEFAULT_LANDING;
  }

  // An allowlist by shape, not a blocklist: the app only ever links to its own
  // top-level pages, so anything that is not one of those is refused whether or
  // not we thought of it.
  return /^[\w.-]+\.html(\?[^#]*)?$/i.test(raw) ? raw : DEFAULT_LANDING;
}
