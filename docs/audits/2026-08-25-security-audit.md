# Security audit — 2026-08-25

Scope: the whole application — Laravel API, browser frontend, and the Capacitor
Android/iOS shells. Written against `main` at `61e8a29`.

Everything listed under **Fixed** was fixed in the same pass and is covered by a
test. Everything under **Accepted** was checked and deliberately left alone, with
the reason. Everything under **Still open** is real but out of this pass's scope.

## Method

Read every controller, policy, middleware and config in `backend/`; every module
in `js/`; the Capacitor config and both native manifests. Where a claim could be
checked mechanically it was:

| Check | How | Result |
|---|---|---|
| Unescaped interpolation into `innerHTML` | scripted scan of every `${…}` in `js/*.js`, filtered to user-controlled fields | 2 real hits, both in `cashflow.js` |
| Raw SQL taking request input | grep `DB::raw` / `whereRaw` / `selectRaw`, read each | none — every dynamic fragment is built from constants, values are bound |
| Mass assignment of privilege columns | read `User::$fillable` + every `create()`/`fill()` call site | clean — `is_admin`, `role`, `shop_id`, `can_manage_products`, `paddle_customer_id` all set through explicit setters |
| Missing authorisation | listed every controller method against its `authorize()` / `dataOwnerId()` scoping | clean — no unscoped read or write found |
| Secrets in the repo | `git ls-files` on `.env`, `*.sqlite`, `storage/*.key` | clean — none tracked |
| Webhook authentication | read `PaddleGateway::verifyWebhook` | correct — HMAC over the raw body, `hash_equals`, timestamp bound |
| Command execution / deserialisation | grep `eval`/`shell_exec`/`system`/`unserialize` | none |
| Image-upload gates | fed real PHP payloads behind JPEG and PNG signatures through `getimagesize()` and finfo | **PNG passed** — see finding 7 |

The authorisation model is the strongest part of this codebase and the audit
found no hole in it. Policies consistently compare against `User::dataOwnerId()`
rather than `id`, which is the distinction that keeps staff inside their own
shop, and the comments explain why at each site.

Six of the seven findings are at the edges — the browser, the native shells, and
the one flow that did not exist. The seventh is not, and it is the one worth
reading: a check written specifically to catch disguised uploads did not catch
them on the format most screenshots are in. It was found by feeding the gate a
real payload rather than by reading it, which is the argument for doing both.

---

## Fixed

### 1. Stored XSS in the cash-flow list — `js/cashflow.js`

Category names were interpolated into `innerHTML` unescaped, in two places: the
`<option>` list of the category picker (line 74) and the category cell of every
entry row (line 146). Every other screen in the app routes such values through an
`escapeHtml()` helper; these two were missed.

Categories are admin-managed, which narrows who can plant the payload but does
not make it harmless — a platform admin's category name renders inside every
shop's cash-flow screen, and the bearer token lives in `localStorage` on that
same origin.

Both now go through the file's existing `escapeHtml()`.

### 2. Google sign-in adopted accounts on an unverified address — `GoogleAuthController`

The callback matched an incoming Google profile to an existing account by email
alone. Google does not promise that the `email` on a profile is verified — the
`email_verified` claim is what says so — and an unverified address is just a
string the profile's owner typed.

The consequence was full account takeover with no password: register a Google
account claiming a shopkeeper's address, sign in, and the callback would graft
that `google_id` onto their row and mint a token for it.

The callback now requires the `email_verified` claim before adopting an existing
account **or** creating a new one, and redirects to
`login.html?error=google_email_unverified` otherwise. Both spellings Google uses
(a real boolean on the id_token, the string `"true"` from userinfo) count;
anything else — absent, false, unexpected — reads as unverified, because this
gate decides whether a stranger can claim a shopkeeper's account and the failure
has to be the safe direction.

Covered by `GoogleAuthTest` — five new cases, including that the victim's row is
left untouched and no token is minted.

### 3. Open redirect on login — `js/auth.js`

`?next=` was taken off the query string and handed to `location.href` unchecked.
The app only ever writes its own page names there, but anyone can mail a link:
`login.html?next=https://evil.example` walks the shopkeeper onto somebody else's
site the instant they finish typing their password, with our domain in the
address bar the whole way. That is the exact shape of a credential-phishing lure,
and `docs/features/users-and-auth.md` had already flagged it as unvalidated.

Now filtered through `safeNext()` in the new `js/safe-redirect.js`: an allowlist
by shape — a bare `*.html` in this directory, optionally with a query — refusing
schemes, protocol-relative `//host`, backslash variants, and `..`. A pure module
rather than a helper inside `auth.js` so it could be unit tested; see
`tests/safe-redirect.test.js`.

### 4. Android backups swept up the bearer token — `AndroidManifest.xml`

`android:allowBackup="true"` (the Capacitor template default) put the webview's
`localStorage` into Google's cloud backup and within reach of `adb backup` on any
device with USB debugging on. That storage holds the Sanctum token, which is good
for 30 days, plus the cached user and the offline sales queue.

Now `allowBackup="false"`, with `fullBackupContent="false"` and a
`data_extraction_rules.xml` that excludes every domain from both cloud backup and
device transfer — API 31+ ignores `allowBackup` for device-to-device unless that
case is spelled out. Nothing here is worth restoring anyway: the ledger is on the
server and a fresh install just logs in again.

### 5. Release builds allowed mixed content — `capacitor.config.json`

`allowMixedContent: true` applied to release APKs as well as debug. The app is
served from `https://localhost`, so that setting let any `http://` subresource or
XHR load into the origin holding the bearer token — an injection point on shop
wifi.

Now `false`. The debug workflow, which genuinely needs to reach a plaintext dev
API, is preserved by a `BuildConfig.DEBUG` branch in `MainActivity` that relaxes
the webview's mixed-content mode for debug builds only. `BuildConfig.DEBUG`
cannot be shipped flipped; a config flag can. This mirrors the existing
debug-only `network_security_config.xml`, and the stale comment in
`mobile/js/config.js` that described the old behaviour was corrected.

### 6. Reset links lost their token to the dev server — `serve.json`

Found while testing the new password reset flow, and worth recording because it
is a class of bug, not a one-off: `serve`'s default `cleanUrls` 301-redirects
`/page.html` to `/page` **and drops the query string**. Any link this app mails
with a query would arrive stripped.

Two fixes, both kept: `serve.json` turns `cleanUrls` off (every link in this app
is written with its extension, so the rewrite bought nothing), and the reset link
now carries its token in the URL **fragment** rather than the query. A fragment
is never sent to a server, so the token also stays out of the frontend host's
access logs, out of `Referer` headers, and out of any proxy in between. The reset
page still accepts a query as a fallback so a link mailed under the old format
does not dead-end.

### 7. `getimagesize()` was not the second gate it claimed to be — `ImageStore`

Found while writing the khata upload tests, and the most interesting finding
here because the code was *specifically written* to stop this and did not.

The upload path has two gates. Laravel's `image`/`mimes` rules run finfo, which
only reads a file's leading bytes — a payload merely has to *start* like an image
to pass. `verifiedExtension()` was the second gate, and its comment said
getimagesize() "parses the header properly and fails when no real dimensions can
be read, so a PHP script wearing a GIF89a hat does not get through."

That is true for JPEG. It is false for PNG. getimagesize() handles PNG by reading
the 8-byte signature and then taking the next eight bytes as the IHDR width and
height **without validating them**. So:

```
"\x89PNG\r\n\x1a\n" . '<?php system($_GET["c"]); ?>'
```

came back as a perfectly good `image/png`, 1,752,113,267 × 1,751,477,356 pixels —
and the `$width < 1 || $height < 1` check passed it, because garbage read as a
huge positive number is still positive.

Practical impact was limited: finfo reads those bytes as
`application/octet-stream` so Laravel's rules still refused them in production,
the stored extension is server-chosen so nothing lands executable, and the
receipts disk is not web-served at all. But the layer that existed *because* the
first one is foolable was itself foolable, on the format most screenshots are in.

`verifiedExtension()` now runs three checks instead of one and a half: the header
parse (which names the format), **plausible dimensions** bounded by
`MAX_DIMENSION` (20,000 px — a 100 MP phone is ~12,000 on its long side, a 600 dpi
A4 scan ~7,000), and an **independent finfo read** that has to agree with the mime
the header claimed. The last one means the store is safe on its own terms rather
than on the assumption that every caller remembered to apply `rules()`.

Pinned by `CatalogImageTest` — on the catalogue rather than the receipts store,
because that is the disk that is publicly served — alongside a test that a large
but genuine 4000×3000 image still goes through, so the ceiling cannot quietly
become a product limit.

---

## Accepted

Checked, and left as they are:

- **The bearer token lives in `localStorage`.** Readable by any script on the
  origin. Moving it to an `httpOnly` cookie would mean switching to Sanctum's SPA
  cookie mode, which the frontend/API split across ports 3000 and 8000 makes
  considerably fussier — and it would not help the Capacitor build at all. The
  app's XSS defence is the escaping helpers on every rendered value, which is why
  finding 1 mattered.
- **`APP_DEBUG=true` in `backend/.env`.** Local only, and `.env` is not tracked.
  Worth a deployment checklist entry, not a code change.
- **Self-serve registration is open.** By design — a new account gets a 7-day
  trial and nothing else until a platform admin grants a subscription.
- **Login is throttled per IP (10/min), with no per-account lockout.** Reasonable
  for the threat model; a lockout is itself a denial-of-service lever against a
  named shopkeeper.
- **`AdminController::userUpdate()` returns the whole `User` model.** `$hidden`
  covers the password and remember token; the rest (`google_id`,
  `paddle_customer_id`) is visible only to a platform admin who could read it
  from the database anyway.
- **iOS carries no ATS exceptions.** Nothing to fix.

## Still open

- **No email verification.** `email_verified_at` exists and is set only by the
  admin seeder. Registration accepts any address. Now partly mitigated for the
  Google path by finding 2, but a self-registered account still proves nothing
  about the address it claims.
- **Changing a user's email (admin or shop admin) does not revoke their tokens.**
  Password changes do; email changes should too.
- **`POST /api/receipts/upload` is still a 501 stub.** Unrelated to the new
  purchase attachments, which have their own endpoints — this one is the
  cash-entry receipt scaffold from M8.
- **No Content-Security-Policy** on any page. Would be defence in depth behind
  the escaping helpers; needs the vendored-asset inventory done first.
