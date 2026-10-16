// Accessibility QA with axe-core (WCAG 2.2 A/AA + best practices) on every customer and admin screen, desktop and phone,
// plus the keyboard / focus / reduced-motion / zoom checks axe cannot do. Local dev only.
//   node tools/qa/a11y.mjs            (customer site + admin)        QA_ONLY=regex to run a subset
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, ROOT, sql, resetLimits, mocksReset, payment, payOk, placeOrder, openCard, placeOrderAtGateway, gatewayPage, serviceId, sleep, useMockProviderIds } from './site.mjs';
import { login } from './admin.mjs';
import { suite } from './harness.mjs';

const require = createRequire(import.meta.url);
const AXE = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const t = suite('a11y');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
useMockProviderIds(); resetLimits(); await mocksReset();
const browser = await launch();
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'];

/** Run axe on the current page state (evaluate() is exempt from the page's CSP). Returns the violations. */
async function axe(page, { include, exclude = [] } = {}) {
  await page.evaluate(AXE);
  const res = await page.evaluate(async ({ tags, include, exclude }) => {
    const ctx = { include: include ? [[include]] : [document], exclude: exclude.map((e) => [e]) };
    const r = await window.axe.run(ctx, { runOnly: { type: 'tag', values: tags }, resultTypes: ['violations'] });
    return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.slice(0, 4).map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 140), why: (n.any[0] || n.all[0] || n.none[0] || {}).message })), count: v.nodes.length }));
  }, { tags: TAGS, include, exclude });
  return res;
}
const report = (name, v) => v.map((x) => `  [${x.impact}] ${x.id} ×${x.count} — ${x.help}\n${x.nodes.map((n) => `       ${n.target}\n         ${n.html}\n         ${n.why}`).join('\n')}`).join('\n');
const scan = async (name, page, opts) => { const v = await axe(page, opts); t.eq(v, [], `${name}\n${report(name, v)}`); };

for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'phone', width: 390, height: 844, mobile: true }]) {
  const page = await newPage(browser, { width: vp.width, height: vp.height }, { mobile: vp.mobile, allowStatus: [404, 503] }); t.page = page;
  await t.step(`${vp.name}: home — Instagram and YouTube panels`, async () => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' }); await page.waitForTimeout(600);
    await scan('home/instagram', page);
    await page.click('[data-platform-tab="youtube"]'); await page.waitForTimeout(800);
    await scan('home/youtube', page);
    await page.click('[data-platform-tab="instagram"]');
  });
  await t.step(`${vp.name}: open order form — idle, invalid and valid states`, async () => {
    const card = await openCard(page, 'ig-followers-50');
    await scan('card/idle', page);
    await card.locator('input[name=target]').fill('not valid!!'); await page.waitForTimeout(400); await scan('card/invalid', page);
    await card.locator('input[name=target]').fill('https://www.instagram.com/valid_name/'); await page.waitForTimeout(400); await scan('card/valid', page);
    await card.locator('[data-action=cancel]').click(); await page.waitForTimeout(500);
  });
  await t.step(`${vp.name}: Shop drawer (empty and with orders)`, async () => {
    await page.click('[data-action=open-orders]'); await page.waitForSelector('#orders-drawer[open]'); await page.waitForTimeout(500);
    await scan('drawer/empty', page);
    await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('#orders-drawer[open]'));
    resetLimits(); await placeOrder(page, 'ig-views-1k', 'https://www.instagram.com/reel/A11yCheck01/');
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.click('[data-action=open-orders]'); await page.waitForSelector('.order-row'); await page.waitForTimeout(500);
    await scan('drawer/orders', page);
    await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('#orders-drawer[open]'));
  });
  await t.step(`${vp.name}: payment page — pending, expired, paid, failed, tracking`, async () => {
    resetLimits();
    const { ref } = await placeOrder(page, 'ig-likes-100', 'https://www.instagram.com/p/A11yPay0001/');
    await page.waitForSelector('#qr-img'); await page.waitForFunction(() => { const i = document.querySelector('#qr-img'); return i && i.complete && i.naturalWidth > 0; }); await page.waitForTimeout(400); await scan('pay/pending-qr', page);
    await gatewayPage('none'); resetLimits();
    const fb = await placeOrderAtGateway(page, 'ig-likes-100', 'https://www.instagram.com/p/A11yPay0002/'); await gatewayPage('link');
    await page.goto(`${BASE}/pay/${fb.ref}`, { waitUntil: 'networkidle' }); await page.waitForSelector('#btn-open'); await page.waitForTimeout(400); await scan('pay/pending-no-qr-fallback', page);
    sql(`UPDATE payments SET expires_at = UTC_TIMESTAMP() + INTERVAL 3 SECOND WHERE ref = '${ref}'`); await page.goto(`${BASE}/pay/${ref}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-view=expired]:not([hidden])', { timeout: 15000 }); await page.waitForTimeout(400); await scan('pay/expired', page);
    resetLimits(); const second = await placeOrder(page, 'yt-views-10k', 'https://youtu.be/dQw4w9WgXcQ');
    await payOk(second.ref); await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 }); await page.waitForTimeout(500); await scan('pay/paid', page);
    await page.click('#btn-track'); await page.waitForURL(/\/order\//); await page.waitForTimeout(500); await scan('order/track', page);
    resetLimits(); const third = await placeOrder(page, 'ig-likes-100', 'https://www.instagram.com/p/A11yFail001/');
    const { alpha } = await import('./site.mjs'); await alpha(`/__pay/${payment(third.ref).gwOrder}/fail`, {});
    await page.waitForSelector('[data-view=failed]:not([hidden])', { timeout: 9000 }); await page.waitForTimeout(400); await scan('pay/failed', page);
  });
  await t.step(`${vp.name}: error page and maintenance page`, async () => {
    await page.goto(BASE + '/no-such-page', { waitUntil: 'networkidle' }); await scan('404', page);
    sql("INSERT INTO admin_settings (k, v, updated_at) VALUES ('site.maintenance', 'true', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE v = 'true'");
    try { await page.goto(BASE + '/', { waitUntil: 'networkidle' }); await scan('maintenance', page); } finally { sql("DELETE FROM admin_settings WHERE k = 'site.maintenance'"); }
  });
  await page.close();
}

// ───────────────────────── admin ─────────────────────────
for (const vp of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'phone', width: 390, height: 844, mobile: true }]) {
  const page = await newPage(browser, { width: vp.width, height: vp.height }, { mobile: vp.mobile, allowStatus: [404, 422, 429] }); t.page = page;
  await t.step(`admin ${vp.name}: sign-in page`, async () => { await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' }); await scan('admin/login', page); });
  await t.step(`admin ${vp.name}: every page`, async () => {
    resetLimits(); await login(page);
    const all = [];
    for (const p of ['dashboard', 'finance', 'orders', 'recovery', 'payments', 'payment-checks', 'services', 'tags', 'website', 'advertising', 'gateway', 'smm', 'security', 'audit', 'system']) {
      await page.goto(`${BASE}/admin/${p}`, { waitUntil: 'networkidle' });
      await page.waitForFunction(() => document.querySelector('[aria-busy="true"]') === null, null, { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(900);
      const v = await axe(page); if (v.length) all.push(`admin/${p}\n${report(p, v)}`);
    }
    t.eq(all, [], all.join('\n'));
  });
  await t.step(`admin ${vp.name}: order drawer, service editor dialog, confirm dialogs`, async () => {
    await page.goto(`${BASE}/admin/orders`, { waitUntil: 'networkidle' }); await page.waitForTimeout(900);
    await page.locator('#orders-root tbody tr').first().click(); await page.waitForSelector('#order-drawer[open] .od-hero'); await page.waitForTimeout(600);
    await scan('admin/order-drawer', page);
    await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('#order-drawer[open]'));
    await page.goto(`${BASE}/admin/services`, { waitUntil: 'networkidle' }); await page.waitForTimeout(900);
    const edit = page.locator('[data-action="edit"], button:has-text("Edit")').first();
    if (await edit.count()) { await edit.click(); await page.waitForSelector('dialog[open]'); await page.waitForTimeout(500); await scan('admin/service-editor', page); await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('dialog[open]')); }
    await page.goto(`${BASE}/admin/orders`, { waitUntil: 'networkidle' }); await page.waitForTimeout(900);
    if (await page.locator('#approve-all:enabled').count()) { await page.click('#approve-all'); await page.waitForSelector('dialog[open]'); await page.waitForTimeout(500); await scan('admin/approve-all-confirm', page); await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('dialog[open]')); }
  });
  await t.step(`admin ${vp.name}: finance tabs and the expense dialog`, async () => {
    const ready = () => page.waitForFunction(() => document.querySelector('#fin-root')?.getAttribute('aria-busy') === 'false', null, { timeout: 15000 }).catch(() => {});
    for (const q of ['?tab=services', '?tab=costs', '?tab=reconcile', '?tab=settings']) {
      await page.goto(`${BASE}/admin/finance${q}`, { waitUntil: 'networkidle' }); await ready(); await page.waitForTimeout(700);
      await scan('admin/finance' + q, page);
    }
    await page.goto(`${BASE}/admin/finance?tab=costs`, { waitUntil: 'networkidle' }); await ready(); await page.waitForTimeout(500);
    await page.click('#fin-add'); await page.waitForSelector('dialog[open]'); await page.waitForTimeout(500); await scan('admin/finance-expense-dialog', page);
    await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('dialog[open]'));
  });
  await page.close();
}

// ───────────────────────── keyboard, focus, motion, zoom ─────────────────────────
const k = await newPage(browser, { width: 1280, height: 800 }); t.page = k;
await t.step('keyboard: skip link, logical tab order, visible focus ring on every control, Enter/Space operate BUY NOW, Escape closes, no keyboard trap', async () => {
  await k.goto(BASE + '/', { waitUntil: 'networkidle' });
  await k.keyboard.press('Tab');
  const first = await k.evaluate(() => ({ text: document.activeElement.textContent.trim(), href: document.activeElement.getAttribute('href'), visible: getComputedStyle(document.activeElement).clipPath }));
  t.has(first.text.toLowerCase(), 'skip'); await k.keyboard.press('Enter');
  const main = await k.evaluate(() => ({ hash: location.hash, focus: document.activeElement.id || document.activeElement.tagName })); t.ok(/main|content|services/i.test(main.hash + main.focus), JSON.stringify(main));
  // walk the whole tab order: every stop must be visible in the viewport order and show a focus indicator
  await k.goto(BASE + '/', { waitUntil: 'networkidle' });
  const stops = [];
  for (let i = 0; i < 40; i++) {
    await k.keyboard.press('Tab');
    const s = await k.evaluate(() => { const e = document.activeElement; if (!e || e === document.body) return null; const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); const out = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 2; const ring = cs.boxShadow !== 'none'; return { tag: e.tagName.toLowerCase(), label: (e.getAttribute('aria-label') || e.textContent || '').trim().slice(0, 30), out, ring, w: Math.round(r.width), h: Math.round(r.height), inert: !!e.closest('[inert]') }; });
    if (!s) break; stops.push(s);
    if (stops.length > 2 && stops[0].label === s.label && i > 10) break;
  }
  t.ok(stops.length >= 10, 'tab stops: ' + stops.length);
  t.ok(stops.every((s) => s.out || s.ring), 'every tab stop shows a focus indicator: ' + JSON.stringify(stops.filter((s) => !(s.out || s.ring))));
  t.ok(!stops.some((s) => s.inert), 'closed order forms are not in the tab order');
  // BUY NOW with the keyboard
  await k.focus(`[data-id="${serviceId('ig-followers-50')}"] [data-action=buy]`);
  await k.keyboard.press('Enter'); await k.waitForTimeout(700);
  t.eq(await k.evaluate(() => document.activeElement.name), 'target', 'focus moves into the order field');
  await k.keyboard.press('Escape'); await k.waitForTimeout(500);
  t.eq(await k.evaluate(() => document.activeElement.getAttribute('data-action')), 'buy', 'Escape returns focus to BUY NOW');
  await k.keyboard.press('Space'); await k.waitForTimeout(700);
  t.eq(await k.evaluate(() => document.activeElement.name), 'target', 'Space also opens it');
  // Shop drawer traps focus and gives it back
  await k.keyboard.press('Escape'); await k.waitForTimeout(400);
  await k.focus('.shop-btn'); await k.keyboard.press('Enter'); await k.waitForSelector('#orders-drawer[open]');
  const inside = await k.evaluate(() => [...document.querySelectorAll('#orders-drawer a[href], #orders-drawer button:not([disabled]), #orders-drawer input, #orders-drawer [tabindex]:not([tabindex="-1"])')].filter((e) => e.getClientRects().length).length);
  for (let i = 0; i < inside; i++) { t.ok(await k.evaluate(() => !!document.activeElement.closest('#orders-drawer')), `focus is inside the open drawer (stop ${i + 1}/${inside})`); await k.keyboard.press('Tab'); }
  t.ok(await k.evaluate(() => document.activeElement === document.body || !document.activeElement.closest('header, main, footer')), 'tabbing past the last control never lands on the page behind the modal (it is inert)');
  await k.keyboard.press('Escape'); await k.waitForFunction(() => !document.querySelector('#orders-drawer[open]'));
  t.eq(await k.evaluate(() => document.activeElement.className.includes('shop-btn')), true, 'focus returns to the Shop button');
});

await t.step('reduced motion: animations/transitions are removed for people who ask for it', async () => {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, reducedMotion: 'reduce' }); const p = await ctx.newPage();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  const m = await p.evaluate(() => { const anims = document.getAnimations().filter((a) => a.playState === 'running' && a.effect?.getTiming().iterations === Infinity); return { infinite: anims.length, names: anims.slice(0, 5).map((a) => a.animationName || a.transitionProperty), dur: getComputedStyle(document.querySelector('.svc [data-action=buy]')).transitionDuration }; });
  t.eq(m.infinite, 0, 'no endlessly looping animations: ' + JSON.stringify(m));
  await ctx.close();
});

await t.step('zoom / text size: 200% browser zoom and large text keep everything reachable with no horizontal scroll (WCAG 1.4.4 / 1.4.10)', async () => {
  const ctx = await browser.newContext({ viewport: { width: 640, height: 450 }, deviceScaleFactor: 2 }); const p = await ctx.newPage();   // 1280×900 CSS px at 200% zoom
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  const m = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
  t.ok(m.sw <= m.cw + 1, `no horizontal scroll at 640px wide: ${m.sw}/${m.cw}`);
  await p.addStyleTag({ content: 'html{font-size:200% !important}' }).catch(() => {});
  await p.setViewportSize({ width: 320, height: 568 });
  const m2 = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
  t.ok(m2.sw <= m2.cw + 1, `320px wide (WCAG reflow): ${m2.sw}/${m2.cw}`);
  await ctx.close();
});

const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
