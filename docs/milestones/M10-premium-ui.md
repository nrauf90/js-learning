# M10 — Premium UI, profile & PDF reports

**Phase:** 3 · **Depends on:** M9

## Goal

Design overhaul to a premium SaaS look (Apple/Stripe-inspired glassmorphism), plus
three product features: an app-shell sidebar for logged-in pages, user profile
management (name + password update), and PDF export of reports.

## Scope

- **Design system**: glass cards (backdrop blur), aurora gradient background,
  gradient buttons with shine/press micro-interactions, hover lifts.
- **Motion**: GSAP + ScrollTrigger scroll-triggered reveals and hero entrance
  timeline on the landing page; Lenis smooth scroll. (Framer Motion is React-only,
  so GSAP fills that role in this vanilla JS app.) All loaded from CDN with
  graceful degradation and `prefers-reduced-motion` respected.
- **App shell**: shared sidebar (`js/shell.js`) on dashboard, cashflow, reports,
  billing, profile — nav links, user card, logout; slide-in drawer on mobile.
- **Dashboard**: Chart.js line chart (income vs expenses per day) + bar chart
  (daily net), 4 stat cards, entrance animations.
- **Profile**: `profile.html` — update name, change password
  (`PUT /api/user/profile`, `PUT /api/user/password`).
- **Reports PDF**: jsPDF export with totals, chart images, category tables.

## Exit criteria

- [x] Backend endpoints for profile/password update with PHPUnit coverage
- [x] Sidebar shell on all logged-in pages; mobile drawer works
- [x] Dashboard renders line + bar charts from the week's entries
- [x] Reports page downloads a real PDF file
- [x] Landing page has scroll-triggered animations + smooth scroll
- [x] `npm run qa:milestone -- M10` green (regression M1–M10)
