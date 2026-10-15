// Storefront QA for two details of the home page:
//   1. the TRIAL PACK badge — shown automatically on every service whose configured selling price is exactly ₹5 (both platforms), driven by
//      the price in the database (not by names or stored text), once per card, never overlapping the title / price / icon / BUY NOW button;
//   2. the selected-platform indicator — a pink→red tick at the card's top-right corner that nothing is ever drawn across (no diagonal line),
//      a visible pink/violet glow around the selected card, smooth switching between Instagram and YouTube, a clean unselected card.
// Geometry is measured in the DOM at 8 viewports (320 px phones … 1920 px desktops); the tick and the glow are checked pixel by pixel
// on screenshots. Screenshots for visual review land in $QA_OUT (default /tmp/qa/badges).
//   NO_BASELINE=1 skips the dev-database reset (use it with BASE=… DB_NAME=… against another copy of the site).
import { execFileSync } from 'node:child_process';
import { PNG } from 'pngjs';
import { launch, newPage, BASE, ROOT, sql, rows, hexToRgb, contrast, resetLimits } from './site.mjs';
import { login, go } from './admin.mjs';
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
    const trial = [...card.querySelectorAll('.svc-badge-trial')];
    const badge = trial[0] || null;
    const base = { id: Number(card.dataset.id), title: card.querySelector('.svc-title').textContent.trim(), price: card.querySelector('.svc-price').textContent.trim(),
      trialCount: trial.length, texts: badges.map((b) => b.textContent.trim()), pageOverflow: doc.scrollWidth - doc.clientWidth, vw: doc.clientWidth };
    if (!badge) return base;
    const c = R(card), b = R(badge);
    const parts = Object.fromEntries(['.svc-title', '.svc-sub', '.svc-price', '.svc-icon', '.svc-chip', '[data-action=buy]'].map((s) => [s, R(card.querySelector(s))]));
    const overlaps = Object.entries(parts).filter(([, r]) => hit(b, r)).map(([s]) => s);
    const rowTop = R(card.querySelector('.svc-top')).t;
    const neighbours = [...panel.querySelectorAll('[data-service]')].filter((x) => x !== card).map(R).filter((r) => hit(b, r)).length + heads.map(R).filter((r) => hit(b, r)).length;
    const cs = getComputedStyle(badge);
    // is anything on top of it / is it clipped by an ancestor?  (the container ignores the pointer, so allow hit-testing for a moment)
    const box = badge.parentElement; box.style.pointerEvents = 'auto';
    card.scrollIntoView({ block: 'center', behavior: 'instant' });
    const bb = R(badge);
    const pts = [[bb.l + bb.w / 2, bb.t + bb.h / 2], [bb.l + 12, bb.t + bb.h / 2], [bb.r - 12, bb.t + bb.h / 2], [bb.l + bb.w / 2, bb.t + 4], [bb.l + bb.w / 2, bb.b - 4]];
    const covered = pts.filter(([x, y]) => { const e = document.elementFromPoint(x, y); return !e || !badge.contains(e); }).length;
    box.style.pointerEvents = '';
    const clipped = [];
    for (let a = badge.parentElement; a && a !== document.body; a = a.parentElement) {
      const o = getComputedStyle(a);
      if (o.overflowX !== 'visible' || o.overflowY !== 'visible') { const r = R(a); if (bb.l < r.l - 0.5 || bb.r > r.r + 0.5 || bb.t < r.t - 0.5 || bb.b > r.b + 0.5) clipped.push(a.className || a.tagName); }
    }
    return { ...base, rect: { card: c, badge: b }, overlaps, neighbours, covered, clipped,
      alignRight: Math.abs(b.r - parts['.svc-price'].r), straddles: b.t < c.t && b.b > c.t, within: b.l >= c.l - 0.5 && b.r <= c.r + 0.5,
      gap: rowTop - b.b, truncated: badge.scrollWidth > badge.clientWidth + 0.5,
      style: { bg: cs.backgroundImage, color: cs.color, weight: Number(cs.fontWeight), size: cs.fontSize, transform: cs.textTransform, radius: parseFloat(cs.borderTopLeftRadius), h: b.h, shadow: cs.boxShadow, z: getComputedStyle(box).zIndex } };
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
    const colour = layer.match(/rgba?\([^)]*\)/)[0], c = colour.match(/[\d.]+/g).map(Number);
    const n = (layer.replace(colour, '').match(/-?[\d.]+(?=px)/g) || []).map(Number);
    return { r: c[0], g: c[1], b: c[2], a: c.length > 3 ? c[3] : 1, x: n[0], y: n[1], blur: n[2] || 0, spread: n[3] || 0, inset: /\binset\b/.test(layer) };
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

// ═══════════════════════════════ 1 · TRIAL PACK badge ═══════════════════════════════
const dt = await open(DESKTOP);
t.page = dt;
await home(dt);
const cat = catalogue();
const five = cat.filter((s) => s.paise === 500);

await t.step('catalogue under test: the six ₹5 packs (3 Instagram + 3 YouTube) and the dearer services', async () => {
  t.eq(cat.length, 14);
  t.eq(five.map((s) => s.code).sort(), ['ig-followers-50', 'ig-likes-100', 'ig-views-1k', 'yt-likes-100', 'yt-subs-50', 'yt-views-10']);
  t.eq(five.filter((s) => s.platform === 'instagram').length, 3);
  t.eq(five.filter((s) => s.platform === 'youtube').length, 3);
});

await t.step('every ₹5 card on BOTH platforms shows TRIAL PACK, no other card does (checked against the prices in the database)', async () => {
  for (const slug of SLUGS) {
    await pick(dt, slug);
    const cards = await measureCards(dt, slug);
    const expected = cat.filter((s) => s.platform === slug);
    t.eq(cards.length, expected.length, `${slug}: cards on the page`);
    for (const e of expected) {
      const c = cards.find((x) => x.id === e.id);
      t.ok(c, `${slug}: card for ${e.code} exists`);
      t.eq(c.price, rupees(e.paise), `${e.code}: the price on the card is the price in the database`);
      t.eq(c.trialCount, e.paise === 500 ? 1 : 0, `${slug} · ${c.title} (${c.price}): TRIAL PACK badges`);
      if (e.paise === 500) t.ok(c.texts.includes('TRIAL PACK'), `${c.title}: badge text`);
    }
  }
  await dt.screenshot({ path: `${OUT}/trial-youtube-desktop.png` });
});

await t.step('no duplicate badges: one TRIAL PACK element per ₹5 card, and none anywhere else on the page', async () => {
  t.eq(await dt.locator('.svc-badge-trial').count(), five.length, 'trial badge elements in the whole document');
  for (const slug of SLUGS) {
    const cards = await measureCards(dt, slug);
    for (const c of cards) t.ok(c.texts.filter((x) => /trial\s*pack/i.test(x)).length <= 1, `${c.title}: duplicate badge text ${JSON.stringify(c.texts)}`);
  }
  t.eq(await dt.locator('.svc-badges').count(), five.length + 1, 'badge containers: the six trial cards + the premium card');
});

await t.step('badge design: premium red gradient, bold white uppercase text, pill shape, shadow + glow, white text contrast ≥ 4.5 on every gradient stop', async () => {
  await pick(dt, 'instagram');
  const c = (await measureCards(dt, 'instagram')).find((x) => x.trialCount);
  const s = c.style;
  t.ok(s.bg.startsWith('linear-gradient'), 'gradient background: ' + s.bg);
  const stops = [...s.bg.matchAll(/rgb\((\d+), (\d+), (\d+)\)/g)].map((m) => m.slice(1, 4).map(Number));
  t.ok(stops.length >= 3, 'at least three colour stops: ' + s.bg);
  for (const [r, g, b] of stops) { t.ok(r >= 150 && g <= 60 && b <= 70, `stop rgb(${r},${g},${b}) is a strong red`); }
  stops.forEach((rgb) => t.ok(contrast(rgb, [255, 255, 255]) >= 4.5, `white on rgb(${rgb}) = ${contrast(rgb, [255, 255, 255]).toFixed(2)}:1`));
  t.ok(stops[0].join() !== stops[stops.length - 1].join(), 'the gradient actually changes colour');
  t.eq(s.color, 'rgb(255, 255, 255)', 'white text');
  t.ok(s.weight >= 800, 'bold: ' + s.weight);
  t.eq(s.transform, 'uppercase');
  t.ok(s.radius >= s.h / 2, `fully rounded: radius ${s.radius} for height ${s.h}`);
  const ls = layers(s.shadow);
  t.ok(ls.length >= 3, 'shadow layers: ' + s.shadow);
  t.ok(ls.some((l) => !l.inset && l.r > 150 && l.g < 100 && l.blur >= 10), 'a red glow layer: ' + s.shadow);
  t.ok(ls.some((l) => !l.inset && l.r === 255 && l.g === 255 && l.b === 255 && l.spread >= 1.5), 'a white ring that keeps the card border away from the pill');
  t.ok(s.h >= 20 && s.h <= 28, 'compact height: ' + s.h);
});

await t.step('placement: on the card top edge, right-aligned with the price, clear of title / price / icon / chip / BUY NOW and of neighbouring cards and headings (desktop, both platforms)', async () => {
  for (const slug of SLUGS) {
    await pick(dt, slug);
    for (const c of (await measureCards(dt, slug)).filter((x) => x.trialCount)) {
      const who = `${slug} · ${c.title}`;
      t.eq(c.overlaps, [], `${who}: overlaps`);
      t.eq(c.neighbours, 0, `${who}: touches a neighbouring card / heading`);
      t.ok(c.straddles, `${who}: sits on the top edge`);
      t.ok(c.within, `${who}: inside the card horizontally`);
      t.ok(c.alignRight <= 2, `${who}: right edge ${c.alignRight.toFixed(1)} px off the price column`);
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
      const trial = cards.filter((c) => c.trialCount);
      if (trial.length !== 3) problems.push(`${vp.name} ${slug}: ${trial.length} trial cards`);
      if (cards[0].pageOverflow > 0) problems.push(`${vp.name} ${slug}: page overflows by ${cards[0].pageOverflow}px`);
      for (const c of trial) {
        const who = `${vp.name} ${slug} ${c.title}`;
        if (c.overlaps.length) problems.push(`${who}: overlaps ${c.overlaps}`);
        if (c.neighbours) problems.push(`${who}: touches ${c.neighbours} neighbour(s)`);
        if (!c.straddles || !c.within) problems.push(`${who}: misplaced (straddles ${c.straddles}, within ${c.within})`);
        if (c.alignRight > 2) problems.push(`${who}: ${c.alignRight.toFixed(1)}px off the price column`);
        if (c.gap < 4) problems.push(`${who}: only ${c.gap.toFixed(1)}px above the title row`);
        if (c.covered) problems.push(`${who}: covered`);
        if (c.clipped.length) problems.push(`${who}: clipped by ${c.clipped}`);
        if (c.truncated) problems.push(`${who}: truncated`);
        if (c.rect.badge.l < 0 || c.rect.badge.r > c.vw) problems.push(`${who}: outside the viewport`);
      }
      // the cards that are NOT trial packs must be untouched by all of this
      for (const c of cards.filter((x) => !x.trialCount)) if (c.texts.some((x) => /trial/i.test(x))) problems.push(`${vp.name} ${slug} ${c.title}: unexpected badge ${c.texts}`);
    }
    await page.screenshot({ path: `${OUT}/home-${vp.name}.png`, fullPage: true });
    await page.close();
  }
  t.eq(problems, [], problems.slice(0, 8).join('\n   '));
});

await t.step('stress: a 40-character custom badge beside TRIAL PACK on a 320 px phone stays inside the card, truncates with an ellipsis and causes no overflow', async () => {
  touch('ig-likes-100', "badge = 'WWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWWW', badge_color = 'purple'");
  try {
    for (const vp of [VIEWPORTS[0], VIEWPORTS[2]]) {
      const page = await open(vp);
      await home(page);
      const info = await page.evaluate(() => {
        const card = [...document.querySelectorAll('[data-panel="instagram"] [data-service]')].find((c) => c.querySelector('.svc-title').textContent.trim() === '100 Likes');
        const R = (e) => { const r = e.getBoundingClientRect(); return { l: r.left, r: r.right, t: r.top, b: r.bottom }; };
        const trial = card.querySelector('[data-badge="trial"]'), custom = card.querySelector('[data-badge="custom"]');
        const cs = getComputedStyle(custom), doc = document.documentElement;
        return { card: R(card), trial: R(trial), custom: R(custom), trialCut: trial.scrollWidth > trial.clientWidth + 0.5, customCut: custom.scrollWidth > custom.clientWidth + 0.5,
          ellipsis: cs.textOverflow, overflow: cs.overflowX, overflowPx: doc.scrollWidth - doc.clientWidth, title: R(card.querySelector('.svc-title')), price: R(card.querySelector('.svc-price')) };
      });
      const who = vp.name;
      t.ok(info.overflowPx <= 0, `${who}: horizontal overflow ${info.overflowPx}px`);
      t.ok(info.trial.l >= info.card.l && info.custom.r <= info.card.r + 0.5 && info.custom.l >= info.card.l, `${who}: both badges inside the card`);
      t.ok(!info.trialCut, `${who}: TRIAL PACK is never truncated`);
      t.eq([info.ellipsis, info.overflow], ['ellipsis', 'hidden'], `${who}: the custom badge is the one that gives way`);
      t.ok(info.custom.r <= info.trial.l + 0.5 || info.trial.r <= info.custom.l + 0.5, `${who}: the two badges do not overlap each other`);
      t.ok(Math.max(info.trial.b, info.custom.b) < Math.min(info.title.t, info.price.t), `${who}: both clear of the title / price row`);
      if (vp === VIEWPORTS[0]) await page.screenshot({ path: `${OUT}/stress-long-badge-320.png`, fullPage: false });
      await page.close();
    }
  } finally { touch('ig-likes-100', 'badge = NULL, badge_color = NULL'); }
});

await t.step('the badge follows the configured price: ₹5 shows it, any other amount hides it, ₹5 again brings it back — names and stored text play no part', async () => {
  const verify = async (label) => {
    const page = await open(DESKTOP);
    await home(page);
    const db = catalogue();
    for (const slug of SLUGS) {
      if (slug !== 'instagram') await pick(page, slug);
      for (const c of await measureCards(page, slug)) {
        const e = db.find((x) => x.id === c.id);
        t.eq(c.trialCount, e.paise === 500 ? 1 : 0, `${label}: ${e.code} at ${rupees(e.paise)}`);
      }
    }
    await page.close();
  };
  try {
    await verify('as seeded');
    touch('ig-likes-100', 'price_paise = 600');                      // ₹5 → ₹6: gone
    await verify('ig-likes-100 → ₹6');
    touch('ig-likes-100', 'price_paise = 500');                      // back to ₹5: back
    await verify('ig-likes-100 → ₹5 again');
    touch('ig-followers-100k', 'price_paise = 500');                 // a ₹99 card becomes a ₹5 card: it gets the badge
    touch('yt-views-10k', 'price_paise = 499');                      // ₹4.99 is not ₹5
    touch('yt-subs-50', 'price_paise = 501');                        // ₹5.01 is not ₹5
    await verify('ig-followers-100k → ₹5, yt-views-10k → ₹4.99, yt-subs-50 → ₹5.01');
    touch('yt-subs-100k', "badge = 'TRIAL PACK', badge_color = 'red'");      // stored text on a ₹99 card must not show …
    touch('ig-views-1k', "badge = 'trial  pack', badge_color = 'red'");      // … and on a ₹5 card it must not double up
    await verify('stored "TRIAL PACK" text');
  } finally {
    touch('ig-likes-100', 'price_paise = 500, badge = NULL, badge_color = NULL');
    touch('ig-followers-100k', 'price_paise = 9900');
    touch('yt-views-10k', 'price_paise = 9900');
    touch('yt-subs-50', 'price_paise = 500');
    touch('yt-subs-100k', 'badge = NULL, badge_color = NULL');
    touch('ig-views-1k', 'badge = NULL, badge_color = NULL');
  }
});

await t.step('a custom badge keeps working beside the automatic one (two different badges on a ₹5 card, one on a ₹99 card) and the premium card is unchanged', async () => {
  touch('ig-likes-100', "badge = 'HOT', badge_color = 'amber'");
  touch('yt-subs-100k', "badge = 'NEW', badge_color = 'blue'");
  try {
    const page = await open(DESKTOP);
    await home(page);
    const ig = await measureCards(page, 'instagram');
    const likes = ig.find((c) => c.title === '100 Likes');
    t.eq(likes.texts, ['TRIAL PACK', 'HOT']);
    t.eq(likes.overlaps, []); t.eq(likes.covered, 0);
    await pick(page, 'youtube');
    const yt = await measureCards(page, 'youtube');
    t.eq(yt.find((c) => c.title === '100K Subscribers').texts, ['NEW']);
    t.eq(yt.find((c) => c.title === '4,000 Hours Watch Time').texts, ['PREMIUM']);
    t.eq(yt.find((c) => c.title === '4,000 Hours Watch Time').trialCount, 0);
    const prem = await page.$eval('.svc-premium .svc-badge', (e) => { const cs = getComputedStyle(e); const r = e.getBoundingClientRect(), c = e.closest('.svc').getBoundingClientRect(); return { bg: cs.backgroundColor, straddles: r.top < c.top && r.bottom > c.top, right: Math.abs(r.right - (c.right - parseFloat(getComputedStyle(e.closest('.svc')).getPropertyValue('--svc-pad')) * 16)) }; });
    t.eq(prem.bg, 'rgb(109, 40, 217)', 'PREMIUM keeps its purple');
    t.ok(prem.straddles && prem.right <= 2, `PREMIUM sits on the same edge line: ${JSON.stringify(prem)}`);
    await page.close();
  } finally { touch('ig-likes-100', 'badge = NULL, badge_color = NULL'); touch('yt-subs-100k', 'badge = NULL, badge_color = NULL'); }
});

await t.step('BUY NOW on a TRIAL PACK card still works (the badge never intercepts a tap) on a phone', async () => {
  const page = await open(PHONE);
  await home(page);
  const card = page.locator('[data-panel="instagram"] [data-service]').filter({ has: page.locator('.svc-badge-trial') }).first();
  await card.scrollIntoViewIfNeeded();
  await card.locator('[data-action=buy]').click();
  await page.waitForFunction((el) => el.dataset.open === 'true', await card.elementHandle());
  t.ok(await card.locator('input[name=target]').isVisible(), 'the inline form opened');
  const m = await measureCards(page, 'instagram');
  const open1 = m.find((c) => c.trialCount && c.overlaps !== undefined);
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
    await page.waitForTimeout(700);
    const other = SLUGS.find((x) => x !== slug);
    t.eq(await tickCount(page), 1, `${label}: exactly one visible tick`);
    const a = await snapshot(page, slug), b = await snapshot(page, other);
    t.eq([a.selected, a.tabindex, a.tickVisibility, a.washOpacity, a.ringOpacity], ['true', '0', 'visible', 1, 1], `${label}: ${slug} selected`);
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

// ═══════════════════════════════ 3 · admin side ═══════════════════════════════
await t.step('admin → Services: the ₹5 services carry the automatic TRIAL PACK chip, the others do not; the editor explains it live and refuses a typed copy', async () => {
  const page = await open({ width: 1440, height: 900 }, { allowStatus: [422] });
  t.page = page;
  await login(page);
  await go(page, '/admin/services');
  const rowsWithChip = await page.locator('#services-root tbody tr').evaluateAll((trs) => trs.map((tr) => ({ title: tr.querySelector('.cell-main b').childNodes[0].textContent.trim(), price: tr.querySelector('td[data-label="Price"] b').textContent.trim(), chips: [...tr.querySelectorAll('.badge')].map((b) => b.textContent.trim()) })));
  for (const r of rowsWithChip) t.eq(r.chips.includes('TRIAL PACK'), r.price === '₹5', `${r.title} (${r.price}) admin chip`);
  t.eq(rowsWithChip.filter((r) => r.chips.includes('TRIAL PACK')).length, five.length);
  await page.locator('#services-root tbody tr').filter({ hasText: '100 Likes' }).first().locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  t.eq(await page.locator('#trial-hint').isVisible(), true, 'hint visible at ₹5');
  t.eq(await page.locator('#f-badge').inputValue(), '', 'the editor does not offer the automatic text');
  await page.fill('#f-price', '6'); t.eq(await page.locator('#trial-hint').isVisible(), false, 'hint hidden at ₹6');
  await page.fill('#f-price', '5.00'); t.eq(await page.locator('#trial-hint').isVisible(), true, 'hint back at ₹5.00');
  await page.fill('#f-price', '4.99'); t.eq(await page.locator('#trial-hint').isVisible(), false, 'hint hidden at ₹4.99');
  await page.fill('#f-price', '5');
  await page.fill('#f-badge', ' trial  PACK ');
  await page.click('#svc-save');
  await page.waitForSelector('#svc-form [data-err="badge"]');
  t.has(await page.locator('#svc-form [data-err="badge"]').innerText(), 'automatically');
  t.eq(sql("SELECT COALESCE(badge, '') FROM services WHERE code = 'ig-likes-100'"), '', 'nothing was saved');
  await page.fill('#f-badge', 'BEST SELLER');
  await page.click('#svc-save');
  await page.locator('.toast[data-type="success"]').last().waitFor();
  t.eq(sql("SELECT badge FROM services WHERE code = 'ig-likes-100'"), 'BEST SELLER');
  await page.close();
  const store = await open(DESKTOP);
  await home(store);
  const likes = (await measureCards(store, 'instagram')).find((c) => c.title === '100 Likes');
  t.eq(likes.texts, ['TRIAL PACK', 'BEST SELLER'], 'storefront: automatic + custom');
  await store.close();
  touch('ig-likes-100', 'badge = NULL, badge_color = NULL');
});

await t.step('no console errors, CSP violations, failed requests or HTTP errors on any page of this suite', async () => {
  const all = pages.flatMap((p) => p.problems).filter((l) => !/status of 422|\[http 422\]/.test(l));   // the one 422 is the admin form refusing a typed TRIAL PACK, on purpose
  t.eq(all, [], all.slice(0, 5).join('\n   '));
});

console.log(`\nscreenshots: ${OUT}`);
const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
