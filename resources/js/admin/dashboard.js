import { $, $$, html, raw, icon, on, url, esc } from '../core/dom.js';
import { inr, num, tenths, pct, inrCompact, compact, fmtDateTime, timeAgo } from '../core/format.js';
import { store } from '../core/dom.js';
import { get } from './net.js';
import { lineChart, sparkline, donut, countUp } from './charts.js';
import { orderChip, payChip, modeChip, errorState, emptyState, whenVisible } from './ui.js';
import { fail } from '../core/toast.js';

const KEY = 'smb.admin.dash.v1';
const COLORS = { revenue: '#ff2d87', net: '#7c3aed', gross: '#16a34a', ads: '#f59e0b', smm: '#0ea5e9', orders: '#64748b', paid: '#16a34a', visitors: '#2563eb', qualified: '#ff2d87' };

const state = { range: '7d', from: '', to: '', platform: '', mode: '', payment: '', ...(store.get(KEY, {}) || {}) };
let data = null;
let charts = [];
let loadSeq = 0;
let seriesOn = { revenue: true, net: true, gross: false, ads: false, smm: false };
let revChart = null;
let revOpts = null;
const lastVals = new Map();
let platNames = {};
const platName = (slug) => platNames[slug] || String(slug).replace(/^./, (ch) => ch.toUpperCase());

// ── date helpers (YYYY-MM-DD strings are calendar days in the business time zone) ──
const dayFmt = (d, o) => new Intl.DateTimeFormat('en-IN', { timeZone: 'UTC', ...o }).format(new Date(d + 'T00:00:00Z'));
function labelFor(bucket, key) {
  if (bucket === 'hour') { const h = Number(key.slice(0, 2)); return h === 0 ? '12 AM' : h < 12 ? `${h} AM` : h === 12 ? '12 PM' : `${h - 12} PM`; }
  if (bucket === 'month') return dayFmt(key, { month: 'short', year: '2-digit' });
  return dayFmt(key, { day: '2-digit', month: 'short' });
}
function titleFor(bucket, key) {
  if (bucket === 'hour') return `${key} – ${String((Number(key.slice(0, 2)) + 1) % 24).padStart(2, '0')}:00`;
  if (bucket === 'month') return dayFmt(key, { month: 'long', year: 'numeric' });
  if (bucket === 'week') return 'Week of ' + dayFmt(key, { day: '2-digit', month: 'short', year: 'numeric' });
  return dayFmt(key, { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
}
const rangeText = (m) => (m.range.from === m.range.to ? dayFmt(m.range.from, { day: '2-digit', month: 'short', year: 'numeric' })
  : `${dayFmt(m.range.from, { day: '2-digit', month: 'short', year: 'numeric' })} – ${dayFmt(m.range.to, { day: '2-digit', month: 'short', year: 'numeric' })}`);

// ── small renderers ──
function delta(d, neutral = false) {
  if (d === null || d === undefined) return html`<span class="delta delta-up" title="No earlier figure to compare with">new</span>`;
  const a = Math.abs(d);
  const text = a >= 100000 ? '999%+' : pct(a);
  if (d === 0) return html`<span class="delta delta-flat">${icon('minus')}0%</span>`;
  const up = d > 0;
  const tone = neutral ? 'flat' : up ? 'up' : 'down';
  return html`<span class="delta delta-${tone}">${icon(up ? 'trending-up' : 'trending-down')}${text}</span>`;
}

const KPI_DEFS = [
  { id: 'revenue', label: 'Revenue', ico: 'indian-rupee', tone: 'green', fmt: 'inr' },
  { id: 'profit', label: 'Net profit', ico: 'trending-up', tone: 'violet', fmt: 'inr', signed: true },
  { id: 'orders', label: 'Orders', ico: 'package', tone: 'blue', fmt: 'num' },
  { id: 'visitors', label: 'Visitors', ico: 'users', tone: 'slate', fmt: 'num' },
  { id: 'payments', label: 'Successful payments', ico: 'circle-check', tone: 'green', fmt: 'num' },
  { id: 'ads', label: 'Ads spend', ico: 'megaphone', tone: 'amber', fmt: 'inr', neutral: true },
  { id: 'smm', label: 'SMM spend', ico: 'server', tone: 'amber', fmt: 'inr', neutral: true },
];
const fmtVal = (fmt, v) => (fmt === 'inr' ? inr(v) : num(v));

function kpiSub(scope, def, k, all) {
  const f = (v) => fmtVal(def.fmt, v);
  if (scope === 'today') {
    if (def.id === 'orders') return `${num(all.today.orders_paid)} paid`;
    if (def.id === 'visitors') return `${num(all.today.qualified)} qualified`;
    if (def.id === 'ads') return `${num(all.today.qualified)} qualified visitors`;
    return `Yesterday ${f(k.prev)}`;
  }
  if (def.id === 'orders') return `${num(all.lifetime.orders_paid)} paid`;
  if (def.id === 'visitors') return `${num(all.lifetime.qualified)} qualified`;
  return `Last 7 days ${f(k.last7)}`;
}

function kpiCard(scope, def, k, all, spark) {
  const title = scope === 'today' ? 'Change vs yesterday' : 'Last 7 days vs the 7 days before';
  const neg = def.signed && k.value < 0;
  return html`<article class="kpi" data-tone="${def.tone}" aria-label="${scope === 'today' ? 'Today' : 'Lifetime'} ${def.label}">
    <div class="kpi-top"><span class="kpi-label">${icon(def.ico)}<span>${def.label}</span></span></div>
    <div class="kpi-row"><div class="kpi-value${neg ? ' amt-neg' : ''}" data-count="${k.value}" data-fmt="${def.fmt}" data-id="${scope}-${def.id}">${fmtVal(def.fmt, k.value)}</div>
      <span title="${title}">${delta(k.delta_tenths, def.neutral)}</span></div>
    <div class="kpi-sub">${kpiSub(scope, def, k, all)}</div>
    <div class="kpi-spark" data-spark="${def.id}" data-tone="${def.tone}" aria-hidden="true"></div>
  </article>`;
}

function kpiSection(d) {
  const mk = (scope) => KPI_DEFS.map((def) => kpiCard(scope, def, d.kpis[scope][def.id], d.kpis, d.kpis.spark[def.id]));
  return html`<h2 class="sec-title"><span class="live-dot" aria-hidden="true"></span>Today</h2>
    <div class="kpi-grid" data-scope="today">${mk('today')}</div>
    <h2 class="sec-title">Lifetime</h2>
    <div class="kpi-grid" data-scope="lifetime">${mk('lifetime')}</div>`;
}

function profitCard(d) {
  const b = d.breakdown;
  const m = d.meta;
  const rev = Math.max(b.revenue, 0);
  const smmPct = rev ? Math.min(100, Math.round((b.smm / rev) * 100)) : 0;
  const adsPct = rev ? Math.min(100 - smmPct, Math.round((b.ads / rev) * 100)) : 0;
  const netPct = Math.max(0, 100 - smmPct - adsPct);
  const neg = b.net < 0;
  const before = !m.ads_attributable;
  return html`<section class="card" aria-labelledby="h-profit">
    <div class="card-head"><h2 class="card-title" id="h-profit">${icon('circle-dollar-sign')}Profit breakdown</h2>
      <span class="card-sub">${rangeText(m)}${m.filtered ? ' · filtered' : ''}</span></div>
    <div class="eq" role="group" aria-label="Revenue minus SMM cost minus ads cost equals net profit">
      <div class="eq-item"><small>Revenue</small><strong>${inr(b.revenue)}</strong></div>
      <div class="eq-op" aria-hidden="true">−</div>
      <div class="eq-item"><small>SMM cost</small><strong>${inr(b.smm)}</strong></div>
      <div class="eq-op" aria-hidden="true">−</div>
      <div class="eq-item"><small>Ads cost</small><strong>${before ? '—' : inr(b.ads)}</strong></div>
      <div class="eq-op" aria-hidden="true">=</div>
      <div class="eq-item eq-net${neg ? ' is-neg' : ''}"><small>${before ? 'Profit before ads' : 'Net profit'} · margin ${tenths(b.margin_tenths)}%</small><strong>${inr(before ? b.gross : b.net)}</strong></div>
    </div>
    ${rev > 0 ? html`<div class="stackbar" role="img" aria-label="Where revenue goes: ${smmPct}% SMM cost, ${adsPct}% ads, ${netPct}% profit">
      <span class="bg-sky-500" data-w="${smmPct}"></span><span class="bg-amber-500" data-w="${adsPct}"></span><span class="bg-emerald-500" data-w="${netPct}"></span></div>
      <div class="legend"><span><i class="bg-sky-500"></i>SMM cost ${smmPct}%</span><span><i class="bg-amber-500"></i>Ads ${adsPct}%</span><span><i class="bg-emerald-500"></i>${before ? 'Profit' : 'Net profit'} ${netPct}%</span></div>` : ''}
    <p class="mt-3 text-[13px] text-muted">${before
      ? 'Advertising spend is tracked for the whole site, so it is not subtracted while a platform or delivery filter is on. Clear the filters to see net profit after ads.'
      : 'Manual orders count fully as profit (no provider cost). Ads cost = qualified visitors × cost per click, charged once per visitor per day.'}</p>
  </section>`;
}

function chartCard(id, title, ico, sub, extra = '') {
  return html`<section class="card" aria-labelledby="h-${id}">
    <div class="card-head"><h2 class="card-title" id="h-${id}">${icon(ico)}${title}</h2>${raw(extra || `<span class="card-sub">${esc(sub)}</span>`)}</div>
    <div data-chart="${id}" class="chart" aria-label="${title} chart"></div>
  </section>`;
}

function toggles() {
  const defs = [['revenue', 'Revenue', COLORS.revenue], ['net', 'Net profit', COLORS.net], ['gross', 'Gross profit', COLORS.gross], ['ads', 'Ads', COLORS.ads], ['smm', 'SMM', COLORS.smm]];
  return `<div class="series-toggles" role="group" aria-label="Series">${defs.map(([k, l, c]) => `<button type="button" class="stog" data-series="${k}" aria-pressed="${seriesOn[k]}"><i></i>${l}</button>`).join('')}</div>`;
}

function visitorsCard(d) {
  const v = d.visitors, t = d.totals, m = d.meta;
  const qs = m.qualify_seconds || 3;
  const fun = [
    ['Visitors', t.visitors, 100], [`Qualified (${qs} s+)`, t.qualified_visitors, null], ['Started an order', t.order_visitors, null], ['Paid', t.paid_visitors, null],
  ].map(([label, val]) => ({ label, val, w: t.visitors ? Math.max(val ? 2 : 0, Math.round((val / t.visitors) * 100)) : 0, p: t.visitors ? pct(Math.round((val / t.visitors) * 1000)) : '—' }));
  const dev = v.devices, src = v.sources;
  const devTotal = dev.desktop + dev.mobile + dev.tablet || 1;
  const srcTotal = src.instagram + src.youtube + src.direct + src.other || 1;
  const bar = (label, val, total, a, b) => html`<div class="hbar"><span class="hbar-label">${label}</span><span class="hbar-value">${num(val)} · ${pct(Math.round((val / total) * 1000))}</span>
    <div class="hbar-track"><div class="hbar-fill" data-w="${Math.round((val / total) * 100)}" data-a="${a}" data-b="${b}"></div></div></div>`;
  return html`<section class="card" aria-labelledby="h-visitors">
    <div class="card-head"><h2 class="card-title" id="h-visitors">${icon('users')}Visitor analytics</h2><span class="card-sub">Qualified = stayed on the page ${qs} seconds or more</span></div>
    <div class="stat-grid">
      <div class="stat"><small>Visitors today</small><strong>${num(v.today.visitors)}</strong><span>${num(v.today.qualified)} qualified</span></div>
      <div class="stat"><small>Lifetime visitors</small><strong>${num(v.lifetime_visitors)}</strong><span>unique browsers</span></div>
      <div class="stat"><small>Cost per click</small><strong>${inr(m.cpc_paise, true)}</strong><span><a class="inline-link" href="${url('/admin/advertising')}">change</a></span></div>
      <div class="stat"><small>Ads spend (range)</small><strong>${inr(t.ads_spend_paise)}</strong><span>${num(t.qualified_visitors)} × CPC</span></div>
      <div class="stat"><small>Visitor → order</small><strong>${pct(d.totals.conv_order_tenths)}</strong><span>${num(t.order_visitors)} of ${num(t.visitors)}</span></div>
      <div class="stat"><small>Visitor → paid</small><strong>${pct(d.totals.conv_paid_tenths)}</strong><span>${num(t.paid_visitors)} of ${num(t.visitors)}</span></div>
    </div>
    <h3 class="mt-5 mb-2 text-[13px] font-bold text-ink-soft">Conversion funnel · ${rangeText(m)}</h3>
    <div class="funnel">${fun.map((f) => html`<div class="funnel-row"><span class="truncate">${f.label}</span><div class="funnel-bar"><span data-w="${f.w}"></span></div><span class="num font-bold">${num(f.val)} <span class="font-medium text-muted">${f.p}</span></span></div>`)}</div>
    <div class="grid-2 mt-5">
      <div><h3 class="mb-2 text-[13px] font-bold text-ink-soft">Devices</h3><div class="hbars">${bar('Desktop', dev.desktop, devTotal, '#2563eb', '#38bdf8')}${bar('Mobile', dev.mobile, devTotal, '#ff2d87', '#ff3b3b')}${bar('Tablet', dev.tablet, devTotal, '#7c3aed', '#d946ef')}</div></div>
      <div><h3 class="mb-2 text-[13px] font-bold text-ink-soft">Traffic sources</h3><div class="hbars">${bar('Instagram', src.instagram, srcTotal, '#d946ef', '#ff2d87')}${bar('YouTube', src.youtube, srcTotal, '#dc2626', '#ff3b3b')}${bar('Direct', src.direct, srcTotal, '#475569', '#94a3b8')}${bar('Other sites', src.other, srcTotal, '#0ea5e9', '#38bdf8')}</div></div>
    </div>
  </section>`;
}

function perfCard(d) {
  const p = d.performance;
  const maxO = Math.max(1, ...p.top_services.map((s) => s.orders));
  const tile = (label, s, f) => html`<div class="stat stat-text"><small>${label}</small><strong>${s ? s.title : '—'}</strong><span>${s ? f(s) : 'No paid orders yet'}</span></div>`;
  const am = p.auto_vs_manual;
  const row = (label, o) => html`<div class="kv"><span>${label}</span><b>${num(o.orders)} orders · ${inr(o.revenue)} · <span class="${o.profit < 0 ? 'amt-neg' : 'amt-pos'}">${inr(o.profit)}</span></b></div>`;
  return html`<section class="card" aria-labelledby="h-perf">
    <div class="card-head"><h2 class="card-title" id="h-perf">${icon('chart-column')}Top services &amp; performance</h2><span class="card-sub">${rangeText(d.meta)}</span></div>
    <div class="stat-grid">
      ${tile('Best platform', p.best_platform && { title: platName(p.best_platform.platform), r: p.best_platform }, (s) => `${inr(s.r.revenue)} revenue`)}
      ${tile('Best seller (revenue)', p.best_revenue, (s) => `${inr(s.revenue)} · ${num(s.orders)} orders`)}
      ${tile('Most profitable', p.best_profit, (s) => `${inr(s.profit)} profit`)}
      ${tile('Lowest profit', p.lowest_profit, (s) => `${inr(s.profit)} profit`)}
      <div class="stat"><small>Average order value</small><strong>${inr(p.aov_paise)}</strong><span>${num(p.orders_paid)} paid orders</span></div>
      <div class="stat"><small>Avg profit / order</small><strong class="${p.avg_profit_paise < 0 ? 'amt-neg' : ''}">${inr(p.avg_profit_paise)}</strong><span>Order success ${pct(p.order_success_tenths)}</span></div>
    </div>
    <h3 class="mt-5 mb-2 text-[13px] font-bold text-ink-soft">Top services by orders</h3>
    ${p.top_services.length ? html`<div class="hbars">${p.top_services.map((s) => html`<div class="hbar"><span class="hbar-label">${s.title}<span class="font-medium text-muted"> · ${platName(s.platform)}</span></span><span class="hbar-value">${num(s.orders)} orders</span>
      <div class="hbar-track"><div class="hbar-fill" data-w="${Math.round((s.orders / maxO) * 100)}"></div></div>
      <span class="hbar-meta">${platName(s.platform)} · ${s.mode} · ${inr(s.revenue)} revenue · <span class="${s.profit < 0 ? 'amt-neg' : ''}">${inr(s.profit)}</span> profit (${tenths(s.margin_tenths)}%)</span></div>`)}</div>`
      : emptyState({ ico: 'chart-column', title: 'No paid orders in this range', text: 'Top services appear after the first paid orders.' })}
    <h3 class="mt-5 mb-1 text-[13px] font-bold text-ink-soft">AUTO vs MANUAL</h3>
    ${row('AUTO (SMM provider)', am.auto)}${row('MANUAL (you deliver)', am.manual)}
  </section>`;
}

function smmCard(d) {
  const s = d.smm;
  const total = s.by_provider.reduce((a, r) => a + r.spend, 0);
  const maxS = Math.max(1, ...s.by_service.map((r) => r.spend));
  return html`<section class="card" aria-labelledby="h-smm">
    <div class="card-head"><h2 class="card-title" id="h-smm">${icon('server')}SMM spend</h2><span class="card-sub">${rangeText(d.meta)}</span></div>
    ${total === 0 && !s.by_provider.length ? emptyState({ ico: 'server', title: 'No SMM spend in this range', text: 'Provider costs are recorded for AUTO orders once the provider confirms the order.' }) : html`
      <div class="kv"><span>Total provider spend</span><b>${inr(total)}</b></div>
      ${s.by_provider.map((p) => html`<div class="kv"><span>${p.name}</span><b>${inr(p.spend)} · ${num(p.orders)} orders</b></div>`)}
      <h3 class="mt-4 mb-2 text-[13px] font-bold text-ink-soft">Spend by service</h3>
      <div class="hbars">${s.by_service.map((r) => html`<div class="hbar"><span class="hbar-label">${r.name}${r.platform ? html`<span class="font-medium text-muted"> · ${platName(r.platform)}</span>` : ''}</span><span class="hbar-value">${inr(r.spend)}</span>
        <div class="hbar-track"><div class="hbar-fill" data-w="${Math.round((r.spend / maxS) * 100)}" data-a="#0ea5e9" data-b="#38bdf8"></div></div></div>`)}</div>`}
  </section>`;
}

function paymentsCard(d) {
  const p = d.payments;
  return html`<section class="card" aria-labelledby="h-pay">
    <div class="card-head"><h2 class="card-title" id="h-pay">${icon('credit-card')}Payment success</h2><span class="card-sub">${rangeText(d.meta)}</span></div>
    <div class="donut-wrap"><div data-donut aria-hidden="false"></div>
      <div class="min-w-0 flex-1">
        <div class="kv"><span><i class="legend-i bg-emerald-500"></i>Successful</span><b>${num(p.success)}</b></div>
        <div class="kv"><span><i class="legend-i bg-red-500"></i>Failed / needs review</span><b>${num(p.failed)}</b></div>
        <div class="kv"><span><i class="legend-i bg-slate-400"></i>Expired (not paid)</span><b>${num(p.expired)}</b></div>
        <div class="kv"><span>Still open</span><b>${num(p.open)}</b></div>
        <div class="kv"><span>Success rate</span><b>${pct(p.success_tenths)}</b></div>
        <div class="kv"><span>Failure rate</span><b>${pct(p.failure_tenths)}</b></div>
      </div></div>
  </section>`;
}

function recentCard(d) {
  const r = d.recent;
  const orders = r.orders.length ? html`<ul class="divide-y divide-line">${r.orders.map((o) => html`<li><a class="flex items-center justify-between gap-3 py-2.5 no-underline text-ink hover:bg-brand-50/50 -mx-2 px-2 rounded-lg" href="${url('/admin/orders')}?open=${o.id}">
    <span class="cell-main"><b>${o.public_id}</b><small>${o.service} · ${timeAgo(o.at)}</small></span>
    <span class="flex items-center gap-2 shrink-0"><b class="num text-sm">${inr(o.amount_paise)}</b>${orderChip(o.status, o.status_label)}</span></a></li>`)}</ul>`
    : emptyState({ ico: 'package', title: 'No orders yet', text: 'Orders appear here as soon as a customer starts checkout.' });
  const pays = r.payments.length ? html`<ul class="divide-y divide-line">${r.payments.map((p) => html`<li class="flex items-center justify-between gap-3 py-2.5">
    <span class="cell-main"><b>${p.order}</b><small>${p.reference ? 'UTR ' + p.reference : p.service} · ${timeAgo(p.at)}${p.late ? ' · late' : ''}</small></span>
    <span class="flex items-center gap-2 shrink-0"><b class="num text-sm">${inr(p.amount_paise)}</b>${payChip(p.status)}</span></li>`)}</ul>`
    : emptyState({ ico: 'credit-card', title: 'No payments match', text: 'Payments appear here once customers reach the payment page.' });
  const act = r.activity.length ? html`<ul class="divide-y divide-line">${r.activity.map((a) => html`<li class="py-2.5 text-sm"><b>${a.label}</b><span class="block text-xs text-muted">${a.admin}${a.entity ? ' · ' + a.entity : ''} · ${timeAgo(a.at)}</span></li>`)}</ul>`
    : emptyState({ ico: 'history', title: 'No activity yet' });
  return html`<div class="grid-3">
    <section class="card" aria-labelledby="h-ro"><div class="card-head"><h2 class="card-title" id="h-ro">${icon('package')}Recent orders</h2><a class="btn btn-sm btn-ghost" href="${url('/admin/orders')}">View all</a></div>${orders}</section>
    <section class="card" aria-labelledby="h-rp"><div class="card-head"><h2 class="card-title" id="h-rp">${icon('credit-card')}Recent payments</h2><a class="btn btn-sm btn-ghost" href="${url('/admin/payments')}">View all</a></div>${pays}</section>
    <section class="card" aria-labelledby="h-ra"><div class="card-head"><h2 class="card-title" id="h-ra">${icon('history')}Recent activity</h2><a class="btn btn-sm btn-ghost" href="${url('/admin/audit')}">Audit log</a></div>${act}</section>
  </div>`;
}

// ── painting ──
function render(d) {
  data = d;
  platNames = Object.fromEntries(d.meta.platforms.map((p) => [p.slug, p.name]));
  const root = $('#dash-root');
  const parts = [
    kpiSection(d),
    html`<h2 class="sec-title">Profit &amp; trends · ${rangeText(d.meta)}</h2>`,
    profitCard(d),
    html`<div class="stack mt-4"><section class="card" aria-labelledby="h-rev"><div class="card-head"><h2 class="card-title" id="h-rev">${icon('trending-up')}Revenue &amp; profit</h2>${raw(toggles())}</div><div data-chart="rev" aria-label="Revenue and profit over time"></div></section>
      <div class="grid-2">${chartCard('orders', 'Orders', 'package', 'Created vs paid')}${chartCard('visitors', 'Visitors', 'users', 'Visitors vs qualified')}</div></div>`,
    html`<h2 class="sec-title">Audience &amp; conversion</h2>`,
    visitorsCard(d),
    html`<h2 class="sec-title">Services &amp; costs</h2><div class="grid-2">${perfCard(d)}<div class="stack">${smmCard(d)}${paymentsCard(d)}</div></div>`,
    html`<h2 class="sec-title">Latest</h2>`,
    recentCard(d),
  ];
  root.innerHTML = parts.map(String).join('');
  root.setAttribute('aria-busy', 'false');
  root.classList.remove('is-stale');
  paint(d);
}

function paint(d) {
  const root = $('#dash-root');
  // KPI numbers + sparklines
  $$('.kpi-value', root).forEach((n) => {
    const id = n.dataset.id; const to = Number(n.dataset.count);
    n.dataset.v = String(lastVals.get(id) ?? 0);
    countUp(n, to, (v) => (n.dataset.fmt === 'inr' ? inr(v) : num(v)));
    lastVals.set(id, to);
  });
  $$('.kpi-spark', root).forEach((n) => {
    const colors = { green: '#16a34a', blue: '#2563eb', violet: '#7c3aed', amber: '#d97706', slate: '#64748b' };
    sparkline(n, d.kpis.spark[n.dataset.spark], colors[n.dataset.tone] || '#ff2d87');
  });
  // bars / widths driven from data attributes (CSS custom properties keep us CSP-clean)
  requestAnimationFrame(() => $$('[data-w]', root).forEach((n) => {
    n.style.width = n.dataset.w + '%';
    if (n.dataset.a) n.style.setProperty('--hb-a', n.dataset.a);
    if (n.dataset.b) n.style.setProperty('--hb-b', n.dataset.b);
  }));
  $$('.stog', root).forEach((b) => b.style.setProperty('--c', ({ revenue: COLORS.revenue, net: COLORS.net, gross: COLORS.gross, ads: COLORS.ads, smm: COLORS.smm })[b.dataset.series]));
  // charts
  charts.forEach((c) => c.destroy());
  charts = [];
  const bucket = d.meta.range.bucket;
  const pts = d.series.map((s) => ({ label: labelFor(bucket, s.key), title: titleFor(bucket, s.key) }));
  const col = (k) => d.series.map((s) => s[k]);
  const rupee = (v) => inr(v);
  const mk = (id, opts) => { const host = $(`[data-chart="${id}"]`, root); if (!host) return null; const c = lineChart(host, opts); charts.push(c); return c; };
  const revSeries = () => [
    { id: 'revenue', label: 'Revenue', color: COLORS.revenue, values: col('revenue'), type: 'area', format: rupee, hidden: !seriesOn.revenue },
    { id: 'net', label: 'Net profit', color: COLORS.net, values: col('net_profit'), type: 'line', format: rupee, hidden: !seriesOn.net },
    { id: 'gross', label: 'Gross profit', color: COLORS.gross, values: col('gross_profit'), type: 'line', format: rupee, hidden: !seriesOn.gross },
    { id: 'ads', label: 'Ads spend', color: COLORS.ads, values: col('ads'), type: 'line', format: rupee, hidden: !seriesOn.ads },
    { id: 'smm', label: 'SMM spend', color: COLORS.smm, values: col('smm'), type: 'line', format: rupee, hidden: !seriesOn.smm },
  ];
  revOpts = () => ({ points: pts, series: revSeries(), yFormat: (v) => inrCompact(v), height: 300, floorMax: 50000, ariaLabel: 'Revenue and profit over time', emptyText: 'No revenue in this range yet.' });
  revChart = mk('rev', revOpts());
  mk('orders', { points: pts, series: [
    { id: 'created', label: 'Orders created', color: '#94a3b8', values: col('orders'), type: 'bar', format: num },
    { id: 'paid', label: 'Orders paid', color: COLORS.paid, values: col('orders_paid'), type: 'bar', format: num },
  ], yFormat: (v) => compact(v), height: 260, floorMax: 4, ariaLabel: 'Orders created and paid', emptyText: 'No orders in this range yet.' });
  mk('visitors', { points: pts, series: [
    { id: 'visitors', label: 'Visitors', color: COLORS.visitors, values: col('visitors'), type: 'area', format: num },
    { id: 'qualified', label: 'Qualified (3 s+)', color: COLORS.qualified, values: col('qualified'), type: 'line', format: num },
    { id: 'conv', label: 'Conversion', color: '#16a34a', values: col('conversion'), tipOnly: true, format: (v) => pct(v) },
  ], yFormat: (v) => compact(v), height: 260, floorMax: 4, ariaLabel: 'Visitors and qualified visitors', emptyText: 'No visitors in this range yet.' });
  const p = d.payments;
  const dn = $('[data-donut]', root);
  if (dn) donut(dn, [{ label: 'Successful', value: p.success, color: '#16a34a' }, { label: 'Failed', value: p.failed, color: '#ef4444' }, { label: 'Expired', value: p.expired, color: '#94a3b8' }], { center: p.success + p.failed + p.expired ? pct(p.success_tenths) : '—', sub: 'success' });
}

// ── controls ──
function syncControls() {
  $$('#range-seg [data-range]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.range === state.range)));
  $('#custom-range').hidden = state.range !== 'custom';
  $('#f-platform').value = state.platform;
  $('#f-mode').value = state.mode;
  $('#f-payment').value = state.payment;
  if (data) { $('#f-from').max = data.meta.today; $('#f-to').max = data.meta.today; $('#f-from').min = data.meta.first_day; }
  $('#reset-filters').hidden = !(state.platform || state.mode || state.payment);
}

async function load({ silent = false } = {}) {
  const seq = ++loadSeq;
  const root = $('#dash-root');
  if (!silent) { root.setAttribute('aria-busy', 'true'); if (data) root.classList.add('is-stale'); }
  try {
    const q = { range: state.range, platform: state.platform, mode: state.mode, payment: state.payment };
    if (state.range === 'custom') { q.from = state.from; q.to = state.to; }
    const { dashboard } = await get('dashboard', q);
    if (seq !== loadSeq) return;       // a newer request superseded this one
    if (!$('#f-platform').dataset.filled) {
      $('#f-platform').dataset.filled = '1';
      dashboard.meta.platforms.forEach((p) => $('#f-platform').append(new Option(p.name, p.slug)));
    }
    store.set(KEY, state);
    render(dashboard);
    syncControls();
  } catch (e) {
    if (seq !== loadSeq) return;
    root.setAttribute('aria-busy', 'false');
    root.classList.remove('is-stale');
    if (!data) root.innerHTML = errorState(e.message).toString();
    else fail('Could not refresh the dashboard', e.message);
    if (e.fields?.from || e.fields?.range) { state.range = '7d'; syncControls(); }
  }
}

export default function init() {
  const root = $('#dash-root');
  syncControls();
  on(document, 'click', '#range-seg [data-range]', (e, b) => {
    state.range = b.dataset.range;
    syncControls();
    if (state.range === 'custom') {
      const t = (data?.meta.today) || new Date().toISOString().slice(0, 10);
      if (!state.from || !state.to) { state.to = t; const d = new Date(t + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() - 6); state.from = d.toISOString().slice(0, 10); }
      $('#f-from').value = state.from; $('#f-to').value = state.to;
      $('#f-from').focus();
      return;
    }
    load();
  });
  on(document, 'click', '#apply-custom', () => {
    const from = $('#f-from').value, to = $('#f-to').value;
    if (!from || !to) { fail('Choose both dates', 'Pick a start and an end date.'); return; }
    state.from = from; state.to = to; state.range = 'custom';
    load();
  });
  for (const [id, key] of [['f-platform', 'platform'], ['f-mode', 'mode'], ['f-payment', 'payment']]) {
    $('#' + id).addEventListener('change', (e) => { state[key] = e.target.value; syncControls(); load(); });
  }
  $('#reset-filters').addEventListener('click', () => { state.platform = state.mode = state.payment = ''; syncControls(); load(); });
  on(document, 'click', '[data-action="refresh"]', async (e, b) => { b.disabled = true; await load(); b.disabled = false; });
  on(root, 'click', '[data-retry]', () => load());
  on(root, 'click', '.stog', (e, b) => {
    const k = b.dataset.series;
    seriesOn[k] = !seriesOn[k];
    if (!Object.values(seriesOn).some(Boolean)) seriesOn[k] = true;   // never allow an empty chart
    $$('.stog', root).forEach((x) => x.setAttribute('aria-pressed', String(seriesOn[x.dataset.series])));
    revChart?.update(revOpts(), false);
  });
  load();
  whenVisible(() => load({ silent: true }), 60000);
}
