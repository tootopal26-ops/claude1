// Admin → Ads Intelligence: what advertising cost, who came, who paid — per campaign. Every figure arrives from the server as integer paise or a count;
// the page labels where spend comes from (actual or a CPC estimate) and how far a ratio can be trusted, and never calls attributed revenue "incremental".
import { $, $$, html, raw, icon, on, url, uuid } from '../core/dom.js';
import { inr, num, tenths, inrCompact, fmtDateTime, timeAgo } from '../core/format.js';
import { get, post, put } from './net.js';
import { lineChart } from './charts.js';
import { skeletonTable, emptyState, errorState, pager, ok, fail, warn, withBusy, showFieldErrors, clearFieldErrors, autoClearErrors, formDialog } from './ui.js';
import { confirmDialog } from '../core/dialog.js';

const TABS = ['overview', 'campaigns', 'spend', 'settings'];
const SOURCE = {
  actual: ['actual spend', 'chip-ok', 'Billed spend entered by an admin'],
  estimated: ['CPC estimate', 'chip-info', 'Qualified visitors × CPC — not billed spend'],
  mixed: ['actual + estimate', 'chip-warn', 'Some days have actual spend, the others use the CPC estimate'],
  none: ['no spend', 'chip-gray', 'No spend recorded'],
};
const CONF = { high: ['high confidence', 'chip-ok'], medium: ['medium confidence', 'chip-info'], low: ['low confidence', 'chip-warn'] };
const COLORS = { spend: '#f59e0b', revenue: '#16a34a', qualified: '#2563eb', orders: '#7c3aed' };

const state = { tab: 'overview', range: '30d', from: '', to: '', campaign: '', voided: false, page: 1, scamp: '' };
let ads = null;
let can = { manage: false };
let chartA = null;
let chartB = null;
let seq = 0;
let loaded = false;
const root = () => $('#ads-root');

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
  if (['overview', 'campaigns'].includes(state.tab)) {
    if (state.range !== '30d') q.set('range', state.range);
    if (state.range === 'custom') { q.set('from', state.from); q.set('to', state.to); }
    if (state.campaign) q.set('campaign', state.campaign);
  }
  if (state.tab === 'spend') {
    if (state.scamp) q.set('campaign', state.scamp);
    if (state.voided) q.set('voided', '1');
    if (state.page > 1) q.set('page', String(state.page));
  }
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

// ── small renderers ───────────────────────────────────────────────────────────
const money = (p) => (p === null || p === undefined ? '—' : inr(p));
const signed = (p) => html`<span class="${p < 0 ? 'amt-neg' : ''}">${inr(p)}</span>`;
const pctText = (t) => (t === null || t === undefined ? '—' : tenths(t) + '%');
const srcChip = (s) => { const [l, c, t] = SOURCE[s] || SOURCE.none; return html`<span class="chip ${c}" title="${t}">${l}</span>`; };
const confChip = (c) => { const [l, cls] = CONF[c.level] || CONF.low; return html`<span class="chip ${cls}" title="${c.why}">${l}</span>`; };
const defOf = (k) => ads?.definitions?.[k] || '';

const kpi = (tone, ico, label, value, sub, chip = '', key = '') => html`<article class="kpi" data-tone="${tone}" aria-label="${label}" ${key ? html`data-kpi="${key}"` : ''}>
  <div class="kpi-top"><span class="kpi-label">${icon(ico)}<span>${label}</span></span></div>
  <div class="kpi-row"><div class="kpi-value">${value}</div></div><div class="kpi-sub kpi-sub-wrap" title="${sub}">${sub}</div>${chip ? html`<div class="mt-1">${chip}</div>` : ''}</article>`;

// ── filters ───────────────────────────────────────────────────────────────────
function paintFilters() {
  const bar = $('#ads-filters');
  bar.hidden = !['overview', 'campaigns'].includes(state.tab);
  $$('#ads-range [data-range]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.range === state.range)));
  $('#ads-custom').hidden = state.range !== 'custom';
  $('#ads-campaign').value = state.campaign;
  $('#ads-from').value = state.from;
  $('#ads-to').value = state.to;
  $('#ads-reset').hidden = !state.campaign;
  if (ads) { $('#ads-from').max = ads.meta.today; $('#ads-to').max = ads.meta.today; $('#ads-from').min = ads.meta.first_day; }
}

function fillCampaigns(m) {
  const sel = $('#ads-campaign');
  sel.replaceChildren(new Option('All campaigns', ''));
  m.campaigns.forEach((c) => sel.append(new Option(c.label, c.key)));
  if (state.campaign && !m.campaigns.some((c) => c.key === state.campaign)) sel.append(new Option(state.campaign, state.campaign));
  sel.value = state.campaign;
}

const paintButtons = () => {
  $('#ads-add').hidden = state.tab !== 'spend' || !can.manage;
  $('#ads-import').hidden = state.tab !== 'spend' || !can.manage;
};

function paintTabs() {
  paintButtons();
  $$('#ads-tabs [data-tab]').forEach((b) => {
    const on_ = b.dataset.tab === state.tab;
    b.setAttribute('aria-selected', String(on_));
    b.tabIndex = on_ ? 0 : -1;
  });
  root().setAttribute('aria-labelledby', 'ads-tab-' + state.tab);
  paintFilters();
}

// ── Overview ──────────────────────────────────────────────────────────────────
function notesHtml(a) {
  const n = a.notes;
  if (!n.length) return '';
  const unattributed = a.coverage.unattributed_orders > 0;
  return html`<div class="callout callout-info mb-4" role="note">${icon('info')}<div class="min-w-0"><strong>What to know about these numbers</strong>
    <ul class="mt-1 list-disc pl-5 grid gap-0.5">${n.map((x) => html`<li>${x}</li>`)}</ul></div>
    ${unattributed ? html`<div class="callout-actions"><button type="button" class="btn btn-sm" data-goto="settings">Open Settings</button></div>` : ''}</div>`;
}

function cardsHtml(a) {
  const c = a.cards;
  const camp = a.meta.campaign;
  const scope = camp ? `“${camp}”` : 'whole shop';
  const sp = (b) => b.paise;
  return html`<div class="kpi-grid kpi-grid-ads mb-4">
    ${kpi('amber', 'megaphone', 'Spend today', money(sp(c.spend_today)), `today · ${scope}`, srcChip(c.spend_today.source), 'spend-today')}
    ${kpi('amber', 'megaphone', 'Spend lifetime', money(sp(c.spend_lifetime)), `${num(c.spend_lifetime.actual_days)} of ${num(c.spend_lifetime.days)} days have actual spend · ${scope}`, srcChip(c.spend_lifetime.source), 'spend-life')}
    ${kpi('amber', 'megaphone', 'Spend in period', money(sp(c.spend_period)), `${rangeText(a.meta)} · ${scope}`, srcChip(c.spend_period.source), 'spend-period')}
    ${kpi('slate', 'mouse-pointer-click', 'Visits', num(c.sessions), `${num(c.page_views)} page views · ${num(c.engaged_sessions)} engaged · ${num(c.returning_sessions)} returning`, '', 'visits')}
    ${kpi('blue', 'users', 'Qualified visitors', num(c.qualified), `of ${num(c.visitors)} visitors (one per day) · stayed ${a.meta.qualify_seconds} s`, '', 'qualified')}
    ${kpi('blue', 'shopping-cart', 'Checkout starts', num(c.checkout_starts), 'visitors who created an order', '', 'checkouts')}
    ${kpi('green', 'badge-check', 'Verified customers', num(c.customers), `${num(c.orders)} verified order${c.orders === 1 ? '' : 's'} credited`, '', 'customers')}
    ${kpi('blue', 'percent', 'Conversion rate', pctText(c.conversion_tenths), c.conversion_tenths === null ? 'no qualified visitors in this period' : 'verified customers ÷ qualified visitors', '', 'conversion')}
    ${kpi('violet', 'indian-rupee', 'Cost per customer', money(c.cost_per_customer), c.cost_per_customer === null ? 'needs spend and a verified customer' : 'advertising cost ÷ verified customers', confChip(c.confidence), 'cpcust')}
    ${kpi('green', 'indian-rupee', 'Attributed revenue', money(c.attributed_revenue), `after ${inr(c.attributed_refunds)} refunded · credit under the ${a.meta.attribution.label} model, not incremental`, '', 'revenue')}
    ${kpi(c.contribution_after_ads < 0 ? 'red' : 'violet', 'trending-up', 'Contribution after ads', signed(c.contribution_after_ads), `after ${inr(c.attributed_costs)} order costs and ${inr(c.advertising_used)} advertising`, confChip(c.confidence), 'contribution')}
    ${kpi('slate', 'shield-check', 'Source & confidence', c.confidence.level.charAt(0).toUpperCase() + c.confidence.level.slice(1), c.confidence.why, '', 'confidence')}
  </div>`;
}

function trendHtml() {
  return html`<div class="grid-2 mb-4">
    <section class="card" aria-labelledby="h-trend-money"><div class="card-head"><h2 class="card-title" id="h-trend-money">${icon('trending-up')}Spend and attributed revenue</h2></div>
      <div data-chart="money" aria-label="Spend and attributed revenue over time"></div>
      <div class="legend" aria-hidden="true">${[['spend', 'Advertising spend'], ['revenue', 'Attributed revenue']].map(([k, l]) => html`<span><i data-c="${k}"></i>${l}</span>`)}</div></section>
    <section class="card" aria-labelledby="h-trend-people"><div class="card-head"><h2 class="card-title" id="h-trend-people">${icon('users')}Qualified visitors and verified orders</h2></div>
      <div data-chart="people" aria-label="Qualified visitors and verified orders over time"></div>
      <div class="legend" aria-hidden="true">${[['qualified', 'Qualified visitors'], ['orders', 'Verified orders']].map(([k, l]) => html`<span><i data-c="${k}"></i>${l}</span>`)}</div></section>
  </div>`;
}

function definitionsHtml(a) {
  return html`<details class="fold"><summary>How these numbers are defined</summary><dl class="dl mt-2">${Object.entries(a.definitions).map(([k, v]) => html`<dt>${k.replace(/_/g, ' ')}</dt><dd>${v}</dd>`)}</dl>
    <p class="mt-2 text-[13px] text-muted">Attribution: <b>${a.meta.attribution.label}</b>, ${a.meta.attribution.window_days}-day window — the window and model used are stored with every credited order. The CPC estimate uses ${inr(a.meta.cpc_paise, true)} per qualified visitor; change it on the <a href="${url('/admin/advertising')}">Advertising</a> page.</p></details>`;
}

function overviewHtml(a) {
  return html`${notesHtml(a)}${cardsHtml(a)}${trendHtml()}${definitionsHtml(a)}`;
}

function drawTrends(a) {
  chartA?.destroy(); chartB?.destroy(); chartA = chartB = null;
  const span = a.meta.range.days - 1;                                   // the server buckets by the same rule: ≤ 120 days by day, ≤ 800 by week, longer by month
  const bucket = span <= 120 ? 'day' : span <= 800 ? 'week' : 'month';
  const pts = a.trend.map((s) => ({ label: labelFor(bucket, s.key), title: titleFor(bucket, s.key) }));
  const col = (k) => a.trend.map((s) => s[k]);
  const hostA = $('[data-chart="money"]', root());
  const hostB = $('[data-chart="people"]', root());
  if (hostA) {
    chartA = lineChart(hostA, {
      points: pts, yFormat: (v) => inrCompact(v), height: 240, floorMax: 5000, ariaLabel: 'Advertising spend and attributed revenue over time', emptyText: 'No spend or credited orders in this period.',
      series: [
        { id: 'revenue', label: 'Attributed revenue', color: COLORS.revenue, values: col('revenue'), type: 'area', format: (v) => inr(v) },
        { id: 'spend', label: 'Advertising spend', color: COLORS.spend, values: col('spend'), type: 'line', format: (v) => inr(v) },
      ],
    });
  }
  if (hostB) {
    chartB = lineChart(hostB, {
      points: pts, yFormat: (v) => String(v), height: 240, floorMax: 4, ariaLabel: 'Qualified visitors and verified orders over time', emptyText: 'No visitors in this period yet.',
      series: [
        { id: 'qualified', label: 'Qualified visitors', color: COLORS.qualified, values: col('qualified'), type: 'area', format: (v) => num(v) },
        { id: 'orders', label: 'Verified orders', color: COLORS.orders, values: col('orders'), type: 'line', format: (v) => num(v) },
      ],
    });
  }
  $$('.legend i[data-c]', root()).forEach((i) => { i.style.background = COLORS[i.dataset.c]; });      // a style ATTRIBUTE would break the strict CSP; the CSSOM does not
}

// ── Campaigns ─────────────────────────────────────────────────────────────────
function campaignsHtml(a) {
  const rows = a.campaigns;
  if (!rows.length) return html`<h2 class="sr-only">Campaigns</h2><div class="tbl-wrap">${emptyState({ ico: 'target', title: 'No campaigns in this period', text: 'Tag your ad links with utm_campaign (for example ?utm_source=meta&utm_campaign=diwali-reels) and visits, spend and orders appear here by campaign.' })}</div>`;
  const special = (k) => k === a.meta.none_label || k === a.meta.day_total_label;
  return html`<section class="card" aria-labelledby="h-camps"><div class="card-head"><h2 class="card-title" id="h-camps">${icon('target')}Campaigns · ${rangeText(a.meta)}</h2><span class="card-sub">${a.meta.timezone_label}</span></div>
    <div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Campaign comparison"><table class="tbl tbl-resp tbl-dense" id="ads-campaigns">
      <thead><tr><th scope="col">Campaign</th><th scope="col" class="num">Spend</th><th scope="col" class="num">Qualified</th><th scope="col" class="num">Checkouts</th>
        <th scope="col" class="num">Customers</th><th scope="col" class="num">Conversion</th><th scope="col" class="num">Cost / customer</th><th scope="col" class="num">Attributed revenue</th><th scope="col" class="num">After ads</th></tr></thead>
      <tbody>${rows.map((r) => html`<tr data-campaign="${r.key}">
        <td data-label="Campaign" class="span2"><span class="cell-main"><b>${r.label}</b><small>${special(r.key) ? '' : html`<button type="button" class="btn btn-sm btn-ghost" data-focus="${r.key}" aria-label="Show ${r.label} on the Overview">${icon('arrow-up-right', 'icon-sm')}<span>Details</span></button>`}</small></span></td>
        <td data-label="Spend" class="num"><span class="cell-main"><b>${inr(r.spend_used)}</b><small>${srcChip(r.spend_source)}</small></span></td>
        <td data-label="Qualified" class="num"><span class="cell-main"><b>${num(r.qualified_visitors)}</b><small>of ${num(r.visitors)} · ${num(r.engaged)} engaged</small></span></td>
        <td data-label="Checkouts" class="num">${num(r.checkout_starts)}</td>
        <td data-label="Customers" class="num"><span class="cell-main"><b>${num(r.customers)}</b><small>${num(r.orders)} order${r.orders === 1 ? '' : 's'}</small></span></td>
        <td data-label="Conversion" class="num">${pctText(r.conversion_tenths)}</td>
        <td data-label="Cost / customer" class="num">${money(r.cost_per_customer)}</td>
        <td data-label="Attributed revenue" class="num"><span class="cell-main"><b>${inr(r.attributed_revenue)}</b><small>${r.attributed_refunds ? `after ${inr(r.attributed_refunds)} refunded` : 'no refunds'}</small></span></td>
        <td data-label="After ads" class="num"><span class="cell-main"><b>${signed(r.contribution_after_ads)}</b><small>${confChip(r.confidence)}</small></span></td></tr>`)}</tbody></table></div></div>
    <p class="mt-3 text-[13px] text-muted">Visitors, spend and checkout starts are counted per visitor-day (the dashboard's unit) and add up to the Overview cards; a visit is a session, which is not a person. A customer who bought through two campaigns appears in both rows — the Overview counts them once. “${a.meta.none_label}” is traffic without a campaign tag; “${a.meta.day_total_label}” is real spend entered without a campaign, so it has no visitors of its own. <b>After ads</b> = attributed revenue − the orders' own costs − the campaign's advertising; the confidence label under it says how far to trust it (hover for why). Attributed revenue is credit under the ${a.meta.attribution.label} model, not incremental revenue. Select Details to see all of a campaign's figures — visits, page views, returning visits, CPC — on the Overview.</p>
  </section>`;
}

// ── Spend ─────────────────────────────────────────────────────────────────────
function spendHtml(d) {
  const campLabel = (c) => (c === '' ? 'not split by campaign' : c);
  const toolbar = html`<section class="panel toolbar" aria-label="Filters"><div class="chips chips-flow" role="group" aria-label="Campaign">
      ${[['', 'All'], ...d.campaigns.map((c) => [c === '' ? '(none)' : c, campLabel(c)])].map(([k, l]) => html`<button type="button" class="fchip" data-scamp="${k}" aria-pressed="${state.scamp === k}">${l}</button>`)}</div>
    <div class="toolbar-row"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" id="as-voided" ${state.voided ? 'checked' : ''}><span>Show voided entries</span></label>
      <span class="ml-auto text-sm text-muted">Total of what is shown (voided excluded): <b class="text-ink">${inr(d.sum_paise)}</b> + ${inr(d.gst_paise)} GST</span></div></section>`;
  const table = d.rows.length ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Advertising spend entries"><table class="tbl tbl-resp" id="ads-spend">
      <thead><tr><th scope="col">Date</th><th scope="col">Campaign</th><th scope="col">Platform</th><th scope="col" class="num">Amount</th><th scope="col" class="num">Clicks</th><th scope="col">Source</th><th scope="col">Entered</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map((e) => html`<tr class="${e.voided ? 'is-void' : ''}" data-spend="${e.id}">
        <td data-label="Date">${longDay(e.day)}</td>
        <td data-label="Campaign"><span class="cell-main"><b>${e.campaign || 'not split'}</b>${e.voided ? html`<small><span class="chip chip-gray">voided</span></small>` : ''}</span></td>
        <td data-label="Platform">${e.platform || '—'}</td>
        <td data-label="Amount" class="num"><span class="cell-main"><b>${inr(e.amount_paise, true)}</b><small>${e.gst_paise ? `+ ${inr(e.gst_paise, true)} GST` : 'no GST entered'}</small></span></td>
        <td data-label="Clicks" class="num">${e.clicks === null ? '—' : html`<span class="cell-main"><b>${num(e.clicks)}</b><small>${e.impressions === null ? '' : num(e.impressions) + ' impressions'}</small></span>`}</td>
        <td data-label="Source"><span class="chip ${e.source === 'import' ? 'chip-info' : 'chip-gray'}">${e.source === 'import' ? 'imported' : 'typed in'}</span></td>
        <td data-label="Entered"><span class="cell-main"><b>${e.by || 'an admin'}</b><small>${timeAgo(e.at)}${e.note ? ' · ' + e.note : ''}</small></span></td>
        <td class="row-actions-cell">${can.manage && !e.voided ? html`<button type="button" class="btn btn-sm" data-void-spend="${e.id}">Void…</button>` : ''}</td></tr>`)}</tbody></table></div>
      ${pager({ page: d.page, pages: d.pages, total: d.total, per: d.per }, 'entries')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'receipt', title: 'No spend entered', text: 'Until you enter what the ad platform billed, advertising cost is the CPC estimate (qualified visitors × CPC). Enter a day and the estimate for that day is replaced — never added.' })}</div>`;
  return html`<h2 class="sr-only">Advertising spend entries</h2>${toolbar}${table}
    <p class="mt-3 text-[13px] text-muted">Enter spend per day, campaign and platform; the same combination replaces the earlier entry (the old value stays in the audit log). A day with any actual spend carries no CPC estimate. An entry is never deleted — a wrong one is voided with a reason. GST is kept apart; whether it counts as a cost is set under Finance → Settings.</p>`;
}

function addSpend() {
  const key = uuid();
  return formDialog({
    title: 'Add advertising spend', confirmText: 'Save',
    message: 'What the ad platform actually billed for one day. The same day + campaign + platform replaces the earlier entry.',
    fields: [
      { name: 'day', label: 'Date', type: 'date', value: today(), required: true },
      { name: 'campaign', label: 'Campaign (utm_campaign)', max: 80, placeholder: 'e.g. diwali-reels — empty = the day’s total', hint: 'Use the same words as in your ad links so spend and visits meet.' },
      { name: 'platform', label: 'Ad platform', max: 24, placeholder: 'e.g. meta, google' },
      { name: 'amount', label: 'Amount (₹, before GST)', required: true, inputmode: 'decimal', placeholder: 'e.g. 1250', hint: '0 is allowed: nothing was spent.' },
      { name: 'gst', label: 'GST (₹, optional)', inputmode: 'decimal', placeholder: 'e.g. 225' },
      { name: 'clicks', label: 'Billable clicks (optional)', inputmode: 'numeric', hint: 'Only what the platform reported — without it there is no CPC.' },
      { name: 'impressions', label: 'Impressions (optional)', inputmode: 'numeric' },
      { name: 'note', label: 'Note (optional)', max: 255 },
    ],
    submit: async (v) => { const r = await post('ads/spend', v, { idempotency: key }); ok('Spend saved', `${inr(r.entry.amount_paise, true)} for ${longDay(r.entry.day)}${r.entry.campaign ? ' · ' + r.entry.campaign : ''}.`); },
  });
}

async function importSpend() {
  let dry = null;
  const values = await formDialog({
    title: 'Import a spend report', confirmText: 'Check it',
    message: 'Paste lines from your ad platform’s report: date, campaign, platform, spend, GST, clicks, impressions — comma, semicolon or tab separated, header line optional. Nothing is saved until you have seen the check.',
    fields: [{ name: 'csv', label: 'The report', type: 'textarea', required: true, rows: 9, max: 60000, placeholder: '2026-10-15,diwali-reels,meta,1250.00,225.00,310,18000' }],
    submit: async (v) => { dry = (await post('ads/spend/import', { csv: v.csv, dry_run: true }, { idempotency: uuid() })).result; },
  });
  if (!values || !dry) return false;
  const yes = await confirmDialog({ title: 'Import these lines?', message: dry.message.replace('Checked: would save', 'This will save').replace(' Nothing was changed.', ''), confirmText: 'Import' });
  if (!yes) return false;
  try {
    const r = (await post('ads/spend/import', { csv: values.csv, dry_run: false }, { idempotency: uuid() })).result;
    ok('Imported', r.message);
    return true;
  } catch (e) { fail('Could not import', e.message); return false; }
}

async function voidSpend(id) {
  return formDialog({
    title: 'Void this entry?', confirmText: 'Void entry', tone: 'danger',
    message: 'The entry stays visible (marked void) and stops counting; the CPC estimate comes back for that day if nothing else was entered. Say why — it is kept in the audit log.',
    fields: [{ name: 'reason', label: 'Why is it wrong?', type: 'textarea', required: true, max: 200 }],
    submit: async (v) => { await post(`ads/spend/${id}/void`, { reason: v.reason }, { idempotency: uuid() }); ok('Entry voided'); },
  });
}

// ── Settings ──────────────────────────────────────────────────────────────────
function settingsHtml(s) {
  const ro = can.manage ? '' : 'disabled';
  const a = s.attribution;
  return html`<h2 class="sr-only">Settings</h2><form class="card" id="ads-settings" novalidate aria-labelledby="h-attr">
      <div class="card-head"><h2 class="card-title" id="h-attr">${icon('target')}Attribution</h2></div>
      <p class="text-sm text-ink-soft mb-3">When a payment is <b>verified</b>, the order is credited to one campaign — decided once, on the server, from the visits it recorded earlier, and stored with the model and window used. Nothing the browser sends afterwards can change it.</p>
      <dl class="dl mb-3"><dt>Model</dt><dd><b>Last non-direct touch</b> — of the visitor’s visits that started inside the window before the payment, the most recent one that came through a campaign tag, a source tag or a referring site gets the credit; none → direct.</dd></dl>
      <div class="field field-narrow"><label class="label" for="as-window">Attribution window (days)</label>
        <input class="input" id="as-window" name="window_days" inputmode="numeric" value="${a.window_days}" ${ro}><p class="field-hint">1 to 90 days. It applies to orders paid from now on; each order keeps the window it was credited under.</p></div>
      ${can.manage ? html`<div class="form-actions"><button type="submit" class="btn btn-primary" id="as-save">${icon('check', 'icon-sm')}<span>Save</span></button></div>` : html`<p class="field-hint mt-3">Only an owner or manager can change this.</p>`}
    </form>
    <section class="card mt-4" aria-labelledby="h-miss"><div class="card-head"><h2 class="card-title" id="h-miss">${icon('list-checks')}Orders without attribution</h2></div>
      ${a.missing > 0
        ? html`<p class="text-sm text-ink-soft"><b data-missing>${num(a.missing)}</b> verified order${a.missing === 1 ? ' has' : 's have'} no attribution row — paid before attribution was recorded, or missed because the process stopped after the payment. The same server-side visit records can credit them now, under the current window; nothing already credited is touched.</p>
          ${can.manage ? html`<div class="mt-3"><button type="button" class="btn btn-primary btn-sm" data-backfill>${icon('check', 'icon-sm')}<span>Attribute them…</span></button></div>` : ''}`
        : html`<p class="text-sm text-ink-soft" data-missing-none>Every verified order has been credited to a campaign (or to “direct”).</p>`}
    </section>
    <section class="card mt-4" aria-labelledby="h-cost"><div class="card-head"><h2 class="card-title" id="h-cost">${icon('indian-rupee')}Where cost comes from</h2></div>
      <p class="text-sm text-ink-soft"><b>CPC estimate:</b> ${inr(s.cpc.cpc_paise, true)} per qualified visitor (a visitor who stayed ${s.cpc.qualify_seconds} seconds), used for every day without actual spend. Change it on the <a href="${url('/admin/advertising')}">Advertising</a> page.</p>
      <p class="mt-2 text-sm text-ink-soft"><b>GST on actual spend:</b> ${s.ad_gst === 'cost' ? 'counted as a cost' : 'recoverable — not counted'}. Change it under <a href="${url('/admin/finance?tab=settings')}">Finance → Settings</a>.</p>
    </section>`;
}

async function saveSettings(form) {
  await withBusy($('#as-save'), async () => {
    clearFieldErrors(form);
    try {
      const d = await put('ads/settings', { window_days: form.elements.window_days.value.trim() }, { idempotency: uuid() });
      form.elements.window_days.value = d.attribution.window_days;
      ok('Settings saved', `Orders paid from now on are credited with a ${d.attribution.window_days}-day window.`);
    } catch (e) {
      if (e.fields) showFieldErrors(form, e.fields); else fail('Could not save', e.message);
    }
  });
}

async function runBackfill(btn) {
  await withBusy(btn, async () => {
    let dry;
    try { dry = (await post('ads/attribution/backfill', { dry_run: true }, { idempotency: uuid() })).result; } catch (e) { fail('Could not check', e.message); return; }
    if (dry.orders === 0) { ok('Nothing to attribute'); return; }
    const yes = await confirmDialog({ title: 'Attribute these orders?', message: `${dry.message} They are credited from the visit records the shop already holds, under the current window; nothing already credited changes.`, confirmText: 'Attribute them' });
    if (!yes) return;
    try {
      const r = (await post('ads/attribution/backfill', { dry_run: false }, { idempotency: uuid() })).result;
      ok('Done', r.message);
      await loadTab();
    } catch (e) { fail('Could not attribute them', e.message); }
  });
}

// ── loading ───────────────────────────────────────────────────────────────────
async function loadTab({ silent = false } = {}) {
  const my = ++seq;
  paintTabs();
  if (!silent || !loaded) root().innerHTML = String(skeletonTable(6));
  root().setAttribute('aria-busy', 'true');
  chartA?.destroy(); chartB?.destroy(); chartA = chartB = null;
  try {
    if (['overview', 'campaigns'].includes(state.tab)) {
      const q = { range: state.range, campaign: state.campaign };
      if (state.range === 'custom') { q.from = state.from; q.to = state.to; }
      const d = await get('ads', q);
      if (my !== seq) return;
      ads = d.ads;
      ads.meta.none_label = '(no campaign)';
      ads.meta.day_total_label = '(not split by campaign)';
      can = d.can;
      fillCampaigns(ads.meta);
      paintFilters();
      paintButtons();
      $('#ads-status').innerHTML = '';
      root().innerHTML = String(state.tab === 'overview' ? overviewHtml(ads) : campaignsHtml(ads));
      if (state.tab === 'overview') drawTrends(ads);
    } else if (state.tab === 'spend') {
      $('#ads-status').innerHTML = '';
      const d = await get('ads/spend', { campaign: state.scamp, include_voided: state.voided ? '1' : '', page: state.page });
      if (my !== seq) return;
      can = d.can;
      paintButtons();
      root().innerHTML = String(spendHtml(d));
    } else {
      $('#ads-status').innerHTML = '';
      const d = await get('ads/settings');
      if (my !== seq) return;
      can = d.can;
      root().innerHTML = String(settingsHtml(d));
      const n = $('#ads-missing-count');
      n.hidden = !d.attribution.missing;
      n.textContent = String(d.attribution.missing);
      const f = $('#ads-settings');
      if (f) autoClearErrors(f);
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
  if (state.tab === 'spend') state.scamp = q.get('campaign') || ''; else state.campaign = q.get('campaign') || '';
  state.voided = q.get('voided') === '1';
  state.page = Math.max(1, Number(q.get('page')) || 1);

  const tabs = $('#ads-tabs');
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

  on($('#ads-range'), 'click', '[data-range]', (e, b) => {
    state.range = b.dataset.range;
    if (state.range === 'custom') {
      state.from ||= ads?.meta.range.from || today();
      state.to ||= ads?.meta.range.to || today();
      paintFilters();
      return;
    }
    loadTab({ silent: true });
  });
  $('#ads-apply').addEventListener('click', () => {
    state.from = $('#ads-from').value;
    state.to = $('#ads-to').value;
    if (!state.from || !state.to) { warn('Choose both dates'); return; }
    loadTab({ silent: true });
  });
  $('#ads-campaign').addEventListener('change', (e) => { state.campaign = e.target.value; loadTab({ silent: true }); });
  $('#ads-reset').addEventListener('click', () => { state.campaign = ''; loadTab({ silent: true }); });

  const r = root();
  on(r, 'click', '[data-focus]', (e, b) => { state.campaign = b.dataset.focus; state.tab = 'overview'; loadTab({ silent: true }); });
  on(r, 'click', '[data-scamp]', (e, b) => { state.scamp = b.dataset.scamp; state.page = 1; loadTab({ silent: true }); });
  on(r, 'change', '#as-voided', (e, c) => { state.voided = c.checked; state.page = 1; loadTab({ silent: true }); });
  on(r, 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); loadTab({ silent: true }); });
  on(r, 'click', '[data-retry]', () => loadTab());
  on(r, 'click', '[data-void-spend]', async (e, b) => { if (await voidSpend(Number(b.dataset.voidSpend))) loadTab({ silent: true }); });
  on(r, 'click', '[data-backfill]', (e, b) => runBackfill(b));
  r.addEventListener('submit', (e) => { if (e.target.id === 'ads-settings') { e.preventDefault(); saveSettings(e.target); } });
  $('#ads-add')?.addEventListener('click', async () => { if (await addSpend()) loadTab({ silent: true }); });
  $('#ads-import')?.addEventListener('click', async () => { if (await importSpend()) loadTab({ silent: true }); });

  loadTab();
}
