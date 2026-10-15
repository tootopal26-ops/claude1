// Customer-site layout QA at every required viewport: measures sizes, overflow, clipping, tap targets, font sizes, line heights, z-index/off-screen
// problems and takes screenshots (kept in $QA_OUT, default /tmp/qa/layout) for visual review. Covers home (both platforms), an expanded card
// (valid + invalid), the Shop drawer, the payment page (pending, expired, paid), the order page, the 404 page and maintenance mode.
import { execFileSync } from 'node:child_process';
import { coveredControls } from './admin.mjs';
import { launch, newPage, BASE, VIEWPORTS, sql, resetLimits, mocksReset, payment, payOk, placeOrder, placeOrderAtGateway, gatewayPage, openCard, serviceId, sleep, useMockProviderIds } from './site.mjs';
import { suite } from './harness.mjs';

const OUT = process.env.QA_OUT || '/tmp/qa/layout';
const t = suite('site-layout', OUT);
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: '/home/user/claude1', stdio: 'ignore' });
useMockProviderIds(); await mocksReset();
const only = process.env.ONLY ? process.env.ONLY.split(',') : null;
const browser = await launch();

/** Everything that must never happen on a page, collected in one pass. */
const audit = (page) => page.evaluate(() => {
  const vw = document.documentElement.clientWidth, vh = window.innerHeight;
  const issues = [];
  const name = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
  const doc = document.documentElement;
  if (doc.scrollWidth > vw + 1) issues.push(`horizontal overflow: scrollWidth ${doc.scrollWidth} > ${vw}`);
  if (document.body.scrollWidth > vw + 1) issues.push(`body overflow ${document.body.scrollWidth} > ${vw}`);
  const visible = (el) => { const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width > 0 && r.height > 0 && !el.closest('[hidden], dialog:not([open]), [inert], .sr-only, .skip-link'); };
  for (const el of document.querySelectorAll('body *')) {
    if (!visible(el)) continue;
    const r = el.getBoundingClientRect(); const cs = getComputedStyle(el);
    if (cs.position !== 'fixed' && (r.right > vw + 1 || r.left < -1) && !el.closest('.toasts, .tbl-scroll, svg')) issues.push(`off-screen x: ${name(el)} left=${Math.round(r.left)} right=${Math.round(r.right)}`);
    // clipped text: a block whose text is wider/taller than its box while overflow is hidden/clip (ellipsis/line-clamp opt-ins are allowed)
    if (['hidden', 'clip'].includes(cs.overflowX) && cs.textOverflow !== 'ellipsis' && !el.closest('svg') && el.textContent.trim() && [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) {
      // measure the TEXT itself (decorative pseudo-elements such as the button shine may legitimately be wider than the box)
      const range = document.createRange(); range.selectNodeContents(el);
      const text = range.getBoundingClientRect();
      if (text.width > el.clientWidth + 1 || text.right > r.right + 1 || text.left < r.left - 1) issues.push(`clipped text: ${name(el)} "${el.textContent.trim().slice(0, 30)}" text ${Math.round(text.width)} > box ${el.clientWidth}`);
    }
    if (el.matches('a, button, input, select, textarea, [role=tab], summary') && !el.disabled && el.type !== 'hidden') {
      const minSide = Math.min(r.width, r.height);
      const inline = el.matches('p a, li a, .footer a, label a');
      if (minSide < 24 && !inline && !el.closest('.switch')) issues.push(`tiny tap target: ${name(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
    }
    if (el.matches('p, span, a, button, label, h1, h2, h3, h4, li, small, b, strong') && el.childNodes.length && [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) {
      const fs = parseFloat(cs.fontSize); const lh = cs.lineHeight === 'normal' ? fs * 1.2 : parseFloat(cs.lineHeight);
      if (fs < 11) issues.push(`tiny font ${fs}px: ${name(el)} "${el.textContent.trim().slice(0, 20)}"`);
      if (lh < fs * 1.05 && r.height > lh * 1.6 && cs.display !== 'flex' && cs.display !== 'inline-flex') issues.push(`cramped multi-line text, line-height ${lh}/${fs}: ${name(el)}`);
      const op = parseFloat(cs.opacity); if (op < 0.2) issues.push(`nearly invisible text: ${name(el)} opacity ${op}`);
    }
  }
  // overlapping interactive elements (two different buttons/links whose boxes intersect substantially)
  const ints = [...document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea')].filter(visible).map((el) => ({ el, r: el.getBoundingClientRect() }));
  for (let i = 0; i < ints.length; i++) for (let j = i + 1; j < ints.length; j++) {
    const a = ints[i], b = ints[j];
    if (a.el.contains(b.el) || b.el.contains(a.el)) continue;
    const w = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left), h = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
    if (w > 4 && h > 4) issues.push(`overlap: ${name(a.el)} × ${name(b.el)} (${Math.round(w)}x${Math.round(h)})`);
  }
  return { vw, vh, issues: [...new Set(issues)].slice(0, 25), scrollH: doc.scrollHeight };
});

const metrics = (page, sel) => page.$eval(sel, (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), fs: cs.fontSize, lh: cs.lineHeight, pad: cs.padding, gap: cs.gap, radius: cs.borderRadius }; });

const shots = [];
for (const vp of VIEWPORTS) {
  if (only && !only.includes(vp.name)) continue;
  const page = await newPage(browser, { width: vp.width, height: vp.height }, { mobile: vp.mobile, dpr: vp.mobile ? 2 : 1 });
  t.page = page;
  const shot = async (label) => { const f = `${OUT}/${vp.name}-${label}.png`; await page.screenshot({ path: f }); shots.push(f); };
  const mobile = vp.width < 700;
  const clean = (label) => t.eq(page.problems.filter((p) => !/favicon/.test(p)), [], `${vp.name} ${label}: ` + page.problems.join('\n'));

  await t.step(`${vp.name}: home — header, platform buttons and service grid are laid out without overflow, clipping or overlap`, async () => {
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.waitForTimeout(500);
    const a = await audit(page); t.eq(a.issues, [], JSON.stringify(a.issues, null, 1));
    const header = await metrics(page, '.site-header-bar'); const brand = await metrics(page, '.brand'); const shop = await metrics(page, '.shop-btn'); const logo = await metrics(page, '.brand-logo');
    t.ok(header.h >= 52 && header.h <= 96, `header height ${header.h}`);
    t.ok(Math.abs(logo.w - logo.h) <= 1 && logo.w >= 38, `logo ${logo.w}x${logo.h}`);
    t.ok(shop.w >= 40 && shop.h >= 40, `shop ${shop.w}x${shop.h}`);
    t.ok(shop.x + shop.w <= vp.width - 8 && brand.x >= 8, `header edges: brand.x=${brand.x} shop.right=${shop.x + shop.w}`);
    const tabs = await page.$$eval('[data-platform-tab]', (els) => els.map((e) => { const r = e.getBoundingClientRect(); return { w: r.width, h: r.height, x: r.x, y: r.y }; }));
    t.eq(tabs.length, 2);
    t.ok(tabs.every((x) => x.h >= (mobile ? 64 : 100)), 'platform buttons are large: ' + JSON.stringify(tabs));
    t.ok(Math.abs(tabs[0].w - tabs[1].w) <= tabs[1].w * 0.05, 'platform buttons are (nearly) equal width — the selected one is scaled up by the spring animation: ' + JSON.stringify(tabs.map((x) => Math.round(x.w))));
    t.ok(tabs[0].y >= header.y + header.h - 2 && tabs[0].y < header.y + header.h + 60, 'platform buttons sit immediately below the header');
    const cols = await page.$$eval('[data-panel]:not([hidden]) .svc-grid', (gs) => gs.map((g) => getComputedStyle(g).gridTemplateColumns.split(' ').length));
    t.ok(cols.every((c) => c === (vp.width >= 640 ? 2 : 1)), `service grid columns ${cols} at ${vp.width}`);
    const cards = await page.$$eval('[data-panel]:not([hidden]) [data-service]', (els) => els.map((e) => { const r = e.getBoundingClientRect(); const t = e.querySelector('.svc-title'); const p = e.querySelector('.svc-price'); return { w: r.width, h: r.height, titleFs: parseFloat(getComputedStyle(t).fontSize), priceFs: parseFloat(getComputedStyle(p.firstElementChild).fontSize), btnH: e.querySelector('[data-action=buy]').getBoundingClientRect().height, price: p.textContent.trim() }; }));
    t.ok(cards.every((c) => c.btnH >= 44), 'BUY NOW is at least 44px tall: ' + cards.map((c) => c.btnH));
    t.ok(cards.every((c) => c.titleFs >= 16 && c.priceFs >= 18), 'title/price font sizes readable');
    t.ok(cards.every((c) => c.w >= (mobile ? 280 : 340)), 'card widths: ' + cards.map((c) => Math.round(c.w)));
    // z-index / unreachable controls: hit-test at the top, middle and bottom of the page
    const covered = [];
    for (const frac of [0, 0.35, 0.7, 1]) {
      await page.evaluate((f) => window.scrollTo({ top: (document.documentElement.scrollHeight - window.innerHeight) * f, behavior: 'instant' }), frac);
      await page.waitForTimeout(150); covered.push(...await coveredControls(page));
    }
    t.eq([...new Set(covered)], [], 'controls covered by something else (z-index): ' + covered.join(' | '));
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await shot('home-instagram');
    clean('home');
  });

  await t.step(`${vp.name}: YouTube list incl. premium watch-time card`, async () => {
    await page.click('[data-platform-tab="youtube"]'); await page.waitForTimeout(700);
    const a = await audit(page); t.eq(a.issues, [], JSON.stringify(a.issues, null, 1));
    const prem = await page.$eval(`[data-id="${serviceId('yt-watch-4000h')}"]`, (e) => { const r = e.getBoundingClientRect(); return { w: r.width, h: r.height, x: r.x, grid: e.parentElement.getBoundingClientRect().width }; });
    t.ok(prem.w >= prem.grid - 2, `premium card spans the whole row: ${prem.w} of ${prem.grid}`);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await page.waitForTimeout(300);
    await shot('home-youtube-bottom');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click('[data-platform-tab="instagram"]');
  });

  await t.step(`${vp.name}: expanded order card — form fits, input/button sizes OK, invalid + valid messages visible, virtual-keyboard safe`, async () => {
    const card = await openCard(page, 'ig-followers-50');
    await card.locator('input[name=target]').fill('bad value!'); await page.waitForTimeout(350);
    const a1 = await audit(page); t.eq(a1.issues, [], JSON.stringify(a1.issues));
    const inp = await metrics(page, `[data-id="${serviceId('ig-followers-50')}"] input[name=target]`);
    t.ok(inp.h >= 44, 'input height ' + inp.h); t.ok(parseFloat(inp.fs) >= 16, 'input font ≥ 16px avoids iOS zoom: ' + inp.fs);
    await shot('card-invalid');
    await card.locator('input[name=target]').fill('https://www.instagram.com/growth.account/'); await page.waitForTimeout(350);
    t.eq(await card.locator('.field-msg').getAttribute('data-state'), 'valid', 'a profile link is accepted for Followers');
    const proceed = await card.locator('[data-action=proceed]').boundingBox();
    t.ok(proceed.height >= 44 && proceed.width >= 200, `PROCEED ${proceed.width}x${proceed.height}`);
    const inView = await card.locator('[data-action=proceed]').evaluate((e) => { const r = e.getBoundingClientRect(); return r.bottom <= window.innerHeight + 1 || true; });
    await card.locator('[data-action=proceed]').scrollIntoViewIfNeeded();
    const vis = await card.locator('[data-action=proceed]').evaluate((e) => { const r = e.getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; });
    t.ok(vis, 'PROCEED TO PAYMENT can be scrolled fully into view');
    t.eq(await coveredControls(page), [], 'the open order form is not covered by the header, toasts or neighbours');
    await shot('card-valid');
    const a2 = await audit(page); t.eq(a2.issues, [], JSON.stringify(a2.issues));
    await card.locator('[data-action=cancel]').click(); await page.waitForTimeout(500);
  });

  await t.step(`${vp.name}: Shop drawer fits the viewport and is fully reachable`, async () => {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click('[data-action=open-orders]'); await page.waitForSelector('#orders-drawer[open]'); await page.waitForTimeout(500);
    const d = await page.$eval('#orders-drawer .dlg-panel', (e) => { const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, r: r.right, b: r.bottom, w: r.width, h: r.height }; });
    t.ok(d.x >= -1 && d.r <= vp.width + 1 && d.y >= -1 && d.b <= vp.height + 1, 'drawer inside the viewport: ' + JSON.stringify(d));
    try {
      const closeBox = await page.locator('#orders-drawer [data-dialog-close][aria-label=Close]').boundingBox(); t.ok(closeBox.width >= 43.5 && closeBox.height >= 43.5, `close button ${closeBox.width}x${closeBox.height} (touch target ≥ 44 px)`);
        t.eq(await coveredControls(page), [], 'inside the open drawer every control is on top (the page behind is inert)');
      const top = await page.evaluate(() => { const d = document.querySelector('#orders-drawer .dlg-panel').getBoundingClientRect(); const e = document.elementFromPoint(d.left + d.width / 2, d.top + 60); return !!e?.closest('#orders-drawer'); });
      t.ok(top, 'the drawer is the top layer above the header');
      await shot('drawer');
    } finally { await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('#orders-drawer[open]')); }
  });

  await t.step(`${vp.name}: payment page (QR, the no-QR fallback, expired, paid) — one centred white card, QR, download button and timer visible, no overflow`, async () => {
    resetLimits();
    const { ref } = await placeOrder(page, 'ig-likes-100', 'https://www.instagram.com/p/LayoutCheck1/');
    await page.waitForSelector('#qr-img'); await page.waitForFunction(() => { const i = document.querySelector('#qr-img'); return i && i.complete && i.naturalWidth > 0; }); await page.waitForTimeout(500);
    const a = await audit(page); t.eq(a.issues, [], JSON.stringify(a.issues));
    const qr = await metrics(page, '#qr-img'); const timer = await metrics(page, '#timer-value'); const dl = await metrics(page, '#btn-download');
    t.ok(qr.w >= 220 && qr.w <= 360 && qr.w === qr.h, `QR ${qr.w}x${qr.h}`);
    t.ok(parseFloat(timer.fs) >= 28, 'timer font ' + timer.fs); t.ok(dl.h >= 44, 'download button ' + dl.h);
    t.eq(await coveredControls(page), [], 'payment page controls are reachable');
    const above = await page.evaluate(() => ({ qrTop: document.querySelector('#qr-img').getBoundingClientRect().top, vh: window.innerHeight }));
    t.ok(above.qrTop < above.vh * 0.7, 'QR starts in the first screen: ' + above.qrTop);
    const g = await page.evaluate(() => {
      const r = (s) => { const b = document.querySelector(s).getBoundingClientRect(); return { l: b.left, r: b.right, t: b.top, b: b.bottom }; };
      return { head: r('.pay-title'), qr: r('#qr-frame'), dl: r('#btn-download'), tm: r('#timer'), box: r('#pay-box'), card: r('#pay-root'), vw: document.documentElement.clientWidth };
    });
    t.ok(g.head.b <= g.qr.t && g.qr.b <= g.dl.t + 1 && g.dl.b <= g.tm.t + 1, 'order: PAY SECURELY, QR, DOWNLOAD QR CODE, timer');
    t.ok(Math.abs((g.card.l + g.card.r) / 2 - g.vw / 2) < 14 && g.card.l >= 0 && g.card.r <= g.vw + 1, 'the payment card is centred and inside the screen');
    t.ok(g.box.l >= g.card.l && g.box.r <= g.card.r && g.qr.l >= g.box.l && g.qr.r <= g.box.r, 'the QR sits inside the white card, inside the payment card');
    t.eq(await page.locator('#btn-open').isVisible(), vp.mobile ? true : false, vp.mobile ? 'OPEN UPI APP is offered on a phone' : 'no OPEN UPI APP on a desktop');
    await shot('pay-pending');
    // the gateway's page carried no QR: checkout went to the gateway; our page for the order is one button inside the same card
    await gatewayPage('none'); resetLimits();
    const hosted = await placeOrderAtGateway(page, 'ig-likes-100', 'https://www.instagram.com/p/LayoutCheck2/'); await gatewayPage('link');
    await page.goto(`${BASE}/pay/${hosted.ref}`, { waitUntil: 'networkidle' }); await page.waitForSelector('#btn-open'); await page.waitForTimeout(400);
    const h = await page.evaluate(() => { const c = document.querySelector('#pay-box').getBoundingClientRect(); const b = document.querySelector('#btn-open').getBoundingClientRect(); return { cw: c.width, cl: c.left, cr: c.right, vw: document.documentElement.clientWidth, btnH: b.height, btnTop: b.top, vh: innerHeight }; });
    t.ok(h.cw >= 260 && h.cl >= 0 && h.cr <= h.vw, `fallback box fits: ${Math.round(h.cw)} wide in ${h.vw}`);
    t.ok(h.btnH >= 44, 'CONTINUE TO PAYMENT button ' + h.btnH); t.ok(h.btnTop < h.vh * 1.05, 'the button is within the first screen: ' + Math.round(h.btnTop) + ' / ' + h.vh);
    t.eq(await page.locator('#qr-img').count(), 0, 'no QR image when there is none');
    const ah = await audit(page); t.eq(ah.issues, [], JSON.stringify(ah.issues));
    t.eq(await coveredControls(page), [], 'fallback payment page controls are reachable');
    await shot('pay-hosted');
    // expired
    sql(`UPDATE payments SET expires_at = UTC_TIMESTAMP() + INTERVAL 4 SECOND WHERE ref = '${ref}'`);
    await page.goto(`${BASE}/pay/${ref}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('[data-view=expired]:not([hidden])', { timeout: 12000 }); await page.waitForTimeout(400);
    const a2 = await audit(page); t.eq(a2.issues, [], JSON.stringify(a2.issues));
    await shot('pay-expired');
    // paid (new order)
    resetLimits();
    const second = await placeOrder(page, 'yt-views-10k', 'https://youtu.be/dQw4w9WgXcQ');
    await payOk(second.ref);
    await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 }); await page.waitForTimeout(600);
    const a3 = await audit(page); t.eq(a3.issues, [], JSON.stringify(a3.issues));
    await shot('pay-paid');
    await page.click('#btn-track'); await page.waitForURL(/\/order\//); await page.waitForTimeout(500);
    const a4 = await audit(page); t.eq(a4.issues, [], JSON.stringify(a4.issues));
    await shot('order-track');
    clean('payment flow');
  });

  await t.step(`${vp.name}: error page (404) and maintenance mode render cleanly`, async () => {
    await page.goto(BASE + '/definitely-not-a-page', { waitUntil: 'networkidle' });
    const a = await audit(page); t.eq(a.issues, [], JSON.stringify(a.issues));
    await shot('404');
    sql("INSERT INTO admin_settings (k, v, updated_at) VALUES ('site.maintenance', 'true', UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE v = 'true'");
    try {
      await page.goto(BASE + '/', { waitUntil: 'networkidle' });
      const a2 = await audit(page); t.eq(a2.issues, [], JSON.stringify(a2.issues));
      await shot('maintenance');
    } finally { sql("DELETE FROM admin_settings WHERE k = 'site.maintenance'"); }
  });
  await page.close();
}

console.log(`\nscreenshots: ${OUT} (${shots.length} files)`);
const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
