import { $, $$, html, icon, on, esc } from '../core/dom.js';
import { get, put, patch, post, del, upload } from './net.js';
import { ok, fail, errorState, showFieldErrors, clearFieldErrors, autoClearErrors, withBusy, setBusy } from './ui.js';

let w = null;           // last saved website settings
let initial = '';       // JSON snapshot of the form, for dirty tracking
const root = () => $('#website-root');

// ── colour maths (mirrors App\Support\Color: white text on color-mix(in oklab, accent 86%, black)) ──
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

const field = (id, label, control, hint = '') => html`<div class="field"><label class="label" for="${id}">${label}</label>${control}${hint ? html`<p class="field-hint">${hint}</p>` : ''}</div>`;

function imageBlock({ id, title, text, url, custom, square = false, size = 72 }) {
  return html`<div class="field" data-image="${id}"><span class="label">${title}</span>
    <div class="upload"><span class="upload-preview${square ? ' is-square' : ''}"><img src="${url}" alt="Current ${title.toLowerCase()}" data-preview width="${size}" height="${size}"></span>
      <div class="upload-text"><b>${custom ? 'Custom image' : 'Default image'}</b>${text}</div>
      <div class="upload-btns"><label class="btn btn-sm" for="file-${id}">${icon('upload', 'icon-sm')}<span>Upload</span></label>
      <input type="file" id="file-${id}" class="sr-only" accept="image/png,image/jpeg,image/webp,image/gif" data-upload="${id}">
      ${custom ? html`<button type="button" class="btn btn-sm btn-ghost" data-reset="${id}">Reset to default</button>` : ''}</div></div></div>`;
}

function platformCard(p) {
  return html`<div class="card !p-4 mb-3" data-platform="${p.id}"><div class="plat-row">
    <img src="${p.logo_url}" alt="${p.name} logo" width="48" height="48">
    <div class="field !mt-0 flex-1 min-w-[10rem]"><label class="label" for="pn-${p.id}">Name</label><input class="input input-sm" id="pn-${p.id}" name="p_name" value="${p.name}" maxlength="40"></div>
    <div class="field !mt-0 flex-[2] min-w-[12rem]"><label class="label" for="pt-${p.id}">Tagline</label><input class="input input-sm" id="pt-${p.id}" name="p_tagline" value="${p.tagline_raw}" maxlength="120" placeholder="Followers · Likes · Views"></div>
    <div class="field !mt-0"><span class="label">Visible</span><label class="switch"><input type="checkbox" name="p_active" ${p.is_active ? 'checked' : ''} aria-label="${p.name} visible on the store"><span></span></label></div>
  </div>
  <div class="mt-3 flex flex-wrap items-center gap-2">
    <label class="btn btn-sm" for="pf-${p.id}">${icon('upload', 'icon-sm')}<span>Upload logo</span></label><input type="file" id="pf-${p.id}" class="sr-only" accept="image/png,image/jpeg,image/webp,image/gif" data-plat-upload="${p.id}">
    ${p.has_custom_logo ? html`<button type="button" class="btn btn-sm btn-ghost" data-plat-reset="${p.id}">Reset logo</button>` : ''}
    <span class="text-xs text-muted">PNG, JPG, WebP or GIF · square works best · up to 2 MB</span>
    <button type="button" class="btn btn-sm btn-primary ml-auto" data-plat-save="${p.id}">${icon('check', 'icon-sm')}<span>Save platform</span></button></div></div>`;
}

function render(site) {
  w = site;
  const swatch = (n, v, def) => html`<div class="field"><label class="label" for="c-${n}">${n === 'from' ? 'Accent — start' : 'Accent — end'}</label>
    <div class="color-field"><input type="color" id="c-${n}" value="${v}" data-color="${n}" aria-label="Pick colour"><input class="input input-sm mono" name="accent_${n}" value="${v}" maxlength="7" spellcheck="false" aria-label="Hex value"></div>
    <p class="field-hint" data-contrast="${n}"></p></div>`;
  root().innerHTML = String(html`
  <form id="website-form" novalidate autocomplete="off" class="stack">
    <section class="card" aria-labelledby="h-brand"><div class="card-head"><h2 class="card-title" id="h-brand">${icon('sparkles')}Brand</h2></div>
      ${field('w-name', 'Website name', html`<input class="input" id="w-name" name="name" value="${w.name}" maxlength="60">`, 'Shown in the header, browser tab and footer. The store displays it in capitals.')}
      ${field('w-tagline', 'Tagline', html`<input class="input" id="w-tagline" name="tagline" value="${w.tagline}" maxlength="160">`)}
      <div class="grid-2 mt-4">${imageBlock({ id: 'site_logo', title: 'Logo', text: 'Square PNG with a transparent background looks best. Up to 2 MB.', url: w.logo_url, custom: w.has_custom_logo })}
      ${imageBlock({ id: 'favicon', title: 'Favicon', text: 'Small icon in the browser tab. Square, at least 64×64.', url: w.favicon_url, custom: w.has_custom_favicon, square: true, size: 48 })}</div>
    </section>

    <section class="card" aria-labelledby="h-colours"><div class="card-head"><h2 class="card-title" id="h-colours">${icon('gem')}Accent colours</h2><button type="button" class="btn btn-sm btn-ghost" data-default-colors>Reset to default</button></div>
      <div class="field-row">${swatch('from', w.accent_from)}${swatch('to', w.accent_to)}</div>
      <div class="accent-preview mt-4" data-accent-preview><span>Sample button text</span></div>
      <p class="field-hint">Used for buttons, highlights and the RGB glow. Colours that make white button text hard to read are rejected.</p>
    </section>

    <section class="card" aria-labelledby="h-content"><div class="card-head"><h2 class="card-title" id="h-content">${icon('file-text')}Content</h2></div>
      ${field('w-support', 'Support link', html`<input class="input" id="w-support" name="support_link" value="${w.support_link}" maxlength="300" placeholder="https://wa.me/91XXXXXXXXXX or mailto:help@example.com">`, 'Shown in the footer and on payment problems. Must start with https:// or mailto:.')}
      ${field('w-footer', 'Footer text', html`<input class="input" id="w-footer" name="footer_text" value="${w.footer_text}" maxlength="200">`, 'You can use {year} and {name}.')}
      ${field('w-instr', 'Payment page instructions', html`<textarea class="textarea" id="w-instr" name="payment_instructions" rows="3" maxlength="600">${w.payment_instructions}</textarea>`, 'Shown at the bottom of the payment page, under the timer and the pay button.')}
    </section>

    <section class="card" aria-labelledby="h-seo"><div class="card-head"><h2 class="card-title" id="h-seo">${icon('search')}Search appearance (SEO)</h2></div>
      ${field('w-seot', 'Page title', html`<input class="input" id="w-seot" name="seo_title" value="${w.seo_title}" maxlength="70">`, html`<span data-count="seo_title"></span>`)}
      ${field('w-seod', 'Meta description', html`<textarea class="textarea" id="w-seod" name="seo_description" rows="2" maxlength="200">${w.seo_description}</textarea>`, html`<span data-count="seo_description"></span>`)}
    </section>

    <section class="card" aria-labelledby="h-ops"><div class="card-head"><h2 class="card-title" id="h-ops">${icon('power')}Operations</h2></div>
      <div class="switch-row"><div><b>Maintenance mode</b><small>Customers see a maintenance page. The admin panel keeps working.</small></div><label class="switch"><input type="checkbox" name="maintenance" ${w.maintenance ? 'checked' : ''} aria-label="Maintenance mode"><span></span></label></div>
      ${field('w-mmsg', 'Maintenance message', html`<input class="input" id="w-mmsg" name="maintenance_message" value="${w.maintenance_message}" maxlength="300">`)}
      <div class="field"><label class="label" for="w-tz">Business time zone</label>
        <select class="select" id="w-tz" name="timezone" ${w.timezone_locked ? 'disabled' : ''}>${w.timezones.map((z) => html`<option value="${z}" ${z === w.timezone ? 'selected' : ''}>${z}</option>`)}</select>
        <p class="field-hint">${w.timezone_locked ? 'Locked because orders or visitors already exist — changing it later would re-label your daily reports. Currently ' + w.timezone_label + '.' : 'Daily reports use this time zone. It locks after the first order or visitor.'}</p></div>
    </section>

    <div class="savebar" id="savebar" hidden><span class="text-sm font-semibold">You have unsaved changes</span>
      <span class="flex gap-2"><button type="button" class="btn btn-sm" id="discard">Discard</button><button type="submit" class="btn btn-sm btn-primary" id="save">${icon('check', 'icon-sm')}<span>Save changes</span></button></span></div>
  </form>

  <section class="card mt-5" aria-labelledby="h-plat"><div class="card-head"><h2 class="card-title" id="h-plat">${icon('layers')}Platform buttons</h2><span class="card-sub">The two big buttons customers choose between</span></div>
    ${w.platforms.map(platformCard)}</section>`);
  const f = $('#website-form');
  autoClearErrors(f);
  initial = snapshot();
  paintDerived();
}

const snapshot = () => JSON.stringify(Object.fromEntries($$('#website-form [name]').map((el) => [el.name, el.type === 'checkbox' ? el.checked : el.value])));
const values = () => {
  const f = $('#website-form');
  const v = (n) => f.elements[n]?.value;
  return {
    name: v('name'), tagline: v('tagline'), support_link: v('support_link'), footer_text: v('footer_text'), payment_instructions: v('payment_instructions'),
    seo_title: v('seo_title'), seo_description: v('seo_description'), maintenance: f.elements.maintenance.checked, maintenance_message: v('maintenance_message'),
    accent_from: v('accent_from').toLowerCase(), accent_to: v('accent_to').toLowerCase(), ...(f.elements.timezone.disabled ? {} : { timezone: v('timezone') }),
  };
};

function paintDerived() {
  const f = $('#website-form');
  if (!f) return;
  const from = f.elements.accent_from.value, to = f.elements.accent_to.value;
  for (const [n, hex] of [['from', from], ['to', to]]) {
    const c = buttonContrast(hex);
    const el = $(`[data-contrast="${n}"]`);
    el.textContent = c === null ? 'Enter a colour like #ff2d87.' : c >= 4.5 ? `Button text contrast ${c.toFixed(1)}:1 — readable.` : `Button text contrast ${c.toFixed(1)}:1 — too light, pick a deeper shade (needs 4.5:1).`;
    el.className = 'field-hint ' + (c === null || c < 4.5 ? 'text-bad-700 font-semibold' : 'text-ok-800');
  }
  const prev = $('[data-accent-preview]');
  if (/^#[0-9a-f]{6}$/i.test(from)) prev.style.setProperty('--ap-a', from);
  if (/^#[0-9a-f]{6}$/i.test(to)) prev.style.setProperty('--ap-b', to);
  for (const n of ['seo_title', 'seo_description']) {
    const el = $(`[data-count="${n}"]`);
    const max = n === 'seo_title' ? 70 : 200;
    const len = f.elements[n].value.length;
    if (el) el.textContent = `${len}/${max} characters` + (n === 'seo_title' ? ' · ~60 shows fully in Google' : ' · ~155 shows fully in Google');
  }
  const dirty = snapshot() !== initial;
  $('#savebar').hidden = !dirty;
}

async function save(btn) {
  const f = $('#website-form');
  clearFieldErrors(f);
  await withBusy(btn, async () => {
    try {
      const res = await put('website', values(), { idempotency: true });
      ok('Website settings saved', 'Changes are live on the store.');
      render(res.website);
    } catch (e) {
      if (e.fields) { showFieldErrors(f, e.fields); fail('Please check the highlighted fields', e.message); }
      else fail('Could not save', e.message);
    }
  });
}

function applyBrand(res) {
  w = res.website;
  // reflect the new logo in the admin chrome straight away
  $$('.adm-brand-logo img, .adm-mobilebrand img').forEach((i) => { i.src = w.logo_url; });
  const keep = values();
  render(w);
  // keep unsaved text edits across an image upload
  for (const [k, v] of Object.entries(keep)) { const el = $('#website-form').elements[k]; if (!el || el.disabled) continue; if (el.type === 'checkbox') el.checked = v; else el.value = v; }
  paintDerived();
}

export default async function init() {
  const r = root();
  try { w = (await get('website')).website; } catch (e) { r.innerHTML = errorState(e.message).toString(); r.setAttribute('aria-busy', 'false'); return; }
  render(w);
  r.setAttribute('aria-busy', 'false');

  on(r, 'input', '#website-form', paintDerived);
  on(r, 'change', '#website-form', paintDerived);
  // colour picker <-> hex field
  on(r, 'input', '[data-color]', (e, picker) => { $(`[name="accent_${picker.dataset.color}"]`).value = picker.value; paintDerived(); });
  on(r, 'input', '[name="accent_from"], [name="accent_to"]', (e, input) => {
    if (/^#[0-9a-f]{6}$/i.test(input.value)) $(`[data-color="${input.name.slice(7)}"]`).value = input.value;
  });
  on(r, 'click', '[data-default-colors]', () => {
    const [a, b] = w.default_accent;
    $('[name="accent_from"]').value = a; $('[name="accent_to"]').value = b; $('[data-color="from"]').value = a; $('[data-color="to"]').value = b; paintDerived();
  });
  on(r, 'click', '#discard', () => render(w));
  r.addEventListener('submit', (e) => { e.preventDefault(); save($('#save')); });

  // logo / favicon upload + reset (applied immediately)
  r.addEventListener('change', async (e) => {
    const t = e.target;
    if (!(t instanceof HTMLInputElement) || t.type !== 'file' || !t.files?.[0]) return;
    const file = t.files[0]; t.value = '';
    if (file.size > 2 * 1024 * 1024) { fail('Image too large', 'Please choose an image under 2 MB.'); return; }
    try {
      if (t.dataset.upload) {
        applyBrand(await upload(`branding/${t.dataset.upload}`, file));
        ok(t.dataset.upload === 'favicon' ? 'Favicon updated' : 'Logo updated');
      } else if (t.dataset.platUpload) {
        const res = await upload(`platforms/${t.dataset.platUpload}/logo`, file);
        w = (await get('website')).website; render(w);
        ok('Platform logo updated', res.platform.name);
      }
    } catch (err) { fail('Upload failed', err.message); }
  });
  on(r, 'click', '[data-reset]', async (e, b) => {
    await withBusy(b, async () => { try { applyBrand(await del(`branding/${b.dataset.reset}`)); ok('Reset to the default image'); } catch (err) { fail('Could not reset', err.message); } });
  });
  on(r, 'click', '[data-plat-reset]', async (e, b) => {
    await withBusy(b, async () => { try { await del(`platforms/${b.dataset.platReset}/logo`); w = (await get('website')).website; render(w); ok('Platform logo reset'); } catch (err) { fail('Could not reset', err.message); } });
  });
  on(r, 'click', '[data-plat-save]', async (e, b) => {
    const card = b.closest('[data-platform]');
    await withBusy(b, async () => {
      clearFieldErrors(card);
      try {
        await patch(`platforms/${card.dataset.platform}`, { name: $('[name="p_name"]', card).value, tagline: $('[name="p_tagline"]', card).value, is_active: $('[name="p_active"]', card).checked });
        w = (await get('website')).website;
        render(w);
        ok('Platform saved');
      } catch (err) {
        if (err.fields) {
          const map = { p_name: err.fields.name, p_tagline: err.fields.tagline, p_active: err.fields.is_active };
          showFieldErrors(card, Object.fromEntries(Object.entries(map).filter(([, v]) => v)));
        }
        fail('Could not save platform', Object.values(err.fields || {})[0] || err.message);
      }
    });
  });
  window.addEventListener('beforeunload', (e) => { if ($('#savebar') && !$('#savebar').hidden) { e.preventDefault(); e.returnValue = ''; } });
}
