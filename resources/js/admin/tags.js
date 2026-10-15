// Admin → Tags & Badges: list, create, edit (name, background / text colour with picker + HEX, enabled), delete custom tags, live previews.
import { $, html, icon, on } from '../core/dom.js';
import { openDialog, closeDialog, confirmDialog } from '../core/dialog.js';
import { get, post, put, del } from './net.js';
import { ok, fail, errorState, skeletonTable, showFieldErrors, clearFieldErrors, autoClearErrors, withBusy } from './ui.js';
import { normHex, contrast, applyTags, pill, cardPreview } from './tagui.js';

let tags = [];
let meta = null;
let editing = null;                                   // the tag in the editor (null = a new one)
const root = () => $('#tags-root');

// ── list ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const kind = (t) => (t.is_auto ? `Predefined · automatic at ${meta.trial.price}` : t.is_system ? 'Predefined' : 'Custom');
const swatch = (hex) => html`<span class="tag-swatch" data-sw="${hex}"><i></i>${hex}</span>`;

function row(t) {
  const low = t.contrast < meta.limits.good_contrast;
  return html`<tr data-id="${t.id}" class="${t.is_active ? '' : 'opacity-60'}">
    <td data-label="Badge" class="span2"><span class="cell-svc">${pill(t)}<span class="cell-main"><b>${t.name}</b><small>${kind(t)}${t.is_active ? '' : ' · hidden on the store'}</small></span></span></td>
    <td data-label="Background">${swatch(t.bg_color)}</td>
    <td data-label="Text"><span class="stackcell">${swatch(t.text_color)}${low ? html`<small class="text-warn-700" title="WCAG recommends 4.5:1 for small text">contrast ${t.contrast.toFixed(1)}:1</small>` : ''}</span></td>
    <td data-label="Used by" class="num">${t.is_auto ? html`<span class="amt-mute" title="Shown on every service priced at exactly ${meta.trial.price}">automatic</span>` : t.usage ? html`<b>${t.usage}</b> service${t.usage === 1 ? '' : 's'}` : html`<span class="amt-mute">—</span>`}</td>
    <td data-label="Enabled"><label class="switch" title="${t.is_active ? 'Shown on the store' : 'Hidden on the store (assignments are kept)'}"><input type="checkbox" data-toggle="${t.id}" ${t.is_active ? 'checked' : ''} aria-label="${t.name} enabled"><span></span></label></td>
    <td class="row-actions-cell"><span class="row-actions">
      <button type="button" class="btn btn-sm" data-edit="${t.id}" aria-label="Edit ${t.name}">${icon('pencil', 'icon-sm')}<span>Edit</span></button>
      ${t.is_system ? '' : html`<button type="button" class="btn btn-sm btn-ghost btn-icon" data-delete="${t.id}" aria-label="Delete ${t.name}" title="Delete">${icon('trash-2', 'icon-sm')}</button>`}</span></td>
  </tr>`;
}

function renderList() {
  const off = tags.filter((t) => !t.is_active).length;
  root().innerHTML = String(html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Tags"><table class="tbl tbl-resp">
    <thead><tr><th>Badge</th><th>Background</th><th>Text</th><th class="num">Used by</th><th>Enabled</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>${tags.map(row)}</tbody></table></div></div>
    <p class="text-[13px] text-muted mt-3">${off ? `${off} tag${off === 1 ? ' is' : 's are'} disabled and hidden on the store. ` : ''}Disabling a tag hides it without removing it from the services that use it. Predefined tags can be edited and disabled but not deleted; deleting a custom tag leaves its services without a tag.
      <b>${tags.find((t) => t.is_auto)?.name ?? 'TRIAL PACK'}</b> is automatic: every service priced at exactly ${meta.trial.price} shows it, and it takes priority over the tag assigned to that service.</p>`);
  applyTags(root());
}

async function load() {
  root().setAttribute('aria-busy', 'true');
  if (!tags.length) root().innerHTML = String(skeletonTable(8));
  try {
    const d = await get('tags');
    tags = d.tags; meta = d.meta;
    renderList();
  } catch (e) {
    root().innerHTML = errorState(e.message).toString();
  } finally { root().setAttribute('aria-busy', 'false'); }
}

// ── editor ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
function colorField(name, label, value, extra = '') {
  return html`<div class="field"><label class="label req" for="t-${name}">${label}</label>
    <div class="color-field"><input type="color" data-color="${name}" value="${value.toLowerCase()}" aria-label="Pick: ${label}">
      <input class="input input-sm mono" id="t-${name}" name="${name}" value="${value}" maxlength="7" spellcheck="false" autocapitalize="characters" aria-describedby="t-contrast"></div>${extra}</div>`;
}

function editorHtml(t) {
  const v = t || { name: '', bg_color: '#2563EB', text_color: '#FFFFFF', is_active: true, default: null };
  return html`<form id="tag-form" novalidate autocomplete="off">
    <div class="field"><label class="label req" for="t-name">Tag name</label>
      <input class="input" id="t-name" name="name" maxlength="${meta.limits.name_max}" value="${v.name}" placeholder="e.g. FLASH SALE" aria-describedby="t-name-hint">
      <p class="field-hint" id="t-name-hint">${meta.limits.name_min}–${meta.limits.name_max} characters. Shown in capitals on the card, so keep it short.</p></div>
    <div class="field-row">
      ${colorField('bg_color', 'Background colour', v.bg_color)}
      ${colorField('text_color', 'Text colour', v.text_color, html`<div class="flex gap-2"><button type="button" class="btn btn-sm" data-fg="#FFFFFF">White text</button><button type="button" class="btn btn-sm" data-fg="#000000">Black text</button></div>`)}
    </div>
    <p class="field-hint" id="t-contrast" role="status" aria-live="polite"></p>
    <div class="switch-row"><div><b>Enabled</b><small>Turn off to hide this tag on the store; services keep it assigned</small></div>
      <label class="switch"><input type="checkbox" name="is_active" ${v.is_active ? 'checked' : ''} aria-label="Tag enabled"><span></span></label></div>
    ${v.default ? html`<div class="mt-1"><button type="button" class="btn btn-sm btn-ghost" data-reset-default>${icon('rotate-ccw', 'icon-sm')}<span>Use the default name and colours (${v.default.bg_color} / ${v.default.text_color})</span></button></div>` : ''}
    ${t?.is_auto ? html`<div class="callout callout-info mt-3">${icon('info')}<div>This tag is <b>automatic</b>: it appears on every service whose price is exactly ${meta.trial.price}, ahead of any tag assigned by hand. You can rename or recolour it, or disable it to turn the rule off.</div></div>` : ''}
    <div class="form-section"><h3>Preview</h3>
      <p class="lead">How the badge looks, and where it sits on a service card.</p>
      <div class="tagprev-pill" id="t-pill"></div>
      <div id="t-card"></div>
      <p class="field-hint" id="t-off" hidden>This tag is disabled, so customers will not see it. The preview shows how it looks once enabled.</p>
    </div>
  </form>`;
}

const values = () => {
  const f = $('#tag-form');
  return { name: f.elements.name.value.trim().replace(/\s+/g, ' '), bg: normHex(f.elements.bg_color.value), fg: normHex(f.elements.text_color.value), active: f.elements.is_active.checked };
};

function paintPreview() {
  const v = values();
  const tag = { name: v.name || 'TAG NAME', bg_color: v.bg || '#9CA3AF', text_color: v.fg || '#FFFFFF' };
  $('#t-pill').innerHTML = String(pill(tag, 'tagpill-lg'));
  $('#t-card').innerHTML = String(cardPreview({ title: '100K Followers', subtitle: 'Instagram Non Drop Followers', pricePaise: 9900, tag, platform: 'instagram', type: 'followers' }));
  $('#t-off').hidden = v.active;
  applyTags($('#td-body'));
  const el = $('#t-contrast');
  if (!v.bg || !v.fg) { el.className = 'field-hint text-bad-700'; el.textContent = 'Enter both colours as HEX, for example #DC2626.'; return; }
  const c = contrast(v.bg, v.fg);
  if (c < meta.limits.min_contrast) { el.className = 'field-hint text-bad-700'; el.textContent = `Contrast ${c.toFixed(2)}:1 — the text would be nearly invisible. Choose lighter or darker colours.`; }
  else if (c < meta.limits.good_contrast) { el.className = 'field-hint text-warn-700'; el.textContent = `Contrast ${c.toFixed(2)}:1 — readable, but below the 4.5:1 recommended for small text. A darker background or a different text colour would help.`; }
  else { el.className = 'field-hint text-ok-700'; el.textContent = `Contrast ${c.toFixed(2)}:1 — easy to read.`; }
}

function openEditor(id) {
  editing = id ? tags.find((t) => t.id === id) : null;
  $('#td-title').textContent = editing ? 'Edit tag' : 'New tag';
  $('#td-sub').textContent = editing ? kind(editing) : 'A custom badge you can assign to any service';
  $('#td-body').innerHTML = String(editorHtml(editing));
  $('#td-foot').innerHTML = String(html`${editing && !editing.is_system ? html`<button type="button" class="btn btn-ghost text-bad-700 mr-auto" data-delete-current>${icon('trash-2', 'icon-sm')}<span>Delete</span></button>` : ''}
    <button type="button" class="btn" data-dialog-close>Cancel</button>
    <button type="button" class="btn btn-primary btn-shine" id="tag-save">${icon('check')}<span>${editing ? 'Save changes' : 'Create tag'}</span></button>`);
  autoClearErrors($('#td-body'));
  paintPreview();
  openDialog($('#tag-dialog'));
  $('#t-name').focus();
}

async function save(btn) {
  const f = $('#tag-form');
  clearFieldErrors(f);
  const body = { name: f.elements.name.value, bg_color: f.elements.bg_color.value, text_color: f.elements.text_color.value, is_active: f.elements.is_active.checked };
  await withBusy(btn, async () => {
    try {
      const res = editing ? await put(`tags/${editing.id}`, body, { idempotency: true }) : await post('tags', body, { idempotency: true });
      ok(editing ? 'Tag saved' : 'Tag created', res.tag.name);
      closeDialog($('#tag-dialog'));
      await load();
    } catch (e) {
      if (e.fields) { showFieldErrors(f, e.fields); fail('Please check the form', e.message); }
      else fail('Could not save', e.message);
    }
  });
}

async function remove(id) {
  const t = tags.find((x) => x.id === id);
  if (!t) return;
  const yes = await confirmDialog({
    title: `Delete “${t.name}”?`, tone: 'danger', confirmText: 'Delete tag',
    message: t.usage ? `${t.usage} service${t.usage === 1 ? ' uses' : 's use'} this tag. ${t.usage === 1 ? 'It' : 'They'} will simply have no tag afterwards. This cannot be undone.` : 'No service uses this tag. This cannot be undone.',
  });
  if (!yes) return;
  try {
    const r = await del(`tags/${id}`);
    ok('Tag deleted', r.unassigned ? `${r.unassigned} service${r.unassigned === 1 ? '' : 's'} now ${r.unassigned === 1 ? 'has' : 'have'} no tag.` : t.name);
    closeDialog($('#tag-dialog'));
    await load();
  } catch (e) { fail('Could not delete', e.message); }
}

export default async function init() {
  await load();
  on(document, 'click', '#add-tag', () => { if (meta) openEditor(null); });
  on(root(), 'click', '[data-edit]', (e, b) => openEditor(Number(b.dataset.edit)));
  on(root(), 'click', '[data-delete]', (e, b) => remove(Number(b.dataset.delete)));
  on(root(), 'click', '[data-retry]', () => load());

  // quick enable / disable in the table
  on(root(), 'change', '[data-toggle]', async (e, cb) => {
    const t = tags.find((x) => x.id === Number(cb.dataset.toggle));
    const want = cb.checked;
    cb.disabled = true;
    try {
      await put(`tags/${t.id}`, { is_active: want });
      ok(want ? 'Tag enabled' : 'Tag disabled', want ? `${t.name} is shown on the store again.` : `${t.name} is hidden on the store. Its assignments are kept.`);
      await load();
    } catch (err) {
      cb.checked = !want;
      fail('Could not update', err.message);
      await load();
    } finally { cb.disabled = false; }
  });

  const dlg = $('#tag-dialog');
  on(dlg, 'click', '#tag-save', (e, b) => save(b));
  on(dlg, 'click', '[data-delete-current]', () => editing && remove(editing.id));
  on(dlg, 'click', '[data-fg]', (e, b) => { const f = $('#tag-form'); f.elements.text_color.value = b.dataset.fg; $('[data-color="text_color"]', f).value = b.dataset.fg.toLowerCase(); paintPreview(); });
  on(dlg, 'click', '[data-reset-default]', () => {
    const d = editing.default, f = $('#tag-form');
    f.elements.name.value = d.name; f.elements.bg_color.value = d.bg_color; f.elements.text_color.value = d.text_color;
    $('[data-color="bg_color"]', f).value = d.bg_color.toLowerCase(); $('[data-color="text_color"]', f).value = d.text_color.toLowerCase();
    clearFieldErrors(f); paintPreview();
  });
  // colour picker ⇄ HEX box
  on(dlg, 'input', '[data-color]', (e, picker) => { $(`[name="${picker.dataset.color}"]`, dlg).value = picker.value.toUpperCase(); paintPreview(); });
  on(dlg, 'input', '#tag-form', (e) => {
    const t = e.target;
    if (t.name === 'bg_color' || t.name === 'text_color') { const h = normHex(t.value); if (h) $(`[data-color="${t.name}"]`, dlg).value = h.toLowerCase(); }
    paintPreview();
  });
  on(dlg, 'focusout', '[name="bg_color"], [name="text_color"]', (e, input) => { const h = normHex(input.value); if (h) { input.value = h; $(`[data-color="${input.name}"]`, dlg).value = h.toLowerCase(); paintPreview(); } });
  on(dlg, 'submit', '#tag-form', (e) => { e.preventDefault(); $('#tag-save', dlg).click(); });
}
