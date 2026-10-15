// The service-link input (the field inside every service card): ONE thin pink border, ONE soft 2 px focus ring, no stacked glow.
// Measured on the rendered page at the pixel densities real devices have (browsers launched with --force-device-scale-factor, because
// emulated density makes headless Chromium snap borders to whole CSS pixels): computed styles AND the pixels of a screenshot.
// States: normal, focused, filled, invalid, valid, disabled — on a phone, an iOS-sized phone, a tablet and a desktop, in a normal and in the
// dark premium card. Screenshots are kept in $QA_OUT (default /tmp/qa/input).
import { execFileSync } from 'node:child_process';
import { PNG } from 'pngjs';
import { launch, newPage, BASE, resetLimits, mocksReset, openCard, useMockProviderIds, serviceId } from './site.mjs';
import { suite } from './harness.mjs';

const OUT = process.env.QA_OUT || '/tmp/qa/input';
const t = suite('site-input', OUT);
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: '/home/user/claude1', stdio: 'ignore' });
useMockProviderIds(); resetLimits(); await mocksReset();

const VIEWPORTS = [
  { name: 'android-360x800', width: 360, height: 800, dpr: 2, mobile: true },
  { name: 'ios-390x844', width: 390, height: 844, dpr: 3, mobile: true },
  { name: 'tablet-768x1024', width: 768, height: 1024, dpr: 2, mobile: true },
  { name: 'desktop-1440x900', width: 1440, height: 900, dpr: 1, mobile: false },
];
const PINK = 'rgb(255, 45, 135)', PINK_DARK = 'rgb(238, 17, 112)', RED = 'rgb(220, 38, 38)', GREEN = 'rgb(22, 163, 74)';
const shadowsOf = (s) => (s === 'none' ? [] : s.split(/,(?![^(]*\))/).map((x) => x.trim()));
const physical = (cssPx, dpr) => Math.max(1, Math.floor(cssPx * dpr + 1e-6));   // browsers snap a border to whole physical pixels (rounding down, at least 1)

// Headless Chromium only advances CSS transitions when a frame is produced, so wait for a frame and for every running animation of the
// field AND of its card to finish before reading a computed style (otherwise a colour caught mid-transition would be reported).
const settle = (input) => input.evaluate(async (el) => {
  const frame = () => new Promise((r) => requestAnimationFrame(() => r()));
  await frame(); await frame();
  for (let i = 0; i < 6; i++) {
    const running = [el, el.closest('.svc')].flatMap((n) => n.getAnimations()).filter((a) => a.playState === 'running');
    if (!running.length) break;
    await Promise.all(running.map((a) => a.finished.catch(() => {})));
    await frame();
  }
});
const probe = async (input) => { await settle(input); return probeNow(input); };
const probeNow = (input) => input.evaluate((el) => {
  const cs = getComputedStyle(el), r = el.getBoundingClientRect(), wrap = el.parentElement, w = getComputedStyle(wrap);
  const ic = wrap.querySelector('.icon').getBoundingClientRect(), card = el.closest('.svc'), ccs = getComputedStyle(card);
  const form = el.closest('.order-form'), fcs = getComputedStyle(form);
  return {
    box: { l: r.left, t: r.top, w: r.width, h: r.height }, dpr: devicePixelRatio,
    border: { w: [cs.borderTopWidth, cs.borderRightWidth, cs.borderBottomWidth, cs.borderLeftWidth], style: cs.borderTopStyle, color: cs.borderTopColor },
    bg: cs.backgroundColor, shadow: cs.boxShadow, outline: cs.outlineStyle, outlineWidth: cs.outlineWidth, radius: cs.borderTopLeftRadius, paddingLeft: parseFloat(cs.paddingLeft),
    wrapper: { shadow: w.boxShadow, border: w.borderTopWidth, outline: w.outlineStyle, bg: w.backgroundColor },
    form: { shadow: fcs.boxShadow, outline: fcs.outlineStyle }, cardShadow: ccs.boxShadow, cardBox: card.getBoundingClientRect().toJSON(),
    iconRight: ic.right - r.left, focused: document.activeElement === el, attrs: { valid: el.dataset.valid ?? null, invalid: el.getAttribute('aria-invalid'), disabled: el.disabled },
  };
});

/** thickness, in physical pixels, of the pink/red/green frame at the left edge of the input, read from a screenshot */
async function borderPixels(page, input, dpr) {
  const box = await input.boundingBox();
  const png = PNG.sync.read(await page.screenshot({ clip: { x: Math.max(0, box.x - 8), y: box.y, width: 40, height: box.height }, animations: 'disabled' }));
  const scale = png.width / 40, y = Math.round(png.height / 2);
  let run = 0, started = false;
  for (let x = 0; x < png.width; x++) {
    const i = (y * png.width + x) * 4, [R, G, B] = [png.data[i], png.data[i + 1], png.data[i + 2]];
    const frame = (R > 180 && G < 140 && B > 40) || (R < 120 && G > 120 && B < 120);    // pink, or green; red/pink share R high G low
    if (frame) { run++; started = true; } else if (started) break;
  }
  return { run, scale };
}

const results = {};
for (const vp of VIEWPORTS) {
  const browser = await launch({ args: ['--no-sandbox', '--disable-dev-shm-usage', `--force-device-scale-factor=${vp.dpr}`] });
  const page = await newPage(browser, { width: vp.width, height: vp.height }, { mobile: vp.mobile, dpr: vp.dpr });
  t.page = page;
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });

  for (const [cardName, code] of [['normal card', 'ig-followers-50'], ['premium dark card', 'yt-watch-4000h']]) {
    const platform = code.startsWith('yt') ? 'youtube' : 'instagram';
    const premium = cardName.startsWith('premium');
    await t.step(`${vp.name} · ${cardName} (dpr ${vp.dpr}): normal / focused / filled / invalid / valid / disabled — one border, one ring, no stacked glow`, async () => {
      const card = await openCard(page, code, platform);
      const input = card.locator('input[name=target]');
      await page.waitForTimeout(700);
      await input.evaluate((el) => el.blur());
      await page.mouse.move(1, 1);                       // the pointer is not over the field (hover is a state of its own)
      await page.waitForTimeout(1000);                   // …and the card's own hover transition has settled

      // ───── NORMAL (not focused) ─────
      const n = await probe(input);
      t.eq(n.focused, false, 'the field is not focused in the normal state');
      const want = (physical(1.5, vp.dpr) / vp.dpr);
      for (const bw of n.border.w) t.ok(Math.abs(parseFloat(bw) - want) < 0.01 || parseFloat(bw) === 1.5, `border is 1.5 px (rendered as ${physical(1.5, vp.dpr)} physical px): ${bw}`);
      t.eq(n.border.style, 'solid'); t.eq(n.border.color, PINK, 'a pink border');
      t.eq(n.bg, 'rgb(255, 255, 255)', 'white background');
      t.eq(n.shadow, 'none', 'no glow or stacked shadow in the normal state');
      t.ok(n.outline === 'none' || parseFloat(n.outlineWidth) === 0, 'no outline');
      t.eq([n.wrapper.shadow, n.wrapper.border, n.wrapper.outline], ['none', '0px', 'none'], 'the wrapper draws nothing of its own');
      t.eq([n.form.shadow, n.form.outline], ['none', 'none'], 'the form draws nothing of its own');
      t.ok(n.box.h >= 47.5, 'height ≥ 48: ' + n.box.h);
      const px = await borderPixels(page, input, vp.dpr);
      t.eq(px.run, physical(1.5, vp.dpr), `the border is ${physical(1.5, vp.dpr)} physical pixels thick on the screen (read from a screenshot)`);
      await page.screenshot({ path: `${OUT}/${vp.name}-${premium ? 'premium-' : ''}1-normal.png`, clip: await card.boundingBox() });

      // ───── FOCUSED: one 2 px ring, no layout shift, nothing added on the card / wrapper / form ─────
      await input.focus(); await page.waitForTimeout(300);
      const f = await probe(input);
      t.eq(f.focused, true);
      t.eq(shadowsOf(f.shadow).length, 1, 'exactly ONE shadow (the focus ring): ' + f.shadow);
      t.eq(f.shadow, 'rgba(255, 45, 135, 0.28) 0px 0px 0px 2px', 'a single 2 px pink ring with a subtle tint');
      t.ok(f.outline === 'none' || parseFloat(f.outlineWidth) === 0, 'the browser outline is replaced by the ring');
      t.eq(f.border.color, PINK_DARK, 'the border deepens slightly');
      t.eq(f.border.w, n.border.w, 'the border does not get thicker');
      const rel = (b, c) => [b.w, b.h, b.l - c.left, b.t - c.top].map((v) => Math.round(v * 10) / 10);
      t.eq(rel(f.box, f.cardBox), rel(n.box, n.cardBox), 'no layout shift on focus (size and place inside the card)');
      t.eq([f.wrapper.shadow, f.wrapper.outline, f.form.shadow, f.form.outline], ['none', 'none', 'none', 'none'], 'the glow is on the input only, not on its wrapper or form');
      t.eq(f.cardShadow, n.cardShadow, 'focusing adds nothing to the card');
      await page.screenshot({ path: `${OUT}/${vp.name}-${premium ? 'premium-' : ''}2-focused.png`, clip: await card.boundingBox() });

      // ───── FILLED: text clear of the link icon, nothing clipped, same single border ─────
      await input.fill('https://www.instagram.com/' + 'some.really.long.account.name.'.repeat(8)); await page.waitForTimeout(300);
      const fi = await probe(input);
      t.ok(fi.iconRight + 4 <= fi.paddingLeft, `the text starts clear of the link icon (icon ends at ${fi.iconRight.toFixed(1)}, text at ${fi.paddingLeft})`);
      t.eq(shadowsOf(fi.shadow).length <= 1, true, 'still a single ring at most');
      t.ok(fi.box.l + fi.box.w <= fi.cardBox.right + 0.5 && fi.box.l >= fi.cardBox.left - 0.5, 'the field stays inside the card');
      t.eq(await input.evaluate((el) => el.scrollWidth > el.clientWidth ? 'scrolls' : 'fits'), 'scrolls', 'a long link scrolls inside the field (never widens it)');
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      t.ok(overflow <= 0, 'no horizontal page overflow: ' + overflow);

      // ───── INVALID ─────
      await input.fill('@bad name'); await page.waitForTimeout(450);
      const iv = await probe(input);
      t.eq(await input.getAttribute('aria-invalid'), 'true'); await page.waitForTimeout(350);
      t.eq(iv.border.color, RED, 'a red border'); t.eq(iv.shadow, 'rgba(220, 38, 38, 0.22) 0px 0px 0px 2px', 'one red 2 px ring while focused');
      await input.evaluate((el) => el.blur()); await page.waitForTimeout(350);
      t.eq((await probe(input)).shadow, 'none', 'no ring once the field is left');
      await page.screenshot({ path: `${OUT}/${vp.name}-${premium ? 'premium-' : ''}3-invalid.png`, clip: await card.boundingBox() });

      // ───── VALID ─────
      await input.fill(premium ? 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' : 'https://www.instagram.com/some_account/'); await page.waitForTimeout(450);
      const va = await probe(input);
      t.eq(await input.getAttribute('data-valid'), 'true'); await page.waitForTimeout(350);
      t.eq(va.border.color, GREEN, 'a green border ' + JSON.stringify(va.attrs) + ' focused=' + va.focused); t.eq(va.shadow, 'rgba(22, 163, 74, 0.25) 0px 0px 0px 2px', 'one green 2 px ring while focused');
      await input.evaluate((el) => el.blur()); await page.waitForTimeout(250);
      t.eq((await probe(input)).shadow, 'none');
      await page.screenshot({ path: `${OUT}/${vp.name}-${premium ? 'premium-' : ''}4-valid.png`, clip: await card.boundingBox() });

      // ───── DISABLED ─────
      await input.evaluate((el) => { el.disabled = true; }); await page.waitForTimeout(400);
      const d = await probe(input);
      t.eq([d.bg, d.border.color, d.shadow], ['rgb(246, 246, 250)', 'rgb(212, 214, 226)', 'none'], 'a quiet grey disabled field with no glow');
      await page.screenshot({ path: `${OUT}/${vp.name}-${premium ? 'premium-' : ''}5-disabled.png`, clip: await card.boundingBox() });
      await input.evaluate((el) => { el.disabled = false; });
      results[`${vp.name}/${cardName}`] = { normal: n.border, focused: f.shadow };
      t.eq(page.problems.filter((p) => !/favicon/.test(p)), [], page.problems.join('\n'));
    });
  }

  await t.step(`${vp.name}: a keyboard user sees the focus indicator (Tab reaches the field and the ring shows); the page has no stacked-glow rules left`, async () => {
    const card = await openCard(page, 'ig-followers-50', 'instagram');
    const input = card.locator('input[name=target]');
    await input.fill(''); await page.waitForTimeout(500);
    await input.focus();
    await page.keyboard.press('Shift+Tab'); await page.keyboard.press('Tab');      // away (to BUY NOW) and back to the field, by keyboard
    await page.waitForTimeout(700);
    const k = await probe(input);
    t.eq(k.focused, true, 'Tab reaches the field');
    t.eq(k.shadow, 'rgba(255, 45, 135, 0.28) 0px 0px 0px 2px', 'the focus indicator is visible to keyboard users');
    // the compiled stylesheet: only the field itself carries a focus ring — the wrapper and the card's focus-within rules draw none
    const css = await (await fetch(BASE + '/assets/css/app.css')).text();
    t.ok(!/\.input-wrap(?:[^{,]*):focus-within/.test(css) && !/\.input-wrap\{[^}]*(?:box-shadow|outline)/.test(css), 'no focus-within glow on the wrapper');
    t.ok((css.match(/\.input-wrap>\.input:focus-visible/g) || []).length >= 1, 'the field has its own focus-visible rule');
  });

  await browser.close();
}

const failed = t.finish();
process.exit(failed ? 1 : 0);
