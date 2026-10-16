// Admin → Finance: the profit statement (today / the chosen period / lifetime), services by contribution, expenses and chargebacks, the reconciliation
// view and the fee model. Every figure arrives from the server as integer paise; unknown costs are labelled there, never counted as ₹0 here.
import { $, $$, html, raw, icon, on, url, uuid } from '../core/dom.js';
import { inr, num, tenths, inrCompact, compact, fmtDateTime, timeAgo } from '../core/format.js';
import { get, post, put } from './net.js';
import { lineChart } from './charts.js';
import { skeletonTable, emptyState, errorState, pager, ok, fail, warn, withBusy, showFieldErrors, clearFieldErrors, autoClearErrors, formDialog } from './ui.js';
import { confirmDialog } from '../core/dialog.js';

const TABS = ['overview', 'services', 'costs', 'reconcile', 'settings'];
const COLORS = { net_revenue: '#16a34a', contribution: '#2563eb', net_profit: '#7c3aed', advertising: '#f59e0b' };
const STATUS = {
  actual: ['Actual costs', 'callout-success', 'circle-check', 'chip-ok'],
  estimated: ['Estimated costs', 'callout-info', 'info', 'chip-info'],
  incomplete: ['Incomplete — some costs are not known', 'callout-warn', 'triangle-alert', 'chip-warn'],
};
const SOURCE = { actual: ['actual', 'chip-ok'], estimated: ['estimated', 'chip-info'], mixed: ['actual + estimated', 'chip-warn'], none: ['none', 'chip-gray'] };
const CATEGORY = { tools: 'Tools', hosting: 'Hosting', chargeback: 'Chargeback', other: 'Other' };
const SEVERITY = { high: ['Needs attention', 'chip-bad'], medium: ['Should be fixed', 'chip-warn'], low: ['Worth a look', 'chip-info'], info: ['For information', 'chip-gray'] };

const state = { tab: 'overview', range: '30d', from: '', to: '', platform: '', service: '', mode: '', payment: '', category: '', voided: false, page: 1, col: 'period' };
let fin = null;
let can = { adjust: false };
let chart = null;
let seq = 0;
let loaded = false;
const root = () => $('#fin-root');

// ── dates ─────────────────────────────────────────────────────────────────────
const dayFmt = (d, o) => new Intl.DateTimeFormat('en-IN', { timeZone: 'UTC', ...o }).format(new Date(d + 'T00:00:00Z'));
const longDay = (d) => dayFmt(d, { day: '2-digit', month: 'short', year: 'numeric' });
const rangeText = (m) => (m.range.from === m.range.to ? longDay(m.range.from) : `${longDay(m.range.from)} – ${longDay(m.range.to)}`);
const labelFor = (bucket, key) => (bucket === 'month' ? dayFmt(key, { month: 'short', year: '2-digit' }) : dayFmt(key, { day: '2-digit', month: 'short' }));
const titleFor = (bucket, key) => (bucket === 'month' ? dayFmt(key, { month: 'long', year: 'numeric' }) : bucket === 'week' ? 'Week of ' + longDay(key) : dayFmt(key, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }));
const today = () => new Date(Date.now() + 5.5 * 3600 * 1000).toISOString().slice(0, 10);

function writeUrl() {
  const q = new URLSearchParams();
  if (state.tab !== 'overview') q.set('tab', state.tab);
  if (['overview', 'services'].includes(state.tab)) {
    if (state.range !== '30d') q.set('range', state.range);
    if (state.range === 'custom') { q.set('from', state.from); q.set('to', state.to); }
    for (const k of ['platform', 'service', 'mode', 'payment']) if (state[k]) q.set(k, state[k]);
  }
  if (state.tab === 'costs') {
    if (state.category) q.set('category', state.category);
    if (state.voided) q.set('voided', '1');
    if (state.page > 1) q.set('page', String(state.page));
  }
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

// ── small renderers ───────────────────────────────────────────────────────────
const money = (p) => (p === null || p === undefined ? '—' : inr(p));
const signed = (p) => html`<span class="${p < 0 ? 'amt-neg' : ''}">${inr(p)}</span>`;
const marginText = (t) => (t === null || t === undefined ? '—' : tenths(t) + '%');
const defOf = (k) => fin?.definitions?.[k] || '';

const kpi = (tone, ico, label, value, sub, extra = '') => html`<article class="kpi" data-tone="${tone}" aria-label="${label}">
  <div class="kpi-top"><span class="kpi-label">${icon(ico)}<span>${label}</span></span></div>
  <div class="kpi-row"><div class="kpi-value">${value}</div>${extra}</div><div class="kpi-sub kpi-sub-wrap" title="${sub}">${sub}</div></article>`;

function statusBanner(l) {
  const [text, cls, ico] = STATUS[l.status] || STATUS.estimated;
  return html`<div class="callout ${cls}" role="status">${icon(ico)}<div class="min-w-0"><strong data-profit-status="${l.status}">${text}</strong>
    ${l.reasons.length ? html`<ul class="mt-1 list-disc pl-5 grid gap-0.5">${l.reasons.map((r) => html`<li>${r}</li>`)}</ul>` : html`<span> — every cost behind these figures is a recorded actual.</span>`}</div>
    ${l.status === 'incomplete' ? html`<div class="callout-actions"><button type="button" class="btn btn-sm" data-goto="reconcile">See what is missing</button></div>` : ''}</div>`;
}

// ── filters ───────────────────────────────────────────────────────────────────
function paintFilters() {
  const bar = $('#fin-filters');
  bar.hidden = !['overview', 'services'].includes(state.tab);
  $$('#fin-range [data-range]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.range === state.range)));
  $('#fin-custom').hidden = state.range !== 'custom';
  for (const [id, k] of [['fin-platform', 'platform'], ['fin-service', 'service'], ['fin-mode', 'mode'], ['fin-payment', 'payment']]) $('#' + id).value = state[k];
  $('#fin-from').value = state.from;
  $('#fin-to').value = state.to;
  $('#fin-reset').hidden = !(state.platform || state.service || state.mode || state.payment);
  if (fin) { $('#fin-from').max = fin.meta.today; $('#fin-to').max = fin.meta.today; $('#fin-from').min = fin.meta.first_day; }
}

function fillSelects(m) {
  const p = $('#fin-platform');
  if (!p.dataset.filled) {
    p.dataset.filled = '1';
    m.platforms.forEach((x) => p.append(new Option(x.name, x.slug)));
    m.services.forEach((x) => $('#fin-service').append(new Option(x.title, x.code)));
    $('#fin-platform').value = state.platform;
    $('#fin-service').value = state.service;
  }
}

// ── tabs ──────────────────────────────────────────────────────────────────────
const paintAdd = () => { const a = $('#fin-add'); if (a) a.hidden = state.tab !== 'costs' || !can.adjust; };

function paintTabs() {
  paintAdd();
  $$('#fin-tabs [data-tab]').forEach((b) => {
    const on_ = b.dataset.tab === state.tab;
    b.setAttribute('aria-selected', String(on_));
    b.tabIndex = on_ ? 0 : -1;
  });
  root().setAttribute('aria-labelledby', 'fin-tab-' + state.tab);
  paintFilters();
}

// ── Profit tab ────────────────────────────────────────────────────────────────
function statementRows(f) {
  const t = f.today, p = f.period, l = f.lifetime;
  const shared = f.meta.shared_costs_included;
  const afterRefunds = (m) => m.collected - m.refunds;
  const R = (label, key, kind, get_, opts = {}) => ({ label, key, kind, get: get_, ...opts });
  return [
    R('Verified revenue', 'collected', 'plain', (m) => m.collected, { def: 'collected' }),
    R('Refunds', 'refunds', 'minus', (m) => m.refunds, { def: 'refunds' }),
    R('Revenue after refunds', 'after_refunds', 'sum', afterRefunds),
    R('API (provider) cost', 'provider_cost', 'minus', (m) => m.provider_cost, { def: 'provider_cost' }),
    R('Manual fulfilment', 'fulfilment', 'minus', (m) => m.fulfilment, { def: 'fulfilment' }),
    R('Payment fees', 'payment_fees', 'minus', (m) => m.payment_fees, { def: 'payment_fees' }),
    R('Other order costs', 'other_costs', 'minus', (m) => m.other_costs, { def: 'other_costs' }),
    R('Contribution', 'contribution', 'sum', (m) => m.contribution, { def: 'contribution' }),
    R('Chargebacks', 'chargebacks', 'minus', (m) => (shared ? m.chargebacks : null), { def: 'chargebacks', shared: !shared }),
    R('Business expenses', 'expenses', 'minus', (m) => (shared ? m.expenses : null), { def: 'expenses', shared: !shared }),
    R('Advertising', 'advertising', 'minus', (m) => (shared ? m.advertising : null), { def: 'advertising', shared: !shared, badge: true }),
    R('Net profit', 'net_profit', 'total', (m) => m.net_profit, { def: 'net_profit' }),
    R('Margin', 'margin', 'info', (m) => marginText(m.margin_tenths), { def: 'margin', text: true }),
    R('Paid orders', 'orders_paid', 'info', (m) => num(m.orders_paid), { text: true }),
    R('Average order value', 'aov', 'info', (m) => (m.aov === null ? '—' : inr(m.aov)), { def: 'aov', text: true }),
  ].map((r) => ({ ...r, cells: [t, p, l].map((m) => r.get(m)) }));
}

function adsBadge(f) {
  const l = f.labels;
  if (l.ads === 'none' || !f.meta.shared_costs_included) return '';
  const [text, cls] = SOURCE[l.ads] || SOURCE.none;
  return html` <span class="chip ${cls}" title="${l.ads === 'mixed' ? `${l.ads_days.actual} day(s) with actual spend, ${l.ads_days.estimated} on the CPC estimate` : 'Where the advertising figure comes from'}">${text}</span>`;
}

function statementHtml(f) {
  const rows = statementRows(f);
  const head = ['Today', rangeText(f.meta), 'Lifetime'];
  return html`<section class="card" aria-labelledby="h-stmt"><div class="card-head"><h2 class="card-title" id="h-stmt">${icon('badge-indian-rupee')}Profit statement</h2>
      <span class="card-sub">${f.meta.filtered ? 'filtered · ' : ''}${f.meta.timezone_label}</span></div>
    <div class="seg mb-3 sm:hidden" role="group" aria-label="Column" id="stmt-cols">
      ${[['today', 'Today'], ['period', 'Period'], ['life', 'Lifetime']].map(([k, l]) => html`<button type="button" data-col="${k}" aria-pressed="${state.col === k}">${l}</button>`)}</div>
    <div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Profit statement table"><table class="tbl stmt" data-col="${state.col}">
      <thead><tr><th scope="col"><span class="sr-only">Line</span></th>${head.map((h, i) => html`<th scope="col" class="num c-${['today', 'period', 'life'][i]}">${h}</th>`)}</tr></thead>
      <tbody>${rows.map((r) => html`<tr class="stmt-${r.kind}"><th scope="row"><span class="stmt-label"><span class="stmt-op" aria-hidden="true">${r.kind === 'minus' ? '−' : r.kind === 'sum' || r.kind === 'total' ? '=' : ''}</span>${r.label}${r.badge ? adsBadge(f) : ''}</span>${r.def ? html`<small class="stmt-def">${defOf(r.def)}</small>` : ''}</th>
        ${r.cells.map((c, i) => html`<td class="num c-${['today', 'period', 'life'][i]}">${r.text ? c : r.shared ? html`<span class="amt-mute" title="Belongs to the whole business: not part of a filtered view">—</span>` : r.kind === 'total' || r.key === 'contribution' ? signed(c) : money(c)}</td>`)}</tr>`)}</tbody></table></div></div>
    ${f.meta.filtered ? html`<p class="mt-3 text-[13px] text-muted">A filtered view shows what those orders earned and cost. Advertising, chargebacks and business expenses belong to the whole business, so net profit here equals contribution.</p>` : ''}
    <details class="fold mt-3"><summary>How these numbers are defined</summary><dl class="dl mt-2">${Object.entries(f.definitions).map(([k, v]) => html`<dt>${k.replace(/_/g, ' ')}</dt><dd>${v}</dd>`)}</dl>
      <p class="mt-2 text-[13px] text-muted">Revenue is counted on the day a payment was verified (${f.meta.timezone_label}); lifetime starts on the first day with any data (${longDay(f.meta.first_day)}). Payment fees: ${f.meta.fee.description}. GST on advertising is ${f.meta.ad_gst === 'cost' ? 'counted as a cost' : 'recoverable and not counted'}.</p></details>
  </section>`;
}

function trendHtml() {
  return html`<section class="card" aria-labelledby="h-trend"><div class="card-head"><h2 class="card-title" id="h-trend">${icon('trending-up')}Revenue and profit over time</h2></div>
    <div data-chart="trend" aria-label="Revenue and profit over time"></div>
    <div class="legend" aria-hidden="true">${[['net_revenue', 'Net revenue'], ['contribution', 'Contribution'], ['net_profit', 'Net profit'], ['advertising', 'Advertising']].map(([k, l]) => html`<span><i data-c="${k}"></i>${l}</span>`)}</div></section>`;
}

function paymentsHtml(f) {
  const p = f.payments;
  return html`<section class="card" aria-labelledby="h-fpay"><div class="card-head"><h2 class="card-title" id="h-fpay">${icon('credit-card')}Payments started in this period</h2><span class="card-sub">${rangeText(f.meta)}</span></div>
    <div class="kv"><span>Successful (verified)</span><b>${num(p.success)}</b></div><div class="kv"><span>Pending</span><b>${num(p.pending)}</b></div>
    <div class="kv"><span>Failed or held for review</span><b>${num(p.failed)}</b></div><div class="kv"><span>Expired (not paid)</span><b>${num(p.expired)}</b></div>
    <div class="kv"><span>Refunded</span><b>${num(p.refunded)}</b></div>
    <p class="mt-2 text-[13px] text-muted">Only successful payments are revenue. ${p.filter ? `Showing the “${p.filter}” filter in the Payments page: ` : ''}<a href="${url('/admin/payments')}${p.filter ? '?status=' + p.filter : ''}">Open the payments</a>.</p></section>`;
}

function overviewHtml(f) {
  const p = f.period;
  const tone = p.net_profit < 0 ? 'red' : 'violet';
  return html`<div class="kpi-grid mb-4">
      ${kpi('green', 'indian-rupee', 'Net revenue', money(p.net_revenue), `${num(p.orders_paid)} paid order${p.orders_paid === 1 ? '' : 's'} · ${rangeText(f.meta)}`)}
      ${kpi(tone, 'trending-up', f.meta.shared_costs_included ? 'Net profit' : 'Profit before shared costs', signed(p.net_profit), f.meta.shared_costs_included ? (f.labels.status === 'actual' ? 'after every cost recorded' : 'after the costs known so far') : 'contribution of the filtered orders')}
      ${kpi('blue', 'percent', 'Margin', marginText(p.margin_tenths), p.margin_tenths === null ? 'no net revenue in this period' : 'net profit ÷ net revenue')}
      ${kpi('slate', 'package', 'Average order', p.aov === null ? '—' : money(p.aov), 'collected ÷ paid orders')}
    </div>
    <div class="stack-lg">${statementHtml(f)}${trendHtml()}${paymentsHtml(f)}</div>`;
}

function drawTrend(f) {
  chart?.destroy();
  chart = null;
  const host = $('[data-chart="trend"]', root());
  if (!host) return;
  const b = f.meta.range.bucket;
  const pts = f.trend.map((s) => ({ label: labelFor(b, s.key), title: titleFor(b, s.key) }));
  const col = (k) => f.trend.map((s) => s[k]);
  const fmt = (v) => inr(v);
  chart = lineChart(host, {
    points: pts, yFormat: (v) => inrCompact(v), height: 280, floorMax: 5000, ariaLabel: 'Net revenue, contribution, net profit and advertising over time', emptyText: 'No revenue in this period yet.',
    series: [
      { id: 'net_revenue', label: 'Net revenue', color: COLORS.net_revenue, values: col('net_revenue'), type: 'area', format: fmt },
      { id: 'contribution', label: 'Contribution', color: COLORS.contribution, values: col('contribution'), type: 'line', format: fmt },
      { id: 'net_profit', label: 'Net profit', color: COLORS.net_profit, values: col('net_profit'), type: 'line', format: fmt },
      { id: 'advertising', label: 'Advertising', color: COLORS.advertising, values: col('advertising'), type: 'line', format: fmt },
    ],
  });
}

// ── Services tab ──────────────────────────────────────────────────────────────
const statusChip = (s) => html`<span class="chip ${(STATUS[s] || STATUS.estimated)[3]}" title="${s === 'incomplete' ? 'A cost of some of these orders is not known' : s === 'estimated' ? 'Costs include estimates' : 'Every cost is a recorded actual'}">${s}</span>`;

function serviceList(title, ico, list, empty, line, note = '') {
  const id = 'h-' + title.replace(/\W+/g, '-');
  return html`<section class="card self-start" aria-labelledby="${id}"><div class="card-head"><h2 class="card-title" id="${id}">${icon(ico)}${title}</h2></div>
    ${list.length ? html`<ul class="divide-y divide-line">${list.map((s) => html`<li class="flex items-center justify-between gap-3 py-2.5"><span class="cell-main"><b>${s.title}</b><small class="cell-prose">${line(s)}</small></span>
      <span class="shrink-0 text-right">${s.contribution !== undefined ? html`<b class="num text-sm block">${signed(s.contribution)}</b>` : ''}${s.status ? statusChip(s.status) : ''}</span></li>`)}</ul>${note ? html`<p class="mt-2 text-[13px] text-muted">${note}</p>` : ''}` : html`<p class="text-sm text-muted">${empty}</p>`}</section>`;
}

function servicesHtml(f) {
  const sv = f.services;
  return html`<div class="grid-3 mb-4">
      ${serviceList('Most profitable', 'trending-up', sv.most_profitable, 'No service earned a positive contribution in this period.', (s) => `${num(s.orders)} orders · margin ${marginText(s.margin_tenths)}${s.status === 'incomplete' ? ' before the costs not known yet' : ''}`,
        sv.most_profitable.some((s) => s.status === 'incomplete') ? 'A service marked incomplete has costs that are not known yet, so its contribution is overstated.' : '')}
      ${serviceList('Loss-making', 'triangle-alert', sv.loss_making, 'No service lost money in this period.', (s) => `${num(s.orders)} orders · collected ${inr(s.collected)} · costs ${inr(s.provider_cost + s.line_costs)}`)}
      ${serviceList('Missing cost data', 'circle-alert', sv.missing_cost, 'Every service that sold has its costs.', (s) => s.why)}
    </div>
    <section class="card" aria-labelledby="h-allsvc"><div class="card-head"><h2 class="card-title" id="h-allsvc">${icon('layers')}Every service that sold · ${rangeText(f.meta)}</h2></div>
      ${sv.rows.length ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Services by contribution"><table class="tbl tbl-resp">
        <thead><tr><th>Service</th><th class="num">Orders</th><th class="num">Collected</th><th class="num">Refunds</th><th class="num">Costs</th><th class="num">Contribution</th><th class="num">Margin</th><th>Costs are</th></tr></thead>
        <tbody>${[...sv.rows].sort((a, b) => b.contribution - a.contribution).map((s) => html`<tr>
          <td data-label="Service" class="span2"><span class="cell-main"><b>${s.title}</b><small class="cell-wide">${s.code} · ${s.mode}</small></span></td>
          <td data-label="Orders" class="num">${num(s.orders)}</td><td data-label="Collected" class="num">${inr(s.collected)}</td><td data-label="Refunds" class="num">${inr(s.refunds)}</td>
          <td data-label="Costs" class="num">${inr(s.provider_cost + s.line_costs)}</td><td data-label="Contribution" class="num">${signed(s.contribution)}</td>
          <td data-label="Margin" class="num">${marginText(s.margin_tenths)}</td><td data-label="Costs are">${statusChip(s.status)}</td></tr>`)}</tbody></table></div></div>`
        : emptyState({ ico: 'layers', title: 'No paid orders in this period', text: 'Services appear here once an order was paid in the chosen period.' })}
      <p class="mt-3 text-[13px] text-muted">Contribution = collected − refunds − provider cost − fulfilment, fee and other order costs. It excludes advertising, chargebacks and business expenses, which belong to the whole business, not to one service.</p></section>`;
}

// ── Expenses tab ──────────────────────────────────────────────────────────────
let expenseData = null;

function expensesHtml(d) {
  expenseData = d;
  const filters = html`<div class="chips chips-flow" role="group" aria-label="Category">${[['', 'All'], ...d.categories.map((c) => [c, CATEGORY[c] || c])].map(([k, l]) => html`<button type="button" class="fchip" data-category="${k}" aria-pressed="${state.category === k}">${l}</button>`)}</div>
    <div class="toolbar-row"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" id="fe-voided" ${state.voided ? 'checked' : ''}><span>Show voided entries</span></label>
      <span class="ml-auto text-sm text-muted">Total of what is shown (voided excluded): <b class="text-ink">${inr(d.sum_paise)}</b></span></div>`;
  const table = d.rows.length ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Expenses and chargebacks"><table class="tbl tbl-resp">
      <thead><tr><th>Date</th><th>Category</th><th class="num">Amount</th><th>Details</th><th>Recorded</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map((e) => html`<tr class="${e.voided ? 'is-void' : ''}">
        <td data-label="Date">${longDay(e.day)}</td><td data-label="Category"><span class="chip ${e.category === 'chargeback' ? 'chip-bad' : 'chip-gray'}">${CATEGORY[e.category] || e.category}</span>${e.voided ? html` <span class="chip chip-gray">voided</span>` : ''}</td>
        <td data-label="Amount" class="num">${inr(e.amount_paise)}</td>
        <td data-label="Details" class="span2"><span class="cell-main">${e.description ? html`<b>${e.description}</b>` : ''}<small class="cell-prose">${[e.order ? 'Order ' + e.order : '', e.reference ? 'Ref ' + e.reference : '', e.voided ? 'Voided: ' + (e.void_reason || '') : ''].filter(Boolean).join(' · ') || '—'}</small></span></td>
        <td data-label="Recorded"><span class="cell-main"><b>${e.by || 'an admin'}</b><small>${timeAgo(e.at)}</small></span></td>
        <td class="row-actions-cell">${can.adjust && !e.voided ? html`<button type="button" class="btn btn-sm" data-void-expense="${e.id}">Void…</button>` : ''}</td></tr>`)}</tbody></table></div>
      ${pager({ page: d.page, pages: d.pages, total: d.total, per: d.per }, 'entries')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'receipt', title: 'No expenses recorded', text: 'Record tools, hosting, other business expenses and chargebacks here. Advertising is entered under Ads Intelligence; a payment fee is a per-order cost.' })}</div>`;
  return html`<h2 class="sr-only">Expenses and chargebacks</h2><section class="panel toolbar" aria-label="Filters">${filters}</section>${table}
    <p class="mt-3 text-[13px] text-muted">An entry is never deleted: a wrong one is voided with a reason and stays visible here and in the audit log. A chargeback reduces profit on the day it is recorded and names its order.</p>`;
}

function addExpense() {
  const todayStr = today();
  return formDialog({
    title: 'Record an expense or a chargeback', confirmText: 'Record',
    message: 'Chargebacks need the order they are for. Business expenses count on the day you choose. Advertising belongs under Ads Intelligence.',
    fields: [
      { name: 'day', label: 'Date', type: 'date', value: todayStr, required: true },
      { name: 'category', label: 'Category', type: 'select', value: 'tools', options: Object.entries(CATEGORY) },
      { name: 'amount', label: 'Amount (₹)', required: true, inputmode: 'decimal', placeholder: 'e.g. 499', hint: 'Rupees, at most two decimals.' },
      { name: 'description', label: 'What is it? (required for “Other”)', max: 255, placeholder: 'e.g. SMS panel, May' },
      { name: 'reference', label: 'Reference (optional)', max: 120, placeholder: 'invoice or bank reference' },
      { name: 'order', label: 'Order (required for a chargeback)', max: 20, placeholder: 'SMB-XXXXXXXX' },
    ],
    submit: async (v) => { const r = await post('finance/expenses', v, { idempotency: uuid() }); ok('Recorded', `${CATEGORY[v.category]} ${inr(r.expense.amount_paise)} on ${longDay(r.expense.day)}.`); },
  });
}

async function voidExpense(id) {
  return formDialog({
    title: 'Void this entry?', confirmText: 'Void entry', tone: 'danger',
    message: 'The entry stays visible (marked void) and stops counting. Say why — it is kept in the audit log.',
    fields: [{ name: 'reason', label: 'Why is it wrong?', type: 'textarea', required: true, max: 200 }],
    submit: async (v) => { await post(`finance/expenses/${id}/void`, { reason: v.reason }, { idempotency: uuid() }); ok('Entry voided'); },
  });
}

// ── Reconciliation tab ────────────────────────────────────────────────────────
function reconcileHtml(d) {
  const issues = d.issues;
  const banner = d.fee && d.fee.mode === 'unset'
    ? html`<div class="callout callout-warn mb-4">${icon('triangle-alert')}<div><strong>The payment fee model is not set up.</strong> The gateway reports no fee, so profit cannot include one until you say what it is (choose “No fee” if it charges nothing).</div><div class="callout-actions"><button type="button" class="btn btn-sm" data-goto="settings">Open Settings</button></div></div>` : '';
  if (!issues.length) return html`<h2 class="sr-only">Reconciliation</h2>${banner}<div class="tbl-wrap">${emptyState({ ico: 'circle-check', title: 'Nothing to reconcile', text: 'Every paid order has the costs it needs, and no record contradicts another.' })}</div>`;
  return html`${banner}<div class="stack-lg">${issues.map((i) => html`<section class="card" data-issue="${i.key}" aria-labelledby="h-${i.key}">
    <div class="card-head"><h2 class="card-title" id="h-${i.key}">${i.title}</h2><span class="chip ${(SEVERITY[i.severity] || SEVERITY.info)[1]}">${(SEVERITY[i.severity] || SEVERITY.info)[0]}</span></div>
    <p class="text-sm text-ink-soft">${i.text}</p>
    <p class="mt-2 text-sm"><b data-count>${num(i.count)}</b> record${i.count === 1 ? '' : 's'}</p>
    ${i.sample.length ? html`<ul class="mt-2 divide-y divide-line">${i.sample.map((o) => html`<li class="flex items-center justify-between gap-3 py-2"><span class="cell-main"><b class="mono">${o.public_id}</b><small class="cell-prose">${o.service} · ${inr(o.amount_paise)} · paid ${timeAgo(o.paid_at)}</small></span><a class="btn btn-sm" href="${url('/admin/orders')}?open=${o.id}" aria-label="Open order ${o.public_id}">${icon('arrow-up-right', 'icon-sm')}<span>Open</span></a></li>`)}</ul>${i.count > i.sample.length ? html`<p class="mt-1 text-[13px] text-muted">…and ${num(i.count - i.sample.length)} more (the newest ${i.sample.length} are shown).</p>` : ''}` : ''}
    ${i.fix && can.adjust ? html`<div class="mt-3"><button type="button" class="btn btn-primary btn-sm" data-fix="${i.fix.action}">${icon('check', 'icon-sm')}<span>${i.fix.label}…</span></button></div>` : ''}</section>`)}</div>`;
}

async function runFix(action, label, btn) {
  await withBusy(btn, async () => {
    let dry;
    try { dry = (await post('finance/reconciliation/fix', { action, dry_run: true }, { idempotency: uuid() })).result; } catch (e) { fail('Could not check', e.message); return; }
    if (dry.orders === 0) { ok('Nothing to add'); return; }
    const yes = await confirmDialog({ title: label + '?', message: `${dry.message} Existing lines are never changed — these are added as estimates and shown as such.`, confirmText: 'Add them' });
    if (!yes) return;
    try {
      const r = (await post('finance/reconciliation/fix', { action, dry_run: false }, { idempotency: uuid() })).result;
      ok('Done', r.message);
      await loadTab();
    } catch (e) { fail('Could not add them', e.message); }
  });
}

// ── Settings tab ──────────────────────────────────────────────────────────────
const FEE_LABEL = { unset: 'Not set up yet', none: 'No fee — the gateway charges nothing', percent: 'A percentage of each payment', fixed: 'A fixed amount per payment', percent_plus_fixed: 'A percentage plus a fixed amount' };

function settingsHtml(s) {
  const ro = can.adjust ? '' : 'disabled';
  return html`<form class="card" id="fin-settings" novalidate aria-labelledby="h-fee">
    <div class="card-head"><h2 class="card-title" id="h-fee">${icon('percent')}Payment fee model</h2></div>
    <p class="text-sm text-ink-soft mb-3">Alpha UPI does not report a fee, so the fee of each payment is estimated from this model <b>when the payment is verified</b>. Changing it later never rewrites an order that was already paid; use Reconciliation to add estimated fees to older orders.</p>
    <div class="field-row"><div class="field"><label class="label" for="fs-mode">Model</label>
      <select class="select" id="fs-mode" name="fee_mode" ${ro}>${Object.entries(FEE_LABEL).map(([k, l]) => html`<option value="${k}" ${s.fee_mode === k ? 'selected' : ''}>${l}</option>`)}</select></div>
      <div class="field"><label class="label" for="fs-pct">Percentage (%)</label><input class="input" id="fs-pct" name="fee_percent" inputmode="decimal" value="${s.fee_percent}" ${ro}><p class="field-hint">0 to 20, at most two decimals.</p></div>
      <div class="field"><label class="label" for="fs-fixed">Fixed amount (₹)</label><input class="input" id="fs-fixed" name="fee_fixed" inputmode="decimal" value="${s.fee_fixed}" ${ro}><p class="field-hint">Per payment, up to ₹1,000.</p></div></div>
    <div class="field mt-3"><label class="label" for="fs-gst">GST on advertising</label>
      <select class="select" id="fs-gst" name="ad_gst" ${ro}><option value="cost" ${s.ad_gst === 'cost' ? 'selected' : ''}>A cost (no input tax credit is claimed)</option><option value="recoverable" ${s.ad_gst === 'recoverable' ? 'selected' : ''}>Recoverable — not counted as a cost</option></select>
      <p class="field-hint">Applies to the actual spend entered under Ads Intelligence. Ask your accountant if unsure.</p></div>
    <p class="mt-3 text-sm" data-fee-now>Currently: <b>${s.description}</b>.</p>
    ${can.adjust ? html`<div class="form-actions"><button type="submit" class="btn btn-primary" id="fs-save">${icon('check', 'icon-sm')}<span>Save</span></button></div>` : html`<p class="field-hint mt-3">Only an owner or manager can change these settings.</p>`}
  </form>`;
}

async function saveSettings(form) {
  await withBusy($('#fs-save'), async () => {
    clearFieldErrors(form);
    try {
      const body = { fee_mode: form.elements.fee_mode.value, fee_percent: form.elements.fee_percent.value, fee_fixed: form.elements.fee_fixed.value, ad_gst: form.elements.ad_gst.value };
      const d = await put('finance/settings', body, { idempotency: uuid() });
      $('[data-fee-now] b', form).textContent = d.settings.description;
      form.elements.fee_percent.value = d.settings.fee_percent;
      form.elements.fee_fixed.value = d.settings.fee_fixed;
      ok('Settings saved', 'Stored days are recomputed with the new rules.');
      fin = null;
    } catch (e) {
      if (e.fields) showFieldErrors(form, e.fields); else fail('Could not save', e.message);
    }
  });
}

// ── loading ───────────────────────────────────────────────────────────────────
async function loadTab({ silent = false } = {}) {
  const my = ++seq;
  paintTabs();
  if (!silent || !loaded) root().innerHTML = String(skeletonTable(6));
  root().setAttribute('aria-busy', 'true');
  chart?.destroy();
  chart = null;
  try {
    if (['overview', 'services'].includes(state.tab)) {
      const q = { range: state.range, platform: state.platform, service: state.service, mode: state.mode, payment: state.payment };
      if (state.range === 'custom') { q.from = state.from; q.to = state.to; }
      const d = await get('finance', q);
      if (my !== seq) return;
      fin = d.finance;
      can = d.can;
      fillSelects(fin.meta);
      paintFilters();
      $('#fin-status').innerHTML = state.tab === 'overview' ? String(statusBanner(fin.labels)) : '';
      root().innerHTML = String(state.tab === 'overview' ? overviewHtml(fin) : servicesHtml(fin));
      if (state.tab === 'overview') {
        drawTrend(fin);
        $$('.legend i[data-c]', root()).forEach((i) => { i.style.background = COLORS[i.dataset.c]; });      // a style ATTRIBUTE would break the strict CSP; the CSSOM does not
      }
    } else if (state.tab === 'costs') {
      $('#fin-status').innerHTML = '';
      const d = await get('finance/expenses', { category: state.category, include_voided: state.voided ? '1' : '', page: state.page });
      if (my !== seq) return;
      can = d.can;
      root().innerHTML = String(expensesHtml(d));
      paintAdd();
    } else if (state.tab === 'reconcile') {
      $('#fin-status').innerHTML = '';
      const d = await get('finance/reconciliation');
      if (my !== seq) return;
      can = d.can;
      root().innerHTML = String(reconcileHtml(d));
      const n = $('#fin-recon-count');
      n.hidden = !d.open;
      n.textContent = String(d.open);
    } else {
      $('#fin-status').innerHTML = '';
      const d = await get('finance/settings');
      if (my !== seq) return;
      can = d.can;
      root().innerHTML = String(settingsHtml(d.settings));
      autoClearErrors($('#fin-settings'));
    }
    loaded = true;
    writeUrl();
  } catch (e) {
    if (my !== seq) return;
    if (!loaded) root().innerHTML = errorState(e.message).toString(); else fail('Could not refresh', e.message);
  } finally {
    if (my === seq) root().setAttribute('aria-busy', 'false');
  }
}

function setTab(t) {
  if (!TABS.includes(t) || t === state.tab) return Promise.resolve();
  state.tab = t;
  state.page = 1;
  return loadTab();
}

// ── boot ──────────────────────────────────────────────────────────────────────
export default function init() {
  const q = new URLSearchParams(location.search);
  state.tab = TABS.includes(q.get('tab')) ? q.get('tab') : 'overview';
  state.range = ['today', '7d', '30d', '90d', 'lifetime', 'custom'].includes(q.get('range')) ? q.get('range') : '30d';
  state.from = /^\d{4}-\d{2}-\d{2}$/.test(q.get('from') || '') ? q.get('from') : '';
  state.to = /^\d{4}-\d{2}-\d{2}$/.test(q.get('to') || '') ? q.get('to') : '';
  if (state.range === 'custom' && !(state.from && state.to)) state.range = '30d';
  for (const k of ['platform', 'service', 'mode', 'payment']) state[k] = q.get(k) || '';
  state.category = q.get('category') || '';
  state.voided = q.get('voided') === '1';
  state.page = Math.max(1, Number(q.get('page')) || 1);

  const tabs = $('#fin-tabs');
  on(tabs, 'click', '[data-tab]', (e, b) => setTab(b.dataset.tab));
  tabs.addEventListener('keydown', (e) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    const list = $$('[data-tab]', tabs);
    const i = list.findIndex((b) => b.dataset.tab === state.tab);
    const next = e.key === 'Home' ? 0 : e.key === 'End' ? list.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + list.length) % list.length;
    e.preventDefault();
    setTab(list[next].dataset.tab).then(() => list[next].focus());
  });
  on(document, 'click', '[data-goto]', (e, b) => setTab(b.dataset.goto));

  on($('#fin-range'), 'click', '[data-range]', (e, b) => {
    state.range = b.dataset.range;
    if (state.range === 'custom') {
      state.from ||= fin?.meta.range.from || today();
      state.to ||= fin?.meta.range.to || today();
      paintFilters();
      return;
    }
    loadTab({ silent: true });
  });
  $('#fin-apply').addEventListener('click', () => {
    state.from = $('#fin-from').value;
    state.to = $('#fin-to').value;
    if (!state.from || !state.to) { warn('Choose both dates'); return; }
    loadTab({ silent: true });
  });
  for (const [id, k] of [['fin-platform', 'platform'], ['fin-service', 'service'], ['fin-mode', 'mode'], ['fin-payment', 'payment']]) {
    $('#' + id).addEventListener('change', (e) => { state[k] = e.target.value; loadTab({ silent: true }); });
  }
  $('#fin-reset').addEventListener('click', () => { for (const k of ['platform', 'service', 'mode', 'payment']) state[k] = ''; loadTab({ silent: true }); });

  const r = root();
  on(r, 'click', '#stmt-cols [data-col]', (e, b) => { state.col = b.dataset.col; $$('#stmt-cols [data-col]').forEach((x) => x.setAttribute('aria-pressed', String(x === b))); $('.stmt')?.setAttribute('data-col', state.col); });
  on(r, 'click', '[data-category]', (e, b) => { state.category = b.dataset.category; state.page = 1; loadTab({ silent: true }); });
  on(r, 'change', '#fe-voided', (e, c) => { state.voided = c.checked; state.page = 1; loadTab({ silent: true }); });
  on(r, 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); loadTab({ silent: true }); });
  on(r, 'click', '[data-retry]', () => loadTab());
  on(r, 'click', '[data-void-expense]', async (e, b) => { if (await voidExpense(Number(b.dataset.voidExpense))) loadTab({ silent: true }); });
  on(r, 'click', '[data-fix]', (e, b) => runFix(b.dataset.fix, b.textContent.replace(/…$/, '').trim(), b));
  r.addEventListener('submit', (e) => { if (e.target.id === 'fin-settings') { e.preventDefault(); saveSettings(e.target); } });
  $('#fin-add')?.addEventListener('click', async () => { if (await addExpense()) loadTab({ silent: true }); });

  loadTab();
}
