# Landing page and legal pages

## What it is

The three public pages nobody has to log in for: the marketing page at
`index.html`, and the Privacy Policy and Terms & Conditions that Paddle (and any
app store) require a paid product to publish.

## How it works

### Landing

`index.html` is four sections — hero, About us, How it works, Contact us — with a
header carrying the shared public nav and the theme toggle.

The hero heading is "Ring up sales. Watch your stock and cash." It sells the app
as a till plus a cash book; there is no free tool on it any more. The page used to
carry a hero tax calculator, and `js/landing.js` existed mainly to drive it — with
the tax tool removed, `js/home.js` is all that is left and it does three things:

```js
initTheme();
initNav({ current: 'home' });
initMotion();
```

The contact form is a `mailto:` form (`action="mailto:support@cashflow.app"`,
`method="get"`) — there is no backend endpoint behind it.

### Motion

`js/motion.js` loads GSAP, ScrollTrigger and Lenis **from a CDN at runtime**, then:

- **Lenis** smooths the scroll and takes over same-page anchor links (`#about`,
  `#contact`) with a −16px offset, keeping ScrollTrigger updated as it goes.
- **`animateHero()`** runs a staggered timeline over the badge, title, lead, CTAs,
  stat list and widget.
- **`animateReveals()`** animates every `[data-reveal]` element into view at
  `top 82%`, staggering its children when `data-reveal-stagger` is present, and
  applies a scrubbed parallax to `.hero-bg`.

Every step degrades gracefully. `initMotion()` returns immediately when
`prefers-reduced-motion: reduce` is set, each helper returns early when its
library is missing, and the whole thing is wrapped in a `try`/`catch` — offline or
with the CDN blocked, the page stays fully usable and visible.

`loadScript()` is idempotent: it resolves straight away if a `<script>` with that
src is already in the document.

### Legal

`privacy.html` and `terms.html` are static prose with the same header and footer
as the landing page, and `js/legal.js` is two lines — `initTheme()` and
`initNav()`.

Privacy covers what is collected, how it is used, sharing, storage and security,
user choices, cookies and local storage, children, changes and contact. Terms
covers the service description, "not professional advice", accounts, acceptable
use, subscriptions and payments, IP, warranty disclaimer, liability, changes,
governing law and contact.

Both are linked from the footer of every public page and are needed as the
customer-facing terms behind Paddle checkout — Paddle is the merchant of record,
so it needs somewhere to point.

## Screens / files

| Layer | File |
|---|---|
| Landing | `index.html`, `js/home.js` |
| Legal | `privacy.html`, `terms.html`, `js/legal.js` |
| Animations | `js/motion.js` (GSAP + ScrollTrigger + Lenis, CDN) |
| Nav | `js/nav.js` |
| Theme | `js/theme.js` |
| Styles | `css/styles.css` |

## API endpoints

None. All three pages are static and make no API calls — `js/nav.js` only reads
the token from `localStorage` to decide whether to render the logged-in links, and
only calls `POST /api/logout` if the log-out link is used.

## Permissions & gating

Fully public. No auth, no subscription, nothing to authorise.

## Edge cases & known limits

- **The contact form does not reach anyone.** It opens the visitor's mail client
  through a `mailto:` link; there is no submission endpoint, no storage and no
  confirmation.
- **The nav is out of date.** `js/nav.js` lists only Home, Sell, Products,
  Dashboard, Reports, Cash Flow, Billing and Profile — a logged-in visitor landing
  here sees a navigation missing Sales, Stock In, Khata, Activity and Shop. See
  [app-shell-and-theme.md](./app-shell-and-theme.md).
- **Three CDN dependencies on the landing page.** With the CDN blocked the page is
  static but intact.
- **The legal text is generic boilerplate.** It names no company, jurisdiction or
  registered address specific to this deployment, and the support address is
  `support@cashflow.app`.
- **No sitemap, robots.txt, Open Graph tags or analytics.**
- There is no pricing section on the landing page — prices only appear on
  `billing.html`, behind a login.
