import { $, $$, html, icon, on, url, uuid } from '../core/dom.js';
import { fmtDateTime, num, timeAgo } from '../core/format.js';
import { get, post, put } from './net.js';
import { reloadStatus } from './shell.js';
import { orderChip, skeletonTable, emptyState, errorState, pager, onSearch, ok, fail, warn, whenVisible, withBusy, showFieldErrors, clearFieldErrors, autoClearErrors } from './ui.js';
import { openDialog, closeDialog } from '../core/dialog.js';
import { LABEL, SHORT, HELP, ICON, CATEGORY_TONE, runRecoveryAction } from './recovery-actions.js';

const FILTERS = [['', 'All'], ['unknown', 'Unknown outcome'], ['review', 'Needs review'], ['failed', 'Failed'], ['sending', 'Stuck sending'], ['errors', 'Provider errors'], ['stale', 'Not followed'], ['waiting', 'Waiting'], ['retry', 'Retry scheduled']];
const PRIMARY = { unknown: 'link_provider_order', failed: 'retry_submit', waiting: 'retry_submit', retry: 'retry_submit', sending: 'mark_review', stale: 'sync', errors: 'sync', review: 'resolve_review' };
const JOB = { ok: ['Running', 'green'], overdue: ['Overdue', 'amber'], failing: ['Failing', 'amber'], never_run: ['Never run', 'amber'], running: ['Running now', 'blue'] };

const state = { tab: 'queue', category: '', q: '', page: 1 };
let can = { recover: false, run_job: false };
let labels = {};
let seq = 0;
let loaded = false;
const root = () => $('#rc-root');

function writeUrl() {
  const q = new URLSearchParams();
  if (state.tab !== 'queue') q.set('tab', state.tab);
  if (state.tab === 'queue') {
    if (state.category) q.set('category', state.category);
    if (state.q) q.set('q', state.q);
    if (state.page > 1) q.set('page', String(state.page));
  }
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

// ── summary ───────────────────────────────────────────────────────────────────
const kpi = (tone, ico, label, value, sub) => html`<article class="kpi" data-tone="${tone}" aria-label="${label}">
  <div class="kpi-top"><span class="kpi-label">${icon(ico)}<span>${label}</span></span></div>
  <div class="kpi-row"><div class="kpi-value">${value}</div></div><div class="kpi-sub kpi-sub-wrap" title="${sub}">${sub}</div></article>`;

function paintSummary(s) {
  const c = s.counts;
  const jobState = s.job ? (JOB[s.job.state] || [s.job.state, 'amber']) : ['Unknown', 'amber'];
  const last = s.job?.last_run;
  $('#rc-summary').innerHTML = String(html`<div class="kpi-grid">
    ${kpi(s.needs_person ? 'amber' : 'green', 'circle-alert', 'Needs a person', num(s.needs_person), `${num(c.unknown)} unknown outcome · ${num(c.review)} review · ${num(c.failed)} failed`)}
    ${kpi('blue', 'hourglass', 'Waiting or retrying', num(c.waiting + c.retry), `${num(c.waiting)} waiting to be sent · ${num(c.retry)} retry scheduled`)}
    ${kpi(c.sending + c.errors + c.stale ? 'amber' : 'green', 'activity', 'Provider follow-up', num(c.sending + c.errors + c.stale), `${num(c.sending)} stuck sending · ${num(c.errors)} repeated errors · ${num(c.stale)} not followed`)}
    ${kpi(jobState[1], 'clock', 'Recovery job', jobState[0], last ? `last run ${timeAgo(last.at)} · ${last.status}${last.dry_run ? ' (dry run)' : ''}` : 'not run yet — add the cron line (Deploy guide)')}
  </div>`);
  $('#rc-summary').setAttribute('aria-busy', 'false');
  const nav = $('#rc-tab-queue span');
  if (nav) nav.textContent = 'Queue' + (s.in_queue ? ` (${num(s.in_queue)})` : '');
}

// ── tabs and toolbar ──────────────────────────────────────────────────────────
function paintTabs() {
  $$('#rc-tabs [data-tab]').forEach((b) => {
    const on_ = b.dataset.tab === state.tab;
    b.setAttribute('aria-selected', String(on_));
    b.tabIndex = on_ ? 0 : -1;
  });
  root().setAttribute('aria-labelledby', 'rc-tab-' + state.tab);
}

let toolbarTab = '';
let counts = {};
function paintToolbar() {
  const bar = $('#rc-toolbar');
  if (state.tab !== 'queue') { bar.hidden = true; bar.innerHTML = ''; toolbarTab = ''; return; }
  bar.hidden = false;
  if (toolbarTab !== 'queue') {
    toolbarTab = 'queue';
    bar.innerHTML = String(html`<div class="chips chips-flow" role="group" aria-label="Problem">${FILTERS.map(([k, l]) => html`<button type="button" class="fchip" data-category="${k}" aria-pressed="false">${l} <b data-count="${k}"></b></button>`)}</div>
      <div class="toolbar-row"><div class="search">${icon('search', 'icon-sm')}<label class="sr-only" for="rc-search">Search orders</label>
        <input class="input input-sm" id="rc-search" type="search" placeholder="Search order ID, link or provider order id…" maxlength="100" autocomplete="off"></div></div>`);
    onSearch($('#rc-search'), (v) => { state.q = v; state.page = 1; load(); });
  }
  $$('[data-category]', bar).forEach((b) => {
    b.setAttribute('aria-pressed', String(b.dataset.category === state.category));
    const n = b.dataset.category === '' ? counts.__all : counts[b.dataset.category];
    const el = b.querySelector('[data-count]');
    if (el) el.textContent = n === undefined ? '' : num(n);
  });
  const box = $('#rc-search');
  if (document.activeElement !== box && box.value !== state.q) box.value = state.q;
}

// ── queue ─────────────────────────────────────────────────────────────────────
const isFuture = (iso) => iso && new Date(iso).getTime() > Date.now();
const providerCell = (r) => (r.provider.order_id
  ? html`<span class="cell-main"><b>${r.provider.name || 'Provider'}</b><small class="mono cell-wide" title="Provider order id">#${r.provider.order_id}</small><small class="cell-wide">${r.provider.status || 'status unknown'}</small>${r.provider.last_sync_at ? html`<small class="cell-wide">synced ${timeAgo(r.provider.last_sync_at)}</small>` : ''}</span>`
  : html`<span class="text-muted cell-none">${r.mode === 'AUTO' ? 'no provider order' : '—'}</span>`);

function primaryAction(r) {
  const want = PRIMARY[r.primary];
  return r.actions.includes(want) ? want : r.actions[0];
}

const escalation = (r) => (r.escalated_at ? `Escalated ${timeAgo(r.escalated_at)}${r.escalated_by ? ' by ' + r.escalated_by : ''}` : '');

function rowHtml(r) {
  const prim = primaryAction(r);
  const flagged = ['unknown', 'review', 'failed'].includes(r.primary);
  return html`<tr class="${flagged ? 'is-flagged' : ''}">
    <td data-label="Order" class="span2"><span class="cell-main"><span class="order-line"><b class="mono">${r.public_id}</b><span title="Standard state: ${r.state}">${orderChip(r.status, r.status_label)}</span></span><small class="cell-wide">${r.service} · ${num(r.quantity)}</small><small class="mono cell-target" title="${r.target}">${r.target}</small><small class="cell-wide">paid ${fmtDateTime(r.paid_at)}</small></span></td>
    <td data-label="Problem" class="span2 col-problem"><span class="chips-wrap">${r.categories.map((c) => html`<span class="chip ${CATEGORY_TONE[c] || 'chip-gray'}" title="${labels[c]?.what || ''}">${labels[c]?.label || c}</span>`)}</span>
      ${r.error || r.escalated_at ? html`<span class="cell-main mt-1">${r.error ? html`<small class="cell-prose text-bad-700" title="${r.error}">${r.error}</small>` : ''}${r.escalated_at ? html`<small class="cell-wide">${escalation(r)}</small>` : ''}</span>` : ''}</td>
    <td data-label="Attempts"><span class="cell-main"><b>${r.attempts} of ${r.max_attempts}</b><small class="cell-wide">last ${r.last_attempt_at ? timeAgo(r.last_attempt_at) : 'never'}</small><small class="cell-wide">${isFuture(r.next_attempt_at) ? 'next ' + fmtDateTime(r.next_attempt_at) : 'no retry scheduled'}</small></span></td>
    <td data-label="Provider">${providerCell(r)}</td>
    <td class="row-actions-cell"><span class="row-actions row-actions-wrap">
      ${can.recover && prim ? html`<button type="button" class="btn btn-sm btn-primary row-primary" data-rc-act="${prim}" data-id="${r.id}" title="${LABEL[prim](r)}">${icon(ICON[prim], 'icon-sm')}<span>${SHORT[prim](r)}</span></button>` : ''}
      ${can.recover && r.actions.length > 1 ? html`<button type="button" class="btn btn-sm btn-icon" data-rc-more="${r.id}" aria-label="More actions for ${r.public_id}" title="More actions">${icon('ellipsis', 'icon-sm')}</button>` : ''}
      <a class="btn btn-sm btn-icon" href="${url('/admin/orders')}?open=${r.id}" aria-label="Open order ${r.public_id}" title="Open the order">${icon('arrow-up-right', 'icon-sm')}</a></span></td></tr>`;
}

const legend = () => html`<details class="rc-legend"><summary>What the problems mean</summary>
  <dl class="dl">${Object.values(labels).map((c) => html`<dt>${c.label}</dt><dd>${c.what}</dd>`)}</dl></details>`;

let rows = new Map();

function renderQueue(d) {
  can = d.can || can;
  labels = d.categories || labels;
  counts = { ...d.summary.counts, __all: d.summary.in_queue };
  paintSummary(d.summary);
  paintToolbar();
  rows = new Map(d.rows.map((r) => [r.id, r]));
  const filtered = state.category || state.q;
  root().innerHTML = String(d.rows.length
    ? html`${legend()}<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Orders that need recovery"><table class="tbl tbl-resp">
      <thead><tr><th>Order</th><th>Problem</th><th>Attempts</th><th>Provider</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map(rowHtml)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: d.per }, 'orders')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'circle-check', title: filtered ? 'No orders match' : 'Nothing needs recovery', text: filtered ? 'Try another problem or search.' : 'Every paid order is where it should be: delivered, being followed at the provider, or in the manual queue.' })}</div>`);
  $('#rc-dry').hidden = !can.run_job;
  $('#rc-run').hidden = !can.run_job;
}

// ── retry policy ──────────────────────────────────────────────────────────────
const dur = (s) => (s >= 3600 ? `${Math.round(s / 3600)} h` : s >= 120 ? `${Math.round(s / 60)} min` : `${s} s`);

function renderPolicy(d) {
  can = d.can || can;
  if (d.summary) { counts = { ...d.summary.counts, __all: d.summary.in_queue }; paintSummary(d.summary); }
  $('#rc-dry').hidden = !can.run_job;
  $('#rc-run').hidden = !can.run_job;
  const p = d.policy;
  const steps = [];
  for (let i = 1; i < p.max_attempts; i++) steps.push(`attempt ${i} fails because the provider cannot be reached → wait ${dur(p.backoff_seconds[Math.min(i - 1, p.backoff_seconds.length - 1)])}`);
  root().innerHTML = String(html`<div class="stack-lg">
    <form class="card" id="rc-policy" novalidate aria-labelledby="h-pol">
      <div class="card-head"><h2 class="card-title" id="h-pol">${icon('rotate-ccw')}How often an order is sent</h2></div>
      <div class="field"><label class="label" for="rp-max">Maximum attempts per order</label>
        <input class="input" id="rp-max" name="max_attempts" type="number" inputmode="numeric" min="${p.bounds[0]}" max="${p.bounds[1]}" step="1" value="${p.max_attempts}" ${can.recover ? '' : 'disabled'}>
        <p class="field-hint">Allowed ${p.bounds[0]}–${p.bounds[1]}; default ${p.default_max_attempts}. After the last attempt a person is asked (the order is “Failed”).${can.recover ? '' : ' Only an owner or manager can change it.'}</p></div>
      ${can.recover ? html`<div class="form-actions"><button type="submit" class="btn btn-primary" id="rp-save">${icon('check', 'icon-sm')}<span>Save</span></button></div>` : ''}
    </form>
    <section class="card" aria-labelledby="h-sched"><div class="card-head"><h2 class="card-title" id="h-sched">${icon('clock')}The schedule</h2></div>
      <ol class="list-decimal pl-5 text-sm text-ink-soft grid gap-1">${steps.length ? steps.map((x) => html`<li>${x}</li>`) : html`<li>one attempt only</li>`}
        <li>attempt ${p.max_attempts} fails → a person is asked: the order appears under <b>Failed</b> here</li></ol></section>
    <section class="card" aria-labelledby="h-rules"><div class="card-head"><h2 class="card-title" id="h-rules">${icon('shield-check')}What is, and is never, retried by itself</h2></div>
      <dl class="dl"><dt>Retried automatically</dt><dd>${p.retried_automatically}</dd><dt>Never retried automatically</dt><dd>${p.never_retried_automatically}</dd><dt>Idempotency key</dt><dd>${p.idempotency_key}</dd></dl>
      <p class="field-hint mt-3">An order the provider may already have can only be reconciled by a person with the provider’s panel: link the provider order that is there, or confirm there is none and resend.</p></section>
  </div>`);
  autoClearErrors($('#rc-policy'));
}

// ── loading ───────────────────────────────────────────────────────────────────
async function load({ silent = false } = {}) {
  const my = ++seq;
  paintTabs();
  paintToolbar();
  if (!silent || !loaded) root().innerHTML = String(skeletonTable(6));
  root().setAttribute('aria-busy', 'true');
  try {
    const d = state.tab === 'queue' ? await get('recovery', { category: state.category, q: state.q, page: state.page }) : await get('recovery/settings');
    if (my !== seq) return;
    if (state.tab === 'queue') renderQueue(d); else renderPolicy(d);
    loaded = true;
    writeUrl();
  } catch (e) {
    if (my !== seq) return;
    if (!loaded) root().innerHTML = errorState(e.message).toString(); else fail('Could not refresh', e.message);
  } finally {
    if (my === seq) root().setAttribute('aria-busy', 'false');
  }
}

// ── actions ───────────────────────────────────────────────────────────────────
async function act(order, action, btn) {
  const send = async (extra, key) => {
    const res = await post(`recovery/${order.id}/action`, { action, ...extra }, { idempotency: key });
    ok(res.message);
    return res;
  };
  try {
    const plain = action === 'sync' || (action === 'retry_submit' && order.failure_kind !== 'ambiguous');
    const done = plain ? await withBusy(btn, () => runRecoveryAction(order, action, send)) : await runRecoveryAction(order, action, send);
    if (done) { await load({ silent: true }); reloadStatus(); }
  } catch (e) {
    fail('Action failed', e.message);
    load({ silent: true });
  }
}

function moreDialog(order) {
  const dlg = document.createElement('dialog');
  dlg.className = 'dlg dlg-modal';
  dlg.setAttribute('aria-labelledby', 'rc-more-title');
  dlg.innerHTML = String(html`<div class="dlg-panel">
    <div class="dlg-head"><h2 id="rc-more-title" class="text-lg font-extrabold">Actions for ${order.public_id}</h2><button type="button" class="btn btn-ghost btn-icon btn-sm" data-dialog-close aria-label="Close">${icon('x')}</button></div>
    <div class="dlg-body"><div class="grid gap-3">${order.actions.map((a) => html`<div class="flex items-start gap-3"><button type="button" class="btn btn-sm shrink-0" data-pick="${a}">${icon(ICON[a], 'icon-sm')}<span>${LABEL[a](order)}</span></button><p class="text-sm text-ink-soft">${HELP[a](order)}</p></div>`)}</div></div></div>`);
  document.body.appendChild(dlg);
  dlg.addEventListener('close', () => dlg.remove());
  dlg.addEventListener('click', (e) => {
    const b = e.target instanceof Element ? e.target.closest('[data-pick]') : null;
    if (!b) return;
    closeDialog(dlg);
    setTimeout(() => act(order, b.dataset.pick, null), 320);
  });
  openDialog(dlg);
}

async function runJob(btn, dry) {
  await withBusy(btn, async () => {
    try {
      const r = await post('jobs/recovery/run', { dry_run: dry }, { idempotency: uuid() });
      const run = r.run;
      const res = run.result || {};
      const parts = dry
        ? [`would send ${res.would_do?.to_submit ?? 0}`, `follow ${res.would_do?.to_sync ?? 0}`, `hand over ${res.would_do?.to_release ?? 0} stuck`]
        : [`sent ${res.submit?.attempted ?? 0}`, `synced ${res.sync?.synced ?? 0}`, `handed over ${res.submit?.released ?? 0} stuck`];
      (run.status === 'ok' ? ok : warn)(dry ? 'Dry run finished — nothing was changed' : 'Recovery run finished', `${run.status === 'skipped' ? 'Another run of this job is in progress.' : parts.join(' · ')}.`);
      await load({ silent: true });
      reloadStatus();
    } catch (e) {
      fail('Could not run the recovery job', e.message);
    }
  });
}

async function savePolicy(form) {
  await withBusy($('#rp-save'), async () => {
    clearFieldErrors(form);
    try {
      const d = await put('recovery/settings', { max_attempts: Number(form.elements.max_attempts.value) }, { idempotency: uuid() });
      renderPolicy({ policy: d.policy, can });
      ok('Retry policy saved');
    } catch (e) {
      if (e.fields) showFieldErrors(form, e.fields); else fail('Could not save', e.message);
    }
  });
}

// ── boot ──────────────────────────────────────────────────────────────────────
export default function init() {
  const q = new URLSearchParams(location.search);
  state.tab = q.get('tab') === 'policy' ? 'policy' : 'queue';
  state.category = FILTERS.some(([k]) => k === q.get('category')) ? q.get('category') : '';
  state.q = q.get('q') || '';
  state.page = Math.max(1, Number(q.get('page')) || 1);

  const tabs = $('#rc-tabs');
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
  on($('#rc-toolbar'), 'click', '[data-category]', (e, b) => { state.category = b.dataset.category; state.page = 1; load(); });
  const r = root();
  on(r, 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); load(); });
  on(r, 'click', '[data-retry]', () => load());
  on(r, 'click', '[data-rc-act]', (e, b) => { const o = rows.get(Number(b.dataset.id)); if (o) act(o, b.dataset.rcAct, b); });
  on(r, 'click', '[data-rc-more]', (e, b) => { const o = rows.get(Number(b.dataset.rcMore)); if (o) moreDialog(o); });
  r.addEventListener('submit', (e) => { if (e.target.id === 'rc-policy') { e.preventDefault(); savePolicy(e.target); } });
  $('#rc-run').addEventListener('click', (e) => runJob(e.currentTarget, false));
  $('#rc-dry').addEventListener('click', (e) => runJob(e.currentTarget, true));

  load();
  whenVisible(() => { if (state.tab === 'queue') load({ silent: true }); }, 45000);
}
