/**
 * Landing page controller.
 *
 * Replaces the old landing.js, which existed mainly to drive the hero tax
 * calculator. With the tax tool gone this page is purely marketing, so all
 * that is left is the shared chrome.
 */
import { initNav } from './nav.js';
import { initTheme } from './theme.js';
import { initMotion } from './motion.js';

initTheme();
initNav({ current: 'home' });
initMotion();
