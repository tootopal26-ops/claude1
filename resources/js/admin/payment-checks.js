import { $, $$, html, icon, on, url, esc } from '../core/dom.js';
import { inr, fmtDateTime, num, timeAgo } from '../core/format.js';
import { info } from '../core/toast.js';
import { get, post, put } from './net.js';
import { reloadStatus } from './shell.js';
import { payChip, skeletonTable, emptyState, errorState, pager, onSearch, ok, fail, warn, whenVisible, withBusy, formDialog, showFieldErrors, clearFieldErrors, autoClearErrors } from './ui.js';

const STATES = [['', 'All'], ['FLAGGED', 'Flagged'], ['REVIEW_REQUIRED', 'Needs review'], ['PENDING', 'Pending'], ['PAID', 'Paid'], ['FAILED', 'Failed'], ['EXPIRED', 'Expired'], ['REFUNDED', 'Refunded']];
const SEV = { high: ['High', 'chip-bad'], medium: ['Medium', 'chip-warn'], low: ['Low', 'chip-gray'] };
const WH = { processed: 'chip-ok', duplicate: 'chip-gray', ignored: 'chip-gray', received: 'chip-info', invalid: 'chip-bad', error: 'chip-bad', mismatch: 'chip-warn' };
const SOURCE = { webhook: 'Signed webhook', poll: 'Gateway lookup', admin: 'Confirmed by hand' };
const MODE = { off: ['Off', 'the signed webhook is trusted on its own'], flag: ['Flag', 'asks the gateway after each webhook and flags a disagreement'], require: ['Require', 'credits only after our own lookup confirms'] };
const DETAIL = {
  expected_paise: 'Expected', reported_paise: 'Gateway reported', gateway_amount_paise: 'Gateway amount', recorded_amount_paise: 'Recorded amount', gateway_currency: 'Gateway currency',
  reference: 'Bank ref', other_payment_id: 'Other payment #', new_reference: 'New bank ref', first_reference: 'First bank ref', gateway_reference: 'Gateway ref', recorded_reference: 'Recorded ref',
  seconds_after_expiry: 'Seconds after expiry', rejected_last_hour: 'Rejected in the last hour', threshold: 'Threshold', unfinished_payments: 'Unfinished payments', window_hours: 'Window (hours)',
  gateway_order_id: 'Gateway order id', event_status: 'Event status', gateway_status: 'Gateway status', recorded_status: 'Recorded status', recorded_via: 'Recorded via', kind: 'Kind',
  paid_via: 'Paid via', mode: 'Mode', order_status: 'Order status', confirmed_via: 'Confirmed via', order_status_before: 'Order was',
};

const state = { tab: 'payments', state: '', q: '', page: 1, fstatus: 'open', fsev: '', ftype: '', fpage: 1 };
let can = { review: false, override: false, configure: false };
let types = {};
let seq = 0;
let loaded = false;
const root = () => $('#pc-root');

// ── address bar ───────────────────────────────────────────────────────────────
function writeUrl() {
  const q = new URLSearchParams();
  if (state.tab !== 'payments') q.set('tab', state.tab);
  if (state.tab === 'payments') {
    if (state.state) q.set('state', state.state);
    if (state.q) q.set('q', state.q);
    if (state.page > 1) q.set('page', String(state.page));
  } else if (state.tab === 'flags') {
    if (state.fstatus !== 'open') q.set('status', state.fstatus);
    if (state.fsev) q.set('severity', state.fsev);
    if (state.ftype) q.set('type', state.ftype);
    if (state.fpage > 1) q.set('page', String(state.fpage));
  }
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

// ── summary ───────────────────────────────────────────────────────────────────
const kpi = (tone, ico, label, value, sub) => html`<article class="kpi" data-tone="${tone}" aria-label="${label}">
  <div class="kpi-top"><span class="kpi-label">${icon(ico)}<span>${label}</span></span></div>
  <div class="kpi-row"><div class="kpi-value">${value}</div></div><div class="kpi-sub kpi-sub-wrap" title="${sub}">${sub}</div></article>`;

function paintSummary(s) {
  const f = s.open_flags;
  const w = s.webhooks_24h;
  const st = s.by_state || {};
  $('#pc-summary').innerHTML = String(html`<div class="kpi-grid">
    ${kpi('green', 'circle-check', 'Paid · 30 days', num(st.PAID || 0), `${num(st.PENDING || 0)} pending · ${num(st.FAILED || 0)} failed · ${num(st.EXPIRED || 0)} expired`)}
    ${kpi(s.needs_review ? 'amber' : 'green', 'hourglass', 'Held for review', num(s.needs_review), s.needs_review ? 'paid but not matching the order' : 'no payment is held')}
    ${kpi(f.attention ? 'amber' : 'green', 'shield-alert', 'Open flags', num(f.attention), `${num(f.high)} high · ${num(f.medium)} medium · ${num(f.low)} low`)}
    ${kpi('blue', 'webhook', 'Webhooks · 24 h', num(w.received), `${num(w.rejected)} rejected · ${num(w.repeats)} repeats ignored`)}
  </div>`);
  $('#pc-summary').setAttribute('aria-busy', 'false');
  const total = $('#pc-tab-flags');
  if (total) total.querySelector('span').innerHTML = esc('Flags') + (f.attention ? ` <b class="tab-count">${f.attention > 99 ? '99+' : f.attention}</b>` : '');
}

async function loadSummary() {
  try {
    const d = await get('payment-checks/summary');
    can = d.can;
    paintSummary(d.summary);
  } catch (e) {
    $('#pc-summary').innerHTML = '';
    $('#pc-summary').setAttribute('aria-busy', 'false');
  }
}

// ── tabs and toolbar ──────────────────────────────────────────────────────────
function paintTabs() {
  $$('#pc-tabs [data-tab]').forEach((b) => {
    const on_ = b.dataset.tab === state.tab;
    b.setAttribute('aria-selected', String(on_));
    b.tabIndex = on_ ? 0 : -1;
  });
  root().setAttribute('aria-labelledby', 'pc-tab-' + state.tab);
}

let toolbarKey = '';

/** Build the filter bar once per tab (and again when the list of flag kinds arrives); afterwards only update it in place, so a search box never loses focus while someone types. */
function paintToolbar() {
  const bar = $('#pc-toolbar');
  if (state.tab === 'settings') { bar.hidden = true; bar.innerHTML = ''; toolbarKey = ''; return; }
  bar.hidden = false;
  const key = state.tab + ':' + Object.keys(types).length;
  if (key !== toolbarKey) {
    toolbarKey = key;
    if (state.tab === 'payments') {
      bar.innerHTML = String(html`<div class="chips" role="group" aria-label="Payment state">${STATES.map(([k, l]) => html`<button type="button" class="fchip" data-state="${k}" aria-pressed="false">${l}</button>`)}</div>
        <div class="toolbar-row"><div class="search">${icon('search', 'icon-sm')}<label class="sr-only" for="pc-search">Search payments</label>
          <input class="input input-sm" id="pc-search" type="search" placeholder="Search order ID, gateway order ID or bank reference…" maxlength="60" autocomplete="off"></div></div>`);
      onSearch($('#pc-search'), (v) => { state.q = v; state.page = 1; load(); });
    } else {
      const opt = (v, l) => html`<option value="${v}">${l}</option>`;
      bar.innerHTML = String(html`<div class="chips" role="group" aria-label="Flag status">${[['open', 'Open'], ['closed', 'Closed'], ['all', 'All']].map(([k, l]) => html`<button type="button" class="fchip" data-fstatus="${k}" aria-pressed="false">${l}</button>`)}</div>
        <div class="toolbar-row">
          <label class="sr-only" for="pc-sev">Severity</label><select class="select select-sm" id="pc-sev">${opt('', 'Any severity')}${opt('high', 'High')}${opt('medium', 'Medium')}${opt('low', 'Low')}</select>
          <label class="sr-only" for="pc-type">Kind of flag</label><select class="select select-sm" id="pc-type">${opt('', 'Any kind')}${Object.entries(types).map(([k, t]) => opt(k, t.label))}</select>
        </div>`);
    }
  }
  if (state.tab === 'payments') {
    $$('[data-state]', bar).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.state === state.state)));
    const box = $('#pc-search');
    if (document.activeElement !== box && box.value !== state.q) box.value = state.q;
  } else {
    $$('[data-fstatus]', bar).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.fstatus === state.fstatus)));
    $('#pc-sev').value = state.fsev;
    $('#pc-type').value = state.ftype;
  }
}

// ── payments tab ──────────────────────────────────────────────────────────────
const flagChips = (fl) => fl.map((f) => html`<span class="chip ${SEV[f.severity]?.[1] || 'chip-gray'}" title="${SEV[f.severity]?.[0] || ''} risk — ${f.label}">${f.label}${f.occurrences > 1 ? ' ×' + f.occurrences : ''}</span>`);

function checksCell(p) {
  const w = p.webhook;
  if (!p.flags.length && !w) return html`<span class="text-muted">—</span>`;
  return html`<span class="stackcell">${flagChips(p.flags)}
    ${w ? html`<span class="cell-main"><span class="chips-wrap"><span class="chip ${WH[w.status] || 'chip-gray'}" title="Latest webhook delivery">webhook ${w.status}</span>${w.signature_valid ? '' : html`<span class="chip chip-bad">bad signature</span>`}</span>
      <small class="cell-wide">${w.deliveries} deliver${w.deliveries === 1 ? 'y' : 'ies'}${w.repeats_ignored ? html` · ${w.repeats_ignored} repeat${w.repeats_ignored === 1 ? '' : 's'} ignored${w.last_repeat_at ? ' (last ' + timeAgo(w.last_repeat_at) + ')' : ''}` : ''}</small></span>` : ''}</span>`;
}

function paymentRow(p) {
  const bad = p.verified_paise !== null && p.verified_paise !== p.expected_paise;
  const canRe = can.review && p.can_recheck;
  const held = p.flags.length > 0 || p.state === 'REVIEW_REQUIRED';
  return html`<tr class="${held ? 'is-flagged' : ''}">
    <td data-label="Order" class="span2"><span class="cell-main"><b class="mono">${p.order}</b><small class="mono cell-wide" title="Gateway reference">${p.gateway_reference}</small>${p.bank_reference ? html`<small class="mono cell-wide" title="Bank reference (UTR)">UTR ${p.bank_reference}</small>` : ''}</span></td>
    <td data-label="Amount" class="num"><span class="cell-main"><b>${inr(p.expected_paise, true)}</b><small class="cell-wide ${bad ? 'amt-neg' : ''}">${p.verified_paise !== null ? 'verified ' + inr(p.verified_paise, true) : 'not verified'} · ${p.currency}</small></span></td>
    <td data-label="State"><span class="stackcell">${payChip(p.status)}<small class="mono text-muted">${p.state}</small>${p.late ? html`<span class="chip chip-warn" title="Confirmed after the 5-minute session ended">late</span>` : ''}</span>${p.reason ? html`<small class="block mt-1 text-xs text-bad-700 md:truncate md:max-w-[15rem]" title="${p.reason}">${p.reason}</small>` : ''}</td>
    <td data-label="Verification"><span class="cell-main"><b>${p.verification_source ? (SOURCE[p.verification_source] || p.verification_source) : 'Not verified'}</b>
      ${p.verified_at ? html`<small class="cell-wide">verified ${fmtDateTime(p.verified_at)}</small>` : ''}<small class="cell-wide">created ${fmtDateTime(p.created_at)}</small><small class="cell-wide">expires ${fmtDateTime(p.expires_at)}</small>
      ${p.last_checked_at ? html`<small class="cell-wide">gateway asked ${timeAgo(p.last_checked_at)}</small>` : ''}</span></td>
    <td data-label="Flags and webhook">${checksCell(p)}</td>
    <td class="row-actions-cell"><span class="row-actions">
      ${canRe ? html`<button type="button" class="btn btn-sm" data-recheck="${p.id}" aria-label="Re-check ${p.order} with the gateway" title="Ask the gateway about this payment now">${icon('refresh-cw', 'icon-sm')}<span class="md:sr-only 2xl:not-sr-only">Re-check</span></button>` : ''}
      <a class="btn btn-sm" href="${url('/admin/orders')}?open=${p.order_id}" aria-label="Open order ${p.order}" title="Open the order">${icon('arrow-up-right', 'icon-sm')}<span class="md:sr-only 2xl:not-sr-only">Order</span></a></span></td></tr>`;
}

function renderPayments(d) {
  can = d.can || can;
  const none = state.q || state.state;
  root().innerHTML = String(d.rows.length
    ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Payments with their checks"><table class="tbl tbl-resp">
      <thead><tr><th>Order</th><th class="num">Amount</th><th>State</th><th>Verification</th><th>Flags and webhook</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map(paymentRow)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: d.per }, 'payments')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'shield-check', title: none ? 'No payments match' : 'No payments yet', text: none ? 'Try another state or search.' : 'Payments appear here, with their checks, once customers reach the payment page.' })}</div>`);
}

// ── flags tab ─────────────────────────────────────────────────────────────────
function detailChips(details) {
  const items = Object.entries(details || {});
  if (!items.length) return '';
  return html`<span class="chips-wrap">${items.map(([k, v]) => {
    const label = DETAIL[k] || k;
    const val = String(k).endsWith('_paise') ? inr(Number(v), true) : String(v);
    return html`<span class="chip chip-gray" title="${label}: ${val}"><b>${label}:</b>&nbsp;${val}</span>`;
  })}</span>`;
}

function flagRow(f) {
  const [sevLabel, sevCls] = SEV[f.severity] || [f.severity, 'chip-gray'];
  return html`<tr class="${f.open ? 'is-flagged' : ''}">
    <td data-label="Risk"><span class="stackcell"><span class="chip ${sevCls}">${sevLabel}</span>${f.open ? '' : html`<span class="chip chip-gray">${f.status}</span>`}</span></td>
    <td data-label="Problem" class="span2 col-problem"><span class="cell-main"><b>${f.label}</b><small class="cell-prose">${f.advice}</small>
      ${f.order ? html`<small class="mono cell-wide">${f.order}${f.gateway_reference ? ' · ' + f.gateway_reference : ''}</small>` : ''}</span>${detailChips(f.details)}</td>
    <td data-label="Seen"><span class="cell-main"><b>${f.occurrences}×</b><small class="cell-wide">first ${fmtDateTime(f.first_seen_at)}</small><small class="cell-wide">last ${fmtDateTime(f.last_seen_at)}</small></span></td>
    <td data-label="Review">${f.open ? html`<span class="text-muted">Waiting for a person</span>` : html`<span class="cell-main"><b>${f.status === 'dismissed' ? 'Dismissed' : f.status === 'reviewed' ? 'Reviewed' : 'Cleared'}</b><small class="cell-wide">${f.reviewed_by ? 'by ' + f.reviewed_by : 'automatically'}${f.reviewed_at ? ' · ' + fmtDateTime(f.reviewed_at) : ''}</small>${f.note ? html`<small class="cell-prose" title="${f.note}">${f.note}</small>` : ''}</span>`}</td>
    <td class="row-actions-cell"><span class="row-actions row-actions-wrap">
      ${f.open && can.review ? html`<button type="button" class="btn btn-sm" data-review="${f.id}" data-status="reviewed" aria-label="Mark ${f.label} as reviewed">${icon('circle-check', 'icon-sm')}<span>Reviewed</span></button>
        <button type="button" class="btn btn-sm" data-review="${f.id}" data-status="dismissed" aria-label="Dismiss ${f.label} as a false alarm">${icon('x', 'icon-sm')}<span>False alarm</span></button>` : ''}
      ${f.payment_id && can.review ? html`<button type="button" class="btn btn-sm" data-recheck="${f.payment_id}" aria-label="Re-check this payment with the gateway" title="Ask the gateway about this payment now">${icon('refresh-cw', 'icon-sm')}<span>Re-check</span></button>` : ''}
      ${f.order_id ? html`<a class="btn btn-sm" href="${url('/admin/orders')}?open=${f.order_id}" aria-label="Open order ${f.order}" title="Open the order">${icon('arrow-up-right', 'icon-sm')}<span>Order</span></a>` : ''}</span></td></tr>`;
}

function renderFlags(d) {
  can = d.can || can;
  types = d.types || types;
  paintToolbar();
  root().innerHTML = String(d.rows.length
    ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Payment flags"><table class="tbl tbl-resp">
      <thead><tr><th>Risk</th><th>Problem</th><th>Seen</th><th>Review</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map(flagRow)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: d.per }, 'flags')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'shield-check', title: state.fstatus === 'open' ? 'No open flags' : 'No flags', text: state.fstatus === 'open' ? 'Nothing needs a person right now. A flag appears when a payment does not match its order, a reference repeats, signatures fail or the gateway disagrees with a webhook.' : 'No flags match these filters.' })}</div>`);
}

// ── thresholds tab ────────────────────────────────────────────────────────────
function renderSettings(d) {
  can = d.can || can;
  const v = d.values;
  const b = d.bounds;
  const dis = can.configure ? '' : 'disabled';
  const field = (name, label, hint) => html`<div class="field"><label class="label" for="rs-${name}">${label}</label>
    <input class="input" id="rs-${name}" name="${name}" type="number" inputmode="numeric" min="${b[name][0]}" max="${b[name][1]}" step="1" value="${v[name]}" ${dis}>
    <p class="field-hint">${hint} Allowed ${b[name][0]}–${b[name][1]}; default ${d.defaults[name]}.</p></div>`;
  root().innerHTML = String(html`<form class="card stack-lg" id="pc-settings" novalidate aria-labelledby="h-thr">
    <div class="card-head"><h2 class="card-title" id="h-thr">${icon('sliders-horizontal')}When something is flagged</h2></div>
    <p class="text-sm text-ink-soft">These only decide when a flag is raised. A flag never blocks a customer, bans a visitor or moves money, and every change here is written to the audit log.${can.configure ? '' : ' Only an owner or manager can change them.'}</p>
    <div class="field-row">
      ${field('invalid_signatures_per_hour', 'Rejected webhook signatures per hour', 'A gateway with at least this many rejected notifications in an hour is flagged.')}
      ${field('confirm_grace_seconds', 'Grace before a missing confirmation is flagged (seconds)', 'After a webhook, the gateway may need a moment before it shows the payment as paid.')}
    </div>
    <div class="field-row">
      ${field('failed_attempts', 'Unfinished payments per visitor', 'A visitor with this many failed or expired payments (and none paid) inside the window is flagged.')}
      ${field('failed_window_hours', 'Window for unfinished payments (hours)', 'How far back unfinished payments are counted.')}
    </div>
    <div class="field"><label class="label" for="rs-webhook_double_check">Cross-check every accepted webhook with the gateway</label>
      <select class="select" id="rs-webhook_double_check" name="webhook_double_check" ${dis}>${d.modes.map((m) => html`<option value="${m}" ${v.webhook_double_check === m ? 'selected' : ''}>${MODE[m][0]} — ${MODE[m][1]}</option>`)}</select>
      <p class="field-hint"><b>Flag</b> credits on the signed webhook, then asks the gateway and flags any disagreement (recommended). <b>Require</b> credits only from our own lookup; if the gateway cannot be asked, the webhook is retried. <b>Off</b> trusts the signed webhook alone.</p></div>
    <label class="check"><input type="checkbox" name="flag_late_payments" ${v.flag_late_payments ? 'checked' : ''} ${dis}><span>Add an informational flag when a payment is confirmed after its 5-minute session ended</span></label>
    ${can.configure ? html`<div class="form-actions"><button type="submit" class="btn btn-primary" id="pc-save">${icon('check', 'icon-sm')}<span>Save thresholds</span></button></div>` : ''}
  </form>`);
  autoClearErrors($('#pc-settings'));
}

// ── loading ───────────────────────────────────────────────────────────────────
async function load({ silent = false } = {}) {
  const my = ++seq;
  paintTabs();
  paintToolbar();
  if (!silent || !loaded) root().innerHTML = String(skeletonTable(6));
  root().setAttribute('aria-busy', 'true');
  try {
    let d;
    if (state.tab === 'payments') d = await get('payment-checks', { state: state.state, q: state.q, page: state.page });
    else if (state.tab === 'flags') d = await get('payment-flags', { status: state.fstatus, severity: state.fsev, type: state.ftype, page: state.fpage });
    else d = await get('payment-checks/settings');
    if (my !== seq) return;
    if (state.tab === 'payments') renderPayments(d); else if (state.tab === 'flags') renderFlags(d); else renderSettings(d);
    loaded = true;
    writeUrl();
  } catch (e) {
    if (my !== seq) return;
    if (!loaded) root().innerHTML = errorState(e.message).toString(); else fail('Could not refresh', e.message);
  } finally {
    if (my === seq) root().setAttribute('aria-busy', 'false');
  }
}

const refresh = (opts) => Promise.all([load(opts), loadSummary()]);

// ── actions ───────────────────────────────────────────────────────────────────
async function recheck(btn) {
  await withBusy(btn, async () => {
    try {
      const r = await post(`payments/${btn.dataset.recheck}/recheck`);
      const g = r.gateway;
      const facts = `Gateway says ${String(g.status).toLowerCase()}${g.amount_paise != null ? ' · ' + inr(g.amount_paise, true) : ''}${g.reference ? ' · ref ' + g.reference : ''}.`;
      (r.action === 'flagged' ? warn : r.action === 'none' ? info : ok)(r.action === 'settled' ? 'Payment confirmed' : r.action === 'flagged' ? 'Needs a person' : 'Checked with the gateway', `${r.message} ${facts}`);
      await refresh({ silent: true });
      reloadStatus();
    } catch (e) {
      fail('Could not re-check', e.message);
    }
  });
}

async function review(btn) {
  const id = btn.dataset.review;
  const status = btn.dataset.status;
  const dismiss = status === 'dismissed';
  const done = await formDialog({
    title: dismiss ? 'Dismiss as a false alarm' : 'Mark as reviewed',
    message: dismiss ? 'The flag is closed and the reason is kept with it. If the same problem happens again a new flag is raised.' : 'Confirm that you have looked at this. The flag is closed; if the same problem happens again a new flag is raised.',
    fields: [{ name: 'note', label: dismiss ? 'Why is this a false alarm?' : 'Note (optional)', type: 'textarea', rows: 3, max: 255, required: dismiss, placeholder: dismiss ? 'e.g. the customer paid twice and we already refunded the extra payment' : '' }],
    confirmText: dismiss ? 'Dismiss flag' : 'Mark reviewed',
    submit: async (v) => { await post(`payment-flags/${id}/review`, { status, note: v.note }, { idempotency: true }); },
  });
  if (done) { ok(dismiss ? 'Flag dismissed' : 'Flag reviewed'); await refresh({ silent: true }); reloadStatus(); }
}

async function saveSettings(form) {
  const btn = $('#pc-save');
  await withBusy(btn, async () => {
    clearFieldErrors(form);
    const f = form.elements;
    const body = {
      invalid_signatures_per_hour: Number(f.invalid_signatures_per_hour.value), failed_attempts: Number(f.failed_attempts.value), failed_window_hours: Number(f.failed_window_hours.value),
      confirm_grace_seconds: Number(f.confirm_grace_seconds.value), webhook_double_check: f.webhook_double_check.value, flag_late_payments: f.flag_late_payments.checked,
    };
    try {
      const d = await put('payment-checks/settings', body, { idempotency: true });
      ok('Thresholds saved');
      renderSettings({ ...d, can });
      loadSummary();
    } catch (e) {
      if (e.fields) showFieldErrors(form, e.fields); else fail('Could not save', e.message);
    }
  });
}

// ── boot ──────────────────────────────────────────────────────────────────────
export default function init() {
  const q = new URLSearchParams(location.search);
  state.tab = ['payments', 'flags', 'settings'].includes(q.get('tab')) ? q.get('tab') : 'payments';
  state.state = STATES.some(([k]) => k === q.get('state')) ? q.get('state') : '';
  state.q = q.get('q') || '';
  if (state.tab === 'payments') state.page = Math.max(1, Number(q.get('page')) || 1);
  if (state.tab === 'flags') {
    state.fstatus = ['open', 'closed', 'all'].includes(q.get('status')) ? q.get('status') : 'open';
    state.fsev = ['high', 'medium', 'low'].includes(q.get('severity')) ? q.get('severity') : '';
    state.ftype = /^[a-z_]{3,32}$/.test(q.get('type') || '') ? q.get('type') : '';
    state.fpage = Math.max(1, Number(q.get('page')) || 1);
  }

  const tabs = $('#pc-tabs');
  on(tabs, 'click', '[data-tab]', (e, b) => { if (b.dataset.tab !== state.tab) { state.tab = b.dataset.tab; load(); } });
  tabs.addEventListener('keydown', (e) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    const list = $$('[data-tab]', tabs);
    const i = list.findIndex((b) => b.dataset.tab === state.tab);
    const next = e.key === 'Home' ? 0 : e.key === 'End' ? list.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + list.length) % list.length;
    e.preventDefault();
    state.tab = list[next].dataset.tab;
    load().then(() => list[next].focus());
  });
  const bar = $('#pc-toolbar');
  on(bar, 'click', '[data-state]', (e, b) => { state.state = b.dataset.state; state.page = 1; load(); });
  on(bar, 'click', '[data-fstatus]', (e, b) => { state.fstatus = b.dataset.fstatus; state.fpage = 1; load(); });
  bar.addEventListener('change', (e) => {
    if (e.target.id === 'pc-sev') { state.fsev = e.target.value; state.fpage = 1; load(); }
    if (e.target.id === 'pc-type') { state.ftype = e.target.value; state.fpage = 1; load(); }
  });
  const r = root();
  on(r, 'click', '[data-page]', (e, b) => { if (b.disabled) return; if (state.tab === 'flags') state.fpage = Number(b.dataset.page); else state.page = Number(b.dataset.page); load(); });
  on(r, 'click', '[data-retry]', () => refresh());
  on(r, 'click', '[data-recheck]', (e, b) => recheck(b));
  on(r, 'click', '[data-review]', (e, b) => review(b));
  r.addEventListener('submit', (e) => { if (e.target.id === 'pc-settings') { e.preventDefault(); saveSettings(e.target); } });

  refresh();
  whenVisible(() => { if (state.tab !== 'settings') refresh({ silent: true }); else loadSummary(); }, 45000);
}
