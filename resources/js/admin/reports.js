// Admin → Daily reports: the history of finished business days, and one report in full. Every figure comes from the server as integer paise or a
// count with a BASIS (measured / estimated / incomplete / unavailable); the page never shows a number without saying how far it can be trusted.
import { $, $$, html, icon, on, url } from '../core/dom.js';
import { inr, num, tenths, fmtDateTime, timeAgo } from '../core/format.js';
import { get, post } from './net.js';
import { skeletonTable, emptyState, errorState, pager, ok, fail, warn, withBusy } from './ui.js';
import { confirmDialog } from '../core/dialog.js';

const BASIS = {
  measured: ['Measured', 'chip-ok', 'Counted from verified records'],
  estimated: ['Estimated', 'chip-warn', 'At least one input is an estimate'],
  incomplete: ['Incomplete', 'chip-bad', 'An input is unknown, so the figure is a floor, not the truth'],
  unavailable: ['Unavailable', 'chip-gray', 'Cannot be calculated from the data there is'],
};
const SEV = { critical: ['Critical', 'chip-bad'], warning: ['Warning', 'chip-warn'], info: ['Info', 'chip-info'] };
const TONE_ICON = { good: 'trending-up', bad: 'circle-alert', warn: 'triangle-alert', info: 'info' };
const GOOD_UP = new Set(['collected', 'net_revenue', 'orders_paid', 'aov', 'net_profit']);
const AI = { none: 'no AI commentary', ok: 'AI commentary', unavailable: 'AI unavailable', invalid: 'AI output rejected' };

const state = { from: '', to: '', page: 1, date: '' };
let can = { generate: false };
let latestDay = '';
let seq = 0;
let loaded = false;
const root = () => $('#rp-root');

// ── dates ─────────────────────────────────────────────────────────────────────
const dayFmt = (d, o) => new Intl.DateTimeFormat('en-IN', { timeZone: 'UTC', ...o }).format(new Date(d + 'T00:00:00Z'));
const longDay = (d) => dayFmt(d, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
const fullDay = (d) => dayFmt(d, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const isDay = (s) => /^\d{4}-\d{2}-\d{2}$/.test(s || '');

function writeUrl() {
  const q = new URLSearchParams();
  if (state.date) q.set('date', state.date);
  else {
    if (state.from) q.set('from', state.from);
    if (state.to) q.set('to', state.to);
    if (state.page > 1) q.set('page', String(state.page));
  }
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

// ── small renderers ───────────────────────────────────────────────────────────
const basisChip = (b) => { const [l, c, t] = BASIS[b] || BASIS.unavailable; return html`<span class="chip ${c}" title="${t}">${l}</span>`; };
const money = (p) => (p === null || p === undefined ? 'Unavailable' : inr(p, true));
const signedMoney = (p) => html`<span class="${p < 0 ? 'amt-neg' : ''}">${inr(p, true)}</span>`;

function value(m) {
  if (m.value === null || m.value === undefined) return 'Unavailable';
  if (m.unit === 'paise') return inr(m.value, true);
  if (m.unit === 'tenths_pct') return tenths(m.value) + '%';
  return num(m.value);
}

// ── list ──────────────────────────────────────────────────────────────────────
function statusHtml(d) {
  const missing = d.missing_days || [];
  if (!missing.length) return '';
  const shown = missing.slice(-5);
  return html`<div class="callout callout-info" role="status" data-missing>${icon('calendar-days')}<div class="min-w-0 flex-1"><strong>${missing.length} finished day${missing.length === 1 ? ' has' : 's have'} no report yet.</strong>
    <p class="mt-1">The daily job creates them by itself shortly after midnight${can.generate ? '; you can also create them now' : ''}. ${shown.map((x) => longDay(x)).join(' · ')}${missing.length > shown.length ? ' …' : ''}</p></div>
    ${can.generate ? html`<span class="callout-actions">${shown.map((x) => html`<button type="button" class="btn btn-sm" data-gen="${x}" aria-label="Create the report for ${longDay(x)}">${icon('play', 'icon-sm')}<span>${dayFmt(x, { day: '2-digit', month: 'short' })}</span></button>`)}</span>` : ''}</div>`;
}

function reportRow(r) {
  return html`<tr data-report="${r.date}">
    <td data-label="Day" class="span2"><span class="cell-main"><b>${longDay(r.date)}</b><small class="cell-wide mono">${r.date}</small></span></td>
    <td data-label="Verified revenue" class="num">${money(r.collected)}</td>
    <td data-label="Paid orders" class="num">${r.orders_paid === null ? '—' : num(r.orders_paid)}</td>
    <td data-label="Net profit" class="num"><span class="cell-main">${r.net_profit === null ? 'Unavailable' : signedMoney(r.net_profit)}<small class="cell-wide">${r.margin === null ? 'no margin' : tenths(r.margin) + '% margin'}</small></span></td>
    <td data-label="Basis">${basisChip(r.net_profit_basis)}</td>
    <td data-label="Alerts when built"><span class="cell-main">${r.alerts_unresolved === null ? '—' : html`<b>${num(r.alerts_unresolved)}</b>`}<small class="cell-prose cell-full">${r.alerts_critical ? r.alerts_critical + ' critical' : 'when built'}</small></span></td>
    <td data-label="Generated"><span class="cell-main"><b>${timeAgo(r.generated_at)}</b><small class="cell-prose cell-full">${fmtDateTime(r.generated_at)} · ${r.generated_by === 'admin' ? 'by an admin' : 'by the daily job'}${r.regenerated_count ? ' · rebuilt ' + r.regenerated_count + '×' : ''}</small></span></td>
    <td class="row-actions-cell"><span class="row-actions"><a class="btn btn-sm" href="?date=${r.date}" data-open="${r.date}" aria-label="Open the report for ${longDay(r.date)}">${icon('arrow-up-right', 'icon-sm')}<span>View</span></a></span></td></tr>`;
}

function renderList(d) {
  can = d.can || can;
  latestDay = d.latest_day;
  $('#rp-status').innerHTML = String(statusHtml(d));
  $('#rp-toolbar').hidden = false;
  $('#rp-from').value = state.from;
  $('#rp-to').value = state.to;
  $('#rp-from').max = latestDay;
  $('#rp-to').max = latestDay;
  $('#rp-reset').hidden = !state.from && !state.to;
  root().innerHTML = String(d.rows.length
    ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Daily reports"><table class="tbl tbl-resp">
      <thead><tr><th>Day</th><th class="num">Verified revenue</th><th class="num">Paid orders</th><th class="num">Net profit</th><th>Basis</th><th>Alerts when built</th><th>Generated</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map(reportRow)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: d.per }, 'reports')}</div>`
    : html`<div class="tbl-wrap">${emptyState({
      ico: 'file-text', title: state.from || state.to ? 'No reports in these dates' : 'No reports yet',
      text: state.from || state.to ? 'Try a wider date range.' : 'The first report is written shortly after midnight (' + d.timezone + ') for the day that just ended, once the daily job has run. Days before the shop’s first order or visit have no report.',
    })}</div>`);
}

// ── one report ────────────────────────────────────────────────────────────────
function tile(m, key) {
  const [bl] = BASIS[m.basis] || BASIS.unavailable;
  return html`<div class="stat" data-metric="${key}" title="${m.note || bl}"><small>${m.label}</small><strong class="${m.value !== null && m.value < 0 && m.unit === 'paise' ? 'amt-neg' : ''}">${value(m)}</strong><span>${basisChip(m.basis)}</span></div>`;
}

function changeCell(r) {
  if (r.delta === null) return html`<span class="text-muted">—</span>`;
  const good = GOOD_UP.has(r.key);
  const cls = r.delta === 0 ? 'amt-mute' : (r.delta > 0) === good ? 'amt-pos' : 'amt-neg';
  const amount = r.unit === 'paise' ? inr(Math.abs(r.delta), true) : num(Math.abs(r.delta));
  const sign = r.delta > 0 ? '+' : r.delta < 0 ? '−' : '';
  return html`<span class="${cls}">${sign}${amount}${r.delta_pct_tenths !== null ? ' (' + (r.delta > 0 ? '+' : r.delta < 0 ? '−' : '') + tenths(Math.abs(r.delta_pct_tenths)) + '%)' : ''}</span>`;
}

function reportHtml(rep) {
  const s = rep.snapshot;
  const m = s.metrics;
  const cmp = s.comparison;
  const groups = [];
  for (const [k, x] of Object.entries(m)) {
    if (x.basis === 'measured' || !x.note) continue;
    const g = groups.find((y) => y.basis === x.basis && y.note === x.note);
    if (g) { g.labels.push(x.label); g.keys.push(k); } else groups.push({ basis: x.basis, note: x.note, labels: [x.label], keys: [k] });
  }
  const f = s.failures;
  const a = s.alerts;
  const q = s.quality;
  const sections = Object.entries(rep.sections).map(([key, [title, keys]]) => html`<section class="card" aria-labelledby="h-rp-${key}"><div class="card-head"><h2 class="card-title" id="h-rp-${key}">${title}</h2></div>
    <div class="stat-grid">${keys.filter((k) => m[k]).map((k) => tile(m[k], k))}</div></section>`);
  const svc = (title, list, empty, withMargin, foot = '') => html`<section class="card" aria-label="${title}"><div class="card-head"><h3 class="card-title">${title}</h3></div>
    ${list.length ? html`<ul class="grid gap-2">${list.map((x) => html`<li class="flex items-start justify-between gap-3"><span class="min-w-0"><b>${x.title}</b><small class="block text-muted">${num(x.orders)} order${x.orders === 1 ? '' : 's'}${x.why ? ' · ' + x.why : ''}</small></span>
      ${x.contribution !== undefined ? html`<span class="text-right">${signedMoney(x.contribution)}${withMargin && x.margin_tenths !== null && x.margin_tenths !== undefined ? html`<small class="block text-muted">${tenths(x.margin_tenths)}%</small>` : ''}</span>` : ''}</li>`)}</ul>`
      : html`<p class="text-sm text-muted">${empty}</p>`}${foot ? html`<p class="text-sm text-muted mt-3" data-svc-foot>${foot}</p>` : ''}</section>`;
  const idle = Number(s.services.no_cost_unsold) || 0;
  return html`<div class="stack-lg" data-report-detail="${rep.date}">
    <div class="card"><div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0"><h2 class="report-date" id="h-rp-date">${fullDay(rep.date)}</h2>
        <p class="text-sm text-muted mt-1">Generated ${fmtDateTime(rep.generated_at)} ${rep.generated_by === 'admin' ? 'by an admin' : 'by the daily job'} · calculation v${rep.calc_version}${rep.regenerated_count ? ' · rebuilt ' + rep.regenerated_count + '×' : ''}</p></div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn" id="rp-back">${icon('arrow-left', 'icon-sm')}<span>All reports</span></button>
        ${can.generate ? html`<button type="button" class="btn" id="rp-regen" title="Recalculate this report from today’s records">${icon('refresh-cw', 'icon-sm')}<span>Regenerate</span></button>` : ''}
      </div></div></div>

    <div class="callout ${q.status === 'actual' ? 'callout-info' : 'callout-warning'}" role="note" data-quality="${q.status}">${icon(q.status === 'actual' ? 'shield-check' : 'triangle-alert')}<div class="min-w-0"><strong>${q.status === 'actual' ? 'All costs behind this report are actual figures.' : q.status === 'estimated' ? 'Some inputs are estimates.' : 'Some costs are not known, so profit is incomplete.'}</strong>
      ${q.reasons.length ? html`<ul class="mt-1 list-disc pl-5 grid gap-0.5">${q.reasons.map((x) => html`<li>${x}</li>`)}</ul>` : html`<p class="mt-1">Every cost on the orders paid this day is a recorded figure.</p>`}</div></div>

    <section class="card" aria-labelledby="h-rp-hi"><div class="card-head"><h2 class="card-title" id="h-rp-hi">${icon('sparkles')}In short</h2><span class="card-sub">written by fixed rules from the numbers below — no AI</span></div>
      <ul class="grid gap-2" data-highlights>${s.highlights.map((x) => html`<li class="flex items-start gap-2"><span class="mt-1 shrink-0">${icon(TONE_ICON[x.tone] || 'info', 'icon-sm')}</span><span>${x.text}</span></li>`)}</ul></section>

    ${sections}

    ${groups.length ? html`<section class="card" aria-labelledby="h-rp-notes"><div class="card-head"><h2 class="card-title" id="h-rp-notes">${icon('info')}Notes on figures that are not fully measured</h2></div>
      <ul class="grid gap-3">${groups.map((g) => html`<li data-note="${g.keys.join(' ')}"><b>${g.labels.join(' · ')}</b> ${basisChip(g.basis)}<br><span class="text-sm text-ink-soft">${g.note}</span></li>`)}</ul>
      ${groups.some((g) => g.basis === 'incomplete') ? html`<a class="btn btn-sm mt-3" href="${url('/admin/finance?tab=reconciliation')}">${icon('arrow-up-right', 'icon-sm')}<span>Open Finance → Reconciliation</span></a>` : ''}</section>` : ''}

    <section class="card" aria-labelledby="h-rp-cmp"><div class="card-head"><h2 class="card-title" id="h-rp-cmp">${icon('trending-up')}Compared with the day before</h2><span class="card-sub">${longDay(cmp.previous_date)}</span></div>
      ${cmp.available ? html`<div class="tbl-scroll" tabindex="0" role="region" aria-label="Comparison with the previous day"><table class="tbl tbl-resp"><thead><tr><th>Figure</th><th class="num">This day</th><th class="num">Day before</th><th class="num">Change</th></tr></thead>
        <tbody>${cmp.rows.map((r) => html`<tr data-compare="${r.key}"><td data-label="Figure">${r.label}</td>
          <td data-label="This day" class="num">${r.value === null ? 'Unavailable' : r.unit === 'paise' ? inr(r.value, true) : num(r.value)}</td>
          <td data-label="Day before" class="num">${r.previous === null ? 'Unavailable' : r.unit === 'paise' ? inr(r.previous, true) : num(r.previous)}</td>
          <td data-label="Change" class="num">${changeCell(r)}</td></tr>`)}</tbody></table></div>`
        : html`<p class="text-sm text-muted">The day before is earlier than the shop’s first data, so there is nothing to compare with.</p>`}</section>

    <div class="grid-3 items-start">${svc('Most profitable services', s.services.most_profitable, 'No service made a profit on this day.', true)}${svc('Loss-making services', s.services.loss_making, 'No service lost money on this day.', true)}${svc('Services with missing cost data', s.services.missing_cost.filter((x) => x.orders > 0), 'Every service that sold has a known cost.', false, idle ? `${num(idle)} other active service${idle === 1 ? ' has' : 's have'} no cost set but did not sell on this day, so ${idle === 1 ? 'it does' : 'they do'} not change these figures. Set ${idle === 1 ? 'its' : 'their'} cost in Finance.` : '')}</div>

    <section class="card" aria-labelledby="h-rp-fail"><div class="card-head"><h2 class="card-title" id="h-rp-fail">${icon('shield-alert')}API and payment failures</h2></div>
      <div class="stat-grid">
        <div class="stat stat-wrap"><small>Payments that failed</small><strong>${num(f.payments.failed)}</strong><span>gateway failure</span></div>
        <div class="stat stat-wrap"><small>Amount / state mismatch</small><strong>${num(f.payments.amount_or_state_mismatch)}</strong><span>held, not fulfilled</span></div>
        <div class="stat stat-wrap"><small>Gateway notifications</small><strong>${num(f.webhooks.received)}</strong><span>${num(f.webhooks.invalid_signature)} bad signature · ${num(f.webhooks.unprocessed)} unprocessed · ${num(f.webhooks.mismatch)} mismatch</span></div>
        <div class="stat stat-wrap"><small>Needs a person now</small><strong>${num(f.needs_person_now)}</strong><span>paid orders in Order recovery</span></div>
      </div>
      ${f.provider_calls.length ? html`<div class="tbl-scroll mt-3" tabindex="0" role="region" aria-label="SMM provider calls"><table class="tbl tbl-resp"><thead><tr><th>SMM provider</th><th class="num">Calls</th><th class="num">Failed</th><th class="num">Could not reach / server error</th><th class="num">Rejected</th></tr></thead>
        <tbody>${f.provider_calls.map((p) => html`<tr><td data-label="Provider">${p.name}</td><td data-label="Calls" class="num">${num(p.calls)}</td><td data-label="Failed" class="num">${num(p.failed)}</td><td data-label="Could not reach" class="num">${num(p.technical)}</td><td data-label="Rejected" class="num">${num(p.rejected)}</td></tr>`)}</tbody></table></div>`
        : html`<p class="text-sm text-muted mt-3">No calls were made to an SMM provider on this day.</p>`}
      ${Object.keys(f.orders_failed_by_kind).length ? html`<p class="text-sm text-ink-soft mt-3">Paid orders that ended FAILED, by kind: ${Object.entries(f.orders_failed_by_kind).map(([k, n]) => `${k} ${n}`).join(' · ')}.</p>` : ''}</section>

    <section class="card" aria-labelledby="h-rp-al"><div class="card-head"><h2 class="card-title" id="h-rp-al">${icon('bell-ring')}Watchdog alerts</h2><span class="card-sub">${num(a.counts?.total ?? a.unresolved.length)} unresolved when this report was built (${fmtDateTime(a.as_of)}) · ${num(a.detected_on_day)} first seen and ${num(a.resolved_on_day)} resolved on the day</span></div>
      ${a.unresolved.length ? html`<ul class="grid gap-2" data-alerts>${a.unresolved.map((x) => html`<li class="flex items-start gap-2"><span class="chip ${(SEV[x.severity] || SEV.info)[1]} min-w-[4.75rem] justify-center shrink-0">${(SEV[x.severity] || SEV.info)[0]}</span><span class="min-w-0"><b>${x.title}</b><small class="block text-muted">${x.status} · seen ${num(x.occurrences)}× · since ${fmtDateTime(x.first_detected_at)}</small></span></li>`)}</ul>
        <a class="btn btn-sm mt-3" href="${url('/admin/alerts')}">${icon('arrow-up-right', 'icon-sm')}<span>Open Business alerts</span></a>`
        : html`<p class="text-sm text-muted">No unresolved alert when this report was built.</p>`}</section>

    <section class="card" aria-labelledby="h-rp-how"><div class="card-head"><h2 class="card-title" id="h-rp-how">${icon('info')}How to read this report</h2></div>
      <ul class="grid gap-1 list-disc pl-5 text-sm text-ink-soft">${s.caveats.map((x) => html`<li>${x}</li>`)}</ul>
      <dl class="mt-3 grid gap-2 text-sm">${['collected', 'refunds', 'net_revenue', 'provider_cost', 'fulfilment', 'payment_fees', 'advertising', 'contribution', 'net_profit', 'margin', 'aov'].filter((k) => rep.definitions[k]).map((k) => html`<div><dt class="font-semibold">${m[k]?.label || k}</dt><dd class="text-ink-soft">${rep.definitions[k]}</dd></div>`)}</dl></section>
  </div>`;
}

// ── loading ───────────────────────────────────────────────────────────────────
async function load({ silent = false } = {}) {
  const my = ++seq;
  if (!silent || !loaded) root().innerHTML = String(skeletonTable(6));
  root().setAttribute('aria-busy', 'true');
  try {
    if (state.date) {
      const d = await get(`reports/${state.date}`);
      if (my !== seq) return;
      can = d.can || can;
      $('#rp-toolbar').hidden = true;
      $('#rp-status').innerHTML = '';
      root().innerHTML = String(reportHtml(d.report));
    } else {
      const d = await get('reports', { from: state.from, to: state.to, page: state.page });
      if (my !== seq) return;
      renderList(d);
    }
    loaded = true;
    writeUrl();
  } catch (e) {
    if (my !== seq) return;
    if (state.date && e.status === 404) {
      state.date = '';
      warn('No such report', 'There is no report for that day yet.');
      return load();
    }
    if (!loaded) root().innerHTML = errorState(e.message).toString(); else fail('Could not refresh', e.message);
  } finally {
    if (my === seq) root().setAttribute('aria-busy', 'false');
  }
}

// ── actions ───────────────────────────────────────────────────────────────────
async function generate(day, force, btn) {
  await withBusy(btn, async () => {
    try {
      const r = await post(`reports/${day}/generate`, { force }, { idempotency: true });
      ok(force ? 'Report rebuilt' : 'Report created', r.message);
      if (!force) state.date = day;
      await load({ silent: true });
    } catch (e) {
      if (e.fields?.date) fail('Cannot create that report', e.fields.date); else fail('Could not create the report', e.message);
    }
  });
}

// ── boot ──────────────────────────────────────────────────────────────────────
export default function init() {
  const q = new URLSearchParams(location.search);
  state.date = isDay(q.get('date')) ? q.get('date') : '';
  state.from = isDay(q.get('from')) ? q.get('from') : '';
  state.to = isDay(q.get('to')) ? q.get('to') : '';
  state.page = Math.max(1, Number(q.get('page')) || 1);

  $('#rp-apply').addEventListener('click', () => {
    const from = $('#rp-from').value;
    const to = $('#rp-to').value;
    if (from && to && from > to) { warn('Check the dates', 'The first date must not be after the last.'); return; }
    state.from = from; state.to = to; state.page = 1;
    load();
  });
  $('#rp-reset').addEventListener('click', () => { state.from = ''; state.to = ''; state.page = 1; load(); });

  const r = root();
  on(r, 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); load(); });
  on(r, 'click', '[data-retry]', () => load());
  on(r, 'click', '[data-open]', (e, a) => { e.preventDefault(); state.date = a.dataset.open; load(); window.scrollTo({ top: 0 }); });
  on(r, 'click', '#rp-back', () => { state.date = ''; load(); });
  on(r, 'click', '#rp-regen', async (e, b) => {
    const yes = await confirmDialog({
      title: 'Regenerate this report?', message: 'It is recalculated from the shop’s records as they are now. If any figure changed, the old and new figures are written to the audit log. This never creates a second report for the day.',
      confirmText: 'Regenerate', tone: 'primary',
    });
    if (yes) generate(state.date, true, b);
  });
  on($('#rp-status'), 'click', '[data-gen]', (e, b) => generate(b.dataset.gen, false, b));

  load();
}
