import { $, html, icon, on, url, esc } from '../core/dom.js';
import { inr, fmtDateTime, num } from '../core/format.js';
import { get } from './net.js';
import { payChip, skeletonTable, emptyState, errorState, pager, onSearch, fail, whenVisible } from './ui.js';

const CHIPS = [['', 'All'], ['success', 'Paid'], ['pending', 'Pending'], ['failed', 'Failed'], ['mismatch', 'Needs review'], ['expired', 'Expired'], ['refunded', 'Refunded']];
const state = { status: '', q: '', page: 1 };
let data = null;
let seq = 0;
const root = () => $('#payments-root');

function writeUrl() {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(state)) if (v && !(k === 'page' && v === 1)) q.set(k, String(v));
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

const WH_TONE = { processed: 'chip-ok', duplicate: 'chip-gray', ignored: 'chip-gray', received: 'chip-info', invalid: 'chip-bad', error: 'chip-bad', mismatch: 'chip-warn' };

function render(d) {
  data = d;
  $('#pay-chips').innerHTML = CHIPS.map(([k, l]) => `<button type="button" class="fchip" data-status="${k}" aria-pressed="${state.status === k}">${esc(l)}</button>`).join('');
  const table = d.rows.length ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Payments"><table class="tbl tbl-resp">
    <thead><tr><th>Order</th><th>Service</th><th class="num">Amount</th><th>Status</th><th>Bank ref (UTR)</th><th>Verified</th><th>Time</th><th><span class="sr-only">Open</span></th></tr></thead>
    <tbody>${d.rows.map((p) => html`<tr>
      <td data-label="Order"><span class="cell-main"><b class="mono">${p.order}</b><small class="mono" title="Gateway order id">${p.gateway_order_id}</small></span></td>
      <td data-label="Service">${p.service}</td>
      <td data-label="Amount" class="num"><span class="cell-main"><b>${inr(p.amount_paise)}</b>${p.refunded_paise ? html`<small class="amt-neg">−${inr(p.refunded_paise)}</small>` : ''}</span></td>
      <td data-label="Status"><span class="stackcell">${payChip(p.status)}${p.late ? html`<span class="chip chip-warn" title="Confirmed after the 5-minute session ended">late</span>` : ''}</span>${p.reason ? html`<small class="block mt-1 text-bad-700 truncate max-w-[14rem]" title="${p.reason}">${p.reason}</small>` : ''}</td>
      <td data-label="UTR" class="mono">${p.utr || '—'}</td>
      <td data-label="Verified via">${p.via || '—'}</td>
      <td data-label="Time"><span class="cell-main"><b>${fmtDateTime(p.paid_at || p.created_at)}</b><small>${p.paid_at ? 'paid' : 'created'}</small></span></td>
      <td class="row-actions-cell"><span class="row-actions"><a class="btn btn-sm" href="${url('/admin/orders')}?open=${p.order_id}" aria-label="Open order ${p.order}">${icon('arrow-up-right', 'icon-sm')}<span>Order</span></a></span></td></tr>`)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: 25 }, 'payments')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'credit-card', title: state.q || state.status ? 'No payments match' : 'No payments yet', text: state.q || state.status ? 'Try another status or search.' : 'Payments appear here once customers reach the payment page.' })}</div>`;
  const wh = html`<section class="card mt-6" aria-labelledby="h-wh"><div class="card-head"><h2 class="card-title" id="h-wh">${icon('webhook')}Latest webhook deliveries</h2><span class="card-sub">Signed notifications from the payment gateway</span></div>
    ${d.webhooks.length ? html`<div class="tbl-scroll" tabindex="0" role="region" aria-label="Webhook events"><table class="tbl tbl-resp"><thead><tr><th>Received</th><th>Event</th><th>Signature</th><th>Result</th><th class="num">Attempts</th></tr></thead>
      <tbody>${d.webhooks.map((w) => html`<tr><td data-label="Received">${fmtDateTime(w.at)}</td><td data-label="Event"><span class="cell-main"><b>${w.type || 'event'}</b><small class="mono">${w.event_id || ''}</small></span></td>
        <td data-label="Signature">${w.signature_valid ? html`<span class="chip chip-ok">valid</span>` : html`<span class="chip chip-bad">invalid</span>`}</td>
        <td data-label="Result"><span class="chip ${WH_TONE[w.status] || 'chip-gray'}">${w.status}</span>${w.error ? html`<br><small class="text-muted">${w.error}</small>` : ''}</td><td data-label="Attempts" class="num">${w.attempts}</td></tr>`)}</tbody></table></div>`
      : emptyState({ ico: 'webhook', title: 'No webhooks received yet', text: 'Configure the webhook URL from Payment gateway in your Alpha UPI dashboard.' })}</section>`;
  root().innerHTML = String(html`${table}${wh}`);
}

async function load({ silent = false } = {}) {
  const my = ++seq;
  if (!data && !silent) root().innerHTML = String(skeletonTable(6));
  root().setAttribute('aria-busy', 'true');
  try {
    const d = await get('payments', state);
    if (my !== seq) return;
    render(d);
    writeUrl();
  } catch (e) {
    if (my !== seq) return;
    if (!data) root().innerHTML = errorState(e.message).toString(); else fail('Could not refresh payments', e.message);
  } finally { if (my === seq) root().setAttribute('aria-busy', 'false'); }
}

export default function init() {
  const q = new URLSearchParams(location.search);
  state.status = q.get('status') || ''; state.q = q.get('q') || ''; state.page = Math.max(1, Number(q.get('page')) || 1);
  $('#p-search').value = state.q;
  on(document, 'click', '[data-status]', (e, b) => { state.status = b.dataset.status; state.page = 1; load(); });
  onSearch($('#p-search'), (v) => { state.q = v; state.page = 1; load(); });
  on(root(), 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); load(); });
  on(root(), 'click', '[data-retry]', () => load());
  load();
  whenVisible(() => load({ silent: true }), 45000);
}
