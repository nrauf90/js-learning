# vendor/

Third-party browser libraries, committed to the repo and served from our own
origin.

Not to be confused with `backend/vendor/`, which is Composer's and is
gitignored. This directory **is** committed — that is the whole point.

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
