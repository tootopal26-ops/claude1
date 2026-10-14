import { $, $$, html, raw, icon, on, url, esc, uuid, debounce } from '../core/dom.js';
import { inr, num, plain, parseRupees, tenths, fmtDateTime } from '../core/format.js';
import { openDialog, closeDialog, confirmDialog } from '../core/dialog.js';
import { ok, fail } from '../core/toast.js';
import { get, post, put, del, upload } from './net.js';
import {
  modeChip, skeletonTable, emptyState, errorState, showFieldErrors, clearFieldErrors, autoClearErrors, withBusy,
} from './ui.js';
import { reloadStatus } from './shell.js';

let services = [];
let meta = null;
let editing = null;          // service being edited (null = new)
let form = { icon_path: undefined };
let providerRates = null;    // { micros, currency } of the selected provider
const root = () => $('#services-root');

const TYPE_ICON = { followers: 'users', subscribers: 'user-plus', likes: 'heart', views: 'eye', verification: 'badge-check', watch_time: 'clock' };
const prefixOf = (slug) => ({ instagram: 'ig', youtube: 'yt' })[slug] || String(slug).slice(0, 3);

// ── list ──
function groups() {
  const by = new Map();
  for (const s of services) { if (!by.has(s.platform_id)) by.set(s.platform_id, []); by.get(s.platform_id).push(s); }
  return meta.platforms.filter((p) => by.has(p.id)).map((p) => ({ platform: p, items: by.get(p.id) }));
}

function marginCell(s) {
  const p = s.preview;
  const margin = p.price ? Math.trunc((p.gross * 1000) / p.price) : 0;
  const cls = p.gross < 0 ? 'amt-neg' : 'amt-pos';
  return html`<span class="cell-main" title="Price minus provider cost (AUTO only), before advertising"><b class="${cls}">${inr(p.gross)}</b><small>${tenths(margin)}% margin</small></span>`;
}

function row(s) {
  const img = s.icon_url || meta.platforms.find((p) => p.id === s.platform_id)?.logo_url || '';
  return html`<tr data-id="${s.id}" class="${s.status === 'inactive' ? 'opacity-60' : ''}">
    <td data-label="Service" class="span2"><span class="cell-svc">${img ? html`<img src="${img}" alt="" width="30" height="30" loading="lazy">` : ''}<span class="cell-main"><b>${s.title}${s.badge ? html` <span class="badge badge-${s.badge_color || 'red'} align-middle">${s.badge}</span>` : ''}${s.is_premium ? html` <span class="chip chip-purple align-middle">premium</span>` : ''}</b><small class="mono" title="${s.type_label}">${s.code}</small></span></span></td>
    <td data-label="Price" class="num"><span class="cell-main"><b>${inr(s.price_paise)}</b><small>${num(s.quantity)} ${s.quantity === 1 ? 'unit' : 'units'}</small></span></td>
    <td data-label="Mode"><span class="stackcell">${modeChip(s.mode)}${s.mode === 'AUTO' && !s.provider_name ? html`<small class="text-bad-700">no provider</small>` : ''}</span></td>
    <td data-label="Provider cost" class="num">${s.mode === 'AUTO' ? html`<span class="cell-main"><b>${inr(s.smm_cost_paise)}</b><small>${s.provider_name || ''}</small></span>` : html`<span class="amt-mute">—</span>`}</td>
    <td data-label="Profit / order" class="num">${marginCell(s)}</td>
    <td data-label="Paid orders" class="num">${num(s.paid_orders)}</td>
    <td data-label="Active"><label class="switch" title="${s.status === 'active' ? 'Visible on the store' : 'Hidden from the store'}"><input type="checkbox" data-toggle="${s.id}" ${s.status === 'active' ? 'checked' : ''} aria-label="${s.title} active on the store"><span></span></label></td>
    <td class="row-actions-cell"><span class="row-actions">
      <button type="button" class="btn btn-sm" data-edit="${s.id}" aria-label="Edit ${s.title}">${icon('pencil', 'icon-sm')}<span>Edit</span></button>
      <button type="button" class="btn btn-sm btn-ghost btn-icon" data-delete="${s.id}" aria-label="Delete ${s.title}" title="Delete">${icon('trash-2', 'icon-sm')}</button></span></td>
  </tr>`;
}

function renderList() {
  if (!services.length) {
    root().innerHTML = String(html`<div class="tbl-wrap">${emptyState({ ico: 'layers', title: 'No services yet', text: 'Add your first service to show it on the store.', action: '<button type="button" class="btn btn-primary btn-sm mt-2" data-add>Add service</button>' })}</div>`);
    return;
  }
  const inactive = services.filter((s) => s.status === 'inactive').length;
  root().innerHTML = String(html`${groups().map(({ platform, items }) => html`<section class="mb-6" aria-labelledby="pl-${platform.id}">
    <div class="flex items-center gap-3 mb-2"><img class="h-8 w-8 rounded-full ring-1 ring-line bg-white" src="${platform.logo_url}" alt="" width="32" height="32">
      <h2 id="pl-${platform.id}" class="text-lg font-extrabold">${platform.name}</h2><span class="chip chip-gray">${items.length} services</span>${platform.is_active ? '' : html`<span class="chip chip-warn">platform hidden</span>`}</div>
    <div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="${platform.name} services"><table class="tbl tbl-resp">
      <thead><tr><th>Service</th><th class="num">Price</th><th>Mode</th><th class="num">Provider cost</th><th class="num">Profit / order</th><th class="num">Paid orders</th><th>Active</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${items.map(row)}</tbody></table></div></div></section>`)}
    <p class="text-[13px] text-muted">${inactive ? `${inactive} service${inactive === 1 ? ' is' : 's are'} hidden from the store. ` : ''}Profit / order = price − provider cost (AUTO only), before advertising. Open a service to see the estimate after the average ad cost per order (${inr(meta.est_ad_cost_paise, true)} · ${meta.est_ad_cost_basis}).</p>`);
}

async function load() {
  root().setAttribute('aria-busy', 'true');
  if (!services.length) root().innerHTML = String(skeletonTable(6));
  try {
    const d = await get('services');
    services = d.services; meta = d.meta;
    renderList();
  } catch (e) {
    root().innerHTML = errorState(e.message).toString();
  } finally { root().setAttribute('aria-busy', 'false'); }
}

// ── editor ──
const val = (name) => $(`#sd-body [name="${name}"]`)?.value ?? '';
const checked = (name) => !!$(`#sd-body [name="${name}"]`)?.checked;

function opt(items, sel, label = (i) => i.label, value = (i) => i.value) {
  return items.map((i) => html`<option value="${value(i)}" ${String(value(i)) === String(sel) ? 'selected' : ''}>${label(i)}</option>`);
}

function iconBlock(icon_url) {
  return html`<div class="field" id="icon-field"><span class="label">Card icon (optional)</span>
    <div class="upload" id="icon-drop"><span class="upload-preview is-square">${icon_url ? html`<img src="${icon_url}" alt="Current icon">` : icon('image', 'icon-lg')}</span>
      <div class="upload-text"><b>${icon_url ? 'Custom icon' : 'Default icon'}</b>PNG, JPG, WebP or GIF · up to 2 MB · shown at 80×80. SVG is not accepted.</div>
      <div class="upload-btns"><label class="btn btn-sm" for="icon-file">${icon('upload', 'icon-sm')}<span>Upload</span></label><input type="file" id="icon-file" class="sr-only" accept="image/png,image/jpeg,image/webp,image/gif">
      ${icon_url ? html`<button type="button" class="btn btn-sm btn-ghost" data-icon-remove>Remove</button>` : ''}</div></div></div>`;
}

function paintIcon() {
  const cur = form.icon_path === undefined ? editing?.icon_url : (form.icon_url || null);
  $('#icon-field').outerHTML = String(iconBlock(cur));
}

function editorHtml(s) {
  const mode = s?.mode || 'MANUAL';
  const platformId = s?.platform_id || meta.platforms[0]?.id;
  const plat = meta.platforms.find((p) => p.id === Number(platformId));
  const kinds = meta.target_kinds[plat?.slug] || ['profile', 'post', 'video', 'channel'];
  const providers = meta.providers;
  const icon_url = form.icon_path === undefined ? s?.icon_url : (form.icon_url || null);
  return html`<form id="svc-form" novalidate autocomplete="off">
    <div class="form-section"><h3>Basics</h3>
      <div class="field-row"><div class="field"><label class="label req" for="f-platform_id">Platform</label>
        <select class="select" id="f-platform_id" name="platform_id">${opt(meta.platforms, platformId, (p) => p.name, (p) => p.id)}</select></div>
        <div class="field"><label class="label req" for="f-type">Service type</label>
        <select class="select" id="f-type" name="type">${opt(meta.types, s?.type || 'followers')}</select></div></div>
      <div class="field"><label class="label req" for="f-title">Title shown on the card</label>
        <input class="input" id="f-title" name="title" maxlength="120" value="${s?.title || ''}" placeholder="e.g. 100K Followers"></div>
      <div class="field"><label class="label" for="f-subtitle">Subtitle</label>
        <input class="input" id="f-subtitle" name="subtitle" maxlength="160" value="${s?.subtitle || ''}" placeholder="e.g. Instagram Non Drop Followers"></div>
      <div class="field"><label class="label" for="f-description">Description (optional)</label>
        <textarea class="textarea" id="f-description" name="description" rows="2" maxlength="2000">${s?.description || ''}</textarea></div>
      <div class="field-row"><div class="field"><label class="label req" for="f-target_kind">Customer enters</label>
        <select class="select" id="f-target_kind" name="target_kind">${kinds.map((k) => html`<option value="${k}" ${k === (s?.target_kind || kinds[0]) ? 'selected' : ''}>${{ profile: 'Username / profile link', post: 'Post / reel link', channel: 'Channel link', video: 'Video link' }[k] || k}</option>`)}</select></div>
        <div class="field"><label class="label req" for="f-code">Service ID</label>
        <input class="input mono" id="f-code" name="code" maxlength="63" value="${s?.code || ''}" placeholder="ig-followers-100k" spellcheck="false" autocapitalize="none">
        <p class="field-hint">Unique, lowercase. Used internally and in reports.</p></div></div>
    </div>

    <div class="form-section"><h3>Price &amp; quantity</h3>
      <div class="field-row"><div class="field"><label class="label req" for="f-price">Price customers pay</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="f-price" name="price" inputmode="decimal" value="${s ? plain(s.price_paise).replace(/\.00$/, '') : ''}" placeholder="99"></div></div>
        <div class="field"><label class="label req" for="f-quantity">Units delivered per purchase</label>
        <input class="input" id="f-quantity" name="quantity" inputmode="numeric" value="${s?.quantity ?? ''}" placeholder="100000"></div></div>
      <div class="field-row"><div class="field"><label class="label" for="f-min_quantity">Minimum quantity</label><input class="input" id="f-min_quantity" name="min_quantity" inputmode="numeric" value="${s?.min_quantity ?? 1}"></div>
        <div class="field"><label class="label" for="f-max_quantity">Maximum quantity</label><input class="input" id="f-max_quantity" name="max_quantity" inputmode="numeric" value="${s?.max_quantity ?? 100000000}"></div></div>
    </div>

    <div class="form-section"><h3>Delivery</h3>
      <p class="lead">AUTO sends paid orders to your SMM provider. MANUAL puts them in the approval queue so you deliver them yourself.</p>
      <div class="seg mb-3" role="radiogroup" aria-label="Delivery mode">
        <button type="button" role="radio" data-mode="AUTO" aria-checked="${mode === 'AUTO' ? 'true' : 'false'}">${icon('zap', 'icon-sm')}AUTO</button>
        <button type="button" role="radio" data-mode="MANUAL" aria-checked="${mode === 'MANUAL' ? 'true' : 'false'}">${icon('user', 'icon-sm')}MANUAL</button></div>
      <input type="hidden" name="mode" value="${mode}">
      <div id="auto-fields" ${mode === 'AUTO' ? '' : 'hidden'}>
        <div class="field-row"><div class="field"><label class="label req" for="f-smm_provider_id">SMM provider</label>
          <select class="select" id="f-smm_provider_id" name="smm_provider_id"><option value="">Choose provider…</option>${providers.map((p) => html`<option value="${p.id}" ${Number(s?.smm_provider_id ?? (providers.find((x) => x.is_default)?.id)) === p.id ? 'selected' : ''}>${p.name}${p.is_active ? '' : ' (inactive)'}</option>`)}</select>
          ${providers.length ? '' : html`<p class="field-hint">No provider yet — <a class="underline" href="${url('/admin/smm')}">connect one</a> first.</p>`}</div>
          <div class="field"><label class="label req" for="f-smm_service_id">SMM service ID</label>
          <input class="input mono" id="f-smm_service_id" name="smm_service_id" maxlength="64" value="${s?.smm_service_id || ''}" placeholder="e.g. 1234" spellcheck="false"></div></div>
        <div class="field combo"><label class="label" for="svc-search">Find the service on your provider</label>
          <input class="input" id="svc-search" type="search" role="combobox" placeholder="Search by name, category or ID…" autocomplete="off" aria-autocomplete="list" aria-controls="svc-results" aria-expanded="false" aria-describedby="svc-search-hint">
          <div class="combo-list" id="svc-results" role="listbox" aria-label="Services at your provider" hidden></div>
          <p class="field-hint" id="svc-search-hint">Pick a result to fill the ID and estimate the cost.</p></div>
        <div class="field"><label class="label req" for="f-smm_cost">Provider cost per purchase</label>
          <div class="input-affix"><span class="affix">₹</span><input class="input" id="f-smm_cost" name="smm_cost" inputmode="decimal" value="${s ? plain(s.smm_cost_paise).replace(/\.00$/, '') : ''}" placeholder="58"></div>
          <p class="field-hint" id="cost-hint">What your provider charges you for ONE purchase of this pack, in rupees. Used for profit reports.</p></div>
      </div>
    </div>

    <div class="form-section"><h3>Profit preview</h3><div id="profit-preview" class="profit-box" aria-live="polite"></div>
      <p class="field-hint">Estimate only — the real figures are calculated on the server from each paid order. Ad cost uses ${esc(meta.est_ad_cost_basis)}.</p></div>

    <div class="form-section"><h3>Appearance</h3>
      <div class="field-row"><div class="field"><label class="label" for="f-badge">Badge text</label>
        <input class="input" id="f-badge" name="badge" maxlength="40" value="${s?.badge || ''}" placeholder="e.g. TRIAL PACK"></div>
        <div class="field"><label class="label" for="f-badge_color">Badge colour</label>
        <select class="select" id="f-badge_color" name="badge_color">${meta.badge_colors.map((c) => html`<option value="${c}" ${(s?.badge_color || 'red') === c ? 'selected' : ''}>${c[0].toUpperCase() + c.slice(1)}</option>`)}</select></div></div>
      <div class="field-row"><div class="field"><label class="label" for="f-sort_order">Sort order</label>
        <input class="input" id="f-sort_order" name="sort_order" inputmode="numeric" value="${s?.sort_order ?? 0}"><p class="field-hint">Lower numbers appear first.</p></div>
        <div class="field"><div class="switch-row"><div><b>Premium styling</b><small>Gold highlight on the card</small></div><label class="switch"><input type="checkbox" name="is_premium" ${s?.is_premium ? 'checked' : ''} aria-label="Premium styling"><span></span></label></div>
        <div class="switch-row"><div><b>Visible on the store</b><small>Turn off to hide without deleting</small></div><label class="switch"><input type="checkbox" name="active" ${(s?.status || 'active') === 'active' ? 'checked' : ''} aria-label="Visible on the store"><span></span></label></div></div></div>
      ${iconBlock(icon_url)}
    </div>

    ${s?.pricing_history?.length ? html`<div class="form-section"><h3>Price history</h3><div class="grid gap-1">${s.pricing_history.map((h) => html`<div class="kv"><span>${fmtDateTime(h.at)} · ${h.by}</span><b>${inr(h.price_paise)} price · ${inr(h.cost_paise)} cost</b></div>`)}</div></div>` : ''}
  </form>`;
}

function footHtml(s) {
  return html`${s ? html`<button type="button" class="btn btn-ghost text-bad-700 mr-auto" data-delete-current>${icon('trash-2', 'icon-sm')}<span>Delete</span></button>` : ''}
    <button type="button" class="btn" data-dialog-close>Cancel</button>
    <button type="button" class="btn btn-primary btn-shine" id="svc-save">${icon('check')}<span>${s ? 'Save changes' : 'Create service'}</span></button>`;
}

function updatePreview() {
  const box = $('#profit-preview');
  if (!box) return;
  const price = parseRupees(val('price'));
  const mode = val('mode');
  const cost = mode === 'AUTO' ? parseRupees(val('smm_cost') || '0') : 0;
  if (price === null || (mode === 'AUTO' && cost === null)) { box.className = 'profit-box'; box.innerHTML = '<span class="text-sm text-muted">Enter a valid price' + (mode === 'AUTO' ? ' and provider cost' : '') + ' to see the estimated profit.</span>'; return; }
  const ad = meta.est_ad_cost_paise;
  const gross = price - cost;
  const net = gross - ad;
  const margin = price ? Math.trunc((net * 1000) / price) : 0;
  box.className = 'profit-box' + (net < 0 ? ' is-neg' : '');
  box.innerHTML = String(html`
    <div class="profit-line"><span>Customer pays</span><b>${inr(price)}</b></div>
    <div class="profit-line"><span>${mode === 'AUTO' ? 'Provider cost' : 'Provider cost (manual — not counted)'}</span><b>− ${inr(cost)}</b></div>
    <div class="profit-line"><span>Gross profit</span><b>${inr(gross)}</b></div>
    <div class="profit-line"><span>Estimated ad cost per order</span><b>− ${inr(ad, true)}</b></div>
    <div class="profit-line total"><span>Estimated net profit · ${tenths(margin)}% margin</span><b class="${net < 0 ? 'amt-neg' : 'amt-pos'}">${inr(net)}</b></div>`);
}

function suggestCode() {
  const codeEl = $('#f-code');
  if (!codeEl || editing || codeEl.dataset.touched) return;
  const plat = meta.platforms.find((p) => p.id === Number(val('platform_id')));
  const q = Number(val('quantity'));
  const short = q >= 1e6 && q % 1e6 === 0 ? `${q / 1e6}m` : q >= 1e3 && q % 1e3 === 0 ? `${q / 1e3}k` : String(q || '');
  codeEl.value = [prefixOf(plat?.slug), val('type').replace(/_/g, '-'), short].filter(Boolean).join('-');
}

function syncTargetKinds() {
  const plat = meta.platforms.find((p) => p.id === Number(val('platform_id')));
  const kinds = meta.target_kinds[plat?.slug] || ['profile', 'post', 'video', 'channel'];
  const sel = $('#f-target_kind');
  const cur = sel.value;
  const labels = { profile: 'Username / profile link', post: 'Post / reel link', channel: 'Channel link', video: 'Video link' };
  sel.innerHTML = kinds.map((k) => `<option value="${k}">${esc(labels[k] || k)}</option>`).join('');
  sel.value = kinds.includes(cur) ? cur : kinds[0];
}

function setMode(mode) {
  $('#sd-body [name="mode"]').value = mode;
  $$('#sd-body [data-mode]').forEach((b) => { const on = b.dataset.mode === mode; b.setAttribute('aria-checked', String(on)); });
  $('#auto-fields').hidden = mode !== 'AUTO';
  updatePreview();
}

// provider catalogue lookup
function costFromRate(rate, quantity) {
  if (!providerRates || !/^\d+(\.\d+)?$/.test(String(rate))) return null;
  const m = /^(\d+)(?:\.(\d{1,6})\d*)?$/.exec(String(rate));
  const rateMicros = BigInt(m[1]) * 1000000n + BigInt((m[2] || '0').padEnd(6, '0'));
  const paise = (rateMicros * BigInt(quantity) * providerRates.micros + 10n ** 13n - 1n) / 10n ** 13n;   // ceil: per-1000 rate × qty × INR rate
  return Number(paise);
}
async function searchProvider(q) {
  const pid = val('smm_provider_id');
  const list = $('#svc-results');
  const hint = $('#svc-search-hint');
  if (!pid) { hint.textContent = 'Choose a provider first.'; return; }
  hint.textContent = 'Searching…';
  try {
    const { result } = await get(`smm/${pid}/services`, { q });
    if (!result.ok) { hint.textContent = result.message; list.hidden = true; return; }
    const qty = Number(val('quantity')) || 0;
    list.innerHTML = result.items.length ? result.items.map((s) => {
      const c = qty ? costFromRate(s.rate, qty) : null;
      return `<button type="button" class="combo-item" role="option" data-pick='${esc(JSON.stringify({ id: s.service, cost: c }))}'><b>#${esc(s.service)} · ${esc(s.name)}</b><small>${esc(s.category)} · rate ${esc(s.rate)}/1000 · min ${esc(s.min)} · max ${esc(s.max)}${c !== null ? ` · ≈ ${esc(inr(c, true))} for ${esc(num(qty))}` : ''}</small></button>`;
    }).join('') : '<div class="combo-item" role="option" aria-disabled="true"><small>No matching services.</small></div>';
    list.hidden = false;
    $('#svc-search').setAttribute('aria-expanded', 'true');
    hint.textContent = result.message + ' — pick one to fill the ID and cost.';
  } catch (e) { hint.textContent = e.message; }
}

async function openEditor(id) {
  editing = id ? services.find((s) => s.id === Number(id)) : null;
  form = { icon_path: undefined };
  const dlg = $('#service-drawer');
  $('#sd-title').textContent = editing ? 'Edit service' : 'New service';
  $('#sd-sub').textContent = editing ? editing.code : 'Create a service customers can buy';
  $('#sd-body').innerHTML = '<div class="skeleton h-32 mb-3"></div><div class="skeleton h-24 mb-3"></div><div class="skeleton h-40"></div>';
  $('#sd-foot').innerHTML = '';
  openDialog(dlg);
  let full = null;
  try { if (editing) full = (await get(`services/${editing.id}`)).service; } catch (e) { $('#sd-body').innerHTML = errorState(e.message).toString(); return; }
  editing = full;
  paintEditor();
}

function paintEditor() {
  $('#sd-body').innerHTML = String(editorHtml(editing));
  $('#sd-foot').innerHTML = String(footHtml(editing));
  providerRates = null;
  const prov = meta.providers.find((p) => p.id === Number(val('smm_provider_id')));
  if (prov) loadRate(prov.id);
  updatePreview();
  autoClearErrors($('#sd-body'));
}

async function loadRate(pid) {
  try {
    const { providers } = await get('smm');
    const p = providers.find((x) => x.id === Number(pid));
    if (!p) { providerRates = null; return; }
    const m = /^(\d+)(?:\.(\d+))?$/.exec(String(p.rate_to_inr));
    providerRates = m ? { micros: BigInt(m[1]) * 1000000n + BigInt((m[2] || '0').padEnd(6, '0').slice(0, 6)), currency: p.currency } : null;
  } catch { providerRates = null; }
}

function collect() {
  const body = {
    platform_id: Number(val('platform_id')), type: val('type'), target_kind: val('target_kind'), title: val('title'), subtitle: val('subtitle'), description: val('description'),
    code: val('code'), price: val('price'), quantity: val('quantity'), min_quantity: val('min_quantity'), max_quantity: val('max_quantity'), mode: val('mode'),
    smm_provider_id: val('smm_provider_id') || null, smm_service_id: val('smm_service_id'), smm_cost: val('smm_cost') || '0', badge: val('badge'), badge_color: val('badge_color'),
    sort_order: val('sort_order') || '0', is_premium: checked('is_premium'), status: checked('active') ? 'active' : 'inactive',
  };
  if (body.mode !== 'AUTO') { body.smm_cost = val('smm_cost') || '0'; }
  if (form.icon_path !== undefined) body.icon_path = form.icon_path;
  if (editing) body.expected_updated_at = editing.updated_at;
  return body;
}

async function save(btn) {
  const f = $('#svc-form');
  clearFieldErrors(f);
  await withBusy(btn, async () => {
    try {
      const body = collect();
      const res = editing ? await put(`services/${editing.id}`, body, { idempotency: true }) : await post('services', body, { idempotency: true });
      ok(editing ? 'Service saved' : 'Service created', res.service.title);
      closeDialog($('#service-drawer'));
      await load();
      reloadStatus();
    } catch (e) {
      if (e.fields) { showFieldErrors(f, e.fields); fail('Please check the form', e.message); }
      else fail(e.code === 'conflict' ? 'Changed elsewhere' : 'Could not save', e.message);
    }
  });
}

async function remove(id) {
  const s = services.find((x) => x.id === Number(id));
  if (!s) return;
  const yes = await confirmDialog({
    title: `Delete “${s.title}”?`, tone: 'danger', confirmText: 'Delete service',
    message: s.paid_orders ? `It has ${s.paid_orders} paid order${s.paid_orders === 1 ? '' : 's'}. Past orders and reports are kept; the service just disappears from the store.` : 'The service will be removed from the store.',
  });
  if (!yes) return;
  try {
    await del(`services/${id}`);
    ok('Service deleted', s.title);
    closeDialog($('#service-drawer'));
    await load();
  } catch (e) { fail('Could not delete', e.message); }
}

export default async function init() {
  await load();
  on(document, 'click', '#add-service, [data-add]', () => { if (meta) openEditor(null); });
  on(root(), 'click', '[data-edit]', (e, b) => openEditor(b.dataset.edit));
  on(root(), 'click', '[data-delete]', (e, b) => remove(b.dataset.delete));
  on(root(), 'click', '[data-retry]', () => load());

  // quick active toggle in the table
  on(root(), 'change', '[data-toggle]', async (e, cb) => {
    const s = services.find((x) => x.id === Number(cb.dataset.toggle));
    const want = cb.checked;
    cb.disabled = true;
    try {
      await put(`services/${s.id}`, { status: want ? 'active' : 'inactive', expected_updated_at: s.updated_at });
      ok(want ? 'Service is now visible' : 'Service hidden', s.title);
      await load();
    } catch (err) {
      cb.checked = !want;
      fail('Could not update', err.message);
      await load();
    } finally { cb.disabled = false; }
  });

  const dlg = $('#service-drawer');
  on(dlg, 'click', '#svc-save', (e, b) => save(b));
  on(dlg, 'click', '[data-delete-current]', () => editing && remove(editing.id));
  on(dlg, 'click', '[data-mode]', (e, b) => setMode(b.dataset.mode));
  on(dlg, 'click', '[data-icon-remove]', () => { form.icon_path = ''; form.icon_url = null; paintIcon(); });
  on(dlg, 'input', '#svc-form', (e) => {
    const n = e.target.name;
    if (n === 'code') e.target.dataset.touched = '1';
    if (['quantity', 'type'].includes(n)) suggestCode();
    updatePreview();
  });
  on(dlg, 'change', '#svc-form', (e) => {
    const n = e.target.name;
    if (n === 'platform_id') { syncTargetKinds(); suggestCode(); }
    if (n === 'type') suggestCode();
    if (n === 'smm_provider_id') loadRate(e.target.value);
    updatePreview();
  });
  // provider service lookup (debounced)
  const search = debounce((q) => searchProvider(q), 350);
  on(dlg, 'input', '#svc-search', (e) => search(e.target.value.trim()));
  on(dlg, 'focusin', '#svc-search', (e) => { if (!$('#svc-results').children.length) searchProvider(''); else $('#svc-results').hidden = false; });
  on(dlg, 'click', '[data-pick]', (e, b) => {
    const p = JSON.parse(b.dataset.pick);
    $('#f-smm_service_id').value = p.id;
    if (p.cost !== null && p.cost !== undefined) { $('#f-smm_cost').value = plain(p.cost).replace(/\.00$/, ''); $('#cost-hint').textContent = 'Estimated from the provider rate and your exchange rate — adjust if your real cost differs.'; }
    $('#svc-results').hidden = true;
    $('#svc-search').setAttribute('aria-expanded', 'false');
    clearFieldErrors($('#svc-form'));
    updatePreview();
  });
  document.addEventListener('click', (e) => { if (!(e.target instanceof Element) || !e.target.closest('.combo')) { const l = $('#svc-results'); if (l) l.hidden = true; } });

  // icon upload
  dlg.addEventListener('change', async (e) => {
    if (e.target.id !== 'icon-file' || !e.target.files?.[0]) return;
    const file = e.target.files[0];
    e.target.value = '';
    if (file.size > 2 * 1024 * 1024) { fail('Image too large', 'Please choose an image under 2 MB.'); return; }
    try {
      const { image } = await upload('services/icon', file);
      form.icon_path = image.path; form.icon_url = image.url;
      paintIcon();
      ok('Icon uploaded', 'It is applied when you save the service.');
    } catch (err) { fail('Upload failed', err.message); }
  });
}
