/**
 * Landing page controller.
 *
 * The page itself is static marketing markup — all this does is wire the shared
 * chrome (theme, nav, motion) and add the two behaviours that only make sense
 * here: a header that gains a background once you scroll off the hero, and
 * scroll-spy on the section links.
 */
import { initNav } from './nav.js';
import { initTheme } from './theme.js';
import { initMotion } from './motion.js';

/** In-page anchors, shown to logged-out visitors only. See initNav. */
const SECTIONS = [
  { href: '#features', label: 'Features' },
  { href: '#khata', label: 'Khata' },
  { href: '#how-it-works', label: 'How it works' },
  { href: '#pricing', label: 'Pricing' },
  { href: '#contact', label: 'Contact' },
];

/**
 * The header sits over the hero, which already has its own gradient, so it only
 * needs a background once it is over ordinary content.
 *
 * IntersectionObserver on a sentinel rather than a scroll listener: this fires
 * twice per page (crossing in, crossing out) instead of on every frame of every
 * scroll, and it does not fight Lenis for the main thread.
 */
function initHeaderScrollState() {
  const header = document.getElementById('site-header');
  if (!header || !('IntersectionObserver' in window)) return;

  const sentinel = document.createElement('div');
  sentinel.setAttribute('aria-hidden', 'true');
  sentinel.style.cssText = 'position:absolute;top:0;height:1px;width:1px;pointer-events:none';
  document.body.prepend(sentinel);

  new IntersectionObserver(
    ([entry]) => header.classList.toggle('is-stuck', !entry.isIntersecting),
    { rootMargin: '-80px 0px 0px 0px' }
  ).observe(sentinel);
}

/**
 * Marks the nav link for whichever section is currently in view.
 *
 * Sections are observed against a band across the middle of the viewport, so
 * the highlight changes when a section genuinely occupies the screen rather
 * than the instant its top edge appears.
 */
function initScrollSpy() {
  if (!('IntersectionObserver' in window)) return;

  const links = new Map();
  for (const { href } of SECTIONS) {
    const link = document.querySelector(`.site-nav a[href="${href}"]`);
    const section = document.querySelector(href);
    if (link && section) links.set(section, link);
  }
  if (!links.size) return;

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        const link = links.get(entry.target);
        if (!link) continue;
        if (entry.isIntersecting) {
          for (const other of links.values()) other.removeAttribute('aria-current');
          link.setAttribute('aria-current', 'true');
        }
      }
    },
    { rootMargin: '-45% 0px -45% 0px' }
  );

  for (const section of links.keys()) observer.observe(section);
}

initTheme();
initNav({ current: 'home', sections: SECTIONS });
initMotion();
initHeaderScrollState();
initScrollSpy();
