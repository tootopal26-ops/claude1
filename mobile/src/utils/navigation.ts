import { router, type Href } from 'expo-router';

let lastPush = 0;

/**
 * Push a route once: a second tap within a short window (a double tap, or a tap that
 * lands while the first transition is still running) is ignored, so a screen never
 * stacks on top of itself.
 */
export function pushOnce(href: Href, windowMs = 600) {
  const now = Date.now();
  if (now - lastPush < windowMs) return;
  lastPush = now;
  router.push(href);
}
