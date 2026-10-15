// Storefront QA for the badges (service tags) and the platform selector of the home page:
//   1. tags — all 10 predefined tags on real cards with their exact colours, the automatic TRIAL PACK on every service priced at exactly ₹5
//      (both platforms, read from the price in the database, priority over a manually assigned tag, the assigned tag restored when the price
//      changes), one badge per card, never overlapping the title / price / icon / BUY NOW button, at 8 viewports from 320 px to 1920 px;
//   2. the selected-platform indicator — a pink→red tick at the card's top-right corner that nothing is ever drawn across (no diagonal line),
//      a visible pink/violet glow around the selected card, smooth switching between Instagram and YouTube, a clean unselected card.
// Geometry is measured in the DOM; the tick and the glow are checked pixel by pixel on screenshots. Screenshots for visual review land in
// $QA_OUT (default /tmp/qa/badges). The admin side of the tag system is tested in admin-tags.mjs.
//   NO_BASELINE=1 skips the dev-database reset (use it with BASE=… DB_NAME=… against another copy of the site).
import { execFileSync } from 'node:child_process';
import { PNG } from 'pngjs';
import { launch, newPage, BASE, ROOT, sql, rows, hexToRgb, contrast, resetLimits } from './site.mjs';
import { suite } from './harness.mjs';

const OUT = process.env.QA_OUT || '/tmp/qa/badges';
const t = suite('site-badges', OUT);
if (!process.env.NO_BASELINE) execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
resetLimits();
const browser = await launch();

const SLUGS = ['instagram', 'youtube'];
const VIEWPORTS = [
  { name: 'phone-320x640', width: 320, height: 640, mobile: true },
  { name: 'phone-360x800', width: 360, height: 800, mobile: true },
  { name: 'phone-390x844', width: 390, height: 844, mobile: true },
  { name: 'phone-412x915', width: 412, height: 915, mobile: true },
  { name: 'tablet-768x1024', width: 768, height: 1024, mobile: true },
  { name: 'laptop-1024x768', width: 1024, height: 768 },
  { name: 'desktop-1366x768', width: 1366, height: 768 },
  { name: 'desktop-1920x1080', width: 1920, height: 1080 },
];
const DESKTOP = { width: 1366, height: 768 };
const PHONE = { width: 390, height: 844, mobile: true };

const pages = [];                                                    // every page opened, for the console / CSP check at the end
let opened = 0;
const open = async (vp, opts = {}) => {
  if (++opened % 25 === 0) resetLimits();                            // every context is a new visitor; the tracker allows 40 new visitors an hour per IP
  const page = await newPage(browser, { width: vp.width, height: vp.height }, { mobile: vp.mobile, dpr: opts.dpr || 1, allowStatus: opts.allowStatus });
  pages.push(page);
  return page;
};
const settle = (page) => page.waitForTimeout(1250);                  // fade-up stagger (≤ 0.4 s delay + 0.5 s) and the tab transitions
const home = async (page, hash = '') => { await page.goto(`${BASE}/${hash}`, { waitUntil: 'networkidle' }); await settle(page); };
const pick = async (page, slug) => { await page.click(`#tab-${slug}`); await page.mouse.move(0, 0); await settle(page); };
const touch = (code, set) => sql(`UPDATE services SET ${set}, updated_at = UTC_TIMESTAMP() WHERE code = '${code}'`);

const catalogue = () => rows(
  "SELECT s.id, s.code, p.slug, s.price_paise, s.title FROM services s JOIN platforms p ON p.id = s.platform_id "
  + "WHERE s.status = 'active' AND s.deleted_at IS NULL ORDER BY p.sort_order, s.sort_order, s.id"
).map(([id, code, platform, paise, title]) => ({ id: Number(id), code, platform, paise: Number(paise), title }));
const rupees = (paise) => '₹' + paise / 100;

// ───────────────────────────── in-page measurements ─────────────────────────────
/** Everything about every card of one platform that the badge rules talk about. Rects are viewport coordinates. */
const measureCards = (page, slug) => page.evaluate((slug) => {
  const R = (e) => { if (!e) return null; const r = e.getBoundingClientRect(); return { l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height }; };
  const hit = (a, b) => !!a && !!b && Math.min(a.r, b.r) - Math.max(a.l, b.l) > 0.01 && Math.min(a.b, b.b) - Math.max(a.t, b.t) > 0.01;
  const panel = document.querySelector(`[data-panel="${slug}"]`);
  const heads = [...panel.querySelectorAll('.sec-head h2, .sec-head .icon')];
  const all = [...panel.querySelectorAll('[data-service]')];
  const doc = document.documentElement;
  return all.map((card) => {
    const badges = [...card.querySelectorAll('.svc-badge')];
    const badge = badges[0] || null;
    const base = { id: Number(card.dataset.id), title: card.querySelector('.svc-title').textContent.trim(), price: card.querySelector('.svc-price').textContent.trim(),
      badgeCount: badges.length, autoCount: card.querySelectorAll('[data-badge="auto"]').length, texts: badges.map((b) => b.textContent.trim()),
      tagId: badge ? Number(badge.dataset.tagId) : null, pageOverflow: doc.scrollWidth - doc.clientWidth, vw: doc.clientWidth };
    if (!badge) return base;
    const c = R(card), b = R(badge);
    const parts = Object.fromEntries(['.svc-title', '.svc-sub', '.svc-price', '.svc-icon', '.svc-chip', '[data-action=buy]'].map((s) => [s, R(card.querySelector(s))]));
    const overlaps = Object.entries(parts).filter(([, r]) => hit(b, r)).map(([s]) => s);
    const rowTop = R(card.querySelector('.svc-top')).t;
    const neighbours = [...panel.querySelectorAll('[data-service]')].filter((x) => x !== card).map(R).filter((r) => hit(b, r)).length + heads.map(R).filter((r) => hit(b, r)).length;
    const cs = getComputedStyle(badge);
    // is anything on top of it / is it clipped by an ancestor?  (the badge ignores the pointer, so allow hit-testing for a moment)
    badge.style.pointerEvents = 'auto';
    card.scrollIntoView({ block: 'center', behavior: 'instant' });
    const bb = R(badge);
    const pts = [[bb.l + bb.w / 2, bb.t + bb.h / 2], [bb.l + 12, bb.t + bb.h / 2], [bb.r - 12, bb.t + bb.h / 2], [bb.l + bb.w / 2, bb.t + 4], [bb.l + bb.w / 2, bb.b - 4]];
    const covered = pts.filter(([x, y]) => { const e = document.elementFromPoint(x, y); return !e || !badge.contains(e); }).length;
    badge.style.pointerEvents = '';
    const clipped = [];
    for (let a = badge.parentElement; a && a !== document.body; a = a.parentElement) {
      const o = getComputedStyle(a);
      if (o.overflowX !== 'visible' || o.overflowY !== 'visible') { const r = R(a); if (bb.l < r.l - 0.5 || bb.r > r.r + 0.5 || bb.t < r.t - 0.5 || bb.b > r.b + 0.5) clipped.push(a.className || a.tagName); }
    }
    return { ...base, rect: { card: c, badge: b }, overlaps, neighbours, covered, clipped,
      alignRight: Math.abs(b.r - parts['.svc-price'].r), straddles: b.t < c.t && b.b > c.t, within: b.l >= c.l - 0.5 && b.r <= c.r + 0.5,
      gap: rowTop - b.b, truncated: badge.scrollWidth > badge.clientWidth + 0.5,
      style: { bg: cs.backgroundColor, bgImage: cs.backgroundImage, color: cs.color, weight: Number(cs.fontWeight), size: cs.fontSize, transform: cs.textTransform, radius: parseFloat(cs.borderTopLeftRadius), h: b.h, shadow: cs.boxShadow, ellipsis: cs.textOverflow, overflowX: cs.overflowX } };
  });
}, slug);

/** Computed state of one platform button, its tick and its pseudo-elements. */
const snapshot = (page, slug) => page.evaluate((slug) => {
  const btn = document.getElementById('tab-' + slug), tick = btn.querySelector('.platform-check'), svg = tick.querySelector('svg');
  const R = (e) => { const r = e.getBoundingClientRect(); return { l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height, cx: r.left + r.width / 2, cy: r.top + r.height / 2 }; };
  const cs = (e, p) => getComputedStyle(e, p);
  return {
    selected: btn.getAttribute('aria-selected'), tabindex: btn.getAttribute('tabindex'), btn: R(btn), tick: R(tick), icon: R(svg), radius: parseFloat(cs(btn).borderTopRightRadius),
    btnTransform: cs(btn).transform, btnShadow: cs(btn).boxShadow, ringOpacity: Number(cs(btn, '::before').opacity), ringZ: cs(btn, '::before').zIndex, washOpacity: Number(cs(btn, '::after').opacity),
    tickOpacity: Number(cs(tick).opacity), tickVisibility: cs(tick).visibility, tickTransform: cs(tick).transform, tickZ: cs(tick).zIndex, tickPos: cs(tick).position, tickRadius: parseFloat(cs(tick).borderTopLeftRadius),
    tickBg: cs(tick).backgroundImage, stroke: cs(svg).stroke, strokeWidth: parseFloat(cs(svg).strokeWidth),
  };
}, slug);

const tickCount = (page) => page.evaluate(() => [...document.querySelectorAll('.platform-check')].filter((e) => { const c = getComputedStyle(e); return c.visibility === 'visible' && Number(c.opacity) > 0.5; }).length);
const shadowAlphas = (css) => [...css.matchAll(/rgba?\(([^)]+)\)/g)].map((m) => { const p = m[1].split(',').map(Number); return p.length > 3 ? p[3] : 1; });
/** Parse a computed box-shadow into layers (Chromium prints the colour first and "inset" last). */
const layers = (css) => {
  const parts = []; let depth = 0, cur = '';
  for (const ch of css) { if (ch === '(') depth++; if (ch === ')') depth--; if (ch === ',' && depth === 0) { parts.push(cur); cur = ''; } else cur += ch; }
  if (cur.trim()) parts.push(cur);
  return parts.map((layer) => {
    // colours come back as rgb()/rgba(), or as color(srgb r g b / a) with 0..1 channels when the CSS used color-mix()
    const colour = layer.match(/(?:rgba?|color)\([^)]*\)/)[0], c = colour.match(/[\d.]+/g).map(Number), k = colour.startsWith('color(') ? 255 : 1;
    const n = (layer.replace(colour, '').match(/-?[\d.]+(?=px)/g) || []).map(Number);
    return { r: Math.round(c[0] * k), g: Math.round(c[1] * k), b: Math.round(c[2] * k), a: c.length > 3 ? c[3] : 1, x: n[0], y: n[1], blur: n[2] || 0, spread: n[3] || 0, inset: /\binset\b/.test(layer) };
  });
};

// ───────────────────────────── pixel helpers ─────────────────────────────
const shot = async (page, clip) => PNG.sync.read(await page.screenshot({ clip }));
const px = (img, x, y) => { const i = (img.width * y + x) * 4; return [img.data[i], img.data[i + 1], img.data[i + 2]]; };
const hue = ([r, g, b]) => { const mx = Math.max(r, g, b), mn = Math.min(r, g, b), d = mx - mn; if (!d) return 0; const h = mx === r ? ((g - b) / d) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; return (h * 60 + 360) % 360; };
const sat = ([r, g, b]) => { const mx = Math.max(r, g, b), mn = Math.min(r, g, b); return mx === 0 ? 0 : (mx - mn) / mx; };
const isWhite = (c) => c[0] >= 235 && c[1] >= 235 && c[2] >= 235;
const diffIn = (a, b, inside, tol = 6) => {
  let n = 0, max = 0;
  for (let y = 0; y < a.height; y++) for (let x = 0; x < a.width; x++) {
    if (!inside(x, y)) continue;
    const i = (a.width * y + x) * 4; const d = Math.max(Math.abs(a.data[i] - b.data[i]), Math.abs(a.data[i + 1] - b.data[i + 1]), Math.abs(a.data[i + 2] - b.data[i + 2]));
    if (d > max) max = d; if (d > tol) n++;
  }
  return { n, max };
};
const ringAnimations = (page, slug) => page.evaluate((slug) => {
  const btn = document.getElementById('tab-' + slug);
  window.__ring = document.getAnimations().filter((a) => a.effect && a.effect.target === btn && a.effect.pseudoElement === '::before');
  window.__ring.forEach((a) => a.pause());
  return window.__ring.length;
}, slug);
const ringAt = (page, ms) => page.evaluate((ms) => window.__ring.forEach((a) => { a.currentTime = ms; }), ms);
const ringResume = (page) => page.evaluate(() => (window.__ring || []).forEach((a) => a.play()));
const hideRing = (page) => page.evaluate(() => {
  const sheet = [...document.styleSheets].find((s) => s.href && s.href.includes('app.css'));
  window.__hideRule = sheet.insertRule('.platform-btn::before { display: none !important; }', sheet.cssRules.length); window.__hideSheet = sheet;
});
const showRing = (page) => page.evaluate(() => window.__hideSheet.deleteRule(window.__hideRule));

// ═══════════════════════════════ 1 · tags on the cards ═══════════════════════════════
const dt = await open(DESKTOP);
t.page = dt;
await home(dt);
const cat = catalogue();
const five = cat.filter((s) => s.paise === 500);
const TAGS = [                                       // the 10 predefined tags exactly as specified
  { code: 'trial-pack', name: 'TRIAL PACK', bg: '#DC2626' }, { code: 'best-seller', name: 'BEST SELLER', bg: '#7C3AED' }, { code: 'popular', name: 'POPULAR', bg: '#D97706' },
  { code: 'best-value', name: 'BEST VALUE', bg: '#15803D' }, { code: 'premium', name: 'PREMIUM', bg: '#0369A1' }, { code: 'hot-deal', name: 'HOT DEAL', bg: '#BE185D' },
  { code: 'non-drop', name: 'NON DROP', bg: '#0F766E' }, { code: 'instant', name: 'INSTANT', bg: '#4338CA' }, { code: 'limited-offer', name: 'LIMITED OFFER', bg: '#475569' },
  { code: 'vip', name: 'VIP', bg: '#A16207' },
];
const rgb = (hex) => `rgb(${[1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(', ')})`;
const tagId = (code) => Number(sql(`SELECT id FROM service_tags WHERE code = '${code}'`));
const assign = (serviceCode, tag) => sql(`UPDATE services SET tag_id = ${tag === null ? 'NULL' : tagId(tag)}, updated_at = UTC_TIMESTAMP() WHERE code = '${serviceCode}'`);
const MANUAL = ['best-seller', 'popular', 'best-value', 'premium', 'hot-deal', 'non-drop', 'instant', 'limited-offer', 'vip'];

await t.step('catalogue under test: the six ₹5 packs (3 Instagram + 3 YouTube), the dearer services and the 10 predefined tags', async () => {
  t.eq(cat.length, 14);
  t.eq(five.map((s) => s.code).sort(), ['ig-followers-50', 'ig-likes-100', 'ig-views-1k', 'yt-likes-100', 'yt-subs-50', 'yt-views-10']);
  t.eq(five.filter((s) => s.platform === 'instagram').length, 3);
  t.eq(five.filter((s) => s.platform === 'youtube').length, 3);
  t.eq(rows("SELECT code, name, bg_color, text_color FROM service_tags WHERE is_system = 1 ORDER BY sort_order").map(([c, n, bg, fg]) => ({ code: c, name: n, bg, fg })),
    TAGS.map((x) => ({ code: x.code, name: x.name, bg: x.bg, fg: '#FFFFFF' })));
});

await t.step('every ₹5 card on BOTH platforms shows TRIAL PACK (#DC2626, white text), no other card does (checked against the prices in the database)', async () => {
  for (const slug of SLUGS) {
    await pick(dt, slug);
    const cards = await measureCards(dt, slug);
    const expected = cat.filter((s) => s.platform === slug);
    t.eq(cards.length, expected.length, `${slug}: cards on the page`);
    for (const e of expected) {
      const c = cards.find((x) => x.id === e.id);
      t.ok(c, `${slug}: card for ${e.code} exists`);
      t.eq(c.price, rupees(e.paise), `${e.code}: the price on the card is the price in the database`);
      if (e.paise === 500) {
        t.eq([c.badgeCount, c.autoCount, c.texts[0]], [1, 1, 'TRIAL PACK'], `${slug} · ${c.title} (${c.price})`);
        t.eq([c.style.bg, c.style.color], ['rgb(220, 38, 38)', 'rgb(255, 255, 255)'], `${c.title}: exact colours`);
        t.eq(c.tagId, tagId('trial-pack'));
      } else if (e.code !== 'yt-watch-4000h') {
        t.eq(c.badgeCount, 0, `${slug} · ${c.title} (${c.price}) has no badge`);
      }
    }
  }
  await dt.screenshot({ path: `${OUT}/trial-youtube-desktop.png` });
});

await t.step('no duplicate badges: one badge per card everywhere; the six automatic ones are the only automatic ones', async () => {
  t.eq(await dt.locator('[data-badge="auto"]').count(), five.length, 'automatic badges in the whole document');
  t.eq(await dt.locator('.svc-badge').count(), five.length + 1, 'six automatic + the PREMIUM tag of the watch-time card');
  for (const slug of SLUGS) for (const c of await measureCards(dt, slug)) t.ok(c.badgeCount <= 1, `${c.title}: ${c.badgeCount} badges ${JSON.stringify(c.texts)}`);
});

await t.step('badge design: compact bold white uppercase pill in the tag colour, rounded, with a subtle shadow and a glow in the tag colour; white text ≥ 4.5:1 on TRIAL PACK', async () => {
  await pick(dt, 'instagram');
  const c = (await measureCards(dt, 'instagram')).find((x) => x.autoCount);
  const s = c.style;
  t.eq(s.bg, 'rgb(220, 38, 38)', 'background is the tag colour');
  t.eq(s.color, 'rgb(255, 255, 255)', 'white text');
  t.ok(contrast([220, 38, 38], [255, 255, 255]) >= 4.5, 'white on #DC2626 ≥ 4.5:1');
  t.ok(s.weight >= 800, 'bold: ' + s.weight);
  t.eq(s.transform, 'uppercase');
  t.ok(s.radius >= s.h / 2, `fully rounded: radius ${s.radius} for height ${s.h}`);
  t.ok(s.h >= 20 && s.h <= 28, 'compact height: ' + s.h);
  const ls = layers(s.shadow);
  t.ok(ls.length >= 3, 'shadow layers: ' + s.shadow);
  t.ok(ls.some((l) => !l.inset && l.r > 150 && l.g < 100 && l.blur >= 10), 'a glow in the badge colour: ' + s.shadow);
  t.ok(ls.some((l) => !l.inset && l.r === 255 && l.g === 255 && l.b === 255 && l.spread >= 1.5), 'a white ring that keeps the card border away from the pill');
  t.ok(ls.every((l) => l.a <= 0.9 || l.r === 255), 'the glow is restrained');
});

await t.step('placement: on the card top edge, right-aligned with the price, clear of title / price / icon / chip / BUY NOW and of neighbouring cards and headings (desktop, both platforms)', async () => {
  for (const slug of SLUGS) {
    await pick(dt, slug);
    for (const c of (await measureCards(dt, slug)).filter((x) => x.badgeCount)) {
      const who = `${slug} · ${c.title}`;
      t.eq(c.overlaps, [], `${who}: overlaps`);
      t.eq(c.neighbours, 0, `${who}: touches a neighbouring card / heading`);
      t.ok(c.straddles, `${who}: sits on the top edge`);
      t.ok(c.within, `${who}: inside the card horizontally`);
      t.ok(c.alignRight <= 2.5, `${who}: right edge ${c.alignRight.toFixed(1)} px off the price column`);
      t.ok(c.gap >= 6, `${who}: ${c.gap.toFixed(1)} px between the badge and the title row`);
      t.eq(c.covered, 0, `${who}: points of the badge covered by another element`);
      t.eq(c.clipped, [], `${who}: clipped by an ancestor`);
      t.ok(!c.truncated, `${who}: text truncated`);
    }
  }
});

await t.step('every ₹5 card at 8 viewports (320 px phone … 1920 px desktop): no overlap, no clipping, no horizontal scroll, badge inside the viewport', async () => {
  const problems = [];
  for (const vp of VIEWPORTS) {
    const page = await open(vp);
    await home(page);
    for (const slug of SLUGS) {
      if (slug !== 'instagram') await pick(page, slug);
      const cards = await measureCards(page, slug);
      const trial = cards.filter((c) => c.autoCount);
      if (trial.length !== 3) problems.push(`${vp.name} ${slug}: ${trial.length} trial cards`);
      if (cards[0].pageOverflow > 0) problems.push(`${vp.name} ${slug}: page overflows by ${cards[0].pageOverflow}px`);
      for (const c of cards.filter((x) => x.badgeCount)) {
        const who = `${vp.name} ${slug} ${c.title}`;
        if (c.overlaps.length) problems.push(`${who}: overlaps ${c.overlaps}`);
        if (c.neighbours) problems.push(`${who}: touches ${c.neighbours} neighbour(s)`);
        if (!c.straddles || !c.within) problems.push(`${who}: misplaced (straddles ${c.straddles}, within ${c.within})`);
        if (c.alignRight > 2.5) problems.push(`${who}: ${c.alignRight.toFixed(1)}px off the price column`);
        if (c.gap < 4) problems.push(`${who}: only ${c.gap.toFixed(1)}px above the title row`);
        if (c.covered) problems.push(`${who}: covered`);
        if (c.clipped.length) problems.push(`${who}: clipped by ${c.clipped}`);
        if (c.truncated) problems.push(`${who}: truncated`);
        if (c.rect.badge.l < 0 || c.rect.badge.r > c.vw) problems.push(`${who}: outside the viewport`);
      }
      for (const c of cards.filter((x) => !x.badgeCount && x.title !== '4,000 Hours Watch Time' && x.price === '₹5')) problems.push(`${vp.name} ${slug} ${c.title}: ₹5 card without a badge`);
    }
    await page.screenshot({ path: `${OUT}/home-${vp.name}.png`, fullPage: true });
    await page.close();
  }
  t.eq(problems, [], problems.slice(0, 8).join('\n   '));
});

await t.step('all 10 predefined tags on real cards (Instagram AND YouTube): exact background, white text, the tag\'s own name, one badge, no overlap — desktop and 360 px phone', async () => {
  const problems = [];
  try {
    for (const vp of [DESKTOP, { width: 360, height: 800, mobile: true }]) {
      for (const tag of TAGS.filter((x) => x.code !== 'trial-pack')) {
        assign('ig-followers-100k', tag.code); assign('yt-subs-100k', tag.code);
        const page = await open(vp);
        await home(page);
        for (const [slug, title] of [['instagram', '100K Followers'], ['youtube', '100K Subscribers']]) {
          if (slug === 'youtube') await pick(page, slug);
          const c = (await measureCards(page, slug)).find((x) => x.title === title);
          const who = `${vp.width}px ${slug} ${tag.name}`;
          if (!c.badgeCount) { problems.push(`${who}: no badge`); continue; }
          if (c.badgeCount !== 1) problems.push(`${who}: ${c.badgeCount} badges`);
          if (c.texts[0] !== tag.name) problems.push(`${who}: text ${c.texts[0]}`);
          if (c.style.bg !== rgb(tag.bg)) problems.push(`${who}: background ${c.style.bg} ≠ ${rgb(tag.bg)}`);
          if (c.style.color !== 'rgb(255, 255, 255)') problems.push(`${who}: text colour ${c.style.color}`);
          if (c.tagId !== tagId(tag.code)) problems.push(`${who}: tag id ${c.tagId}`);
          if (c.overlaps.length || c.neighbours || c.covered || c.clipped.length || c.truncated || !c.straddles || !c.within || c.alignRight > 2.5) problems.push(`${who}: layout ${JSON.stringify({ o: c.overlaps, n: c.neighbours, cv: c.covered, cl: c.clipped, tr: c.truncated, st: c.straddles, w: c.within, a: c.alignRight })}`);
        }
        if (vp === DESKTOP && ['popular', 'vip'].includes(tag.code)) await page.screenshot({ path: `${OUT}/tag-${tag.code}-desktop.png` });
        await page.close();
      }
    }
  } finally { assign('ig-followers-100k', null); assign('yt-subs-100k', null); }
  t.eq(problems, [], problems.slice(0, 8).join('\n   '));
});

await t.step('the automatic ₹5 rule beats an assigned tag, one badge only; at ₹10 the assigned tag is back; ₹4.99 / ₹5.01 are not ₹5; any ₹5 service on either platform, whatever its name', async () => {
  const verify = async (label, expectations) => {
    const page = await open(DESKTOP);
    await home(page);
    for (const slug of SLUGS) {
      if (slug !== 'instagram') await pick(page, slug);
      for (const c of await measureCards(page, slug)) {
        const e = expectations[c.id];
        if (e === undefined) continue;
        t.eq([c.badgeCount, c.texts[0] ?? null, c.autoCount], e, `${label}: ${c.title} (${c.price})`);
      }
    }
    await page.close();
  };
  const id = (code) => Number(sql(`SELECT id FROM services WHERE code = '${code}'`));
  const setPrice = (code, paise) => sql(`UPDATE services SET price_paise = ${paise}, updated_at = UTC_TIMESTAMP() WHERE code = '${code}'`);
  try {
    assign('ig-likes-100', 'best-seller'); assign('yt-likes-100', 'vip');                    // two ₹5 services with a manual tag
    await verify('₹5 + manual tag', { [id('ig-likes-100')]: [1, 'TRIAL PACK', 1], [id('yt-likes-100')]: [1, 'TRIAL PACK', 1], [id('ig-views-1k')]: [1, 'TRIAL PACK', 1] });
    setPrice('ig-likes-100', 1000); setPrice('yt-likes-100', 1000);                           // ₹5 → ₹10: the manual tag is restored
    await verify('₹10', { [id('ig-likes-100')]: [1, 'BEST SELLER', 0], [id('yt-likes-100')]: [1, 'VIP', 0] });
    setPrice('ig-likes-100', 500); setPrice('yt-likes-100', 500);                             // back to ₹5: the automatic badge wins again
    await verify('₹5 again', { [id('ig-likes-100')]: [1, 'TRIAL PACK', 1], [id('yt-likes-100')]: [1, 'TRIAL PACK', 1] });
    setPrice('ig-likes-100', 499); setPrice('yt-likes-100', 501);
    await verify('₹4.99 / ₹5.01', { [id('ig-likes-100')]: [1, 'BEST SELLER', 0], [id('yt-likes-100')]: [1, 'VIP', 0] });
    setPrice('ig-followers-100k', 500); setPrice('yt-views-10k', 500);                        // dearer services become ₹5 services: they get the badge
    await verify('another service at ₹5', { [id('ig-followers-100k')]: [1, 'TRIAL PACK', 1], [id('yt-views-10k')]: [1, 'TRIAL PACK', 1] });
    setPrice('ig-followers-100k', 9900); setPrice('yt-views-10k', 9900);
    await verify('back to ₹99', { [id('ig-followers-100k')]: [0, null, 0], [id('yt-views-10k')]: [0, null, 0] });
  } finally {
    setPrice('ig-likes-100', 500); setPrice('yt-likes-100', 500); setPrice('ig-followers-100k', 9900); setPrice('yt-views-10k', 9900);
    assign('ig-likes-100', null); assign('yt-likes-100', null);
  }
});

await t.step('a disabled tag disappears from the cards but stays assigned; enabling it brings it back; a deleted custom tag leaves its services without a badge', async () => {
  try {
    assign('ig-followers-100k', 'hot-deal'); assign('yt-subs-100k', 'hot-deal');
    const shows = async () => { const page = await open(DESKTOP); await home(page); const a = (await measureCards(page, 'instagram')).find((c) => c.title === '100K Followers'); await pick(page, 'youtube'); const b = (await measureCards(page, 'youtube')).find((c) => c.title === '100K Subscribers'); await page.close(); return [a.texts[0] ?? null, b.texts[0] ?? null]; };
    t.eq(await shows(), ['HOT DEAL', 'HOT DEAL']);
    sql("UPDATE service_tags SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE code = 'hot-deal'");
    t.eq(await shows(), [null, null], 'disabled → hidden');
    t.eq(Number(sql(`SELECT COUNT(*) FROM services WHERE tag_id = ${tagId('hot-deal')}`)), 2, 'both assignments are kept');
    sql("UPDATE service_tags SET is_active = 1, updated_at = UTC_TIMESTAMP() WHERE code = 'hot-deal'");
    t.eq(await shows(), ['HOT DEAL', 'HOT DEAL'], 'enabled → back');
    sql("INSERT INTO service_tags (name, bg_color, text_color, is_active, is_system, sort_order, created_at, updated_at) VALUES ('Temp tag', '#1D4ED8', '#FFFFFF', 1, 0, 1000, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    sql("UPDATE services SET tag_id = (SELECT id FROM service_tags WHERE name = 'Temp tag') WHERE code IN ('ig-followers-100k', 'yt-subs-100k')");
    t.eq(await shows(), ['Temp tag', 'Temp tag'], 'a custom tag shows (capitals are CSS, the text is as typed)');
    sql("DELETE FROM service_tags WHERE name = 'Temp tag'");
    t.eq(await shows(), [null, null], 'deleted → the services simply have no badge');
  } finally { sql("DELETE FROM service_tags WHERE name = 'Temp tag'"); assign('ig-followers-100k', null); assign('yt-subs-100k', null); sql("UPDATE service_tags SET is_active = 1 WHERE code = 'hot-deal'"); }
});

await t.step('stress: a 24-character custom tag name on a 320 px phone stays inside the card, truncates with an ellipsis and causes no overflow; a short one is never truncated', async () => {
  sql("INSERT INTO service_tags (name, bg_color, text_color, is_active, is_system, sort_order, created_at, updated_at) VALUES ('WWWWWWWWWWWWWWWWWWWWWWWW', '#7C3AED', '#FFFFFF', 1, 0, 1000, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
  sql("UPDATE services SET tag_id = (SELECT id FROM service_tags WHERE name = 'WWWWWWWWWWWWWWWWWWWWWWWW') WHERE code = 'ig-followers-100k'");
  try {
    for (const vp of [VIEWPORTS[0], VIEWPORTS[2]]) {
      const page = await open(vp);
      await home(page);
      const c = (await measureCards(page, 'instagram')).find((x) => x.title === '100K Followers');
      const who = vp.name;
      t.eq([c.pageOverflow <= 0, c.within, c.overlaps, c.neighbours, c.covered, c.clipped], [true, true, [], 0, 0, []], `${who}: layout`);
      t.eq([c.style.ellipsis, c.style.overflowX], ['ellipsis', 'hidden'], `${who}: the long name gives way with an ellipsis`);
      t.ok(c.rect.badge.r - c.rect.badge.l <= c.rect.card.r - c.rect.card.l - 2 * 16 + 1, `${who}: badge no wider than the card's content column`);
      if (vp === VIEWPORTS[0]) { t.ok(c.truncated, 'a 24-character name is cut at 320 px'); await page.screenshot({ path: `${OUT}/stress-long-badge-320.png`, fullPage: false }); }
      const short = (await measureCards(page, 'instagram')).find((x) => x.title === '50 Followers');
      t.ok(!short.truncated, `${who}: TRIAL PACK is never truncated`);
      await page.close();
    }
  } finally { assign('ig-followers-100k', null); sql("DELETE FROM service_tags WHERE name = 'WWWWWWWWWWWWWWWWWWWWWWWW'"); }
});

await t.step('BUY NOW on a card with a badge still works (the badge never intercepts a tap) on a phone', async () => {
  const page = await open(PHONE);
  await home(page);
  const card = page.locator('[data-panel="instagram"] [data-service]').filter({ has: page.locator('[data-badge="auto"]') }).first();
  await card.scrollIntoViewIfNeeded();
  await card.locator('[data-action=buy]').click();
  await page.waitForFunction((el) => el.dataset.open === 'true', await card.elementHandle());
  t.ok(await card.locator('input[name=target]').isVisible(), 'the inline form opened');
  const m = await measureCards(page, 'instagram');
  const open1 = m.find((c) => c.autoCount);
  t.eq(open1.overlaps, [], 'badge still clear of the content while the card is open');
  await page.screenshot({ path: `${OUT}/trial-card-open-phone.png` });
  await page.close();
});

// ═══════════════════════════════ 2 · selected-platform indicator ═══════════════════════════════
await t.step('selected state: the tick is a circular pink→red disc on the top-right corner arc, above the animated border, with a centred white check', async () => {
  for (const vp of [DESKTOP, PHONE]) {
    const page = await open(vp, { dpr: 2 });
    await home(page);
    for (const slug of SLUGS) {
      await pick(page, slug);
      const s = await snapshot(page, slug);
      const who = `${vp.width}px ${slug}`;
      t.eq(s.selected, 'true'); t.eq(s.tabindex, '0');
      t.eq([s.tickOpacity, s.tickVisibility, s.tickPos], [1, 'visible', 'absolute'], `${who}: tick shown`);
      t.ok(s.tickTransform === 'none' || /^matrix\(1, 0, 0, 1, 0, 0\)$/.test(s.tickTransform), `${who}: tick at full size (${s.tickTransform})`);
      t.eq(s.ringOpacity, 1, `${who}: the gradient border is on`);
      t.ok(Number(s.tickZ) > Number(s.ringZ), `${who}: tick z-index ${s.tickZ} must be above the border ring's ${s.ringZ}`);
      t.ok(Math.abs(s.tick.w - s.tick.h) < 0.5 && s.tickRadius >= s.tick.w / 2, `${who}: circular ${s.tick.w}×${s.tick.h}, radius ${s.tickRadius}`);
      t.ok(s.tick.w >= 26 && s.tick.w <= 32, `${who}: disc size ${s.tick.w}`);
      // centred on the 45° point of the rounded corner (the button is scaled 1.025 while selected)
      const r = s.radius * 1.025, ex = s.btn.r - 0.2929 * r, ey = s.btn.t + 0.2929 * r;
      t.ok(Math.abs(s.tick.cx - ex) <= 2.5 && Math.abs(s.tick.cy - ey) <= 2.5, `${who}: tick centre (${s.tick.cx.toFixed(1)}, ${s.tick.cy.toFixed(1)}) vs corner arc point (${ex.toFixed(1)}, ${ey.toFixed(1)})`);
      t.ok(s.tickBg.includes('linear-gradient'), `${who}: gradient disc`);
      t.ok(Math.abs(s.icon.cx - s.tick.cx) <= 0.75 && Math.abs(s.icon.cy - s.tick.cy) <= 0.75, `${who}: check centred in the disc (Δ ${(s.icon.cx - s.tick.cx).toFixed(2)}, ${(s.icon.cy - s.tick.cy).toFixed(2)})`);
      t.ok(s.icon.w >= 14, `${who}: check size ${s.icon.w}`);
      t.eq(s.stroke, 'rgb(255, 255, 255)', `${who}: white check`);
      t.ok(s.strokeWidth >= 3, `${who}: check stroke ${s.strokeWidth}`);
      t.ok(s.tick.r <= vp.width && s.tick.l >= 0 && s.tick.t >= 0, `${who}: the tick is inside the viewport`);
      // nothing but the tick at its position, and no ancestor clips it
      const hitInfo = await page.evaluate((slug) => {
        const tick = document.querySelector(`#tab-${slug} .platform-check`); tick.style.pointerEvents = 'auto';
        const r = tick.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2, rad = r.width * 0.36;
        const pts = [[cx, cy], [cx + rad, cy], [cx - rad, cy], [cx, cy + rad], [cx, cy - rad]];
        const covered = pts.filter(([x, y]) => { const e = document.elementFromPoint(x, y); return !e || !tick.contains(e); }).length;
        tick.style.pointerEvents = '';
        const clipped = []; for (let a = tick.parentElement; a && a !== document.body; a = a.parentElement) { const o = getComputedStyle(a); if (o.overflowX !== 'visible' || o.overflowY !== 'visible') clipped.push(a.className || a.tagName); }
        return { covered, clipped };
      }, slug);
      t.eq(hitInfo, { covered: 0, clipped: [] }, `${who}: covered / clipped`);
      await page.screenshot({ path: `${OUT}/selected-${slug}-${vp.width}.png`, clip: { x: 0, y: 0, width: vp.width, height: Math.min(vp.height, 330) } });
    }
    await page.close();
  }
});

await t.step('platform buttons at every viewport (280 px Galaxy Fold … 1920 px): logo, label and tagline stay inside their own card, the two cards never overlap and the tick is never off-screen', async () => {
  const widths = [280, 300, 320, 340, 359, 360, 375, 390, 412, 480, 639, 640, 768, 1024, 1366, 1920];
  const problems = [];
  for (const w of widths) {
    const page = await open({ width: w, height: 800, mobile: w < 700 });
    await page.goto(BASE + '/', { waitUntil: 'load' }); await page.waitForTimeout(500);
    const r = await page.evaluate(() => {
      const R = (e) => e.getBoundingClientRect(), doc = document.documentElement;
      const btns = [...document.querySelectorAll('.platform-btn')], range = document.createRange();
      return { overflow: doc.scrollWidth - doc.clientWidth, vw: doc.clientWidth, btns: btns.map((b) => {
        const br = R(b), fits = {};
        for (const sel of ['.platform-name', '.platform-tag', '.platform-logo']) {
          const e = b.querySelector(sel); if (!e || getComputedStyle(e).display === 'none') continue;
          range.selectNodeContents(e); const x = sel === '.platform-logo' ? R(e) : range.getBoundingClientRect();
          fits[sel] = x.left >= br.left + 2 && x.right <= br.right - 2 && x.top >= br.top && x.bottom <= br.bottom;
        }
        const tk = R(b.querySelector('.platform-check'));
        return { id: b.id, l: br.left, r: br.right, h: br.height, fits, tickRight: tk.right, tickLeft: tk.left };
      }) };
    });
    for (const b of r.btns) {
      for (const [sel, ok] of Object.entries(b.fits)) if (!ok) problems.push(`${w}px ${b.id}: ${sel} sticks out of its card`);
      if (b.tickRight > r.vw + 0.5 || b.tickLeft < 0) problems.push(`${w}px ${b.id}: tick off-screen`);
    }
    if (r.btns[0].r > r.btns[1].l + 0.5) problems.push(`${w}px: the two platform cards overlap (${r.btns[0].r.toFixed(1)} > ${r.btns[1].l.toFixed(1)})`);
    if (Math.abs(r.btns[0].h - r.btns[1].h) > 8) problems.push(`${w}px: unequal card heights ${r.btns[0].h.toFixed(0)} vs ${r.btns[1].h.toFixed(0)}`);
    if (r.overflow > 0) problems.push(`${w}px: horizontal overflow ${r.overflow}px`);
    await page.close();
  }
  t.eq(problems, [], problems.slice(0, 8).join('\n   '));
});

await t.step('no diagonal line: the pixels of the tick are identical at 8 positions of the animated border and with the border switched off', async () => {
  for (const vp of [DESKTOP, PHONE]) {
    const page = await open(vp, { dpr: 2 });
    await home(page);
    for (const slug of SLUGS) {
      await pick(page, slug);
      const s = await snapshot(page, slug);
      t.eq(await ringAnimations(page, slug), 1, `${vp.width}px ${slug}: found the border animation`);
      const m = 8, D = 2, R0 = s.tick.w / 2;
      const clip = { x: s.tick.l - m, y: s.tick.t - m, width: s.tick.w + 2 * m, height: s.tick.h + 2 * m };
      const cx = (m + R0) * D, cy = (m + R0) * D, inner = 0.78 * R0 * D;
      const disc = (x, y) => Math.hypot(x + 0.5 - cx, y + 0.5 - cy) <= inner;
      const frames = [];
      for (let k = 0; k < 8; k++) { await ringAt(page, k * 625); await page.waitForTimeout(60); frames.push(await shot(page, clip)); }
      await hideRing(page); await page.waitForTimeout(80);
      const reference = await shot(page, clip);
      await showRing(page);
      await ringAnimations(page, slug);                              // the pseudo-element (and so its animation) was re-created
      const who = `${vp.width}px ${slug}`;
      // anti-aliasing noise on the check's edge stays under ~20 levels; a ring band crossing the disc would change dozens of pixels by 100+
      frames.forEach((f, k) => {
        const loud = diffIn(f, reference, disc, 40), quiet = diffIn(f, reference, disc, 6);
        t.ok(loud.n === 0 && quiet.n <= 40, `${who}: frame ${k * 45}° differs from the border-less reference in ${loud.n} px by > 40 levels and ${quiet.n} px by > 6 (max ${loud.max})`);
      });
      // the border really does move (so a green result is not an artefact): a strip of the card's top edge differs between angles
      const edge = await page.evaluate((slug) => { const r = document.getElementById('tab-' + slug).getBoundingClientRect(); return { x: r.left + r.width * 0.25, y: r.top - 3, width: r.width * 0.3, height: 7 }; }, slug);
      await ringAt(page, 0); await page.waitForTimeout(60); const e0 = await shot(page, edge);
      await ringAt(page, 2500); await page.waitForTimeout(60); const e1 = await shot(page, edge);
      t.ok(diffIn(e0, e1, () => true, 20).n > 20, `${who}: the border ring itself changes with its angle`);
      // what is inside the disc: only the pink→red gradient and the white check
      let white = 0, minX = 1e9, maxX = -1, minY = 1e9, maxY = -1, off = 0;
      for (let y = 0; y < reference.height; y++) for (let x = 0; x < reference.width; x++) {
        if (!disc(x, y)) continue;
        const c = px(reference, x, y);
        if (isWhite(c)) { white++; minX = Math.min(minX, x); maxX = Math.max(maxX, x); minY = Math.min(minY, y); maxY = Math.max(maxY, y); }
        else if (Math.min(...c) < 150) { const h = hue(c); if (!((h >= 325 || h <= 10) && sat(c) >= 0.3)) off++; }
      }
      t.eq(off, 0, `${who}: pixels inside the disc that are neither white nor pink/red`);
      t.ok(white >= 60, `${who}: white check pixels ${white}`);
      const bw = (maxX - minX + 1) / D, bh = (maxY - minY + 1) / D;
      t.ok(bw >= 0.3 * s.tick.w && bh >= 0.2 * s.tick.w, `${who}: check shape ${bw}×${bh}`);
      t.ok(Math.abs((minX + maxX) / 2 / D - (m + R0)) <= 1.6 && Math.abs((minY + maxY) / 2 / D - (m + R0)) <= 1.6, `${who}: the white check is centred in the disc`);
      const tl = px(reference, Math.round(cx - 0.55 * R0 * D), Math.round(cy - 0.55 * R0 * D)), br = px(reference, Math.round(cx + 0.55 * R0 * D), Math.round(cy + 0.55 * R0 * D));
      const hTL = hue(tl), hBR = hue(br) < 20 ? hue(br) + 360 : hue(br);
      t.ok(hTL >= 320 && hTL < hBR && Math.abs(tl[2] - br[2]) >= 25, `${who}: pink (${tl}) → red (${br}) gradient across the disc`);
      await ringResume(page);
    }
    await page.close();
  }
});

await t.step('glow: a clearly visible pink/violet halo around the selected card (and nothing but the card\'s own state around the unselected one), not excessive', async () => {
  for (const vp of [DESKTOP, PHONE]) {
    const page = await open(vp, { dpr: 2 });
    await home(page);
    const D = 2;
    const stats = (img, clip, box, side, d0, d1) => {
      let n = 0, g = 0, minG = 255;
      for (let d = d0; d <= d1; d += 0.5) for (let u = 0.3; u <= 0.7; u += 0.02) {
        const [x, y] = side === 'left' ? [box.l - d, box.t + box.h * u] : side === 'right' ? [box.r + d, box.t + box.h * u] : side === 'top' ? [box.l + box.w * u, box.t - d] : [box.l + box.w * u, box.b + d];
        const c = px(img, Math.round((x - clip.x) * D), Math.round((y - clip.y) * D)); g += c[1]; minG = Math.min(minG, c[1]); n++;
      }
      return { g: g / n, minG };
    };
    for (const slug of SLUGS) {
      const other = SLUGS.find((x) => x !== slug);
      await pick(page, slug);
      const sel = await snapshot(page, slug);
      const cx0 = Math.max(0, sel.btn.l - 40);
      const clip = { x: cx0, y: Math.max(0, sel.btn.t - 30), width: Math.min(sel.btn.w + 80, vp.width - cx0), height: sel.btn.h + 60 };
      const withGlow = await shot(page, clip);
      const box = { l: sel.btn.l, r: sel.btn.r, t: sel.btn.t, b: sel.btn.b, w: sel.btn.w, h: sel.btn.h };
      await pick(page, other);
      const uns = await snapshot(page, slug);
      const noGlow = await shot(page, clip);
      // the card has not moved between the two shots by more than its 2.5 % selection scale
      const box0 = { l: uns.btn.l, r: uns.btn.r, t: uns.btn.t, b: uns.btn.b, w: uns.btn.w, h: uns.btn.h };
      const sides = slug === 'instagram' ? ['left', 'top', 'bottom'] : ['right', 'top', 'bottom'];       // the 4th side faces the neighbouring card
      for (const side of sides) {
        const who = `${vp.width}px ${slug} ${side}`;
        const near = [stats(withGlow, clip, box, side, 3, 6), stats(noGlow, clip, box0, side, 3, 6)];
        const mid = [stats(withGlow, clip, box, side, 6, 12), stats(noGlow, clip, box0, side, 6, 12)];
        t.ok(near[0].g - near[1].g <= -25, `${who}: halo 3–6 px out tints green channel by ${(near[0].g - near[1].g).toFixed(1)} (needs ≤ −25 to be clearly visible)`);
        t.ok(mid[0].g - mid[1].g <= -12, `${who}: halo 6–12 px out tints green channel by ${(mid[0].g - mid[1].g).toFixed(1)} (needs ≤ −12)`);
        t.ok(near[0].g >= 130 && near[0].minG >= 100, `${who}: not excessively bright — mean G ${near[0].g.toFixed(0)}, darkest G ${near[0].minG}`);
        t.ok(near[1].g >= 215, `${who}: the unselected card shows no halo (mean G ${near[1].g.toFixed(0)})`);
      }
      // computed style: selected has a pink and a violet halo, the unselected one has the same layers fully transparent
      const ls = layers(sel.btnShadow), lu = layers(uns.btnShadow);
      t.ok(ls.filter((l) => !l.inset && l.blur >= 12 && l.a >= 0.15).length >= 2, `${vp.width}px ${slug}: halo layers ${sel.btnShadow}`);
      t.ok(ls.some((l) => l.r >= 240 && l.g <= 80 && l.b >= 100 && l.blur >= 12 && l.a >= 0.15), `${vp.width}px ${slug}: a pink halo`);
      t.ok(ls.some((l) => l.r >= 150 && l.r <= 190 && l.b >= 230 && l.blur >= 12 && l.a >= 0.15), `${vp.width}px ${slug}: a violet halo`);
      t.eq(ls.length, lu.length, `${vp.width}px ${slug}: same number of shadow layers in both states (so they can be tweened)`);
      t.ok(ls.every((l, i) => l.inset === lu[i].inset), `${vp.width}px ${slug}: same inset-ness layer by layer`);
      t.eq(lu.filter((l) => !l.inset && l.blur >= 12 && l.a > 0 && l.r > 150 && l.g < 100).length, 0, `${vp.width}px ${slug}: unselected has no halo: ${uns.btnShadow}`);
      t.eq([uns.tickVisibility, uns.tickOpacity, uns.washOpacity, uns.ringOpacity, uns.selected, uns.tabindex], ['hidden', 0, 0, 0, 'false', '-1'], `${vp.width}px ${slug}: unselected tick / wash / border / ARIA`);
      await page.screenshot({ path: `${OUT}/glow-${slug}-${vp.width}.png`, clip: { x: 0, y: Math.max(0, sel.btn.t - 40), width: vp.width, height: sel.btn.h + 80 } }).catch(() => {});
    }
    await page.close();
  }
});

await t.step('switching: the tick, glow and ARIA state follow the platform (mouse, keyboard, #hash, history); exactly one tick is ever visible', async () => {
  const page = await open(DESKTOP);
  await home(page);
  const state = async (label, slug) => {
    await page.waitForTimeout(1000);
    const other = SLUGS.find((x) => x !== slug);
    t.eq(await tickCount(page), 1, `${label}: exactly one visible tick`);
    const a = await snapshot(page, slug), b = await snapshot(page, other);
    t.eq([a.selected, a.tabindex, a.tickVisibility, a.washOpacity > 0.99, a.ringOpacity > 0.99], ['true', '0', 'visible', true, true], `${label}: ${slug} selected`);
    t.eq([b.selected, b.tabindex, b.tickVisibility, b.tickOpacity, b.washOpacity], ['false', '-1', 'hidden', 0, 0], `${label}: ${other} not selected`);
    t.eq(await page.locator(`[data-panel="${slug}"]`).isVisible(), true, `${label}: ${slug} panel visible`);
    t.eq(await page.locator(`[data-panel="${other}"]`).isVisible(), false, `${label}: ${other} panel hidden`);
    t.ok(a.tick.cx > a.btn.l + a.btn.w / 2 && a.tick.cy < a.btn.t + a.btn.h / 2, `${label}: the tick is at the top-right of the ${slug} card`);
  };
  await state('initial', 'instagram');
  for (let i = 0; i < 3; i++) {
    await page.click('#tab-youtube'); await state(`click YouTube #${i + 1}`, 'youtube');
    await page.click('#tab-instagram'); await state(`click Instagram #${i + 1}`, 'instagram');
  }
  await page.locator('#tab-instagram').focus();
  await page.keyboard.press('ArrowRight'); await state('ArrowRight', 'youtube');
  await page.keyboard.press('ArrowLeft'); await state('ArrowLeft', 'instagram');
  await page.keyboard.press('End'); await state('End', 'youtube');
  await page.keyboard.press('Home'); await state('Home', 'instagram');
  await page.evaluate(() => { location.hash = '#youtube'; }); await state('#youtube', 'youtube');
  await page.goBack(); await state('history back', 'instagram');
  await page.close();
  const fresh = await open(DESKTOP);
  await home(fresh, '#youtube');
  t.eq((await snapshot(fresh, 'youtube')).selected, 'true', 'opening /#youtube selects YouTube');
  t.eq(await tickCount(fresh), 1, 'opening /#youtube: one tick');
  await fresh.close();
});

await t.step('smooth transition: the glow, the wash and the tick are CSS transitions that interpolate (checked at fixed times through the Web Animations API), and settle within 0.9 s', async () => {
  const page = await open(DESKTOP);
  await home(page);
  await page.mouse.move(0, 0);
  const run = await page.evaluate(async () => {
    const a = document.getElementById('tab-instagram'), b = document.getElementById('tab-youtube');
    const halo = (el) => { const m = [...getComputedStyle(el).boxShadow.matchAll(/rgba?\(([^)]+)\)/g)].map((x) => x[1].split(',').map(Number)); return m[1] ? +(m[1][3] ?? 1).toFixed(3) : 0; };
    const tick = (el) => el.querySelector('.platform-check');
    b.click();
    await new Promise((r) => requestAnimationFrame(r));                          // style recalculation: the transitions now exist
    const trs = document.getAnimations().filter((x) => x.constructor.name === 'CSSTransition');
    const names = trs.map((x) => `${x.effect.target.id || (x.effect.target.className || '').split(' ')[0]}${x.effect.pseudoElement || ''}:${x.transitionProperty}`);
    trs.forEach((x) => x.pause());
    const rows = [0, 60, 120, 200, 300, 500].map((ms) => {
      trs.forEach((x) => { x.currentTime = ms; });
      const tr = getComputedStyle(tick(b)).transform;
      return { ms, bHalo: halo(b), aHalo: halo(a), bWash: +getComputedStyle(b, '::after').opacity, aWash: +getComputedStyle(a, '::after').opacity, bTick: +getComputedStyle(tick(b)).opacity, bScale: tr === 'none' ? 1 : +tr.match(/matrix\(([^,]+)/)[1] };
    });
    trs.forEach((x) => { x.finish(); });
    return { names, rows };
  });
  for (const n of ['tab-youtube:box-shadow', 'tab-instagram:box-shadow', 'tab-youtube::after:opacity', 'tab-instagram::after:opacity', 'platform-check:opacity', 'platform-check:transform']) t.ok(run.names.includes(n), `transition ${n} exists (got ${run.names})`);
  const col = (k) => run.rows.map((r) => r[k]);
  const mids = (arr, lo, hi) => arr.filter((v) => v > lo && v < hi).length;
  const up = (arr) => arr.every((v, i) => i === 0 || v >= arr[i - 1] - 1e-9), down = (arr) => arr.every((v, i) => i === 0 || v <= arr[i - 1] + 1e-9);
  t.ok(col('bHalo')[0] < 0.02 && col('bHalo').at(-1) > 0.37 && up(col('bHalo')) && mids(col('bHalo'), 0.01, 0.37) >= 3, `new glow grows smoothly: ${col('bHalo')}`);
  t.ok(col('aHalo')[0] > 0.37 && col('aHalo').at(-1) < 0.02 && down(col('aHalo')) && mids(col('aHalo'), 0.01, 0.37) >= 3, `old glow fades out smoothly: ${col('aHalo')}`);
  t.ok(col('bWash')[0] === 0 && col('bWash').at(-1) === 1 && up(col('bWash')) && mids(col('bWash'), 0.02, 0.98) >= 3, `selected wash fades in: ${col('bWash')}`);
  t.ok(col('aWash')[0] === 1 && col('aWash').at(-1) === 0 && down(col('aWash')) && mids(col('aWash'), 0.02, 0.98) >= 3, `old wash fades out: ${col('aWash')}`);
  t.ok(col('bTick')[0] === 0 && col('bTick').at(-1) === 1 && mids(col('bTick'), 0.05, 0.95) >= 1, `the tick fades in: ${col('bTick')}`);
  t.ok(col('bScale')[0] < 0.5 && mids(col('bScale'), 0.5, 1.2) >= 2, `the tick scales up: ${col('bScale')}`);
  // and for real, without the Web Animations API: after 0.9 s everything is at rest
  await page.waitForTimeout(900);
  t.eq(await tickCount(page), 1);
  const [y, i] = [await snapshot(page, 'youtube'), await snapshot(page, 'instagram')];
  t.ok(shadowAlphas(y.btnShadow)[1] >= 0.35 && shadowAlphas(i.btnShadow)[1] === 0, `rest state: ${y.btnShadow} / ${i.btnShadow}`);
  await page.close();
});

await t.step('reduced motion: the end state is still correct (tick, glow) when animations are switched off by the visitor', async () => {
  const page = await open(DESKTOP, { dpr: 1 });
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.click('#tab-youtube'); await page.waitForTimeout(500);              // the tick keeps its 50 ms stagger delay, the durations collapse to ~0
  const y = await snapshot(page, 'youtube'), i = await snapshot(page, 'instagram');
  t.eq([y.tickOpacity, y.tickVisibility, y.washOpacity], [1, 'visible', 1]);
  t.ok(shadowAlphas(y.btnShadow)[1] >= 0.35, 'glow at full strength: ' + y.btnShadow);
  t.eq([i.tickOpacity, i.tickVisibility, i.washOpacity], [0, 'hidden', 0]);
  await page.close();
});

await t.step('hover and focus: the tick stays on top of the border ring while the selected card is hovered / keyboard-focused, and an unselected card still shows no tick', async () => {
  const page = await open(DESKTOP, { dpr: 2 });
  await home(page);
  await pick(page, 'instagram');
  const frame = async (angleMs) => {
    const s = await snapshot(page, 'instagram');
    const m = 8, D = 2, R0 = s.tick.w / 2;
    const clip = { x: s.tick.l - m, y: s.tick.t - m, width: s.tick.w + 2 * m, height: s.tick.h + 2 * m };
    await ringAnimations(page, 'instagram'); await ringAt(page, angleMs); await page.waitForTimeout(80);
    return { img: await shot(page, clip), disc: (x, y) => Math.hypot(x + 0.5 - (m + R0) * D, y + 0.5 - (m + R0) * D) <= 0.78 * R0 * D, s };
  };
  const rest = await frame(0);
  await page.hover('#tab-instagram'); await page.waitForTimeout(700);
  const hovered = await frame(1250);
  t.eq([hovered.s.tickOpacity, hovered.s.tickVisibility], [1, 'visible'], 'hover: tick');
  await page.mouse.move(0, 0); await page.waitForTimeout(500);
  await page.keyboard.press('ArrowRight'); await page.waitForTimeout(500);       // the tabs' own keyboard handling moves the focus ring (:focus-visible) …
  await page.keyboard.press('ArrowLeft'); await page.waitForTimeout(900);        // … and back to Instagram
  t.eq(await page.evaluate(() => document.activeElement.id), 'tab-instagram');
  const focused = await frame(3125);
  t.eq([focused.s.tickOpacity, focused.s.tickVisibility, focused.s.ringOpacity], [1, 'visible', 1], 'keyboard focus: tick + ring');
  for (const [name, f] of [['hovered', hovered], ['keyboard-focused', focused]]) {
    const loud = diffIn(rest.img, f.img, rest.disc, 40), quiet = diffIn(rest.img, f.img, rest.disc, 6);
    t.ok(loud.n === 0 && quiet.n <= 40, `the disc looks the same ${name} as at rest and at another border angle (${loud.n} px by > 40 levels, ${quiet.n} px by > 6, max ${loud.max})`);
  }
  await page.mouse.move(0, 0);
  await page.hover('#tab-youtube'); await page.waitForTimeout(600);
  const y = await snapshot(page, 'youtube');
  t.eq([y.tickOpacity, y.tickVisibility, y.washOpacity], [0, 'hidden', 0], 'hovering the UNselected card does not show a tick or the selected wash');
  await page.close();
});

await t.step('service cards still lift on hover after a platform switch (the fade-in animation must not pin their transform)', async () => {
  const page = await open(DESKTOP);
  await home(page);
  for (const slug of ['youtube', 'instagram', 'youtube']) {
    await pick(page, slug);
    const card = page.locator(`[data-panel="${slug}"] [data-service]`).nth(1);
    await card.scrollIntoViewIfNeeded(); await page.mouse.move(0, 0); await page.waitForTimeout(500);
    const rest = await card.evaluate((e) => getComputedStyle(e).transform);
    await card.hover(); await page.waitForTimeout(700);
    const lifted = await card.evaluate((e) => getComputedStyle(e).transform);
    t.eq([rest === 'none' || rest === 'matrix(1, 0, 0, 1, 0, 0)', lifted], [true, 'matrix(1, 0, 0, 1, 0, -3)'], `${slug}: hover lift`);
  }
  await page.close();
});

await t.step('no console errors, CSP violations, failed requests or HTTP errors on any page of this suite', async () => {
  const all = pages.flatMap((p) => p.problems).filter((l) => !/status of 422|\[http 422\]/.test(l));   // the one 422 is the admin form refusing a typed TRIAL PACK, on purpose
  t.eq(all, [], all.slice(0, 5).join('\n   '));
});

console.log(`\nscreenshots: ${OUT}`);
const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
