# vendor/

Third-party browser libraries and webfonts, committed to the repo and served
from our own origin.

Not to be confused with `backend/vendor/`, which is Composer's and is
gitignored. This directory **is** committed — that is the whole point.

- Libraries — this file, below.
- Fonts — `fonts/`, documented in [Fonts](#fonts) at the end.

## Why these are not loaded from a CDN

They used to be, via `cdn.jsdelivr.net`, with no Subresource Integrity
attribute. A `<script src>` runs with full page privileges, and
`js/api.js` keeps the session token in `localStorage['cashflow_auth_token']`.
So a compromise of the CDN — or a DNS hijack, or a hostile network between a
shopkeeper and jsDelivr — meant arbitrary JavaScript running on the dashboard
and reports pages with the ability to read that token and act as the user.

Adding `integrity="sha384-..."` would have closed the tampering hole, but not
the availability one: a kiryana shop on an unreliable connection still needs
its reports page to work when a foreign CDN is slow, blocked, or down. Serving
from our own origin fixes both, and costs one HTTP request we were already
making to ourselves for the rest of the page.

It also removes an unintended third-party disclosure: every page load told
jsDelivr the visitor's IP address.

## What is here

| File | Package | Version | Source path in tarball |
| --- | --- | --- | --- |
| `chart.umd.js` | [chart.js](https://www.npmjs.com/package/chart.js) | 4.4.1 | `dist/chart.umd.js` |
| `jspdf.umd.min.js` | [jspdf](https://www.npmjs.com/package/jspdf) | 2.5.2 | `dist/jspdf.umd.min.js` |
| `gsap.min.js` | [gsap](https://www.npmjs.com/package/gsap) | 3.12.5 | `dist/gsap.min.js` |
| `ScrollTrigger.min.js` | [gsap](https://www.npmjs.com/package/gsap) | 3.12.5 | `dist/ScrollTrigger.min.js` |
| `lenis.min.js` | [lenis](https://www.npmjs.com/package/lenis) | 1.1.14 | `dist/lenis.min.js` |

Referenced from:

- `dashboard.html` — `chart.umd.js`
- `reports.html` — `chart.umd.js`, `jspdf.umd.min.js`
- `js/motion.js` — `gsap.min.js`, `ScrollTrigger.min.js`, `lenis.min.js`
  (loaded dynamically; paths there are document-relative, so they assume the
  loading page sits at the repo root — today only `index.html` does)

Chart.js is `chart.umd.js`, **not** `chart.umd.min.js`. The old CDN URL asked
for `chart.umd.min.js`, which does not exist in the published package —
jsDelivr was generating it on the fly. `dist/chart.umd.js` is already minified
and is what chart.js's own `package.json` names in its `jsdelivr` field.

`www/` (the Capacitor mobile build) does not reference any of these; the mobile
screens have no charts, no PDF export and no scroll animation. Nothing in
`scripts/build-mobile.mjs` needs to copy this directory.

## Integrity

SHA-384, base64 — the same digest an `integrity=` attribute would carry.

```
chart.umd.js          sha384-dug+JxfBvklEQdJ4AYuBBAIScUz0bVN73xpy273gcAwHjb3qI0fXmuYNaNfdyYJG
jspdf.umd.min.js      sha384-en/ztfPSRkGfME4KIm05joYXynqzUgbsG5nMrj/xEFAHXkeZfO3yMK8QQ+mP7p1/
gsap.min.js           sha384-g4NTh/Iv5PPU4xPyhEWqPcwtNXOvdaDI8LLnyYfyNZOjKJeYQyjzQ9X5275eBjpt
ScrollTrigger.min.js  sha384-Z3REaz79l2IaAZqJsSABtTbhjgOUYyV3p90XNnAPCSHg3EMTz1fouunq9WZRtj3d
lenis.min.js          sha384-O55L/6rhHr9CFvrxqv5luxOCcmVaBmETbZbJDP+Do8T0pztTACsFBD/IXCNkj7DV
```

Verify a file against its row:

```sh
openssl dgst -sha384 -binary vendor/chart.umd.js | openssl base64 -A
```

## Updating a library

Fetch from the npm registry, not from a CDN — `npm pack` verifies the
package integrity hash on download, a raw `curl` of a CDN URL verifies nothing.

```sh
cd "$(mktemp -d)"
npm pack chart.js@4.4.1          # or jspdf@x.y.z, gsap@x.y.z, lenis@x.y.z
tar -xzf chart.js-4.4.1.tgz
cp package/dist/chart.umd.js "<repo>/vendor/chart.umd.js"
```

Then update the version in the table above, recompute the SHA-384 row, and
check the page still renders — these libraries are loaded as globals
(`window.Chart`, `window.jspdf`, `window.gsap`, `window.ScrollTrigger`,
`window.Lenis`), so a breaking change surfaces as a runtime error on the page,
not as a build failure. There is no bundler here to catch it for you.

---

# Fonts

`fonts/` holds DM Sans and JetBrains Mono as woff2, plus `fonts.css` with the
`@font-face` rules. Every HTML page links it:

```html
<link rel="stylesheet" href="vendor/fonts/fonts.css" />
```

`css/styles.css` reaches them through `--font: 'DM Sans', system-ui, sans-serif`
and `--mono: 'JetBrains Mono', monospace`, which is unchanged — the fonts moved,
the way stylesheets ask for them did not.

## Why not Google Fonts

Each page previously carried three tags: `preconnect` to `fonts.googleapis.com`,
`preconnect` to `fonts.gstatic.com`, and the stylesheet itself. That meant:

- **Both Google hosts saw every visitor's IP address**, on every page load, for
  a POS app whose users never chose to talk to Google.
- **First paint depended on two foreign hosts.** A kiryana shop on a weak
  connection paid two DNS lookups and two TLS handshakes before any text could
  render, and if either host was slow or blocked, `font-display: swap` left the
  page in fallback until it resolved.

Self-hosting removes both. It is also *fewer* round trips than before, not more:
the old path needed DNS+TLS to `googleapis`, then DNS+TLS to `gstatic`, before
the first byte of a font. Now the CSS and the woff2 come from the connection the
page is already using, so the `preconnect` tags became pointless and were dropped
along with the stylesheet link.

## What is here

Downloaded from `fonts.gstatic.com` as referenced by the Google Fonts CSS the
app used to link. DM Sans is at `v17`, JetBrains Mono at `v24`.

| File | Family | Style | Subset |
| --- | --- | --- | --- |
| `dm-sans-latin.woff2` | DM Sans | normal (variable 400–700) | latin |
| `dm-sans-latin-ext.woff2` | DM Sans | normal (variable 400–700) | latin-ext |
| `dm-sans-italic-latin.woff2` | DM Sans | italic 400 | latin |
| `dm-sans-italic-latin-ext.woff2` | DM Sans | italic 400 | latin-ext |
| `jetbrains-mono-latin.woff2` | JetBrains Mono | normal 500 | latin |
| `jetbrains-mono-latin-ext.woff2` | JetBrains Mono | normal 500 | latin-ext |
| `jetbrains-mono-cyrillic.woff2` | JetBrains Mono | normal 500 | cyrillic |
| `jetbrains-mono-cyrillic-ext.woff2` | JetBrains Mono | normal 500 | cyrillic-ext |
| `jetbrains-mono-greek.woff2` | JetBrains Mono | normal 500 | greek |
| `jetbrains-mono-vietnamese.woff2` | JetBrains Mono | normal 500 | vietnamese |

208 KB on disk, but a plain English page downloads only
`dm-sans-latin.woff2` (61 KB) and, where monospace is used,
`jetbrains-mono-latin.woff2` (21 KB). Each `@font-face` carries a
`unicode-range`, so a browser fetches a subset only when the page actually
contains a character from it. That is why the Cyrillic, Greek and Vietnamese
subsets are worth keeping: they cost nothing until something needs them, and
keeping them removes any chance of a missing glyph later.

Two deliberate differences from what Google served:

- **DM Sans weights are one range, not four blocks.** It is a variable font;
  Google emitted four identical `@font-face` blocks for 400/500/600/700 all
  pointing at the same file. They are collapsed into `font-weight: 400 700`,
  which is equivalent at those four weights and also allows the ones between.
- **Italic now works on every page.** Only `index.html` used to request the
  italic variant, so the three `font-style: italic` rules in `css/styles.css`
  rendered as browser-synthesised faux italic on the other 23 pages. All pages
  share `fonts.css`, so they now get the real italic cut.

## Integrity

```
dm-sans-latin.woff2                sha384-H+4W4FHMKbn0A/IxuKmnoB5/G0F1At8yw1MDI3quP1b3xQfJTq6ltYKW/YnMoUj9
dm-sans-latin-ext.woff2            sha384-NC/aMzKWL//tfAkFPOXRx6eU+hDKI//kgtmxcbAqeRZorumogf38Eq5POLM3ar3R
dm-sans-italic-latin.woff2         sha384-VTlMd+L35V/c+Pk+YyGqMbWIQ2yjONxhjp62vaFXnflj6CTMy3VQekKoOmCGDOxu
dm-sans-italic-latin-ext.woff2     sha384-/oXR0JgjKIVFsCANCKuLP0gK+UJ/xlKs+yu6sBBd2QNB68C/zpgXiGFrStyb2KDa
jetbrains-mono-latin.woff2         sha384-3iyOQaeJXnQxIW20oqgpGwfOVKUBAjMsLqvIIwa7iIJ+b/4hcIy0H8MDF8CKlE2b
jetbrains-mono-latin-ext.woff2     sha384-2eXrjTEbp46k5ZofMwh/Ha5g9R5He1spdzkNjlg+aLo//cSNdlRDy6PORrrNhb/7
jetbrains-mono-cyrillic.woff2      sha384-Ls7qrRZOV3Uyk3zXkp/9Wd1PD2iTBJ3MwRsKp5gKM639UBOTVvj6vB6yhjRI5frG
jetbrains-mono-cyrillic-ext.woff2  sha384-MdhWexSPnNt7dJX9tfrDxy8IQEODQU33ib6Pin6u2NskLKZTx61DcHsnV/p4MwET
jetbrains-mono-greek.woff2         sha384-ENIi6YfgIc/4x6kMoKtCKY5WwbcW+DJn2m8PXKgvkGvUihMxIG006stcZlHDwNWD
jetbrains-mono-vietnamese.woff2    sha384-y5yxcjo5nZLfADdYdr+6tACSW26NsmfiGXcmKlgkyZAFZp0i6EYZy8olk4GFvGqv
```

## Updating a font

Fetch the Google Fonts CSS with a browser `User-Agent` — without one, Google
serves legacy `ttf` instead of `woff2`:

```sh
curl -s -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 \
(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36" \
  "https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=JetBrains+Mono:wght@500&display=swap"
```

That prints one `@font-face` per style-and-subset with a `fonts.gstatic.com`
URL. Download each, drop it in here under the naming scheme above, update
`fonts.css`, and recompute the hashes:

```sh
openssl dgst -sha384 -binary vendor/fonts/dm-sans-latin.woff2 | openssl base64 -A
```

Verify by rendering, not by reading the CSS: a wrong path fails silently into a
system fallback that looks close enough to miss. Chrome DevTools →
**Rendering → Show rendering** , or the Network panel filtered to `Font`, will
show which files were actually fetched.

