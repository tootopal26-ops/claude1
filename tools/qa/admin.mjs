// Helpers for driving the admin panel in headless Chromium (QA only).
import { launch } from './browser.mjs';

export const BASE = process.env.BASE || 'http://127.0.0.1:8080';
export const CREDS = { user: process.env.ADMIN_USER || 'owner', pass: process.env.ADMIN_PASS || 'Sm8!Qx-Valley#2026' };

/** Open a context at a viewport and collect every console error, page error, CSP violation and failed request. */
export async function newPage(browser, viewport = { width: 1440, height: 900 }, opts = {}) {
  const ctx = await browser.newContext({ viewport, deviceScaleFactor: opts.dpr || 1, isMobile: !!opts.mobile, hasTouch: !!opts.mobile, userAgent: opts.ua });
  const page = await ctx.newPage();
  const problems = [];
  page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) problems.push(`[console.${m.type()}] ${m.text()}`); });
  page.on('pageerror', (e) => problems.push(`[pageerror] ${e.message}`));
  page.on('requestfailed', (r) => { if (!/favicon|__control/.test(r.url()) && r.failure()?.errorText !== 'net::ERR_ABORTED') problems.push(`[requestfailed] ${r.method()} ${r.url()} ${r.failure()?.errorText}`); });
  page.on('response', (r) => { if (r.status() >= 400 && !opts.allowStatus?.includes(r.status())) problems.push(`[http ${r.status()}] ${r.request().method()} ${r.url()}`); });
  page.problems = problems;
  return page;
}

export async function login(page, { remember = false } = {}) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('#username', CREDS.user);
  await page.fill('#password', CREDS.pass);
  if (remember) await page.check('#remember');
  await Promise.all([page.waitForURL('**/admin/dashboard', { timeout: 15000 }), page.click('#login-submit')]);
}

export async function go(page, path, { settle = 700 } = {}) {
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => document.querySelector('[aria-busy="true"]') === null, null, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(settle);
}

/** Layout health: horizontal overflow, elements wider than the viewport, tiny tap targets. */
export async function layoutReport(page) {
  return page.evaluate(() => {
    const vw = document.documentElement.clientWidth;
    const out = { vw, scrollW: document.documentElement.scrollWidth, overflowX: document.documentElement.scrollWidth > vw + 1, wide: [], small: [] };
    for (const el of document.querySelectorAll('body *')) {
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'none') continue;
      if (r.right > vw + 1 && !el.closest('.tbl-scroll, .chips, .seg, dialog:not([open]), .toasts, .skip-link') && cs.position !== 'fixed') out.wide.push(`${el.tagName.toLowerCase()}.${String(el.className).split(' ').slice(0, 2).join('.')} right=${Math.round(r.right)}`);
      if (['A', 'BUTTON', 'INPUT', 'SELECT'].includes(el.tagName) && !el.disabled && (r.width < 24 || r.height < 24) && !el.closest('dialog:not([open])') && el.type !== 'checkbox' && el.type !== 'hidden' && !el.closest('.switch')) out.small.push(`${el.tagName.toLowerCase()} "${(el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 24)}" ${Math.round(r.width)}x${Math.round(r.height)}`);
    }
    out.wide = [...new Set(out.wide)].slice(0, 12);
    out.small = [...new Set(out.small)].slice(0, 12);
    return out;
  });
}

/**
 * "Unreachable buttons / incorrect z-index": every visible control that is inside the viewport must be what the user actually hits when they
 * press at its centre (nothing — a sticky bar, a toast, a stray overlay — may sit on top of it). Controls under the sticky header while
 * scrolling, and the page behind an open modal, are exempt.
 */
export async function coveredControls(page) {
  return page.evaluate(() => {
    const vw = document.documentElement.clientWidth, vh = window.innerHeight;
    const name = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
    const open = document.querySelector('dialog[open]');
    // any sticky/fixed bar pinned to the top edge (storefront header, admin mobile bar …): content scrolling underneath it is normal
    let headerBottom = 0;
    for (const e of document.querySelectorAll('header, nav, [role=banner], body > *')) {
      const c = getComputedStyle(e); const b = e.getBoundingClientRect();
      if (['sticky', 'fixed'].includes(c.position) && b.top <= 1 && b.width >= vw * 0.9 && b.height < 160 && b.height > 0) headerBottom = Math.max(headerBottom, b.bottom);
    }
    const out = [];
    for (const el of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=tab], summary')) {
      if (el.disabled || el.closest('[inert], [hidden], .sr-only, .skip-link') || (open && !open.contains(el))) continue;
      const cs = getComputedStyle(el); const r = el.getBoundingClientRect();
      if (cs.display === 'none' || cs.visibility === 'hidden' || r.width === 0 || r.height === 0 || parseFloat(cs.opacity) === 0) continue;
      if (r.bottom <= headerBottom + 1 || r.top >= vh || r.right <= 0 || r.left >= vw) continue;
      const x = r.left + r.width / 2, y = r.top + r.height / 2;
      if (x < 0 || x >= vw || y <= headerBottom + 1 || y >= vh) continue;
      const top = document.elementFromPoint(x, y);
      if (!top || el === top || el.contains(top) || top.contains(el) || (el.labels && [...el.labels].some((l) => l.contains(top)))) continue;
      out.push(`${name(el)} "${(el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 24)}" is covered by ${name(top)}`);
    }
    return [...new Set(out)].slice(0, 12);
  });
}

export { launch };
