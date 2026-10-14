import { $, html, icon, on, url } from '../core/dom.js';
import { openDialog, closeDialog, confirmDialog } from '../core/dialog.js';
import { fmtDateTime, timeAgo } from '../core/format.js';
import { get, post, put, del } from './net.js';
import { ok, fail, errorState, emptyState, showFieldErrors, clearFieldErrors, autoClearErrors, withBusy } from './ui.js';
import { secretField, wireSecrets } from './secret.js';
import { reloadStatus } from './shell.js';

let providers = [];
let editing = null;
const root = () => $('#smm-root');

function card(p) {
  const t = p.last_test_ok === null ? null : p.last_test_ok;
  return html`<article class="card prov-card" data-provider="${p.id}" aria-label="${p.name}">
    <div class="prov-head"><div class="min-w-0"><h3 class="text-lg font-extrabold truncate">${p.name}</h3><p class="text-xs text-muted mono break-all">${p.api_url}</p></div>
      <span class="flex flex-wrap gap-1.5">${p.is_default ? html`<span class="chip chip-brand">Default</span>` : ''}<span class="chip ${p.is_active ? 'chip-ok' : 'chip-gray'}">${p.is_active ? 'Active' : 'Inactive'}</span></span></div>
    <dl class="dl"><dt>Currency</dt><dd>${p.currency}${p.currency !== 'INR' ? ` · 1 ${p.currency} = ₹${p.rate_to_inr}` : ''}</dd>
      <dt>API key</dt><dd class="mono">${p.has_api_key ? p.api_key_hint : 'not set'}</dd>
      <dt>Services using it</dt><dd>${p.services_using}</dd>
      <dt>Balance</dt><dd>${p.last_balance || '—'}</dd>
      <dt>Last test</dt><dd>${t === null ? 'Never tested' : html`<span class="chip ${t ? 'chip-ok' : 'chip-bad'}">${t ? 'Passed' : 'Failed'}</span> <span class="text-xs text-muted">${p.last_tested_at ? timeAgo(p.last_tested_at) : ''}</span>`}</dd></dl>
    ${p.last_test_message ? html`<p class="text-xs text-muted">${p.last_test_message}</p>` : ''}
    <div class="flex flex-wrap gap-2" aria-live="polite"><button type="button" class="btn btn-sm btn-primary" data-test="${p.id}">${icon('activity', 'icon-sm')}<span>TEST CONNECTION</span></button>
      <button type="button" class="btn btn-sm" data-edit="${p.id}">${icon('pencil', 'icon-sm')}<span>Edit</span></button>
      <button type="button" class="btn btn-sm btn-ghost text-bad-700" data-remove="${p.id}" aria-label="Remove ${p.name}">${icon('trash-2', 'icon-sm')}</button></div>
    <div data-test-result="${p.id}"></div></article>`;
}

function renderList() {
  root().innerHTML = String(providers.length
    ? html`<div class="grid-2">${providers.map(card)}</div>
      <div class="callout callout-info mt-5">${icon('info')}<div><strong>How AUTO delivery works.</strong> When a payment is confirmed, the server calls your provider’s API (the key never leaves the server), records the provider order ID and cost, and keeps the order status in sync. Set a service to AUTO in <a class="underline" href="${url('/admin/services')}">Services</a> and give it the provider’s service ID.</div></div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'server', title: 'No SMM provider connected', text: 'Connect a provider to deliver orders automatically. Until then, services stay MANUAL and appear in the approval queue.', action: '<button type="button" class="btn btn-primary btn-sm mt-2" data-add>Add provider</button>' })}</div>`);
  root().setAttribute('aria-busy', 'false');
}

function formHtml(p) {
  const cur = p?.currency || 'USD';
  return html`<form id="prov-form" novalidate autocomplete="off">
    <div class="field"><label class="label req" for="pv-name">Name</label><input class="input" id="pv-name" name="name" maxlength="80" value="${p?.name || ''}" placeholder="e.g. MyProvider"></div>
    <div class="field"><label class="label req" for="pv-url">API URL</label><input class="input mono" id="pv-url" name="api_url" value="${p?.api_url || ''}" placeholder="https://provider.com/api/v2" spellcheck="false" autocapitalize="none">
      <p class="field-hint">The provider’s standard API v2 endpoint (the one that accepts <span class="kbd">key</span> and <span class="kbd">action</span>). Must be https:// in production.</p></div>
    ${secretField({ name: 'api_key', label: 'API key', has: !!p?.has_api_key, hint: p?.api_key_hint || '', placeholder: 'Paste your provider API key', help: 'Stored encrypted. Only the server uses it; the browser never receives it.' })}
    <div class="field-row"><div class="field"><label class="label req" for="pv-cur">Provider currency</label>
      <input class="input" id="pv-cur" name="currency" maxlength="8" value="${cur}" list="cur-list" autocapitalize="characters"><datalist id="cur-list"><option value="INR"><option value="USD"><option value="EUR"><option value="GBP"></datalist></div>
      <div class="field" id="rate-field" ${cur.toUpperCase() === 'INR' ? 'hidden' : ''}><label class="label req" for="pv-rate">Rupees per 1 ${cur.toUpperCase()}</label><input class="input" id="pv-rate" name="rate_to_inr" inputmode="decimal" value="${p?.currency !== 'INR' ? p?.rate_to_inr || '' : ''}" placeholder="83.5">
      <p class="field-hint">Used to convert the provider’s charges into rupees for profit reports.</p></div></div>
    <div class="switch-row"><div><b>Active</b><small>Inactive providers are never called.</small></div><label class="switch"><input type="checkbox" name="is_active" ${(p?.is_active ?? true) ? 'checked' : ''} aria-label="Active"><span></span></label></div>
    <div class="switch-row"><div><b>Default provider</b><small>Used for services that do not name a provider.</small></div><label class="switch"><input type="checkbox" name="is_default" ${(p?.is_default ?? providers.length === 0) ? 'checked' : ''} aria-label="Default"><span></span></label></div>
  </form>`;
}

function openForm(p) {
  editing = p || null;
  const dlg = $('#prov-dialog');
  $('#pd-title').textContent = p ? `Edit ${p.name}` : 'Add SMM provider';
  $('#pd-body').innerHTML = String(formHtml(p));
  autoClearErrors($('#pd-body'));
  openDialog(dlg);
}

function formBody() {
  const f = $('#prov-form');
  const o = { name: f.elements.name.value, api_url: f.elements.api_url.value, currency: f.elements.currency.value, rate_to_inr: f.elements.rate_to_inr?.value || '', is_active: f.elements.is_active.checked, is_default: f.elements.is_default.checked };
  const k = f.elements.api_key; if (k && !k.disabled && k.value.trim()) o.api_key = k.value.trim();
  return o;
}

async function load() {
  try { providers = (await get('smm')).providers; renderList(); } catch (e) { root().innerHTML = errorState(e.message).toString(); root().setAttribute('aria-busy', 'false'); }
}

export default async function init() {
  const r = root();
  await load();
  on(document, 'click', '[data-add]', () => openForm(null));
  on(r, 'click', '[data-edit]', (e, b) => openForm(providers.find((p) => p.id === Number(b.dataset.edit))));
  on(r, 'click', '[data-retry]', load);
  wireSecrets($('#pd-body'));
  const dlg = $('#prov-dialog');
  on(dlg, 'input', '[name="currency"]', (e, el) => {
    const c = el.value.trim().toUpperCase();
    $('#rate-field').hidden = c === 'INR' || c === '';
    const lab = $('#rate-field label'); if (lab) lab.textContent = `Rupees per 1 ${c}`;
  });
  on(dlg, 'click', '#pv-save', async (e, btn) => {
    clearFieldErrors($('#prov-form'));
    await withBusy(btn, async () => {
      try {
        const res = editing ? await put(`smm/${editing.id}`, formBody(), { idempotency: true }) : await post('smm', formBody(), { idempotency: true });
        providers = res.providers;
        closeDialog(dlg);
        renderList();
        ok(editing ? 'Provider saved' : 'Provider added', res.provider.name);
        reloadStatus();
      } catch (err) {
        if (err.fields) { showFieldErrors($('#prov-form'), err.fields); fail('Please check the highlighted fields', err.message); } else fail('Could not save', err.message);
      }
    });
  });
  on(r, 'click', '[data-test]', async (e, btn) => {
    const id = btn.dataset.test;
    const out = $(`[data-test-result="${id}"]`);
    out.innerHTML = '';
    await withBusy(btn, async () => {
      try {
        const res = await post(`smm/${id}/test`);
        providers = res.providers;
        const t = res.result;
        out.innerHTML = String(html`<div class="callout callout-${t.ok ? 'success' : 'error'} mt-1" role="status">${icon(t.ok ? 'circle-check' : 'circle-alert')}<div>${t.message}<br><small>${t.duration_ms} ms</small></div></div>`);
        (t.ok ? ok : fail)(t.ok ? 'Connection successful' : 'Connection failed', t.message);
        // refresh the card facts (balance, last test) without losing the result box
        const keep = out.innerHTML;
        renderList();
        $(`[data-test-result="${id}"]`).innerHTML = keep;
      } catch (err) { fail('Test failed', err.message); }
    });
  });
  on(r, 'click', '[data-remove]', async (e, btn) => {
    const p = providers.find((x) => x.id === Number(btn.dataset.remove));
    if (!(await confirmDialog({ title: `Remove “${p.name}”?`, tone: 'danger', confirmText: 'Remove provider', message: p.services_using ? `${p.services_using} service(s) still use it — you will be asked to switch them first.` : 'The saved API key is deleted. Past orders keep their provider history.' }))) return;
    try { const res = await del(`smm/${p.id}`); providers = res.providers; renderList(); ok(res.message); reloadStatus(); } catch (err) { fail('Could not remove', err.message); }
  });
}
