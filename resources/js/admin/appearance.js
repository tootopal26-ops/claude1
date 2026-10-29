import { $, $$, html, icon, on } from '../core/dom.js';
import { confirmDialog } from '../core/dialog.js';
import { get, put, post } from './net.js';
import { ok, fail, errorState, showFieldErrors, clearFieldErrors, withBusy } from './ui.js';

let a = null;      // last saved appearance
let form = null;   // candidate (unsaved) selection: { theme, density, accent_from, accent_to }
const root = () => $('#appearance-root');

// mirrors App\Support\Color::buttonContrast (white text on color-mix(in oklab, accent 86%, black))
const lin = (c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
function buttonContrast(hex) {
  if (!/^#[0-9a-f]{6}$/i.test(hex)) return null;
  const [r, g, b] = [1, 3, 5].map((i) => lin(parseInt(hex.slice(i, i + 2), 16) / 255));
  const l = 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b, m = 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b, s = 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b;
  const [l_, m_, s_] = [l, m, s].map(Math.cbrt);
  const k = 0.86;
  const L = (0.2104542553 * l_ + 0.793617785 * m_ - 0.0040720468 * s_) * k, A = (1.9779984951 * l_ - 2.428592205 * m_ + 0.4505937099 * s_) * k, B = (0.0259040371 * l_ + 0.7827717662 * m_ - 0.808675766 * s_) * k;
  const l2 = (L + 0.3963377774 * A + 0.2158037573 * B) ** 3, m2 = (L - 0.1055613458 * A - 0.0638541728 * B) ** 3, s2 = (L - 0.0894841775 * A - 1.291485548 * B) ** 3;
  const cl = (x) => Math.max(0, Math.min(1, x));
  const R = cl(4.0767416621 * l2 - 3.3077115913 * m2 + 0.2309699292 * s2), G = cl(-1.2684380046 * l2 + 2.6097574011 * m2 - 0.3413193965 * s2), Bc = cl(-0.0041960863 * l2 - 0.7034186147 * m2 + 1.707614701 * s2);
  return 1.05 / (0.2126 * R + 0.7152 * G + 0.0722 * Bc + 0.05);
}

const DENSITY_HINT = {
  compact: 'Tighter corners and a lighter glass blur — denser screens, more rows visible.',
  standard: 'The balanced default used throughout this design.',
  premium: 'Rounder corners and a stronger glass blur — a more luxurious, spacious feel.',
};

function themeCard(t, selected) {
  return html`<button type="button" class="theme-card${selected ? ' is-selected' : ''}" data-theme-pick="${t.name}" role="radio" aria-checked="${selected ? 'true' : 'false'}" aria-label="${t.label}">
    <span class="theme-card-preview" data-preview-bg>
      <span class="theme-swatch-row">
        <span class="theme-dot" data-dot="surface"></span><span class="theme-dot" data-dot="accent"></span><span class="theme-dot" data-dot="ink"></span>
      </span>
    </span>
    <span class="theme-card-body"><b>${t.label}</b><small>${t.blurb}</small></span>
    ${selected ? html`<span class="theme-card-check">${icon('check-circle', 'icon-sm')}</span>` : ''}
  </button>`;
}

function render() {
  root().innerHTML = String(html`
  <div class="grid gap-5">
    ${(a.can_revert || a.can_reset) ? html`<div class="flex justify-end gap-2">
      ${a.can_revert ? html`<button type="button" class="btn btn-sm btn-ghost" id="revert">${icon('undo-2', 'icon-sm')}<span>Revert last change</span></button>` : ''}
      ${a.can_reset ? html`<button type="button" class="btn btn-sm btn-ghost" id="reset">${icon('rotate-ccw', 'icon-sm')}<span>Restore defaults</span></button>` : ''}
    </div>` : ''}
    <section class="card" aria-labelledby="h-themes">
      <div class="card-head"><h2 class="card-title" id="h-themes">${icon('palette')}Theme</h2></div>
      <div class="theme-grid" role="radiogroup" aria-label="Theme">${a.themes.map((t) => themeCard(t, t.name === form.theme))}</div>
    </section>

    <section class="card" aria-labelledby="h-density">
      <div class="card-head"><h2 class="card-title" id="h-density">${icon('layout-grid')}Density</h2></div>
      <div class="seg" role="radiogroup" aria-label="Density">
        ${a.densities.map((d) => html`<button type="button" role="radio" aria-checked="${d === form.density ? 'true' : 'false'}" data-density-pick="${d}">${d[0].toUpperCase() + d.slice(1)}</button>`)}
      </div>
      <p class="field-hint" data-density-hint>${DENSITY_HINT[form.density]}</p>
    </section>

    <section class="card" aria-labelledby="h-accent">
      <div class="card-head"><h2 class="card-title" id="h-accent">${icon('gem')}Accent colours</h2></div>
      <div class="field-row">
        <div class="field"><label class="label" for="ap-from">Accent — start</label>
          <div class="color-field"><input type="color" id="ap-from-c" value="${form.accent_from}" aria-label="Pick colour"><input class="input input-sm mono" id="ap-from" value="${form.accent_from}" maxlength="7" spellcheck="false"></div>
          <p class="field-hint" data-contrast="from"></p></div>
        <div class="field"><label class="label" for="ap-to">Accent — end</label>
          <div class="color-field"><input type="color" id="ap-to-c" value="${form.accent_to}" aria-label="Pick colour"><input class="input input-sm mono" id="ap-to" value="${form.accent_to}" maxlength="7" spellcheck="false"></div>
          <p class="field-hint" data-contrast="to"></p></div>
      </div>
      <p class="field-hint">Switching theme resets these to that theme's own default — edit them afterwards for a custom brand gradient.</p>
    </section>

    <section class="card" aria-labelledby="h-preview">
      <div class="card-head"><h2 class="card-title" id="h-preview">${icon('eye')}Live preview</h2>
        <div class="seg" role="radiogroup" aria-label="Preview viewport">
          <button type="button" role="radio" aria-checked="true" data-viewport="desktop">${icon('monitor', 'icon-sm')}</button>
          <button type="button" role="radio" aria-checked="false" data-viewport="tablet">${icon('tablet', 'icon-sm')}</button>
          <button type="button" role="radio" aria-checked="false" data-viewport="mobile">${icon('smartphone', 'icon-sm')}</button>
        </div>
      </div>
      <div class="theme-preview-wrap" data-viewport-box="desktop">
        <div class="theme-preview" id="theme-preview">
          <div class="theme-preview-bar glass-strong"><b>SOCIAL MEDIA BOOSTER</b></div>
          <div class="theme-preview-card glass">
            <div class="theme-preview-row"><span class="theme-preview-title">100 Likes</span><span class="theme-preview-price">₹59</span></div>
            <p class="theme-preview-sub">Instagram Post Likes</p>
            <button type="button" class="btn btn-success btn-block" tabindex="-1">BUY NOW</button>
          </div>
        </div>
      </div>
      <p class="field-hint">This preview updates instantly as you pick — nothing is applied to the live site until you save.</p>
    </section>

    <div class="savebar" id="savebar" hidden><span class="text-sm font-semibold">You have unsaved appearance changes</span>
      <span class="flex gap-2">
        <button type="button" class="btn btn-sm" id="discard">Discard</button>
        <button type="button" class="btn btn-sm btn-primary" id="save">${icon('check', 'icon-sm')}<span>Save & publish</span></button>
      </span></div>
  </div>`);
  paint();
}

function paint() {
  $$('.theme-card').forEach((c) => {
    const sel = c.dataset.themePick === form.theme;
    c.classList.toggle('is-selected', sel);
    c.setAttribute('aria-checked', String(sel));
  });
  $$('[data-density-pick]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.densityPick === form.density)));
  const hint = $('[data-density-hint]');
  if (hint) hint.textContent = DENSITY_HINT[form.density];

  const t = a.themes.find((x) => x.name === form.theme);
  $$('.theme-card').forEach((c) => {
    const tt = a.themes.find((x) => x.name === c.dataset.themePick);
    $('[data-preview-bg]', c).style.setProperty('background', tt.swatch.canvas);
    const surface = $('[data-dot="surface"]', c), accent = $('[data-dot="accent"]', c), ink = $('[data-dot="ink"]', c);
    surface.style.setProperty('background', tt.swatch.surface);
    accent.style.setProperty('background', tt.accent_from);
    ink.style.setProperty('background', tt.swatch.ink);
  });

  for (const [n, id] of [['from', 'ap-from'], ['to', 'ap-to']]) {
    const hex = $(`#${id}`).value.toLowerCase();
    const c = buttonContrast(hex);
    const el = $(`[data-contrast="${n}"]`);
    el.textContent = c === null ? 'Enter a colour like #ff2d87.' : c >= 4.5 ? `Button text contrast ${c.toFixed(1)}:1 — readable.` : `Button text contrast ${c.toFixed(1)}:1 — too light, pick a deeper shade (needs 4.5:1).`;
    el.className = 'field-hint ' + (c === null || c < 4.5 ? 'text-bad-700 font-semibold' : 'text-ok-800');
  }

  // scoped live preview: these custom properties cascade to every real component inside #theme-preview,
  // overriding the page's actual (saved) theme only within this one box — nothing elsewhere changes.
  const box = $('#theme-preview');
  if (box && t) {
    const sw = t.swatch;
    box.style.setProperty('--color-canvas', sw.canvas);
    box.style.setProperty('--color-surface', sw.surface);
    box.style.setProperty('--color-ink', sw.ink);
    box.style.setProperty('--color-muted', sw.muted);
    box.style.setProperty('--color-line', sw.line);
    box.style.setProperty('--accent-from', $('#ap-from').value);
    box.style.setProperty('--accent-to', $('#ap-to').value);
    box.style.setProperty('--color-brand-500', $('#ap-from').value);
    box.style.setProperty('background', sw.canvas);
    box.style.setProperty('color', sw.ink);
    if (sw['glass-bg']) { box.style.setProperty('--glass-bg', sw['glass-bg']); box.style.setProperty('--glass-bg-strong', sw['glass-bg']); }
    else { box.style.removeProperty('--glass-bg'); box.style.removeProperty('--glass-bg-strong'); }
    if (sw['glass-border']) { box.style.setProperty('--glass-border', sw['glass-border']); box.style.setProperty('--glass-border-strong', sw['glass-border']); }
    else { box.style.removeProperty('--glass-border'); box.style.removeProperty('--glass-border-strong'); }
  }

  const dirty = form.theme !== a.theme || form.density !== a.density || form.accent_from.toLowerCase() !== a.accent_from.toLowerCase() || form.accent_to.toLowerCase() !== a.accent_to.toLowerCase();
  const bar = $('#savebar');
  if (bar) bar.hidden = !dirty;
}

function pickTheme(name) {
  const t = a.themes.find((x) => x.name === name);
  if (!t) return;
  form.theme = name;
  form.accent_from = t.accent_from;
  form.accent_to = t.accent_to;
  $('#ap-from').value = t.accent_from; $('#ap-from-c').value = t.accent_from;
  $('#ap-to').value = t.accent_to; $('#ap-to-c').value = t.accent_to;
  paint();
}

async function save(btn) {
  await withBusy(btn, async () => {
    clearFieldErrors(root());
    try {
      const res = await put('appearance', form, { idempotency: true });
      a = res.appearance;
      form = { theme: a.theme, density: a.density, accent_from: a.accent_from, accent_to: a.accent_to };
      ok('Appearance saved', 'The new theme is live on the store and admin panel.');
      render();
    } catch (e) {
      if (e.fields) { showFieldErrors(root(), e.fields); fail('Please check the highlighted fields', e.message); }
      else fail('Could not save', e.message);
    }
  });
}

export default async function init() {
  const r = root();
  try { a = (await get('appearance')).appearance; } catch (e) { r.innerHTML = errorState(e.message).toString(); r.setAttribute('aria-busy', 'false'); return; }
  form = { theme: a.theme, density: a.density, accent_from: a.accent_from, accent_to: a.accent_to };
  render();
  r.setAttribute('aria-busy', 'false');

  on(r, 'click', '[data-theme-pick]', (e, b) => pickTheme(b.dataset.themePick));
  on(r, 'click', '[data-density-pick]', (e, b) => { form.density = b.dataset.densityPick; paint(); });
  on(r, 'click', '[data-viewport]', (e, b) => {
    $$('[data-viewport]').forEach((x) => x.setAttribute('aria-checked', String(x === b)));
    $('[data-viewport-box]').dataset.viewportBox = b.dataset.viewport;
  });
  on(r, 'input', '#ap-from-c', (e, p) => { $('#ap-from').value = p.value; form.accent_from = p.value; paint(); });
  on(r, 'input', '#ap-to-c', (e, p) => { $('#ap-to').value = p.value; form.accent_to = p.value; paint(); });
  on(r, 'input', '#ap-from', (e, input) => { if (/^#[0-9a-f]{6}$/i.test(input.value)) { $('#ap-from-c').value = input.value; } form.accent_from = input.value; paint(); });
  on(r, 'input', '#ap-to', (e, input) => { if (/^#[0-9a-f]{6}$/i.test(input.value)) { $('#ap-to-c').value = input.value; } form.accent_to = input.value; paint(); });
  on(r, 'click', '#discard', () => { form = { theme: a.theme, density: a.density, accent_from: a.accent_from, accent_to: a.accent_to }; render(); });
  on(r, 'click', '#save', (e, b) => save(b));
  on(r, 'click', '#reset', async (e, b) => {
    const yes = await confirmDialog({ title: 'Restore the default appearance?', message: 'Rose Glass, standard density and the default accent colours.', confirmText: 'Restore defaults' });
    if (!yes) return;
    await withBusy(b, async () => {
      try { a = (await post('appearance/reset', {}, { idempotency: true })).appearance; form = { theme: a.theme, density: a.density, accent_from: a.accent_from, accent_to: a.accent_to }; ok('Restored the default appearance'); render(); }
      catch (err) { fail('Could not reset', err.message); }
    });
  });
  on(r, 'click', '#revert', async (e, b) => {
    await withBusy(b, async () => {
      try { a = (await post('appearance/revert', {}, { idempotency: true })).appearance; form = { theme: a.theme, density: a.density, accent_from: a.accent_from, accent_to: a.accent_to }; ok('Reverted to the previous appearance'); render(); }
      catch (err) { fail('Could not revert', err.message); }
    });
  });
  window.addEventListener('beforeunload', (e) => { if ($('#savebar') && !$('#savebar').hidden) { e.preventDefault(); e.returnValue = ''; } });
}
