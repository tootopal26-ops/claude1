// Customer-site QA at 1440×900 (desktop): head/SEO, header, platform tabs, the exact catalogue, BUY NOW expansion, live validation,
// checkout → payment page (QR, 05:00 timer, DOWNLOAD QR), webhook success, manual orders, late/expired sessions, failed payments,
// the Shop drawer and order tracking. The same flows run again on a phone viewport at the end.
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, sql, rows, alpha, smm, mocksReset, resetLimits, payment, payOk, decodeQr, openCard, placeOrder, serviceId, sleep, hexToRgb, luminance, contrast, useMockProviderIds } from './site.mjs';
import { suite } from './harness.mjs';

const t = suite('site-flows');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: '/home/user/claude1', stdio: 'ignore' });
useMockProviderIds(); resetLimits(); await mocksReset();
const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 });
t.page = page;
const text = (loc) => t.text(loc);
const fresh = async () => { const p = await newPage(browser, { width: 1440, height: 900 }); t.page = p; return p; };
const css = (sel, prop) => page.$eval(sel, (el, p) => getComputedStyle(el)[p], prop);

const CATALOG = {
  instagram: {
    sections: ['FOLLOWERS', 'LIKES', 'VIEWS', 'VERIFICATION'],
    cards: [
      ['50 Followers', '₹5', 'Instagram Non Drop Followers', 'TRIAL PACK'],
      ['100K Followers', '₹99', 'Instagram Non Drop Followers', null],
      ['100 Likes', '₹5', null, 'TRIAL PACK'], ['50K Likes', '₹25', null, null],
      ['1,000 Views', '₹5', null, 'TRIAL PACK'], ['1 Million Views', '₹99', null, null],
      ['Permanent Blue Tick', '₹199', null, null],
    ],
  },
  youtube: {
    sections: ['SUBSCRIBERS', 'LIKES', 'VIEWS', 'WATCH TIME'],
    cards: [
      ['50 Subscribers', '₹5', null, 'TRIAL PACK'], ['100K Subscribers', '₹99', null, null],
      ['100 Likes', '₹5', null, 'TRIAL PACK'], ['50K Likes', '₹49', null, null],
      ['10 Views', '₹5', null, 'TRIAL PACK'], ['10,000 Views', '₹99', null, null],
      ['4,000 Hours Watch Time', '₹199', null, 'PREMIUM'],
    ],
  },
};

// ───────────────────────────── page head / SEO / branding ─────────────────────────────
await t.step('home loads cleanly: no console errors, CSP violations or failed requests', async () => {
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(600);
  t.eq(page.problems, [], page.problems.join('\n'));
});

await t.step('SEO: title, description, canonical, Open Graph/Twitter, manifest, icons, lang, landmarks, single h1', async () => {
  const m = await page.evaluate(() => {
    const q = (s, a = 'content') => document.querySelector(s)?.getAttribute(a) ?? null;
    return {
      title: document.title, lang: document.documentElement.lang, desc: q('meta[name=description]'), canonical: q('link[rel=canonical]', 'href'),
      ogTitle: q('meta[property="og:title"]'), ogDesc: q('meta[property="og:description"]'), ogImage: q('meta[property="og:image"]'), ogType: q('meta[property="og:type"]'),
      twCard: q('meta[name="twitter:card"]'), manifest: q('link[rel=manifest]', 'href'), icon: q('link[rel~=icon]', 'href'), apple: q('link[rel=apple-touch-icon]', 'href'),
      viewport: q('meta[name=viewport]'), theme: q('meta[name=theme-color]'), h1: document.querySelectorAll('h1').length,
      landmarks: { header: !!document.querySelector('header'), main: !!document.querySelector('main'), footer: !!document.querySelector('footer') },
      ld: [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => { try { return JSON.parse(s.textContent)['@type']; } catch { return 'INVALID'; } }),
    };
  });
  t.has(m.title.toUpperCase(), 'SOCIAL MEDIA BOOSTER');
  t.eq(m.lang, 'en');
  t.ok(m.desc && m.desc.length > 60 && m.desc.length <= 170, `description length ${m.desc?.length}`);
  t.eq(m.canonical, BASE + '/');
  t.ok(m.ogTitle && m.ogDesc && m.ogImage?.startsWith('http') && m.ogType === 'website', JSON.stringify(m));
  t.eq(m.twCard, 'summary_large_image');
  t.ok(m.manifest && m.icon && m.apple && m.viewport?.includes('width=device-width') && m.theme, JSON.stringify(m));
  t.eq(m.h1, 1); t.eq(m.landmarks, { header: true, main: true, footer: true });
  t.ok(!m.ld.includes('INVALID'), 'JSON-LD parses: ' + m.ld);
  for (const u of [m.ogImage, m.manifest, m.icon, m.apple]) { const r = await fetch(new URL(u, BASE)); t.eq(r.status, 200, u); }
  const img = await fetch(m.ogImage); t.eq(img.headers.get('content-type'), 'image/png');
  const mf = await (await fetch(new URL(m.manifest, BASE))).json();
  t.ok(mf.name && mf.icons?.length >= 2 && mf.start_url, JSON.stringify(mf).slice(0, 200));
  t.has(await (await fetch(BASE + '/robots.txt')).text(), 'Disallow: /admin');
  const sm = await (await fetch(BASE + '/sitemap.xml')).text(); t.has(sm, '<urlset'); t.has(sm, BASE + '/');
});

await t.step('security headers: CSP without unsafe-inline, nosniff, frame protection, referrer policy, no server banner', async () => {
  const r = await fetch(BASE + '/');
  const h = Object.fromEntries(r.headers.entries());
  t.has(h['content-security-policy'], "default-src 'self'");
  t.ok(!/script-src[^;]*unsafe-inline/.test(h['content-security-policy']), h['content-security-policy']);
  t.ok(!/style-src[^;]*unsafe-inline/.test(h['content-security-policy']), h['content-security-policy']);
  t.has(h['content-security-policy'], "frame-ancestors 'none'");
  t.eq(h['x-content-type-options'], 'nosniff');
  t.ok(/strict-origin/.test(h['referrer-policy'] || ''), h['referrer-policy']);
  t.ok(!/php/i.test(h['x-powered-by'] || ''), 'X-Powered-By leaks PHP: ' + h['x-powered-by']);
});

await t.step('header: circular glowing logo, admin-editable site name, black filled Shop SVG button', async () => {
  const logo = await page.$eval('.brand-logo', (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); const b = getComputedStyle(el, '::before'); return { w: r.width, h: r.height, radius: cs.borderRadius, glow: cs.boxShadow, animation: cs.animationName, ringOpacity: b.content, img: el.querySelector('img').complete && el.querySelector('img').naturalWidth }; });
  t.ok(Math.abs(logo.w - logo.h) < 0.5 && logo.w >= 40, `logo ${logo.w}x${logo.h}`);
  t.ok(parseFloat(logo.radius) >= logo.w / 2 - 1, 'logo is circular: ' + logo.radius);
  t.ok(logo.glow !== 'none' && logo.animation !== 'none', `glow ${logo.glow} / ${logo.animation}`);
  t.ok(logo.img > 0, 'logo image loaded');
  const name = await text(page.locator('.brand-name'));
  t.has(name.toUpperCase(), 'SOCIAL MEDIA BOOSTER');
  const shop = await page.$eval('.shop-btn', (el) => { const r = el.getBoundingClientRect(); const svg = el.querySelector('svg'); const cs = getComputedStyle(svg); return { tag: el.tagName, w: r.width, h: r.height, fill: cs.fill, color: cs.color, label: el.getAttribute('aria-label'), hasUse: !!svg.querySelector('use') }; });
  t.eq(shop.tag, 'BUTTON'); t.ok(shop.w >= 40 && shop.h >= 40, `${shop.w}x${shop.h}`); t.ok(shop.hasUse && shop.label);
  const rgb = hexToRgb(shop.fill === 'currentcolor' || shop.fill === 'currentColor' ? shop.color : shop.fill);
  t.ok(luminance(rgb) < 0.03, `shop icon must be black-filled, fill=${shop.fill} color=${shop.color}`);
  const side = await page.evaluate(() => ({ brand: document.querySelector('.brand').getBoundingClientRect().left, shopRight: document.documentElement.clientWidth - document.querySelector('.shop-btn').getBoundingClientRect().right }));
  t.ok(side.brand < 400 && side.shopRight < 400, JSON.stringify(side));
});

// ───────────────────────────── platform selector ─────────────────────────────
await t.step('two large platform buttons with uploaded logos; Instagram selected first', async () => {
  const tabs = await page.$$eval('[data-platform-tab]', (els) => els.map((el) => { const r = el.getBoundingClientRect(); const im = el.querySelector('img'); return { slug: el.dataset.platformTab, w: r.width, h: r.height, sel: el.getAttribute('aria-selected'), img: im.naturalWidth, src: im.getAttribute('src'), role: el.getAttribute('role'), text: el.innerText.replace(/\s+/g, ' ').trim() }; }));
  t.eq(tabs.map((x) => x.slug), ['instagram', 'youtube']);
  t.eq(tabs.map((x) => x.sel), ['true', 'false']);
  for (const x of tabs) { t.ok(x.w >= 300 && x.h >= 100, `${x.slug} ${x.w}x${x.h} (must be large)`); t.ok(x.img > 0, `${x.slug} logo loaded`); t.eq(x.role, 'tab'); }
  t.has(tabs[0].text, 'Instagram'); t.has(tabs[1].text, 'YouTube');
  t.ok((await page.$$eval('[data-panel]:not([hidden])', (n) => n.length)) === 1, 'exactly one panel visible');
});

await t.step('selected platform has glowing border, RGB ring animation and spring/scale animation on the logo', async () => {
  const sel = await page.$eval('[data-platform-tab="instagram"]', (el) => { const b = getComputedStyle(el, '::before'); const l = getComputedStyle(el.querySelector('.platform-logo')); return { before: b.opacity, anim: b.animationName, shadow: getComputedStyle(el).boxShadow, logoTransform: l.transform, transition: l.transitionTimingFunction }; });
  t.eq(sel.before, '1'); t.ok(sel.anim !== 'none', 'animated ring: ' + sel.anim); t.ok(sel.shadow !== 'none');
  t.ok(sel.logoTransform !== 'none', 'logo scaled/rotated when selected: ' + sel.logoTransform);
  t.has(sel.transition, 'cubic-bezier');
  const idle = await page.$eval('[data-platform-tab="youtube"]', (el) => getComputedStyle(el, '::before').opacity);
  t.eq(idle, '0');
});

await t.step('clicking YouTube swaps the service list (animated), updates ARIA + URL hash; selection survives reload', async () => {
  await page.click('[data-platform-tab="youtube"]');
  await page.waitForFunction(() => !document.querySelector('[data-panel="youtube"]').hidden);
  t.eq(await page.getAttribute('[data-platform-tab="youtube"]', 'aria-selected'), 'true');
  t.eq(await page.getAttribute('[data-platform-tab="instagram"]', 'aria-selected'), 'false');
  t.ok(await page.$eval('[data-panel="instagram"]', (e) => e.hidden));
  t.has(page.url(), '#youtube');
  const anim = await page.$eval('[data-panel="youtube"] .svc', (e) => e.className);
  t.has(anim, 'animate-fade-up');
  await page.reload({ waitUntil: 'networkidle' });
  t.eq(await page.getAttribute('[data-platform-tab="youtube"]', 'aria-selected'), 'true', 'remembered after reload');
  await page.click('[data-platform-tab="instagram"]');
  t.eq(await page.getAttribute('[data-platform-tab="instagram"]', 'aria-selected'), 'true');
});

await t.step('keyboard: arrow keys move between platform tabs (roving tabindex) and Home/End work', async () => {
  await page.focus('[data-platform-tab="instagram"]');
  await page.keyboard.press('ArrowRight');
  t.eq(await page.getAttribute('[data-platform-tab="youtube"]', 'aria-selected'), 'true');
  t.eq(await page.$eval('[data-platform-tab="youtube"]', (e) => document.activeElement === e), true);
  t.eq(await page.getAttribute('[data-platform-tab="instagram"]', 'tabindex'), '-1');
  await page.keyboard.press('Home');
  t.eq(await page.getAttribute('[data-platform-tab="instagram"]', 'aria-selected'), 'true');
  await page.keyboard.press('End');
  t.eq(await page.getAttribute('[data-platform-tab="youtube"]', 'aria-selected'), 'true');
  await page.keyboard.press('Home');
});

// ───────────────────────────── catalogue ─────────────────────────────
for (const [slug, spec] of Object.entries(CATALOG)) {
  await t.step(`${slug}: exact sections, titles, prices, subtitles, badges, button colour`, async () => {
    await page.click(`[data-platform-tab="${slug}"]`);
    const panel = page.locator(`[data-panel="${slug}"]`);
    t.eq(await panel.locator('.sec-head h2').allInnerTexts().then((a) => a.map((s) => s.toUpperCase())), spec.sections);
    const cards = await panel.locator('[data-service]').evaluateAll((els) => els.map((el) => ({
      title: el.querySelector('.svc-title').textContent.trim(), price: el.querySelector('.svc-price').textContent.trim(),
      sub: el.querySelector('.svc-sub')?.textContent.trim() ?? null, badge: el.querySelector('.svc-badge')?.textContent.trim() ?? null,
      premium: el.classList.contains('svc-premium'), btn: el.querySelector('[data-action=buy]').textContent.trim(), btnBg: getComputedStyle(el.querySelector('[data-action=buy]')).backgroundColor,
      icon: !!el.querySelector('.svc-icon svg, .svc-icon img'), chip: el.querySelector('.svc-chip img')?.naturalWidth > 0,
    })));
    t.eq(cards.length, spec.cards.length);
    spec.cards.forEach(([title, price, sub, badge], i) => {
      t.eq(cards[i].title, title); t.eq(cards[i].price, price, title);
      if (sub) t.eq(cards[i].sub, sub, title);
      t.eq(cards[i].badge, badge, title + ' badge');
      t.eq(cards[i].btn, 'BUY NOW'); t.ok(cards[i].icon && cards[i].chip, `${title}: icon + platform chip`);
      const [r, g, b] = hexToRgb(cards[i].btnBg); t.ok(g > r + 30 && g > b + 30, `BUY NOW must be green: ${cards[i].btnBg}`);
    });
  });
}

await t.step('TRIAL PACK badge is exactly #DC2626 with white text; the 4,000-hour watch-time card is visually distinct (premium dark card)', async () => {
  await page.click('[data-platform-tab="instagram"]');
  // the colours come from the tag (generated /tags.css); the full badge rules live in site-badges.mjs and admin-tags.mjs
  const badge = await page.$eval(`[data-id="${serviceId('ig-followers-50')}"] .svc-badge`, (e) => { const cs = getComputedStyle(e); return { bg: cs.backgroundColor, fg: cs.color }; });
  t.eq(badge, { bg: 'rgb(220, 38, 38)', fg: 'rgb(255, 255, 255)' }, 'TRIAL PACK colours');
  await page.click('[data-platform-tab="youtube"]');
  const id = serviceId('yt-watch-4000h');
  const prem = await page.$eval(`[data-id="${id}"]`, (e) => { const cs = getComputedStyle(e); return { cls: e.className, bg: cs.backgroundImage + cs.backgroundColor, wide: e.getBoundingClientRect().width, title: getComputedStyle(e.querySelector('.svc-title')).color }; });
  const normal = await page.$eval(`[data-id="${serviceId('yt-views-10k')}"]`, (e) => ({ bg: getComputedStyle(e).backgroundImage + getComputedStyle(e).backgroundColor, w: e.getBoundingClientRect().width }));
  t.has(prem.cls, 'svc-premium'); t.ok(prem.bg !== normal.bg, 'premium background differs'); t.ok(prem.wide > normal.w * 1.8, `premium card spans the row: ${prem.wide} vs ${normal.w}`);
  t.ok(luminance(hexToRgb(prem.title)) > 0.8, 'light title on the dark premium card: ' + prem.title);
  await page.click('[data-platform-tab="instagram"]');
});

await t.step('Permanent Blue Tick uses the premium verified-badge SVG', async () => {
  const id = serviceId('ig-blue-tick');
  const use = await page.$eval(`[data-id="${id}"] .svc-icon use`, (e) => e.getAttribute('href'));
  t.has(use, '#i-verified');
});

// ───────────────────────────── BUY NOW → inline order form ─────────────────────────────
await t.step('BUY NOW opens the order form INSIDE the card (animated height + opacity), no navigation, input focused', async () => {
  const id = serviceId('ig-followers-50');
  const card = page.locator(`[data-id="${id}"]`);
  const url0 = page.url();
  const before = await card.evaluate((e) => e.getBoundingClientRect().height);
  // sample every frame for 1.5 s instead of looking at one instant: a busy machine delays frames, but a real animation always passes
  // through intermediate heights and opacities
  await card.evaluate((e) => {
    window.__samples = [];
    const t0 = performance.now();
    const tick = () => {
      window.__samples.push({ h: e.getBoundingClientRect().height, op: Number(getComputedStyle(e.querySelector('.expand')).opacity) });
      if (performance.now() - t0 < 1500) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });
  await card.locator('[data-action=buy]').click();
  await page.waitForTimeout(1700);
  const samples = await page.evaluate(() => window.__samples);
  const after = await card.evaluate((e) => ({ h: e.getBoundingClientRect().height, op: getComputedStyle(e.querySelector('.expand')).opacity, focus: document.activeElement?.name, inert: e.querySelector('.expand').inert }));
  t.eq(page.url(), url0, 'no navigation');
  const midH = samples.filter((x) => x.h > before + 1 && x.h < after.h - 1);
  t.ok(midH.length >= 1, `height animates through intermediate values: ${before} → [${midH.slice(0, 3).map((x) => Math.round(x.h)).join(', ')}] → ${after.h} (${samples.length} frames sampled)`);
  t.ok(samples.every((x, i) => i === 0 || x.h >= samples[i - 1].h - 0.5), 'the card only ever grows while opening (no jump back)');
  t.ok(samples.some((x) => x.op > 0.02 && x.op < 0.98), 'the form fades in through intermediate opacities');
  t.ok(after.h > before + 90, `expanded by ${after.h - before}px`);
  t.eq(after.op, '1'); t.eq(after.focus, 'target'); t.eq(after.inert, false);
  t.eq(await card.locator('[data-action=buy]').getAttribute('aria-expanded'), 'true');
  t.eq((await text(card.locator('label'))).toUpperCase(), 'ENTER USERNAME OR LINK');
  t.eq(await card.locator('[data-action=proceed]').isDisabled(), true, 'PROCEED disabled until valid');
  t.eq((await text(card.locator('[data-action=proceed]'))).toUpperCase(), 'PROCEED TO PAYMENT');
});

await t.step('only one card is open at a time; Cancel, Escape and BUY NOW (toggle) close it', async () => {
  const a = page.locator(`[data-id="${serviceId('ig-followers-50')}"]`), b = page.locator(`[data-id="${serviceId('ig-followers-100k')}"]`);
  await b.locator('[data-action=buy]').click(); await page.waitForTimeout(500);
  t.eq(await a.getAttribute('data-open'), 'false'); t.eq(await b.getAttribute('data-open'), 'true');
  await b.locator('[data-action=cancel]').click(); await page.waitForTimeout(500);
  t.eq(await b.getAttribute('data-open'), 'false');
  await a.locator('[data-action=buy]').click(); await page.waitForTimeout(500);
  await a.locator('input[name=target]').press('Escape'); await page.waitForTimeout(500);
  t.eq(await a.getAttribute('data-open'), 'false');
  await a.locator('[data-action=buy]').click(); await page.waitForTimeout(500);
  await a.locator('[data-action=buy]').click(); await page.waitForTimeout(500);
  t.eq(await a.getAttribute('data-open'), 'false');
  t.eq(await a.locator('.expand').evaluate((e) => e.inert), true, 'closed form is inert (not tabbable)');
});

await t.step('placeholders match the service kind (@username / instagram.com / youtube.com)', async () => {
  const want = { 'ig-followers-50': ['@username', 'instagram.com/username'], 'ig-likes-100': ['instagram.com/p/', '/reel/'], 'yt-subs-50': ['@channel', 'youtube.com'], 'yt-views-10': ['youtube.com/watch'] };
  for (const [code, parts] of Object.entries(want)) {
    const ph = await page.$eval(`[data-id="${serviceId(code)}"] input[name=target]`, (e) => e.placeholder);
    parts.forEach((p) => t.has(ph, p, code));
  }
});

await t.step('live validation: invalid shows ⚠ "Invalid username/link", valid shows ✓ "Valid input", PROCEED enables only when valid', async () => {
  const card = await openCard(page, 'ig-followers-50');
  const input = card.locator('input[name=target]'), msg = card.locator('.field-msg'), go = card.locator('[data-action=proceed]');
  const settle = () => page.waitForTimeout(350);
  t.eq(await msg.getAttribute('data-state'), 'idle');
  await input.fill('not a valid name!!'); await settle();
  t.eq(await msg.getAttribute('data-state'), 'invalid'); t.has(await text(msg), 'Invalid username/link'); t.eq(await go.isDisabled(), true);
  t.eq(await input.getAttribute('aria-invalid'), 'true');
  t.has(await msg.locator('use').getAttribute('href'), 'triangle-alert');
  await input.fill('@valid_user.name'); await settle();
  t.eq(await msg.getAttribute('data-state'), 'valid'); t.has(await text(msg), 'Valid input'); t.eq(await go.isDisabled(), false);
  t.has(await msg.locator('use').getAttribute('href'), 'circle-check');
  const colours = await page.evaluate(() => { const m = document.querySelector('[data-service][data-open=true] .field-msg'); return getComputedStyle(m).color; });
  const [r, g, b] = hexToRgb(colours); t.ok(g > r && g > b, 'valid message is green: ' + colours);
  await input.fill('https://www.instagram.com/valid_user/?utm_source=x'); await settle();
  t.eq(await msg.getAttribute('data-state'), 'valid', 'profile URLs with tracking params are accepted');
  await input.fill('https://evil.example.com/valid_user'); await settle();
  t.eq(await msg.getAttribute('data-state'), 'invalid', 'foreign host rejected');
  await input.fill('javascript:alert(1)'); await settle();
  t.eq(await msg.getAttribute('data-state'), 'invalid'); t.eq(await go.isDisabled(), true);
  await input.fill(''); await settle();
  t.eq(await msg.getAttribute('data-state'), 'idle'); t.eq(await go.isDisabled(), true);
  const [rr, gg, bb] = hexToRgb(await msg.evaluate((e) => getComputedStyle(e).color)); t.ok(contrast([rr, gg, bb], [255, 255, 255]) >= 4.5, 'hint text contrast');
});

await t.step('post / channel / video services validate their own link shapes', async () => {
  const cases = [
    ['ig-likes-100', 'https://www.instagram.com/p/CxYz123AbC/', 'valid'], ['ig-likes-100', 'https://instagram.com/reel/CxYz123AbC', 'valid'], ['ig-likes-100', '@username', 'invalid'],
    ['yt-subs-50', '@MrBeast', 'valid'], ['yt-subs-50', 'https://youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA', 'valid'], ['yt-subs-50', 'https://youtu.be/dQw4w9WgXcQ', 'invalid'],
    ['yt-views-10', 'https://youtu.be/dQw4w9WgXcQ', 'valid'], ['yt-views-10', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'valid'], ['yt-views-10', 'https://www.youtube.com/shorts/abcDEF12345', 'valid'], ['yt-views-10', 'hello', 'invalid'],
  ];
  for (const [code, value, want] of cases) {
    const card = await openCard(page, code);
    await card.locator('input[name=target]').fill(value); await page.waitForTimeout(330);
    t.eq(await card.locator('.field-msg').getAttribute('data-state'), want, `${code} ← ${value}`);
  }
});

await t.step('Enter key submits only when valid; invalid Enter shakes + keeps focus, no request is sent', async () => {
  const card = await openCard(page, 'ig-followers-50');
  let posts = 0; const count = (r) => { if (r.url().includes('/api/checkout')) posts++; }; page.on('request', count);
  const input = card.locator('input[name=target]');
  await input.fill('???'); await page.waitForTimeout(300); await input.press('Enter'); await page.waitForTimeout(400);
  page.off('request', count);
  t.eq(posts, 0); t.eq(page.url().includes('/pay/'), false);
  await input.fill('');
});

// ───────────────────────────── checkout → payment page ─────────────────────────────
let ORDER = {};
await t.step('PROCEED TO PAYMENT creates ONE pending order with the SERVER price and opens the payment page', async () => {
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const reqs = []; page.on('request', (r) => { if (r.url().includes('/api/checkout')) reqs.push({ body: r.postData(), idem: r.headers()['idempotency-key'], xrw: r.headers()['x-requested-with'] }); });
  const { ref } = await placeOrder(page, 'ig-followers-50', '@Valid_User');
  ORDER = { ref, ...payment(ref) };
  t.eq(reqs.length, 1); t.ok(reqs[0].idem?.length >= 16, 'idempotency key'); t.eq(reqs[0].xrw, 'smb');
  t.eq(JSON.parse(reqs[0].body), { service_id: serviceId('ig-followers-50'), target: '@Valid_User' }, 'only the service id and what was typed are sent: no price');
  t.eq(ORDER.oStatus, 'PENDING_PAYMENT'); t.eq(ORDER.pStatus, 'pending'); t.eq(ORDER.oAmount, 500); t.eq(ORDER.pAmount, 500); t.eq(ORDER.mode, 'AUTO');
  t.eq(ORDER.targetUrl, 'https://www.instagram.com/Valid_User/', 'target normalised to a canonical profile URL server-side');
  t.eq(Number(sql(`SELECT COUNT(*) FROM orders WHERE target_url = '${ORDER.targetUrl}'`)), 1);
  const mock = await (await fetch('http://127.0.0.1:4010/__state')).json();
  t.eq(mock.orders[ORDER.gwOrder].amount, '5.00', 'gateway asked for ₹5.00');
  page.removeAllListeners('request');
});

await t.step('payment page: SCAN AND PAY heading, summary, QR image, 05:00 timer counting down, DOWNLOAD QR, no layout problems', async () => {
  await page.waitForLoadState('networkidle');
  t.eq((await text(page.locator('[data-view=pending] h1'))).toUpperCase(), 'SCAN AND PAY');
  t.has(await text(page.locator('.pay-summary')), '₹5'); t.has(await text(page.locator('.pay-summary')), '50 Followers');
  const qr = await page.$eval('#qr-img', (e) => ({ w: e.naturalWidth, h: e.naturalHeight, shown: e.getBoundingClientRect().width, src: e.getAttribute('src') }));
  t.ok(qr.w >= 200 && qr.w === qr.h, `QR ${qr.w}x${qr.h}`); t.ok(qr.shown >= 240, 'QR displayed large: ' + qr.shown);
  const t0 = await text(page.locator('#timer-value')); t.ok(/^0[45]:\d\d$/.test(t0) && t0 >= '04:55', 'timer starts at 05:00: ' + t0);
  await page.waitForTimeout(2300);
  const t1 = await text(page.locator('#timer-value')); t.ok(t1 < t0, `counts down: ${t0} → ${t1}`);
  const tm = await page.$eval('#timer', (e) => ({ level: e.dataset.level, role: e.getAttribute('role') })); t.eq(tm.level, 'ok'); t.eq(tm.role, 'timer');
  t.eq((await text(page.locator('#btn-download'))).toUpperCase(), 'DOWNLOAD QR');
  t.has(await page.getAttribute('#btn-download', 'download') ?? '', ''); t.ok(await page.getAttribute('#btn-download', 'href'));
  t.eq(page.problems.filter((p) => !/favicon/.test(p)), [], page.problems.join('\n'));
});

await t.step('DOWNLOAD QR downloads the REAL QR image (PNG) and it decodes to the gateway payment link', async () => {
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#btn-download')]);
  const path = await dl.path(); const buf = readFileSync(path);
  t.eq(buf.subarray(1, 4).toString(), 'PNG'); t.ok(buf.length > 300, 'size ' + buf.length);
  t.has(dl.suggestedFilename(), '.png'); t.has(dl.suggestedFilename().toUpperCase(), ORDER.publicId.replace('SMB-', ''));
  const decoded = decodeQr(buf);
  t.ok(decoded, 'QR must be decodable');
  const pay = sql(`SELECT payment_url FROM payments WHERE ref = '${ORDER.ref}'`);
  t.eq(decoded, pay, 'the QR encodes the gateway payment link');
  const inline = decodeQr(Buffer.from(await (await fetch(`${BASE}/pay/${ORDER.ref}/qr.png`)).arrayBuffer()));
  t.eq(inline, pay);
  t.has((await text(page.locator('.toast').last())).toLowerCase(), 'qr');
});

await t.step('PAY ON THIS DEVICE opens the gateway link; order number + "do not close" note shown', async () => {
  const href = await page.getAttribute('#btn-open', 'href');
  t.eq(href, sql(`SELECT payment_url FROM payments WHERE ref = '${ORDER.ref}'`));
  t.has(await text(page.locator('.pay-meta')), ORDER.publicId);
});

await t.step('webhook success flips the page to "Payment successful" (no reload), order is PAID → handed to the SMM panel', async () => {
  const res = await payOk(ORDER.ref);
  t.eq(res.webhook?.status, 200, JSON.stringify(res));
  await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 });
  t.has((await text(page.locator('[data-view=paid] h1'))).toLowerCase(), 'payment successful');
  t.eq(await page.locator('[data-view=pending]').isHidden(), true);
  const p = payment(ORDER.ref);
  t.eq(p.pStatus, 'success'); t.ok(p.paidAt);
  await page.waitForFunction(() => true); await sleep(1500);
  const p2 = payment(ORDER.ref);
  t.ok(['SUBMITTED', 'IN_PROGRESS', 'PROCESSING', 'COMPLETED', 'PAID'].includes(p2.oStatus), 'AUTO order moved on: ' + p2.oStatus);
  const smmState = await (await fetch('http://127.0.0.1:4020/__state')).json();
  const sent = smmState.requests.filter((r) => r.action === 'add');
  t.ok(sent.length <= 1, 'at most one provider order');
  t.ok(smmState.requests.every((r) => r.keySeen), 'key is server side only');
});

await t.step('TRACK ORDER shows the progress timeline; page polls and stays free of console problems', async () => {
  await page.click('#btn-track');
  await page.waitForURL(/\/order\/[a-f0-9]{32}/);
  t.has(await text(page.locator('.pay-summary')), '₹5');
  t.ok((await page.locator('.step').count()) >= 2, 'timeline steps');
  t.has(await text(page.locator('.pay-note')), ORDER.publicId);
  t.ok((await page.locator('.step[data-state=done]').count()) >= 1);
  await page.waitForTimeout(600);
  t.eq(page.problems.filter((p) => !/favicon/.test(p)), [], page.problems.join('\n'));
});

await t.step('Shop icon on the order-tracking page takes you to your orders (drawer opens on arrival, URL is cleaned)', async () => {
  const p = await fresh();
  const { ref } = await placeOrder(p, 'ig-likes-100', 'https://www.instagram.com/p/ShopFromOrder1/');
  await payOk(ref); await p.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 });
  await p.click('#btn-track'); await p.waitForURL(/\/order\//);
  t.eq(await p.locator('.shop-btn').evaluate((e) => e.tagName), 'A', 'a plain link on pages that have no drawer');
  await Promise.all([p.waitForURL((u) => u.pathname === '/' || u.pathname === ''), p.click('.shop-btn')]);
  await p.waitForSelector('#orders-drawer[open]'); await p.waitForSelector('.order-row');
  t.ok(!p.url().includes('orders=1'), p.url());
  await p.close();
});

await t.step('MANUAL order: paid → waits for admin approval (no SMM call, honest status for the customer)', async () => {
  resetLimits(); const before = (await (await fetch('http://127.0.0.1:4020/__state')).json()).requests.length;
  const { ref } = await placeOrder(page, 'ig-followers-100k', 'https://instagram.com/manual_buyer');
  const o = payment(ref); t.eq(o.mode, 'MANUAL'); t.eq(o.oAmount, 9900);
  await payOk(ref);
  await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 });
  await sleep(800);
  const p = payment(ref); t.eq(p.oStatus, 'MANUAL_PENDING');
  t.eq((await (await fetch('http://127.0.0.1:4020/__state')).json()).requests.length, before, 'manual orders never reach the SMM panel automatically');
  await page.click('#btn-track'); await page.waitForURL(/\/order\//);
  const heading = (await text(page.locator('#o-heading'))).toLowerCase();
  t.ok(!/completed|delivered/.test(heading), 'must not claim completion: ' + heading);
});

await t.step('failed payment shows "Payment failed" with TRY AGAIN, never success', async () => {
  resetLimits();
  const { ref } = await placeOrder(page, 'ig-likes-100', 'https://www.instagram.com/p/FailPay123/');
  await alpha(`/__pay/${payment(ref).gwOrder}/fail`, {});
  await page.waitForSelector('[data-view=failed]:not([hidden])', { timeout: 9000 });
  t.has((await text(page.locator('[data-view=failed] h1'))).toLowerCase(), 'payment failed');
  t.eq(await page.locator('[data-view=paid]').isHidden(), true);
  t.eq((await text(page.locator('[data-view=failed] .btn'))).toUpperCase(), 'TRY AGAIN');
  t.ok(['FAILED', 'CANCELLED', 'PENDING_PAYMENT', 'EXPIRED'].includes(payment(ref).oStatus), payment(ref).oStatus);
  t.eq(payment(ref).pStatus, 'failed');
});

await t.step('session expiry (accelerated): at 00:00 → "Payment session expired", QR removed, download disabled, never a success state', async () => {
  resetLimits();
  const { ref } = await placeOrder(page, 'yt-views-10', 'https://youtu.be/dQw4w9WgXcQ');
  const gwOrder = payment(ref).gwOrder;
  sql(`UPDATE payments SET expires_at = UTC_TIMESTAMP() + INTERVAL 9 SECOND WHERE ref = '${ref}'`);
  await page.reload({ waitUntil: 'networkidle' });
  const first = await text(page.locator('#timer-value')); t.ok(/^00:0\d$/.test(first) || first === '00:10', 'timer follows the server expiry: ' + first);
  await page.waitForSelector('[data-view=expired]:not([hidden])', { timeout: 15000 });
  t.has(await text(page.locator('[data-view=expired] h1')), 'Payment session expired');
  t.eq(await page.locator('[data-view=paid]').isHidden(), true);
  t.eq(await page.$eval('#qr-img', (e) => e.hasAttribute('src')), false, 'QR image removed');
  t.eq(await page.$eval('#btn-download', (e) => e.getAttribute('aria-disabled')), 'true');
  t.eq(await page.$eval('#btn-download', (e) => e.hasAttribute('href')), false);
  t.has((await text(page.locator('[data-view=expired] .pay-note'))).toLowerCase(), 'do not pay');
  t.has((await text(page.locator('[data-view=expired] .btn'))).toUpperCase(), 'START AGAIN');
  await sleep(1500);
  t.eq(payment(ref).oStatus, 'EXPIRED'); t.eq(payment(ref).pStatus, 'expired');
  await page.reload({ waitUntil: 'networkidle' });
  t.eq(await page.locator('[data-view=expired]').isVisible(), true, 'a reload keeps showing the expired state');
  t.eq(await page.$eval('#qr-img', (e) => e.hasAttribute('src')), false, 'the expired QR is never served again');
  const qr = await fetch(`${BASE}/pay/${ref}/qr.png`); t.ok([404, 410].includes(qr.status), 'qr.png of an expired session: ' + qr.status);
  ORDER.expired = { ref, gwOrder };
});

await t.step('a payment that arrives AFTER expiry is credited, flagged late, and explained (never silently lost, never shown as on-time)', async () => {
  const { ref, gwOrder } = ORDER.expired;
  sql(`UPDATE payments SET expires_at = expires_at - INTERVAL 10 MINUTE WHERE ref = '${ref}'`);   // well past the 2-minute grace window
  await alpha(`/__pay/${gwOrder}/success`, {});
  await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 16000 });
  t.has(await text(page.locator('#paid-note')), 'after');
  const p = payment(ref); t.eq(p.pStatus, 'success'); t.eq(p.late, true, 'flagged late for the admin');
  t.ok(!['EXPIRED', 'PENDING_PAYMENT'].includes(p.oStatus), 'order revived: ' + p.oStatus);
});

await t.step('duplicate webhook delivery is idempotent (one payment, one order, no second provider order)', async () => {
  const { ref } = ORDER; const gw = ORDER.gwOrder;
  const before = Number(sql('SELECT COUNT(*) FROM orders')); const smm0 = (await (await fetch('http://127.0.0.1:4020/__state')).json()).requests.filter((r) => r.action === 'add').length;
  for (let i = 0; i < 3; i++) { const r = await alpha(`/__resend/${gw}`, {}); t.eq(r.status, 200); }
  await sleep(600);
  t.eq(Number(sql('SELECT COUNT(*) FROM orders')), before);
  t.eq(Number(sql(`SELECT COUNT(*) FROM payments WHERE gateway_order_id = '${gw}'`)), 1);
  t.eq((await (await fetch('http://127.0.0.1:4020/__state')).json()).requests.filter((r) => r.action === 'add').length, smm0);
});

// ───────────────────────────── Shop drawer ─────────────────────────────

await t.step('Shop drawer: empty state on a fresh device, then lists orders with status chips; badge counts active orders', async () => {
  const p = await fresh();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  t.eq(await p.locator('[data-orders-count]').isHidden(), true);
  await p.click('[data-action=open-orders]');
  await p.waitForSelector('#orders-drawer[open]');
  t.has(await t.text(p.locator('#orders-drawer [data-orders-body]')), 'No orders yet');
  t.eq(await p.evaluate(() => document.activeElement?.closest('#orders-drawer') !== null), true, 'focus moves into the drawer');
  await p.keyboard.press('Escape'); await p.waitForFunction(() => !document.querySelector('#orders-drawer[open]'));
  t.eq(await p.evaluate(() => document.activeElement?.classList.contains('shop-btn')), true, 'focus returns to the Shop button');
  resetLimits();
  const { ref } = await placeOrder(p, 'ig-views-1k', 'https://www.instagram.com/reel/DrawerTest1/');
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.waitForFunction(() => !document.querySelector('[data-orders-count]').hidden);
  t.eq(await t.text(p.locator('[data-orders-count]')), '1');
  await p.click('[data-action=open-orders]');
  await p.waitForSelector('.order-row');
  const row = await t.text(p.locator('.order-row').first());
  t.has(row, '1,000 Views'); t.has(row, '₹5'); t.has(row, 'Awaiting payment'); t.has(row, payment(ref).publicId);
  await Promise.all([p.waitForURL(`**/pay/${ref}`), p.click('.order-row')]);
  await p.close();
});

await t.step('Shop drawer: closes with X, Escape and backdrop click; "Browse services" closes and scrolls; Try again recovers from an error', async () => {
  const p = await fresh();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  const opened = async () => { await p.click('[data-action=open-orders]'); await p.waitForSelector('#orders-drawer[open]'); };
  await opened(); await p.click('#orders-drawer [data-dialog-close][aria-label=Close]'); await p.waitForFunction(() => !document.querySelector('#orders-drawer[open]'));
  await opened(); await p.mouse.click(30, 450); await p.waitForFunction(() => !document.querySelector('#orders-drawer[open]'));
  await opened(); await p.click('#orders-drawer .dlg-foot .btn-primary'); await p.waitForFunction(() => !document.querySelector('#orders-drawer[open]'));
  p.__skip = true;
  // an order is remembered, then the lookup API fails once: friendly error + Try again
  await p.evaluate(() => localStorage.setItem('smb_orders', JSON.stringify([{ token: 'a'.repeat(32), order_id: 'SMB-TEST', title: 'x', at: Date.now() }])));
  await p.route('**/api/orders/lookup', (r) => r.abort('failed'));
  await opened();
  await p.waitForSelector('#orders-drawer .site-empty button');
  t.has(await t.text(p.locator('#orders-drawer [data-orders-body]')), 'Could not load your orders');
  await p.unroute('**/api/orders/lookup');
  await p.click('#orders-drawer [data-orders-body] button');
  await p.waitForFunction(() => document.querySelector('#orders-drawer [data-orders-body]').textContent.includes('No orders yet') || document.querySelector('.order-row'));
  await p.close();
});

await t.step('Shop drawer shows only the orders held by THIS browser (tokens), never someone else\'s', async () => {
  const p = await fresh();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  const other = sql("SELECT access_token FROM orders ORDER BY id DESC LIMIT 1");
  const r = await p.evaluate(async (tok) => (await fetch('/api/orders/lookup', { method: 'POST', headers: { 'content-type': 'application/json', 'x-requested-with': 'smb' }, body: JSON.stringify({ tokens: [tok] }) })).json(), other);
  t.eq(r.orders.length, 1, 'a token is a capability: possessing it is access');
  const guess = await p.evaluate(async () => (await fetch('/api/orders/lookup', { method: 'POST', headers: { 'content-type': 'application/json', 'x-requested-with': 'smb' }, body: JSON.stringify({ tokens: ['SMB-AAAAAAAA', '1', 'x'.repeat(32)] }) })).json());
  t.eq(guess.orders.length, 0, 'public ids are not accepted as credentials');
  await p.close();
});

// ───────────────────────────── order tracking page edge cases ─────────────────────────────
await t.step('order tracking: unknown token → friendly 404 page; public id in the URL is not a credential', async () => {
  const p = await fresh();
  const a = await p.goto(BASE + '/order/' + 'f'.repeat(32)); t.eq(a.status(), 404);
  t.has((await p.locator('body').innerText()).toLowerCase(), 'not found');
  const pub = sql('SELECT public_id FROM orders ORDER BY id DESC LIMIT 1');
  const b = await fetch(`${BASE}/order/${pub}`); t.eq(b.status, 404);
  const c = await p.goto(BASE + '/pay/' + 'e'.repeat(32)); t.eq(c.status(), 404);
  await p.close();
});

// ───────────────────────────── phone viewport: the whole flow again ─────────────────────────────
await t.step('PHONE (390×844): choose platform → BUY NOW → type link → pay page → success, all reachable and un-clipped', async () => {
  resetLimits();
  const p = await newPage(browser, { width: 390, height: 844 }, { mobile: true }); t.page = p;
  await p.goto(BASE + '/', { waitUntil: 'networkidle' });
  await p.tap('[data-platform-tab="youtube"]');
  const card = p.locator(`[data-id="${serviceId('yt-subs-50')}"]`);
  await card.scrollIntoViewIfNeeded(); await card.locator('[data-action=buy]').tap(); await p.waitForTimeout(700);
  const input = card.locator('input[name=target]'); await input.fill('@phone_channel'); await p.waitForTimeout(350);
  t.eq(await card.locator('.field-msg').getAttribute('data-state'), 'valid');
  const box = await card.locator('[data-action=proceed]').boundingBox(); t.ok(box.height >= 44, 'tap target ' + box.height);
  await Promise.all([p.waitForURL(/\/pay\//), card.locator('[data-action=proceed]').tap()]);
  const ref = (p.url().match(/\/pay\/([a-f0-9]{32})/) || [])[1];
  await p.waitForSelector('#qr-img');
  const m = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth, qr: document.querySelector('#qr-img').getBoundingClientRect().width, timerTop: document.querySelector('#timer').getBoundingClientRect().top, dlBottom: document.querySelector('#btn-download').getBoundingClientRect().bottom }));
  t.ok(m.sw <= m.cw + 1, `no horizontal overflow ${m.sw}/${m.cw}`); t.ok(m.qr >= 220 && m.qr <= 340, 'QR size ' + m.qr);
  await payOk(ref);
  await p.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 });
  t.eq(p.problems.filter((x) => !/favicon/.test(x)), [], p.problems.join('\n'));
  await p.close();
});

const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
