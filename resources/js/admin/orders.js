import { $, $$, html, raw, icon, on, url, esc, uuid, store } from '../core/dom.js';
import { inr, num, fmtDateTime, timeAgo, plain } from '../core/format.js';
import { openDialog, closeDialog, confirmDialog } from '../core/dialog.js';
import { ok, fail } from '../core/toast.js';
import { get, post } from './net.js';
import { reloadStatus, onStatus } from './shell.js';
import {
  orderChip, payChip, modeChip, signedMoney, skeletonTable, emptyState, errorState, pager, onSearch, copyText, withBusy, setBusy,
  formDialog, ORDER_STATUSES, whenVisible,
} from './ui.js';

const CHIPS = [
  ['all', 'All'], ['pending', 'Pending payment'], ['paid', 'Paid'], ['processing', 'Processing'], ['completed', 'Completed'],
  ['manual', 'Manual'], ['auto', 'Auto'], ['failed', 'Failed'], ['refunded', 'Refunded'],
];

const state = { filter: 'all', q: '', platform: '', mode: '', status: '', from: '', to: '', page: 1 };
let platforms = [];
let listData = null;
let seq = 0;
let openId = null;
let drawerData = null;

const root = () => $('#orders-root');
const hasFilters = () => state.q || state.platform || state.mode || state.status || state.from || state.to || state.filter !== 'all';

// ── URL <-> state (so the dashboard can deep-link and the back button behaves) ──
function readUrl() {
  const q = new URLSearchParams(location.search);
  for (const k of Object.keys(state)) if (k !== 'page' && q.has(k)) state[k] = q.get(k) || '';
  if (q.has('page')) state.page = Math.max(1, Number(q.get('page')) || 1);
  return q.get('open');
}
function writeUrl() {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(state)) if (v && v !== 'all' && !(k === 'page' && v === 1)) q.set(k, String(v));
  if (openId) q.set('open', String(openId));
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

// ── list ──
const platformIcon = (slug) => platforms.find((p) => p.slug === slug)?.logo_url || '';

function profitCell(o) {
  if (o.cost_paise === null || o.profit_paise === null) return html`<span class="amt-mute">—</span>`;
  return html`<span class="cell-main"><b>${signedMoney(o.profit_paise)}</b><small>${o.mode === 'AUTO' ? `cost ${inr(o.cost_paise)}${o.cost_final ? '' : ' (est.)'}` : 'no provider cost'}</small></span>`;
}

function rowHtml(o) {
  return html`<tr data-open="${o.id}" tabindex="0" role="button" aria-label="Open order ${o.public_id}">
    <td data-label="Order"><span class="cell-main"><b class="mono">${o.public_id}</b><small>${fmtDateTime(o.created_at)}</small></span></td>
    <td data-label="Service" class="span2"><span class="cell-svc">${platformIcon(o.platform) ? html`<img src="${platformIcon(o.platform)}" alt="" width="30" height="30" loading="lazy">` : ''}<span class="cell-main"><b>${o.service}</b><small title="${o.target}">${o.target}</small></span></span></td>
    <td data-label="Amount" class="num"><b>${inr(o.amount_paise)}</b>${o.refunded_paise ? html`<br><small class="amt-neg">−${inr(o.refunded_paise)} refunded</small>` : ''}</td>
    <td data-label="Payment · mode"><span class="stackcell">${payChip(o.payment_status)}${modeChip(o.mode)}</span></td>
    <td data-label="Profit" class="num">${profitCell(o)}</td>
    <td data-label="Status"><span class="stackcell">${orderChip(o.status, o.status_label)}${o.late ? html`<span class="chip chip-warn" title="Payment arrived after the 5-minute session ended">late payment</span>` : ''}</span>${o.last_error && o.status === 'FAILED' ? html`<small class="block mt-1 text-bad-700 truncate max-w-[14rem]" title="${o.last_error}">${o.last_error}</small>` : ''}</td>
    <td class="row-actions-cell"><span class="row-actions"><button type="button" class="btn btn-sm" data-open="${o.id}" aria-label="View order ${o.public_id}">${icon('eye', 'icon-sm')}<span class="md:sr-only 2xl:not-sr-only">View</span></button></span></td>
  </tr>`;
}

function renderList(d) {
  listData = d;
  const chips = $('#order-chips');
  chips.innerHTML = CHIPS.map(([k, label]) => `<button type="button" class="fchip" data-filter="${k}" aria-pressed="${state.filter === k}">${esc(label)} <b>${num(d.chips[k] ?? 0)}</b></button>`).join('');

  const ab = $('#approve-all');
  ab.disabled = d.approvable === 0;
  const badge = $('#approve-all-count');
  badge.hidden = d.approvable === 0;
  badge.textContent = String(d.approvable);
  ab.title = d.approvable === 0 ? 'No paid manual orders are waiting for approval' : `${d.approvable} paid manual order${d.approvable === 1 ? '' : 's'} waiting`;

  $('#o-reset').hidden = !hasFilters();
  if (!d.rows.length) {
    root().innerHTML = String(html`<div class="tbl-wrap">${hasFilters()
      ? emptyState({ ico: 'search', title: 'No orders match these filters', text: 'Try a different search, widen the dates, or clear the filters.', action: '<button type="button" class="btn btn-sm mt-2" data-clear>Clear filters</button>' })
      : emptyState({ ico: 'package', title: 'No orders yet', text: 'Orders appear here the moment a customer starts checkout. Share your store link to get the first one.' })}</div>`);
    return;
  }
  root().innerHTML = String(html`<div class="tbl-wrap"><div class="tbl-scroll"><table class="tbl tbl-resp">
    <thead><tr><th>Order</th><th>Service &amp; target</th><th class="num">Amount</th><th>Payment · mode</th><th class="num">Profit</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>${d.rows.map(rowHtml)}</tbody></table></div>${pager(d, 'orders')}</div>`);
}

async function load({ silent = false } = {}) {
  const my = ++seq;
  const r = root();
  if (!silent && !listData) r.innerHTML = String(skeletonTable(7));
  else if (!silent) r.classList.add('is-stale');
  r.setAttribute('aria-busy', 'true');
  try {
    const d = await get('orders', state);
    if (my !== seq) return;
    r.classList.remove('is-stale');
    renderList(d);
    writeUrl();
  } catch (e) {
    if (my !== seq) return;
    r.classList.remove('is-stale');
    if (!listData) r.innerHTML = errorState(e.message).toString();
    else fail('Could not refresh orders', e.message);
  } finally {
    if (my === seq) r.setAttribute('aria-busy', 'false');
  }
}

// ── order drawer ──
const ACTION_UI = {
  approve: { label: 'Approve', ico: 'circle-check', cls: 'btn-success' },
  complete: { label: 'Mark completed', ico: 'square-check-big', cls: 'btn-primary' },
  retry_submit: { label: 'Retry provider submission', ico: 'rotate-ccw', cls: 'btn-primary' },
  move_to_manual: { label: 'Move to manual queue', ico: 'package', cls: '' },
  sync: { label: 'Sync status now', ico: 'refresh-cw', cls: '' },
  confirm_payment: { label: 'Confirm payment manually', ico: 'badge-check', cls: '' },
  refund: { label: 'Record refund', ico: 'undo-2', cls: '' },
  cancel: { label: 'Cancel order', ico: 'ban', cls: 'btn-danger' },
};

const dt = (iso) => (iso ? fmtDateTime(iso) : '—');
const dd = (label, value, cls = '') => html`<dt>${label}</dt><dd class="${cls}">${value ?? '—'}</dd>`;

function copyBtn(text, label = 'Copy') {
  return html`<button type="button" class="btn btn-ghost btn-sm btn-icon" data-copy="${text}" aria-label="${label}" title="${label}">${icon('copy', 'icon-sm')}</button>`;
}

function drawerHtml(d) {
  const o = d.order;
  const m = d.money;
  const actions = o.actions.filter((a) => ACTION_UI[a]);
  const tl = d.history.map((h) => html`<div class="tl-item" data-tone="${h.to}">
    <b>${h.to_label}</b><small>${dt(h.at)} · ${h.actor_label || h.actor}${h.note ? ' — ' + h.note : ''}</small></div>`);
  return html`
    <div class="od-hero">
      <div>${orderChip(o.status, o.status_label)} ${modeChip(o.mode)}${o.late ? html` <span class="chip chip-warn">late payment</span>` : ''}
        <p class="mt-2 text-2xl font-extrabold tracking-tight">${inr(o.amount_paise)} <span class="text-sm font-semibold text-muted">· ${o.service}</span></p></div>
    </div>

    ${o.last_error && ['FAILED', 'PAID', 'PROCESSING'].includes(o.status) ? html`<div class="callout callout-error mt-3" role="alert">${icon('circle-alert')}<div><strong>Provider issue:</strong> ${o.last_error}${o.next_attempt_at ? html`<br><small>Next automatic retry at ${dt(o.next_attempt_at)} (attempt ${o.attempts}).</small>` : ''}</div></div>` : ''}

    ${actions.length ? html`<div class="od-section"><h3>Actions</h3><div class="action-bar">${actions.map((a) => html`<button type="button" class="btn btn-sm ${ACTION_UI[a].cls}" data-act="${a}">${icon(ACTION_UI[a].ico, 'icon-sm')}<span>${ACTION_UI[a].label}</span></button>`)}</div></div>` : ''}

    <div class="od-section"><h3>Order</h3><dl class="dl">
      ${dd('Order ID', o.public_id, 'mono')}${dd('Service', `${o.service} (${o.service_code})`)}${dd('Quantity', num(o.quantity))}
      ${dd('Target', o.target_url ? html`<a class="underline break-all" href="${o.target_url}" target="_blank" rel="noopener noreferrer nofollow">${o.target}</a>` : o.target)}
      ${dd('Created', dt(o.created_at))}${dd('Paid', dt(o.paid_at))}${dd('Submitted', dt(o.submitted_at))}${dd('Completed', dt(o.completed_at))}
      ${dd('Customer link', html`<span class="inline-flex items-center gap-1"><a class="underline break-all" href="${o.access_url}" target="_blank" rel="noopener">${o.access_url.replace(/^https?:\/\//, '')}</a>${copyBtn(o.access_url, 'Copy customer link')}</span>`)}
    </dl></div>

    ${m ? html`<div class="od-section"><h3>Money</h3><div class="money-grid">
      <div class="stat"><small>Paid</small><strong>${inr(m.amount)}</strong></div>
      <div class="stat"><small>Refunded</small><strong>${inr(m.refunded)}</strong></div>
      <div class="stat"><small>Provider cost${o.mode === 'MANUAL' ? ' (manual)' : ''}</small><strong>${inr(m.cost)}</strong><span>${o.mode === 'MANUAL' ? 'not counted for manual orders' : o.cost_final ? 'final provider charge' : 'estimate until the provider confirms'}</span></div>
      <div class="stat"><small>Profit (before ads)</small><strong class="${m.gross < 0 ? 'amt-neg' : 'amt-pos'}">${inr(m.gross)}</strong></div></div></div>` : ''}

    <div class="od-section"><h3>Payment${d.payments.length > 1 ? 's (' + d.payments.length + ')' : ''}</h3>
      ${d.payments.length ? d.payments.map((p) => html`<div class="card !p-3 mb-2"><div class="flex items-center justify-between gap-2 mb-2">${payChip(p.status)}<b>${inr(p.amount_paise)}</b></div><dl class="dl">
        ${dd('Gateway order', p.gateway_order_id, 'mono')}${dd('Bank ref (UTR)', p.utr ? html`<span class="inline-flex items-center gap-1">${p.utr}${copyBtn(p.utr, 'Copy UTR')}</span>` : '—', 'mono')}
        ${dd('Method', p.method)}${dd('Verified via', p.verified_via)}${p.paid_amount_paise !== null ? dd('Gateway reported', inr(p.paid_amount_paise)) : ''}
        ${dd('Session expiry', dt(p.expires_at))}${dd('Paid at', dt(p.paid_at))}${p.late ? dd('Late', 'Paid after the session ended') : ''}${p.failure_reason ? dd('Reason', p.failure_reason) : ''}
        ${p.refunded_paise ? dd('Refunded', `${inr(p.refunded_paise)}${p.refund_reference ? ' · ref ' + p.refund_reference : ''}`) : ''}${dd('Status checks', `${p.polls}${p.last_polled_at ? ' · last ' + timeAgo(p.last_polled_at) : ''}`)}</dl></div>`)
        : html`<p class="text-sm text-muted">No payment session.</p>`}</div>

    ${o.mode === 'AUTO' ? html`<div class="od-section"><h3>Provider (AUTO)</h3><dl class="dl">
      ${dd('Provider', o.provider)}${dd('Provider order', o.provider_order_id, 'mono')}${dd('SMM service ID', o.smm_service_id, 'mono')}${dd('Provider status', o.provider_status)}
      ${dd('Attempts', o.attempts)}${o.start_count !== null ? dd('Start count', num(o.start_count)) : ''}${o.remains !== null ? dd('Remains', num(o.remains)) : ''}</dl>
      ${d.smm.length ? html`<details class="fold mt-3"><summary>Provider calls (${d.smm.length})</summary><div class="grid gap-2">${d.smm.map((t) => html`<div><div class="flex items-center justify-between text-xs mb-1"><b>${t.action.toUpperCase()} · ${t.ok ? 'ok' : 'failed'}${t.http_status ? ' · HTTP ' + t.http_status : ''}${t.duration_ms ? ' · ' + t.duration_ms + ' ms' : ''}</b><span class="text-muted">${dt(t.at)}</span></div>
        ${t.error ? html`<p class="text-xs text-bad-700 mb-1">${t.error}</p>` : ''}<pre class="code">${(t.request || '') + '\n→ ' + (t.response || '')}</pre></div>`)}</div></details>` : ''}</div>` : ''}

    ${d.manual ? html`<div class="od-section"><h3>Manual delivery</h3><dl class="dl">${dd('State', d.manual.state)}${dd('Approved by', d.manual.approved_by)}${dd('Approved at', dt(d.manual.approved_at))}</dl></div>` : ''}

    <div class="od-section"><h3>Timeline</h3><div class="timeline">${tl}</div></div>

    ${d.webhooks.length ? html`<div class="od-section"><h3>Webhook deliveries</h3><div class="grid gap-1.5">${d.webhooks.map((w) => html`<div class="flex items-center justify-between gap-2 text-xs"><span class="min-w-0 truncate"><b>${w.type || 'event'}</b> · ${w.event_id || ''}</span><span class="shrink-0 chip ${w.signature_valid ? (w.status === 'processed' ? 'chip-ok' : 'chip-gray') : 'chip-bad'}">${w.signature_valid ? w.status : 'bad signature'}</span></div>`)}</div></div>` : ''}

    <div class="od-section"><h3>Internal note</h3>
      <label class="sr-only" for="od-note">Internal note (not shown to the customer)</label>
      <textarea class="textarea" id="od-note" rows="3" maxlength="400" placeholder="Only visible to admins">${o.admin_note || ''}</textarea>
      <div class="mt-2"><button type="button" class="btn btn-sm" data-act="note">${icon('check', 'icon-sm')}<span>Save note</span></button></div></div>`;
}

function paintDrawer(d) {
  drawerData = d;
  $('#od-title').textContent = d.order.public_id;
  $('#od-sub').textContent = `${d.order.service} · ${d.order.platform}`;
  $('#od-body').innerHTML = String(drawerHtml(d));
  $$('.tl-item', $('#od-body')).forEach((n) => {
    const t = n.dataset.tone;
    const col = { COMPLETED: '#16a34a', MANUAL_APPROVED: '#16a34a', FAILED: '#dc2626', CANCELLED: '#6b7280', EXPIRED: '#6b7280', REFUNDED: '#7c3aed', MANUAL_PENDING: '#e11d74', PENDING_PAYMENT: '#d97706' }[t] || '#2563eb';
    n.style.setProperty('--tl', col);
  });
}

async function openOrder(id, opener) {
  openId = Number(id);
  writeUrl();
  const dlg = $('#order-drawer');
  $('#od-title').textContent = 'Loading…';
  $('#od-sub').textContent = '';
  $('#od-body').innerHTML = '<div class="skeleton h-40 mb-3"></div><div class="skeleton h-24 mb-3"></div><div class="skeleton h-40"></div>';
  openDialog(dlg, opener);
  try {
    const d = await get(`orders/${id}`);
    if (openId !== Number(id)) return;
    paintDrawer(d);
  } catch (e) {
    $('#od-title').textContent = 'Order';
    $('#od-body').innerHTML = errorState(e.message).toString();
    $('#od-body').querySelector('[data-retry]')?.addEventListener('click', () => openOrder(id, opener));
  }
}

async function runAction(action, btn) {
  const o = drawerData.order;
  const id = o.id;
  const send = async (extra = {}) => {
    const res = await post(`orders/${id}/action`, { action, ...extra }, { idempotency: true });
    paintDrawer(res.detail);
    ok(res.message);
    load({ silent: true });
    reloadStatus();
    return res;
  };
  try {
    switch (action) {
      case 'approve':
        await withBusy(btn, () => send());
        break;
      case 'sync':
      case 'retry_submit':
        await withBusy(btn, () => send());
        break;
      case 'complete':
        if (await confirmDialog({ title: 'Mark this order as completed?', message: `${o.public_id} will be shown to the customer as completed.`, confirmText: 'Mark completed' })) await withBusy(btn, () => send());
        break;
      case 'move_to_manual':
        if (await confirmDialog({ title: 'Move to the manual queue?', message: 'The order will stop retrying the SMM provider and wait for you to approve and deliver it yourself.', confirmText: 'Move to manual' })) await withBusy(btn, () => send());
        break;
      case 'cancel': {
        const key = uuid();
        await formDialog({
          title: 'Cancel this order?', tone: 'danger', confirmText: 'Cancel order', cancelText: 'Keep order',
          message: o.paid_at ? 'The order will be cancelled. This does NOT refund the customer — record the refund separately once you have returned the money.' : 'The order will be cancelled and its payment session closed.',
          fields: [{ name: 'note', label: 'Reason (optional)', type: 'textarea', placeholder: 'e.g. Customer requested cancellation' }],
          submit: async (v) => { const res = await post(`orders/${id}/action`, { action, note: v.note }, { idempotency: key }); paintDrawer(res.detail); ok(res.message); load({ silent: true }); reloadStatus(); },
        });
        break;
      }
      case 'refund': {
        const remaining = Math.max(0, o.amount_paise - o.refunded_paise);
        const key = uuid();
        await formDialog({
          title: 'Record a refund', confirmText: 'Record refund',
          message: 'The payment gateway has no refund API. Send the money back from your bank or UPI app first, then record it here so your reports stay correct.',
          fields: [
            { name: 'amount', label: 'Amount refunded (₹)', value: plain(remaining), inputmode: 'decimal', required: true, hint: `At most ${inr(remaining)} can still be refunded.` },
            { name: 'reference', label: 'Bank / UPI reference of the refund', required: true, placeholder: 'e.g. UTR of the refund' },
          ],
          submit: async (v) => { const res = await post(`orders/${id}/action`, { action, amount: v.amount, reference: v.reference }, { idempotency: key }); paintDrawer(res.detail); ok(res.message); load({ silent: true }); reloadStatus(); },
        });
        break;
      }
      case 'confirm_payment': {
        const key = uuid();
        await formDialog({
          title: 'Confirm payment manually', confirmText: 'Confirm payment',
          message: 'Only use this after you have checked in your bank / UPI app that the exact amount was received for this order.',
          fields: [
            { name: 'reference', label: 'Bank / UPI reference (UTR)', required: true, placeholder: 'e.g. 412345678901' },
            { name: 'note', label: 'Note (optional)', type: 'textarea' },
          ],
          submit: async (v) => { const res = await post(`orders/${id}/action`, { action, reference: v.reference, note: v.note }, { idempotency: key }); paintDrawer(res.detail); ok(res.message); load({ silent: true }); reloadStatus(); },
        });
        break;
      }
      case 'note':
        await withBusy(btn, () => send({ note: $('#od-note').value }));
        break;
      default:
        break;
    }
  } catch (e) {
    fail('Action failed', e.message);
    // the order may have changed underneath us — refresh the drawer so the buttons match reality
    get(`orders/${id}`).then(paintDrawer).catch(() => {});
  }
}

async function approveAll(btn) {
  let count;
  try { count = (await get('orders/approvable')).count; } catch (e) { fail('Could not check pending orders', e.message); return; }
  if (count === 0) { ok('Nothing to approve', 'There are no paid manual orders waiting for approval.'); load({ silent: true }); return; }
  const yes = await confirmDialog({
    title: 'Approve all eligible manual orders?',
    message: `${count} paid manual order${count === 1 ? '' : 's'} will be approved. Unpaid, cancelled, refunded and already completed orders are never touched.`,
    confirmText: `Approve ${count} order${count === 1 ? '' : 's'}`, tone: 'success',
  });
  if (!yes) return;
  await withBusy(btn, async () => {
    try {
      const res = await post('orders/approve-all', { confirm: true }, { idempotency: true, timeout: 60000 });
      ok(res.message, res.remaining ? `${res.remaining} still waiting (they arrived while approving).` : '');
      await load({ silent: true });
      reloadStatus();
    } catch (e) {
      fail('Approve All failed', e.message);
      load({ silent: true });
    }
  });
}

export default async function init() {
  const open = readUrl();
  try { platforms = (await get('services/meta')).meta.platforms; } catch { platforms = []; }
  platforms.forEach((p) => $('#o-platform').append(new Option(p.name, p.slug)));
  ORDER_STATUSES.forEach((s) => $('#o-status').append(new Option(s.label, s.value)));
  const sync = () => {
    $('#o-search').value = state.q; $('#o-platform').value = state.platform; $('#o-mode').value = state.mode;
    $('#o-status').value = state.status; $('#o-from').value = state.from; $('#o-to').value = state.to;
  };
  sync();

  on(document, 'click', '[data-filter]', (e, b) => { state.filter = b.dataset.filter; state.page = 1; load(); });
  onSearch($('#o-search'), (v) => { state.q = v; state.page = 1; load(); });
  for (const [id, key] of [['o-platform', 'platform'], ['o-mode', 'mode'], ['o-status', 'status'], ['o-from', 'from'], ['o-to', 'to']]) {
    $('#' + id).addEventListener('change', (e) => { state[key] = e.target.value; state.page = 1; load(); });
  }
  const reset = () => { Object.assign(state, { filter: 'all', q: '', platform: '', mode: '', status: '', from: '', to: '', page: 1 }); sync(); load(); };
  $('#o-reset').addEventListener('click', reset);
  on(root(), 'click', '[data-clear]', reset);
  on(root(), 'click', '[data-retry]', () => load());
  on(root(), 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); load(); $('#page-title').scrollIntoView({ behavior: 'smooth', block: 'start' }); });
  on(root(), 'click', '[data-open]', (e, el) => { if (e.target.closest('a')) return; openOrder(el.dataset.open, el); });
  root().addEventListener('keydown', (e) => {
    const tr = e.target instanceof Element ? e.target.closest('tr[data-open]') : null;
    if (tr && e.target === tr && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openOrder(tr.dataset.open, tr); }
  });
  $('#order-drawer').addEventListener('close', () => { openId = null; drawerData = null; writeUrl(); });
  on($('#order-drawer'), 'click', '[data-act]', (e, b) => { if (drawerData && !b.disabled) runAction(b.dataset.act, b); });
  on($('#order-drawer'), 'click', '[data-copy]', (e, b) => copyText(b.dataset.copy));
  $('#approve-all').addEventListener('click', (e) => approveAll(e.currentTarget));
  onStatus(() => { /* the sidebar badge is handled by the shell */ });

  await load();
  if (open && /^\d+$/.test(open)) openOrder(open, null);
  whenVisible(() => { if (!document.querySelector('dialog[open]')) load({ silent: true }); }, 45000);
}
