# Frontend design guide — PK Galla landing page

An adapted version of the premium-landing-page brief, rewritten for what this
product actually is and what this repository actually runs on.

**Read [§1 Conflicts](#1-conflicts-with-the-source-brief) first.** Three parts of
the original brief describe a different product and a different stack, and
following them literally would ship false claims to customers and break a locked
architecture decision.

---

## 1. Conflicts with the source brief

### 1.1 The business model is inverted — this is the important one

The source brief is written for an **application marketplace**: customers buy
apps for a lifetime, then pay separately for hosting, and own the software
forever even if the vendor disappears.

PK Galla is **not that**. It is a subscription SaaS:

| Source brief | PK Galla (`backend/config/billing.php`, `docs/features/billing-subscriptions.md`) |
|---|---|
| One-time lifetime purchase | Recurring subscription — monthly or yearly |
| Hosting billed separately by the customer | Fully hosted; the customer buys nothing else |
| "Own it forever" | Access ends when the subscription lapses |
| Self-serve checkout | Shops are **activated by the platform admin** — there is no buy button in the app |
| Marketplace of many vendors' apps | One product, built in-house |
| — | 7-day free trial, then paid (`trial_days`, default 7) |
| — | Paddle as merchant of record, charged in **USD** (Paddle cannot charge PKR) |

So the headline **"Buy Software. Deploy Instantly. Own It Forever."** cannot be
used. "Own it forever" would be a false statement about what the customer is
buying, and a landing page is exactly where that kind of claim becomes a refund
argument later.

Every marketplace-shaped section in the source brief — the app-card grid with
lifetime prices, the "Build It. Publish It. Get Paid." developer section, the
"Application ≠ Hosting" comparison — describes a product that does not exist
here. §4–§10 below replace them.

> **If a marketplace pivot is actually intended**, that is a product decision
> that changes billing, entitlements and the data model — not something a
> landing page can front-run. Say so and this guide gets rewritten around it.

### 1.2 The stack is prescribed, and it is not ours

The brief says to prefer React/Next.js, Tailwind and Framer Motion. This repo is
**vanilla HTML/CSS/JS**, and "Keep vanilla frontend; Laravel is API only" is a
**locked architecture decision** in `docs/CONTEXT.md`.

What is already here and must be reused:

| Need | Already available |
|---|---|
| Animation | **GSAP 3.12 + ScrollTrigger**, loaded in `js/motion.js` |
| Smooth scroll | **Lenis 1.1**, wired to ScrollTrigger in the same file |
| Scroll reveal | The `data-reveal` / `data-reveal-stagger` attribute contract |
| Type | **DM Sans** (400–700) + **JetBrains Mono** (500) |
| Colour, spacing, elevation | The token block at the top of `css/styles.css` |
| Buttons | `.btn-primary`, `.btn-secondary`, `.btn-ghost` |

Do not add Tailwind, React or Framer Motion. Everything the brief asks for is
achievable with what is loaded, and each of those would be a second system doing
a job the first already does.

### 1.3 Dark-first is backwards here

The brief asks for a "deep dark background, dark-first" design. This app is
deliberately **light by default**.

That was a deliberate fix (M13): dark-first meant painting dark on `:root` and
overriding to light, which flashed dark on every load for the majority of users
who are on light. Light now lives on the unattributed `:root` and
`:root[data-theme='dark']` is the explicit override.

The landing page must therefore be **excellent in both themes**, not designed
dark and checked in light. See §3.

---

## 2. Product context (corrected)

PK Galla — *Dukaan Sambhalo, Aasani Se* — is a **point of sale and khata system
for Pakistani kiryana shops**.

What makes it specific, and what the landing page should actually sell:

- **Weighed goods.** Half the shelf is sold by weight or by rupee amount, not by
  the piece. The till takes "ek pao daal" (→ 250 g → Rs 62.50) and "pachaas ka
  daal" (→ Rs 50 → 200 g) in both directions.
- **Khata / udhaar.** Goods leave before the money does. Who owes what, 30/60/90
  aging, credit limits, oldest-first payment allocation.
- **Whole-rupee settlement.** Paisa coins do not circulate, so totals round and
  the remainder is kept honestly.
- **The day book.** Opening float, closing count, and the variance that tells the
  owner whether the day added up.
- **Stock that cannot drift.** Every movement is audited with a running balance.
- **Profit that does not lie.** A line with no recorded cost is reported as
  *unknown* margin, never as pure profit.

**The audience is a mohalla shopkeeper, not a Silicon Valley engineer.** The
source brief's "serious, venture-backed technology product" framing, and its
Linear/Vercel/Stripe reference points, are aimed at developer tooling buyers. A
kiryana owner evaluating this is asking "will this tell me kitna bacha, and will
it handle udhaar?" — not whether the marketing site feels venture-backed.

Keep the **craft** of those references. Drop the audience assumption.

### Voice

The product speaks Roman Urdu where it counts, and it is the strongest
differentiator on the page. Real product strings:

> "Dukaan Sambhalo, Aasani Se" · "Kitna bacha?" · "Paisa kahan hai?" · Khata ·
> Udhaar · Bori · Peti · Pao

Use these. Do not translate "Khata" to "Customer Credit Ledger" in a headline —
the whole point is that it reads as the shopkeeper's own word. English carries
the explanatory copy; Roman Urdu carries the labels and the emotional beats.

---

## 3. Visual direction

Keep the source brief's quality bar. Change the defaults.

**Both themes are first-class.** Design in light, verify in dark, ship neither as
an afterthought. The `--accent` / `--warm` / `--surface` tokens already resolve
per theme; use them and both come out right.

### Palette — already defined, sampled from the logo

| Token | Role |
|---|---|
| `--accent` | Brand blue from the wordmark. Links, primary buttons, active state. |
| `--warm` | Flame orange from the swoosh. Highlights, glows, the badge pill. |
| `--surface`, `--surface-2` | Card and raised surfaces |
| `--border` | Hairlines. Navy-tinted, not neutral grey. |
| `--glass`, `--glass-strong`, `--glass-border` | Glassmorphism, already tuned per theme |
| `--shadow`, `--radius` | Elevation and corner rounding |
| `--ease-spring` | The shared easing curve |

**Blue leads, orange accents.** The brief's "avoid excessive gradients / neon /
clutter" applies as written — with one addition: the logo is already a
high-saturation, high-gloss 3D lockup. The page around it must stay calm, or the
two compete and the logo loses.

Do not introduce new colours. If something needs a hue that is not a token, the
token set is wrong and should be extended deliberately — not bypassed inline.

### Type

DM Sans throughout; JetBrains Mono for figures only — prices, quantities,
variance, anything the eye scans in a column. Both are already loaded.

---

## 4. Structure

Replacing the source brief's §4–§11. The narrative arc it asks for is right; the
content is not.

**Problem → The till → Khata → Stock & profit → Proof → Pricing → CTA**

### Navbar
Sticky, translucent-on-scroll, hamburger below the tablet breakpoint. Links:
Features · How It Works · Pricing · Contact. Right: **Log in** · **Get started**.
The current header markup and `.site-nav` already exist — extend, do not replace.

### Hero
Headline candidates, all honest about the model:

> **Dukaan sambhalo, aasani se.**
> Sell by weight, track udhaar, and know exactly kitna bacha — every day.

Primary CTA **Start free trial** (7 days, real). Secondary **See how it works**.

Do **not** use "Own It Forever" or any lifetime-purchase language (§1.1).

Hero visual: the source brief's animated flow still works, retargeted to the
actual product loop —

> Ring up a sale → Stock moves itself → Udhaar goes on the khata → Day book
> closes → *Kitna bacha* answered

Animate with GSAP; the existing `animateHero()` timeline in `js/motion.js` is the
starting point.

### The till
Weighed goods are the single most convincing thing this product does. Show the
keypad working both directions — grams → rupees and rupees → grams. A shopkeeper
recognises this instantly and no competitor screenshot will match it.

### Khata
Who owes what, aging buckets, a credit limit refusing a sale with the figures
named. This is the second-strongest section; give it room.

### Stock & profit
The audited movement ledger, wastage by cause (not free text), and the honest
*unknown* margin. Frame the last one as trustworthiness: **the app tells you when
it does not know**.

### Proof
Replaces the brief's trust/security section. Keep its instruction — *do not make
unrealistic security claims* — and stick to what is true: isolated per-shop data
scoping, audited stock movements, day-book reconciliation, roles for staff
versus owner. No SOC 2 badge, no "bank-grade encryption", no uptime figure
nobody measures.

### Pricing
Replaces the "Application ≠ Hosting" comparison, which does not apply.

State plainly: **7-day free trial → monthly or yearly subscription**. Billing is
in **USD** through Paddle — say so on the page rather than surprising a
PKR-thinking shopkeeper at checkout. If self-serve checkout is still
admin-activated, the CTA must be "Request access" or similar, not "Buy now" —
a buy button that does not buy is the fastest way to lose the sale.

### Final CTA
> **Aaj se hisaab shuru karein.**
> Start the free trial. No card, no setup, no server to configure.

Buttons: **Start free trial** · **Talk to us**. Drop "Become a Seller" — there is
no seller side.

---

## 5. Animation

The brief's animation list is good and mostly already implemented. Reuse rather
than rebuild:

- **Scroll reveal** — add `data-reveal` to a section, `data-reveal-stagger` to
  stagger its children. `js/motion.js` picks both up automatically.
- **Smooth scroll and anchors** — Lenis already handles in-page links.
- **Hover, gradient drift, micro-interactions** — CSS, using `--ease-spring`.
- **Number counters** — GSAP, and only where a figure earns it.

`prefers-reduced-motion` is **already respected** in `js/motion.js:28`. Anything
new must honour it too — including CSS animations, which that check does not
cover. Guard those with `@media (prefers-reduced-motion: no-preference)`, the
pattern already used at `css/styles.css:2662`.

Performance: animate `transform` and `opacity` only.

**Degradation is already correct — keep it that way.** GSAP, ScrollTrigger and
Lenis are **served from our own origin** out of `vendor/` (see `vendor/README.md`
for why they left the CDN), and the reveals use `gsap.from()`, so GSAP itself
sets the hidden start state and animates to the element's natural one. Nothing in
`css/styles.css` hides `[data-reveal]`. If a script fails to load for any reason,
no animation runs and **all content stays visible**.

That property is easy to destroy by accident: adding `opacity: 0` to
`[data-reveal]` in CSS — the obvious way to stop the pre-animation flash — makes
every revealed section permanently invisible whenever the CDN fails. If the flash
needs fixing, gate the initial state on a class that JS adds only once GSAP has
loaded.

---

## 6. Responsive

`css/styles.css` has **15 distinct `max-width` breakpoints** (360 → 1180px), most
used once or twice for a single component. There is no shared scale — new work
should reuse the nearest existing value rather than adding a sixteenth, and the
set is overdue a consolidation pass.

The ones carrying actual layout shifts: **960**, **768/767** (sidebar → drawer),
**720**, **640**, **600**, **480**.

Verify at **1440 / 1024 / 768 / 390**.

Two live issues to fix rather than inherit:

1. **The landing page overflows horizontally by ~2px below 380px wide** —
   `.hero`, `.hero-bg`, `.landing-section`, `.site-footer` measure 385px in a
   380px viewport. Found and confirmed 2026-08-11; predates this work.
2. **The logo lockup is 277×87 (3.18:1)** — wide. It is height-driven with
   `width: auto` and fits every breakpoint, but the tagline inside it is
   illegible below roughly 3.5rem. Treat it as decorative texture at header
   sizes, or use a variant without the tagline.

---

## 7. Definition of done

Beyond the source brief's checklist:

- [ ] No lifetime-ownership, marketplace or "your own hosting" language anywhere
- [ ] Pricing states the trial, the recurrence **and** that billing is in USD
- [ ] Polished in **both** themes, verified — not designed dark and spot-checked
- [ ] No Tailwind, React or Framer Motion added
- [ ] New colours are tokens, not inline hex
- [ ] `prefers-reduced-motion` honoured by CSS animations as well as GSAP
- [ ] Content still readable if a script fails to load (no CSS-level `opacity: 0`
      on `[data-reveal]` — see §5)
- [ ] No new off-origin `<script src>`; third-party libraries go in `vendor/`
- [ ] The ~2px sub-380px overflow is gone
- [ ] Roman Urdu labels intact and correctly spelled
- [ ] `npm run lint` clean, `npm test` green, `npm run qa:m1` passing
      (`m1-health.spec.js` asserts on the hero heading — update the spec
      deliberately if the headline changes, do not let it fail silently)

---

## 8. Source brief sections that do not apply

Recorded so nobody re-derives them later:

| Section | Why |
|---|---|
| §5 Marketplace preview | No marketplace, no app catalogue, no per-app pricing |
| §8 Developer section | No seller side; nobody publishes apps here |
| §10 Application ≠ Hosting | We host; the customer buys no hosting |
| §4 "Own It Forever" headline | Contradicts a subscription model (§1.1) |
| §15 Tailwind / React / Framer Motion | Violates the locked vanilla-frontend decision |
| §3 "Dark-first" | Inverted here by the M13 fix (§1.3) |
