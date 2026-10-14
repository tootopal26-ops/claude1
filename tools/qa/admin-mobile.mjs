// Phone/tablet behaviour of the admin panel: navigation drawer, drawers/dialogs fit the screen, touch charts, tap targets.
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { launch, newPage, login, go, layoutReport, coveredControls, BASE } from './admin.mjs';
import { suite } from './harness.mjs';

const OUT = process.env.QA_OUT || '/tmp/qa';
mkdirSync(OUT, { recursive: true });
const t = suite('admin-mobile');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: '/home/user/claude1', stdio: 'ignore' });
const browser = await launch();
const UA = 'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';
const page = await newPage(browser, { width: 390, height: 844 }, { mobile: true, dpr: 2, ua: UA });
t.page = page;
const toast = (type) => page.locator(`.toast[data-type="${type}"]`).last();

await t.step('login page fits a phone: no overflow, big tap targets, password reveal works', async () => {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  const rep = await layoutReport(page);
  t.eq(rep.overflowX, false);
  for (const sel of ['#username', '#password', '#login-submit', '#pw-toggle']) {
    const b = await page.locator(sel).boundingBox();
    t.ok(b.height >= 40 && b.width >= 40, `${sel} ${Math.round(b.width)}x${Math.round(b.height)}`);
  }
  await page.waitForTimeout(550); await page.screenshot({ path: `${OUT}/m-login.png` });
  await page.fill('#username', 'owner'); await page.fill('#password', 'Sm8!Qx-Valley#2026');
  await page.tap('#pw-toggle');
  t.eq(await page.locator('#password').getAttribute('type'), 'text');
  await Promise.all([page.waitForURL('**/admin/dashboard'), page.tap('#login-submit')]);
});

await t.step('hamburger opens the navigation drawer; every link works; drawer closes on navigation', async () => {
  await go(page, '/admin/dashboard');
  t.eq(await page.locator('.adm-side').isVisible(), false, 'sidebar hidden on phones');
  await page.tap('[data-action="open-nav"]');
  await page.waitForSelector('#nav-dialog[open].is-open');
  await page.waitForTimeout(550); await page.screenshot({ path: `${OUT}/m-nav.png` });
  const links = await page.locator('#nav-dialog .adm-link').count();
  t.ok(links >= 11, 'links: ' + links);
  for (const b of await page.locator('#nav-dialog .adm-link').all()) { const bb = await b.boundingBox(); t.ok(bb.height >= 40, 'link height ' + bb.height); }
  await Promise.all([page.waitForURL('**/admin/orders'), page.tap('#nav-dialog a[href$="/admin/orders"]')]);
  t.ok(await page.locator('#nav-dialog[open]').count() === 0, 'drawer closed after navigation');
});

await t.step('nav drawer closes with its X button and by tapping outside', async () => {
  await page.tap('[data-action="open-nav"]');
  await page.waitForSelector('#nav-dialog[open].is-open');
  await page.tap('#nav-dialog [data-dialog-close]');
  await page.waitForFunction(() => !document.querySelector('#nav-dialog[open]'));
  await page.tap('[data-action="open-nav"]');
  await page.waitForSelector('#nav-dialog[open].is-open');
  await page.mouse.click(380, 400);
  await page.waitForFunction(() => !document.querySelector('#nav-dialog[open]'));
});

await t.step('sign out from the drawer works', async () => {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, userAgent: UA });
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/login'); await p.fill('#username', 'owner'); await p.fill('#password', 'Sm8!Qx-Valley#2026');
  await Promise.all([p.waitForURL('**/admin/dashboard'), p.tap('#login-submit')]);
  await p.tap('[data-action="open-nav"]');
  await p.waitForSelector('#nav-dialog[open].is-open');
  await Promise.all([p.waitForURL('**/admin/login**'), p.tap('#nav-dialog [data-action="logout"]')]);
  await ctx.close();
});

await t.step('orders on a phone: stacked cards, filter chips scroll, drawer fills the screen and scrolls, actions reachable', async () => {
  await go(page, '/admin/orders');
  t.eq((await layoutReport(page)).overflowX, false);
  t.ok(await page.locator('#orders-root thead').isHidden(), 'table header hidden (card layout)');
  await page.tap('#orders-root tbody tr:has-text("Manual pending") [data-open]');
  await page.waitForSelector('#order-drawer[open].is-open .od-hero');
  const box = await page.locator('#order-drawer .dlg-panel').boundingBox();
  t.ok(box.width >= 388, 'drawer is full width: ' + box.width);
  t.eq((await layoutReport(page)).overflowX, false, 'no horizontal overflow with the drawer open');
  await page.waitForTimeout(550); await page.screenshot({ path: `${OUT}/m-order-drawer.png` });
  const approve = page.locator('#od-body [data-act="approve"]');
  t.ok(await approve.isVisible(), 'approve visible');
  const ab = await approve.boundingBox();
  t.ok(ab.height >= 36, 'approve tap height ' + ab.height);
  await page.evaluate(() => { document.querySelector('#od-body').scrollTop = 99999; });
  t.ok(await page.locator('#od-note').isVisible(), 'note reachable by scrolling');
  await page.tap('#order-drawer [data-dialog-close]');
  await page.waitForFunction(() => !document.querySelector('#order-drawer[open]'));
});

await t.step('Approve All dialog fits a phone screen and its buttons are tappable', async () => {
  await page.tap('#approve-all');
  await page.waitForSelector('dialog.dlg-modal[open].is-open');
  const b = await page.locator('dialog.dlg-modal[open] .dlg-panel').boundingBox();
  t.ok(b.x >= 0 && b.x + b.width <= 390, `inside viewport x=${b.x} w=${b.width}`);
  await page.waitForTimeout(550); await page.screenshot({ path: `${OUT}/m-approve-all.png` });
  for (const sel of ['[data-act="yes"]', '[data-act="no"]:has-text("Cancel")']) { const bb = await page.locator(`dialog.dlg-modal[open] ${sel}`).boundingBox(); t.ok(bb.height >= 40, sel + ' ' + bb.height); }
  await page.tap('dialog.dlg-modal[open] [data-act="no"]:has-text("Cancel")');
  await page.waitForFunction(() => !document.querySelector('dialog.dlg-modal[open]'));
});

await t.step('service editor drawer on a phone: form usable, footer buttons always visible', async () => {
  await go(page, '/admin/services');
  await page.tap('#services-root tbody tr:first-child [data-edit]');
  await page.waitForSelector('#service-drawer[open].is-open #svc-form');
  t.eq((await layoutReport(page)).overflowX, false);
  const foot = await page.locator('#sd-foot').boundingBox();
  t.ok(foot.y + foot.height <= 844 + 1, 'footer inside the viewport');
  await page.waitForTimeout(550); await page.screenshot({ path: `${OUT}/m-service-editor.png` });
  await page.evaluate(() => { document.querySelector('#sd-body').scrollTop = 600; });
  await page.waitForTimeout(550); await page.screenshot({ path: `${OUT}/m-service-editor-2.png` });
  t.ok(await page.locator('#svc-save').isVisible(), 'save visible after scrolling');
  await page.tap('#service-drawer [data-dialog-close]');
});

await t.step('website: save bar appears on edit and stays reachable above the bottom edge', async () => {
  await go(page, '/admin/website');
  await page.fill('#w-name', 'Phone Edit');
  await page.waitForSelector('#savebar:not([hidden])');
  const b = await page.locator('#savebar').boundingBox();
  t.ok(b.y + b.height <= 844, 'inside viewport');
  await page.waitForTimeout(550); await page.screenshot({ path: `${OUT}/m-website-savebar.png` });
  await page.tap('#discard');
});

await t.step('dashboard charts respond to touch (tap shows the tooltip) and fit the width', async () => {
  await go(page, '/admin/dashboard');
  await page.locator('[data-chart="rev"]').scrollIntoViewIfNeeded();
  const box = await page.locator('[data-chart="rev"] svg').boundingBox();
  t.ok(box.width <= 390, 'chart width ' + box.width);
  await page.touchscreen.tap(box.x + box.width / 2, box.y + box.height / 2);
  await page.waitForSelector('[data-chart="rev"] .chart-tip.is-on');
  t.ok(/₹/.test(await page.locator('[data-chart="rev"] .chart-tip').innerText()));
});

await t.step('every admin page: no horizontal overflow at 360, 390, 412 and tablet 768/820 widths', async () => {
  for (const [w, h] of [[360, 800], [412, 915], [768, 1024], [820, 1180]]) {
    await page.setViewportSize({ width: w, height: h });
    for (const p of ['dashboard', 'orders', 'payments', 'services', 'website', 'gateway', 'smm', 'advertising', 'security', 'audit', 'system']) {
      await go(page, '/admin/' + p, { settle: 300 });
      const rep = await layoutReport(page);
      t.ok(!rep.overflowX && rep.wide.length === 0, `${p} @${w}: scrollW ${rep.scrollW} wide=${rep.wide.join(';')}`);
      // nothing may sit on top of a control (z-index / stray overlay): hit-test every visible control at the top, middle and bottom of the page
      const covered = [];
      for (const frac of [0, 0.5, 1]) {
        await page.evaluate((f) => window.scrollTo({ top: (document.documentElement.scrollHeight - window.innerHeight) * f, behavior: 'instant' }), frac);
        await page.waitForTimeout(120);
        covered.push(...await coveredControls(page));
      }
      t.eq([...new Set(covered)], [], `${p} @${w}: controls covered by other elements`);
      await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    }
  }
});

console.log('console/network problems:', page.problems.join('\n') || 'none');
await browser.close();
process.exit(t.finish() ? 1 : 0);
