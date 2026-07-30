/**
 * Motion layer: GSAP + ScrollTrigger (scroll-triggered animations) and
 * Lenis (smooth scroll). Libraries load from CDN; every step degrades
 * gracefully — if a script fails, the page stays fully usable and visible.
 */

const GSAP_SRC = 'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js';
const SCROLLTRIGGER_SRC = 'https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js';
const LENIS_SRC = 'https://cdn.jsdelivr.net/npm/lenis@1.1.14/dist/lenis.min.js';

function loadScript(src) {
  return new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[src="${src}"]`);
    if (existing) {
      resolve();
      return;
    }
    const s = document.createElement('script');
    s.src = src;
    s.async = true;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error(`Failed to load ${src}`));
    document.head.appendChild(s);
  });
}

function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function initLenis() {
  if (typeof window.Lenis === 'undefined') return;
  const lenis = new window.Lenis({
    duration: 1.1,
    easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
    smoothWheel: true,
  });

  function raf(time) {
    lenis.raf(time);
    requestAnimationFrame(raf);
  }
  requestAnimationFrame(raf);

  // Keep ScrollTrigger in sync with the smoothed scroll position.
  if (window.ScrollTrigger) {
    lenis.on('scroll', window.ScrollTrigger.update);
  }

  // Smooth-scroll same-page anchor links (e.g. #about, #contact).
  document.querySelectorAll('a[href^="#"]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const target = document.querySelector(a.getAttribute('href'));
      if (!target) return;
      e.preventDefault();
      lenis.scrollTo(target, { offset: -16 });
    });
  });
}

function animateHero(gsap) {
  if (!document.querySelector('.hero')) return;

  const tl = gsap.timeline({ defaults: { ease: 'power3.out', duration: 0.8 } });
  tl.from('.hero-badge', { y: 24, opacity: 0 })
    .from('.hero-title', { y: 34, opacity: 0 }, '-=0.55')
    .from('.hero-lead', { y: 28, opacity: 0 }, '-=0.55')
    .from('.hero-cta > *', { y: 20, opacity: 0, stagger: 0.1 }, '-=0.5')
    .from('.hero-stats li', { y: 16, opacity: 0, stagger: 0.08 }, '-=0.5')
    .from('.hero-widget', { y: 40, opacity: 0, scale: 0.97, duration: 0.9 }, '-=0.7');
}

function animateReveals(gsap, ScrollTrigger) {
  gsap.utils.toArray('[data-reveal]').forEach((el) => {
    const children = el.hasAttribute('data-reveal-stagger')
      ? Array.from(el.children)
      : [el];
    gsap.from(children, {
      y: 32,
      opacity: 0,
      duration: 0.75,
      ease: 'power3.out',
      stagger: 0.12,
      scrollTrigger: {
        trigger: el,
        start: 'top 82%',
        toggleActions: 'play none none none',
      },
    });
  });

  // Subtle parallax on the hero glow.
  const heroBg = document.querySelector('.hero-bg');
  if (heroBg) {
    gsap.to(heroBg, {
      yPercent: 22,
      ease: 'none',
      scrollTrigger: {
        trigger: '.hero',
        start: 'top top',
        end: 'bottom top',
        scrub: true,
      },
    });
  }
}

/** Initialize smooth scroll + scroll-triggered animations. Safe to call on any page. */
export async function initMotion() {
  if (prefersReducedMotion()) return;

  try {
    await loadScript(GSAP_SRC);
    await Promise.all([loadScript(SCROLLTRIGGER_SRC), loadScript(LENIS_SRC)]);

    const { gsap, ScrollTrigger } = window;
    if (!gsap) return;
    if (ScrollTrigger) gsap.registerPlugin(ScrollTrigger);

    initLenis();
    animateHero(gsap);
    if (ScrollTrigger) animateReveals(gsap, ScrollTrigger);
  } catch {
    // Offline or CDN blocked — page works without animations.
  }
}
