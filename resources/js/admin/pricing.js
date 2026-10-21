// Admin → Adaptive pricing: the experiment dashboard, the Price Experiment Simulator, Profit Guard and the AI Learning History.
// THE SOURCE OF TRUTH FOR A PRICE stays services.price_paise — an experiment only ever shows a different price to its own treatment
// cohort, and only once an owner has explicitly switched it from a dry run (an A/A test, same price to both cohorts) to live. Every
// figure on this page comes from App\Services\Pricing; nothing here is invented client-side.
import { $, $$, html, icon, on, url } from '../core/dom.js';
import { inr, num, tenths, fmtDateTime, timeAgo } from '../core/format.js';
import { get, post, put } from './net.js';
import { skeletonTable, emptyState, errorState, pager, ok, fail, warn, whenVisible, withBusy, formDialog } from './ui.js';
import { confirmDialog } from '../core/dialog.js';

const TABS = ['experiments', 'simulator', 'guard', 'history'];
const STATUS = {
  proposed: ['Proposed', 'chip-gray'], running: ['Running', 'chip-ok'], paused: ['Paused', 'chip-warn'],
  concluded: ['Concluded', 'chip-info'], rolled_back: ['Rolled back', 'chip-bad'], cancelled: ['Cancelled', 'chip-gray'],
};
const MODE = { dry_run: ['Dry run', 'chip-gray', 'Both cohorts are shown the SAME price — a safety rehearsal, zero customer impact'], live: ['Live', 'chip-info', 'The treatment cohort is shown the candidate price'] };
const DECISION = {
  winner: ['Winner', 'chip-ok'], loser: ['Loser', 'chip-bad'], inconclusive: ['Inconclusive', 'chip-gray'],
  insufficient_data: ['Not enough data', 'chip-gray'], rolled_back: ['Rolled back', 'chip-bad'],
};

const state = { tab: 'experiments', status: '', page: 1, historyPage: 1 };
let can = { propose: false, approve: false, pause: false };
let settings = null;
let seq = 0;
let loaded = false;
const root = () => $('#pr-root');

function writeUrl() {
  const q = new URLSearchParams();
  if (state.tab !== 'experiments') q.set('tab', state.tab);
  if (state.tab === 'experiments') {
    if (state.status) q.set('status', state.status);
    if (state.page > 1) q.set('page', String(state.page));
  }
  if (state.tab === 'history' && state.historyPage > 1) q.set('page', String(state.historyPage));
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

const statusChip = (s) => { const [l, c] = STATUS[s] || [s, 'chip-gray']; return html`<span class="chip ${c}">${l}</span>`; };
const modeChip = (m) => { const [l, c, t] = MODE[m] || [m, 'chip-gray', '']; return html`<span class="chip ${c}" title="${t}">${l}</span>`; };
const decisionChip = (d) => d ? (() => { const [l, c] = DECISION[d] || [d, 'chip-gray']; return html`<span class="chip ${c}">${l}</span>`; })() : html`<span class="text-muted">—</span>`;
const timeLeft = (secs) => { if (secs === null || secs === undefined) return '—'; const h = Math.floor(secs / 3600); return h >= 48 ? Math.round(h / 24) + ' d' : h >= 1 ? h + ' h' : Math.max(1, Math.round(secs / 60)) + ' min'; };

// ── summary (KPI row, shared across tabs) ──────────────────────────────────────
const kpi = (tone, ico, label, value, sub) => html`<article class="kpi" data-tone="${tone}" aria-label="${label}"><div class="kpi-top"><span class="kpi-label">${icon(ico)}<span>${label}</span></span></div>
  <div class="kpi-row"><div class="kpi-value">${value}</div></div><div class="kpi-sub">${sub}</div></article>`;

async function loadSummary() {
  try {
    const [active, guard] = await Promise.all([get('pricing/experiments', { status: 'active', page: 1 }), get('pricing/profit-guard')]);
    const live = (guard.live_experiments || []).length;
    $('#pr-summary').innerHTML = String(html`<div class="kpi-grid">
      ${kpi(active.total ? 'info' : 'gray', 'sliders-horizontal', 'Active experiments', num(active.total), active.total ? `${num(live)} live, ${num(active.total - live)} dry run / paused` : 'none running right now')}
      ${kpi(guard.global_pause ? 'red' : 'green', 'shield-alert', 'Pricing experiments', guard.global_pause ? 'Paused' : 'Active', guard.global_pause ? 'globally paused — no new assignment is made' : 'global pause is off')}
      ${kpi(guard.recent_rollbacks?.length ? 'warn' : 'gray', 'rotate-ccw', 'Automatic rollbacks', num(guard.recent_rollbacks?.length || 0), 'most recent, this list')}
      ${kpi('gray', 'percent', 'Eligible services', num(guard.services?.length || 0), 'manual-mode, adaptive pricing on')}
    </div>`);
    $('#pr-summary').setAttribute('aria-busy', 'false');
    return guard;
  } catch {
    $('#pr-summary').innerHTML = '';
    $('#pr-summary').setAttribute('aria-busy', 'false');
    return null;
  }
}

// ── tabs ──────────────────────────────────────────────────────────────────────
function paintTabs() {
  $$('#pr-tabs [data-tab]').forEach((b) => {
    const on_ = b.dataset.tab === state.tab;
    b.setAttribute('aria-selected', String(on_));
    b.tabIndex = on_ ? 0 : -1;
  });
  root().setAttribute('aria-labelledby', 'pr-tab-' + state.tab);
}

// ══════════════════════════ experiments ════════════════════════════════════

function serviceOptionLabel(s) {
  const price = inr(s.price_paise, true);
  if (s.eligible) return `${s.title} (${s.platform}) — ${price}`;
  return `${s.title} (${s.platform}) — ${price} — NOT eligible: ${s.reasons[0] || 'ineligible'}`;
}

async function openProposeDialog(prefillServiceId, prefillPrice) {
  let services;
  try { services = (await get('pricing/services')).rows; } catch (e) { fail('Could not load services', e.message); return; }
  if (!services.length) { warn('No manual services', 'Only manual-mode services can be made adaptive.'); return; }
  const s = settings || {};
  const values = await formDialog({
    title: 'New pricing experiment', confirmText: 'Create experiment', tone: 'primary',
    message: 'Starts as a PROPOSAL only — nothing changes for customers until it is started, and even then it begins as a dry run (same price to both cohorts) unless an owner starts it live.',
    fields: [
      { name: 'service_id', label: 'Service', type: 'select', value: prefillServiceId ? String(prefillServiceId) : String(services[0].id), options: services.map((x) => [String(x.id), serviceOptionLabel(x)]), required: true },
      { name: 'candidate_price', label: 'Candidate price (₹)', type: 'text', value: prefillPrice || '', placeholder: 'e.g. 49', hint: 'Must end in 4 or 9 (price-ending rule) and differ from the current price.', required: true },
      { name: 'treatment_share_pct', label: 'Treatment share (%)', type: 'number', value: String((s.default_treatment_share_bps || 5000) / 100), hint: 'Share of visitors shown the candidate price once the experiment is live.', required: true },
      { name: 'min_sample', label: 'Minimum sample per cohort', type: 'number', value: String(s.default_min_sample || 200), required: true },
      { name: 'max_duration_days', label: 'Maximum duration (days)', type: 'number', value: String(s.default_max_duration_days || 21), required: true },
      { name: 'profit_floor', label: 'Minimum contribution profit per order (₹, optional)', type: 'text', value: '', placeholder: '0', hint: 'Leave at 0 to only refuse a loss-making order.' },
    ],
    submit: async (v) => {
      await post('pricing/experiments', {
        service_id: Number(v.service_id), candidate_price: v.candidate_price, treatment_share_pct: Number(v.treatment_share_pct),
        min_sample: Number(v.min_sample), max_duration_days: Number(v.max_duration_days), profit_floor: v.profit_floor || '0',
      }, { idempotency: true });
    },
  });
  if (values) { ok('Experiment proposed', 'Start it as a dry run to begin measuring.'); state.tab = 'experiments'; state.status = ''; load(); }
}

async function openEngineSettings() {
  let d;
  try { d = await get('pricing/settings'); } catch (e) { fail('Could not load settings', e.message); return; }
  const s = d.settings;
  const pct = (bps) => String(bps / 100);
  const values = await formDialog({
    title: 'Adaptive pricing settings', confirmText: 'Save settings', tone: 'primary',
    message: 'Defaults used for a new experiment, and the thresholds the automatic rollback checks use. The global pause switch itself lives on the Profit Guard tab.',
    fields: [
      { name: 'cooldown_days', label: 'Cooldown after an experiment ends (days)', type: 'number', value: String(s.cooldown_days), required: true },
      { name: 'assignment_ttl_days', label: 'Visitor assignment lifetime (days)', type: 'number', value: String(s.assignment_ttl_days), required: true },
      { name: 'min_price', label: 'Minimum adaptive price (₹)', type: 'text', value: String(s.min_price_paise / 100), required: true },
      { name: 'default_treatment_share_bps', label: 'Default treatment share (%)', type: 'number', value: pct(s.default_treatment_share_bps), required: true },
      { name: 'default_min_sample', label: 'Default minimum sample per cohort', type: 'number', value: String(s.default_min_sample), required: true },
      { name: 'default_max_duration_days', label: 'Default maximum duration (days)', type: 'number', value: String(s.default_max_duration_days), required: true },
      { name: 'decision_margin_bps', label: 'Decision margin (%)', type: 'number', value: pct(s.decision_margin_bps), hint: 'How much more profit per visitor the candidate needs to be called a winner.', required: true },
      { name: 'rollback_conversion_tolerance_bps', label: 'Conversion-decline tolerance (%)', type: 'number', value: pct(s.rollback_conversion_tolerance_bps), required: true },
      { name: 'rollback_failure_gap_bps', label: 'Payment-failure gap tolerance (percentage points)', type: 'number', value: pct(s.rollback_failure_gap_bps), required: true },
      { name: 'cost_increase_tolerance_bps', label: 'Cost-increase tolerance (%)', type: 'number', value: pct(s.cost_increase_tolerance_bps), required: true },
      { name: 'max_price_change_bps', label: 'Maximum price change per experiment (%)', type: 'number', value: pct(s.max_price_change_bps), required: true },
    ],
    submit: async (v) => {
      const bps = (x) => Math.round(Number(x) * 100);
      settings = (await put('pricing/settings', {
        cooldown_days: Number(v.cooldown_days), assignment_ttl_days: Number(v.assignment_ttl_days), min_price_paise: v.min_price /* a rupee string — the server parses it */,
        default_treatment_share_bps: bps(v.default_treatment_share_bps), default_min_sample: Number(v.default_min_sample), default_max_duration_days: Number(v.default_max_duration_days),
        decision_margin_bps: bps(v.decision_margin_bps), rollback_conversion_tolerance_bps: bps(v.rollback_conversion_tolerance_bps),
        rollback_failure_gap_bps: bps(v.rollback_failure_gap_bps), cost_increase_tolerance_bps: bps(v.cost_increase_tolerance_bps), max_price_change_bps: bps(v.max_price_change_bps),
      })).settings;
    },
  });
  if (values) ok('Settings saved');
}

function servicesCard(rows) {
  if (!rows.length) return '';
  const row = (s) => html`<tr data-svc="${s.id}">
    <td data-label="Service"><span class="cell-main"><b>${s.title}</b><small class="cell-wide">${s.platform} · ${inr(s.price_paise, true)}</small></span></td>
    <td data-label="Adaptive"><label class="check"><input type="checkbox" data-f="adaptive_enabled" ${s.adaptive_enabled ? 'checked' : ''} ${can.pause ? '' : 'disabled'}><span class="sr-only">Adaptive pricing on</span></label></td>
    <td data-label="Min (₹)"><input class="input input-sm max-w-[6rem]" data-f="min_price" type="text" inputmode="decimal" value="${s.adaptive_min_paise !== null ? (s.adaptive_min_paise / 100) : ''}" placeholder="auto" ${can.pause ? '' : 'disabled'}></td>
    <td data-label="Max (₹)"><input class="input input-sm max-w-[6rem]" data-f="max_price" type="text" inputmode="decimal" value="${s.adaptive_max_paise !== null ? (s.adaptive_max_paise / 100) : ''}" placeholder="none" ${can.pause ? '' : 'disabled'}></td>
    <td data-label="Paused"><label class="check"><input type="checkbox" data-f="pricing_paused" ${s.pricing_paused ? 'checked' : ''} ${can.pause ? '' : 'disabled'}><span class="sr-only">Pricing paused</span></label></td>
    <td data-label="Status">${s.eligible ? html`<span class="chip chip-ok">Eligible</span>` : html`<span class="chip chip-gray" title="${s.reasons.join(' ')}">Not eligible</span>`}${s.active_experiment ? html`<small class="block text-muted">experiment #${s.active_experiment.id} ${s.active_experiment.status}</small>` : ''}</td>
    <td class="row-actions-cell">${can.pause ? html`<button type="button" class="btn btn-sm" data-save-svc="${s.id}">${icon('check', 'icon-sm')}<span>Save</span></button>` : ''}</td></tr>`;
  return html`<details class="card mb-4" id="pr-services"><summary class="card-head cursor-pointer"><h2 class="card-title">${icon('layers')}Eligible services (${rows.length})</h2><span class="card-sub">turn adaptive pricing on, set price bounds, pause one service</span></summary>
    <div class="tbl-scroll mt-3" tabindex="0" role="region" aria-label="Eligible services"><table class="tbl tbl-resp">
      <thead><tr><th>Service</th><th>Adaptive</th><th>Min</th><th>Max</th><th>Paused</th><th>Status</th><th><span class="sr-only">Save</span></th></tr></thead>
      <tbody>${rows.map(row)}</tbody></table></div></details>`;
}

function expRow(x) {
  const actions = [];
  if (x.status === 'proposed') {
    if (can.propose) actions.push(['start-dry', 'play', 'Start dry run']);
    if (can.approve) actions.push(['start-live', 'rocket', 'Start live']);
    if (can.pause) actions.push(['cancel', 'x', 'Cancel']);
  } else if (x.status === 'running') {
    if (x.mode === 'dry_run' && can.approve) actions.push(['golive', 'rocket', 'Go live']);
    if (can.pause) { actions.push(['pause', 'hourglass', 'Pause']); actions.push(['evaluate', 'refresh-cw', 'Evaluate now']); actions.push(['rollback', 'rotate-ccw', 'Roll back']); }
  } else if (x.status === 'paused') {
    if (can.pause) { actions.push(['resume', 'play', 'Resume']); actions.push(['rollback', 'rotate-ccw', 'Roll back']); }
  } else if (x.status === 'concluded' && x.decision === 'winner' && can.approve) {
    actions.push(['apply', 'badge-check', 'Apply winner']);
  }
  return html`<tr data-exp="${x.id}">
    <td data-label="Service"><span class="cell-main"><b>${x.service_title}</b><small class="cell-wide">${x.platform} · #${x.id}</small></span></td>
    <td data-label="Control → candidate" class="num"><span class="cell-main">${x.control} → <b>${x.candidate}</b></span></td>
    <td data-label="Status">${statusChip(x.status)}</td>
    <td data-label="Mode">${modeChip(x.mode)}</td>
    <td data-label="Decision">${decisionChip(x.decision)}</td>
    <td data-label="Time left">${x.status === 'running' ? timeLeft(x.time_remaining_seconds) : '—'}</td>
    <td data-label="Last evaluated">${x.last_evaluated_at ? timeAgo(x.last_evaluated_at) : '—'}</td>
    <td class="row-actions-cell"><span class="row-actions">${actions.map(([a, ico, label]) => html`<button type="button" class="btn btn-sm" data-act="${a}" data-id="${x.id}" aria-label="${label} (experiment ${x.id})">${icon(ico, 'icon-sm')}<span>${label}</span></button>`)}</span></td></tr>`;
}

async function renderExperiments(d) {
  can = d.can || can;
  const bar = $('#pr-toolbar');
  bar.hidden = false;
  const statuses = [['', 'All'], ['active', 'Active'], ['running', 'Running'], ['paused', 'Paused'], ['proposed', 'Proposed'], ['concluded', 'Concluded'], ['rolled_back', 'Rolled back'], ['cancelled', 'Cancelled']];
  bar.innerHTML = String(html`<div class="toolbar-row flex-wrap items-center justify-between gap-3">
    <div class="chips" role="group" aria-label="Experiment status">${statuses.map(([k, l]) => html`<button type="button" class="fchip" data-status="${k}" aria-pressed="${String(k === state.status)}">${l}</button>`)}</div>
    <div class="flex gap-2">${can.approve ? html`<button type="button" class="btn btn-sm" id="pr-settings-btn">${icon('settings', 'icon-sm')}<span>Engine settings</span></button>` : ''}
      ${can.propose ? html`<button type="button" class="btn btn-sm btn-primary" id="pr-new-btn">${icon('plus', 'icon-sm')}<span>New experiment</span></button>` : ''}</div></div>`);

  let servicesHtml = '';
  try { servicesHtml = String(servicesCard((await get('pricing/services')).rows)); } catch { /* the table below still renders */ }

  root().innerHTML = servicesHtml + String(d.rows.length
    ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Pricing experiments"><table class="tbl tbl-resp">
      <thead><tr><th>Service</th><th class="num">Control → candidate</th><th>Status</th><th>Mode</th><th>Decision</th><th>Time left</th><th>Last evaluated</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map(expRow)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: 20 }, 'experiments')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'sliders-horizontal', title: state.status ? 'No experiments in this state' : 'No experiments yet', text: can.propose ? 'Create one from an eligible service above.' : 'An owner or manager can propose one.' })}</div>`);
}

// ══════════════════════════ simulator ═══════════════════════════════════════

const simState = { serviceId: null, candidates: [] };

function simCandidateRow(c) {
  return html`<tr data-cand="${c.price_paise}">
    <td data-label="Price"><span class="cell-main"><b>${c.price}</b>${c.valid_ending ? html`<small class="cell-wide text-ok">valid ending</small>` : html`<small class="cell-wide text-bad">${c.rejection_reason}</small>`}</span></td>
    <td data-label="Change" class="num">${c.change_from_current_bps === null ? '—' : html`<span class="${c.change_from_current_bps >= 0 ? 'amt-pos' : 'amt-neg'}">${c.change_from_current_bps >= 0 ? '+' : ''}${tenths(Math.round(c.change_from_current_bps / 10))}%</span>`}</td>
    <td data-label="Contribution / order" class="num">${c.contribution_per_order_paise === null ? 'Unavailable' : inr(c.contribution_per_order_paise, true)}</td>
    <td data-label="Margin" class="num">${c.margin_tenths === null ? '—' : tenths(c.margin_tenths) + '%'}</td>
    <td data-label="Projected contribution" class="num">${c.projected_contribution_paise === null ? 'Unavailable' : inr(c.projected_contribution_paise, true)}</td>
    <td data-label="vs current" class="num">${c.projected_contribution_delta_vs_current_paise === null ? '—' : html`<span class="${c.projected_contribution_delta_vs_current_paise >= 0 ? 'amt-pos' : 'amt-neg'}">${c.projected_contribution_delta_vs_current_paise >= 0 ? '+' : ''}${inr(c.projected_contribution_delta_vs_current_paise, true)}</span>`}</td>
    <td class="row-actions-cell">${c.activatable ? html`<button type="button" class="btn btn-sm" data-propose="${c.price_paise}">${icon('plus', 'icon-sm')}<span>Propose</span></button>` : html`<span class="text-muted text-sm">not activatable</span>`}</td></tr>`;
}

function renderSimulator(services) {
  $('#pr-toolbar').hidden = true;
  const svc = simState.serviceId ? services.find((s) => s.id === simState.serviceId) : services[0];
  if (svc && !simState.serviceId) simState.serviceId = svc.id;
  root().innerHTML = String(html`<div class="stack-lg">
    <section class="card" aria-label="Choose a service"><div class="card-head"><h2 class="card-title">${icon('flask-conical')}Price Experiment Simulator</h2><span class="card-sub">a volume-held-constant projection from this service's own real order history and costs — never a demand forecast</span></div>
      <div class="field-row items-end">
        <div class="field"><label class="label" for="sim-service">Service</label><select class="select" id="sim-service">${services.map((s) => html`<option value="${s.id}" ${s.id === simState.serviceId ? 'selected' : ''}>${serviceOptionLabel(s)}</option>`)}</select></div>
        <div class="field"><label class="label" for="sim-candidates">Candidate prices (₹, comma separated)</label><input class="input" id="sim-candidates" type="text" placeholder="e.g. 44, 49, 59" value="${simState.candidates.join(', ')}"></div>
        <button type="button" class="btn btn-primary" id="sim-run">${icon('activity', 'icon-sm')}<span>Compare</span></button>
      </div></section>
    <div id="sim-result"></div>
  </div>`);
}

function simResultHtml(sim) {
  if (!sim.eligible) {
    return html`<div class="callout callout-warning" role="note">${icon('triangle-alert')}<div class="min-w-0"><strong>This service is not eligible for activation yet.</strong><ul class="mt-1 list-disc pl-5">${sim.reasons.map((r) => html`<li>${r}</li>`)}</ul><p class="mt-1">The projection below still works — only activating an experiment is blocked.</p></div></div>`;
  }
  return '';
}

async function runSimulator(btn) {
  await withBusy(btn, async () => {
    const list = $('#sim-candidates').value.split(',').map((x) => x.trim()).filter(Boolean);
    simState.candidates = list;
    if (!list.length) { warn('Add a candidate price', 'Enter at least one candidate price, e.g. 49.'); return; }
    try {
      const sim = await post(`pricing/simulate/${simState.serviceId}`, { candidates: list });
      const s = sim.simulation;
      $('#sim-result').innerHTML = String(html`<div class="stack-lg">
        ${simResultHtml(s)}
        <section class="card" aria-label="Current"><div class="stat-grid">
          <div class="stat"><small>Current price</small><strong>${s.service.price}</strong><span>today</span></div>
          <div class="stat"><small>Current contribution / order</small><strong>${s.current_contribution_per_order_paise === null ? 'Unavailable' : inr(s.current_contribution_per_order_paise, true)}</strong><span>${s.fulfil_cost_paise === null ? 'no fulfilment cost set' : 'incl. est. ad cost'}</span></div>
          <div class="stat"><small>Volume used</small><strong>${num(s.projected_orders_same_volume)}</strong><span>${s.volume_window}</span></div>
          <div class="stat"><small>Est. ad cost / order</small><strong>${inr(s.est_ad_cost_paise, true)}</strong><span title="${s.est_ad_cost_basis}">basis shown on hover</span></div>
        </div></section>
        <div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Candidate comparison"><table class="tbl tbl-resp">
          <thead><tr><th>Candidate</th><th class="num">Change</th><th class="num">Contribution / order</th><th class="num">Margin</th><th class="num">Projected contribution</th><th class="num">vs current</th><th><span class="sr-only">Action</span></th></tr></thead>
          <tbody>${s.candidates.map(simCandidateRow)}</tbody></table></div></div>
        <div class="callout callout-info" role="note">${icon('info')}<div class="min-w-0">${s.notes.map((n) => html`<p>${n}</p>`)}</div></div>
      </div>`);
    } catch (e) {
      fail('Could not run the simulator', e.message);
    }
  });
}

// ══════════════════════════ profit guard ════════════════════════════════════

function floorBadge(e) {
  if (e.treatment_avg_contribution_paise === null) return html`<span class="chip chip-gray">no purchases yet</span>`;
  if (e.breached) return html`<span class="chip chip-bad">floor breached</span>`;
  if (e.at_risk) return html`<span class="chip chip-warn">at risk</span>`;
  return html`<span class="chip chip-ok">OK</span>`;
}

function renderGuard(g) {
  $('#pr-toolbar').hidden = true;
  root().innerHTML = String(html`<div class="stack-lg">
    <section class="card" aria-label="Global pause"><div class="card-head"><h2 class="card-title">${icon('power')}Global pause</h2><span class="card-sub">stops every pricing experiment from making a new assignment, everywhere, at once</span></div>
      <div class="switch-row"><div><b>${g.global_pause ? 'Paused' : 'Active'}</b><small>${g.global_pause ? 'No visitor is being assigned a test price.' : 'Experiments may run.'}</small></div>
        <label class="switch"><input type="checkbox" id="pr-global-pause" ${g.global_pause ? 'checked' : ''} ${can.pause ? '' : 'disabled'} aria-label="Global pricing pause"><span></span></label></div></section>

    <section class="card" aria-label="Per-service pause"><div class="card-head"><h2 class="card-title">${icon('layers')}Per-service pause</h2></div>
      ${g.services.length ? html`<ul class="grid gap-2">${g.services.map((s) => html`<li class="switch-row"><div><b>${s.title}</b><small>${s.code}</small></div>
        <label class="switch"><input type="checkbox" data-svc-pause="${s.id}" ${s.paused ? 'checked' : ''} ${can.pause ? '' : 'disabled'} aria-label="${s.title} pricing paused"><span></span></label></li>`)}</ul>`
      : html`<p class="text-sm text-muted">No service has adaptive pricing switched on yet.</p>`}</section>

    <section class="card" aria-label="Live experiments"><div class="card-head"><h2 class="card-title">${icon('shield-alert')}Live experiments — profit floor status</h2></div>
      ${g.live_experiments.length ? html`<div class="tbl-scroll" tabindex="0" role="region" aria-label="Live experiment risk"><table class="tbl tbl-resp">
        <thead><tr><th>Service</th><th>Mode</th><th class="num">Floor</th><th class="num">Treatment avg / order</th><th>Status</th><th>Last evaluated</th></tr></thead>
        <tbody>${g.live_experiments.map((e) => html`<tr><td data-label="Service">${e.service_title} <small class="text-muted">#${e.id}</small></td><td data-label="Mode">${modeChip(e.mode)}</td>
          <td data-label="Floor" class="num">${inr(e.profit_floor_paise, true)}</td><td data-label="Avg / order" class="num">${e.treatment_avg_contribution_paise === null ? '—' : inr(e.treatment_avg_contribution_paise, true)}</td>
          <td data-label="Status">${floorBadge(e)}</td><td data-label="Last evaluated">${e.last_evaluated_at ? timeAgo(e.last_evaluated_at) : '—'}</td></tr>`)}</tbody></table></div>`
      : html`<p class="text-sm text-muted">No experiment is running right now.</p>`}</section>

    <section class="card" aria-label="Recent automatic rollbacks"><div class="card-head"><h2 class="card-title">${icon('rotate-ccw')}Recent rollbacks</h2></div>
      ${g.recent_rollbacks.length ? html`<ul class="grid gap-2">${g.recent_rollbacks.map((r) => html`<li><b>${r.service_title}</b> <small class="text-muted">#${r.id}</small><br><span class="text-sm">${r.reason}</span><br><small class="text-muted">${fmtDateTime(r.at)}${r.cooldown_until ? ' · cooldown until ' + fmtDateTime(r.cooldown_until) : ''}</small></li>`)}</ul>`
      : html`<p class="text-sm text-muted">No experiment has been rolled back.</p>`}</section>

    <section class="card" aria-label="Related alerts"><div class="card-head"><h2 class="card-title">${icon('bell-ring')}Alerts</h2><span class="card-sub">also on Business alerts</span></div>
      ${g.alerts.length ? html`<ul class="grid gap-2">${g.alerts.map((a) => html`<li class="flex items-start gap-2"><span class="chip ${a.severity === 'critical' ? 'chip-bad' : a.severity === 'warning' ? 'chip-warn' : 'chip-info'} shrink-0">${a.severity}</span><span class="min-w-0"><b>${a.title}</b><small class="block text-muted">${a.status} · ${fmtDateTime(a.last_detected_at)}</small></span></li>`)}</ul>`
      : html`<p class="text-sm text-muted">No pricing alert.</p>`}
      <a class="btn btn-sm mt-3" href="${url('/admin/alerts')}">${icon('arrow-up-right', 'icon-sm')}<span>Open Business alerts</span></a></section>
  </div>`);
}

// ══════════════════════════ learning history ════════════════════════════════

function historyRow(x) {
  const c = x.control_metrics || { purchases: 0, revenue_paise: 0, contribution_paise: 0 };
  const t = x.treatment_metrics || { purchases: 0, revenue_paise: 0, contribution_paise: 0 };
  return html`<tr data-exp="${x.id}">
    <td data-label="Service"><span class="cell-main"><b>${x.service_title}</b><small class="cell-wide">#${x.id} · ended ${fmtDateTime(x.ended_at || x.rolled_back_at)}</small></span></td>
    <td data-label="Control → candidate" class="num">${x.control} → <b>${x.candidate}</b></td>
    <td data-label="Decision">${decisionChip(x.decision)}</td>
    <td data-label="Control" class="num"><span class="cell-main">${num(c.purchases)} purchases<small class="cell-wide">${inr(c.contribution_paise, true)} contribution</small></span></td>
    <td data-label="Treatment" class="num"><span class="cell-main">${num(t.purchases)} purchases<small class="cell-wide">${inr(t.contribution_paise, true)} contribution</small></span></td>
    <td data-label="Applied">${x.applied ? html`<span class="chip chip-ok">Activated</span>` : html`<span class="text-muted">—</span>`}</td>
    <td class="row-actions-cell"><button type="button" class="btn btn-sm" data-insight="${x.id}">${icon('bot', 'icon-sm')}<span>AI insight</span></button></td></tr>
    <tr class="is-detail" data-insight-row="${x.id}" hidden><td colspan="7"><div class="card" data-insight-box></div></td></tr>`;
}

function renderHistory(d) {
  $('#pr-toolbar').hidden = true;
  const sm = d.summary;
  root().innerHTML = String(html`<div class="stack-lg">
    <div class="stat-grid">
      <div class="stat"><small>Winners</small><strong>${num(sm.winners)}</strong><span>candidate activated or activatable</span></div>
      <div class="stat"><small>Losers</small><strong>${num(sm.losers)}</strong><span>control stayed better</span></div>
      <div class="stat"><small>Inconclusive</small><strong>${num(sm.inconclusive)}</strong><span>not enough evidence</span></div>
      <div class="stat"><small>Rolled back</small><strong>${num(sm.rolled_back)}</strong><span>a safety trigger fired</span></div>
    </div>
    ${d.rows.length ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Experiment history"><table class="tbl tbl-resp">
      <thead><tr><th>Service</th><th class="num">Control → candidate</th><th>Decision</th><th class="num">Control</th><th class="num">Treatment</th><th>Applied</th><th><span class="sr-only">AI insight</span></th></tr></thead>
      <tbody>${d.rows.map(historyRow)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: 15 }, 'experiments')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'history', title: 'No finished experiments yet', text: 'Once an experiment concludes, is rolled back or is cancelled, it appears here with its evidence.' })}</div>`}
  </div>`);
}

function insightBoxHtml(r) {
  if (r.status === 'ok' && r.insight) {
    const i = r.insight;
    return html`<div class="card-head"><h3 class="card-title">${icon('bot')}AI insight</h3><span class="chip chip-info">AI advisory — not a decision</span></div>
      <p class="mb-2">${i.explanation}</p><p class="text-sm"><b>Suggested next step:</b> ${i.recommendation}</p><p class="text-xs text-muted mt-2">${i.confidence_note}</p>`;
  }
  return html`<div class="card-head"><h3 class="card-title">${icon('info')}Note</h3><span class="chip chip-gray">rules-based — no AI model was used</span></div><p class="text-sm">${r.message}</p>`;
}

async function toggleInsight(id, btn) {
  const row = $(`[data-insight-row="${id}"]`);
  if (!row.hidden) { row.hidden = true; return; }
  row.hidden = false;
  const box = row.querySelector('[data-insight-box]');
  box.innerHTML = String(html`<p class="text-sm text-muted">Loading…</p>`);
  await withBusy(btn, async () => {
    try {
      const r = (await post(`pricing/experiments/${id}/ai-insight`)).result;
      box.innerHTML = String(insightBoxHtml(r));
    } catch (e) {
      box.innerHTML = String(html`<p class="text-sm text-bad">${e.message}</p>`);
    }
  });
}

// ══════════════════════════ loading ═════════════════════════════════════════

async function load({ silent = false } = {}) {
  const my = ++seq;
  paintTabs();
  if (!silent || !loaded) root().innerHTML = String(skeletonTable(5));
  root().setAttribute('aria-busy', 'true');
  try {
    if (state.tab === 'experiments') {
      const d = await get('pricing/experiments', { status: state.status, page: state.page });
      if (my !== seq) return;
      await renderExperiments(d);
    } else if (state.tab === 'simulator') {
      const d = await get('pricing/services');
      if (my !== seq) return;
      can = d.can || can;
      renderSimulator(d.rows);
    } else if (state.tab === 'guard') {
      const g = await get('pricing/profit-guard');
      if (my !== seq) return;
      can = g.can || can;
      renderGuard(g.guard);
    } else {
      const d = await get('pricing/learning-history', { page: state.historyPage });
      if (my !== seq) return;
      renderHistory(d);
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

// ══════════════════════════ actions ═════════════════════════════════════════

async function saveServiceRow(id, tr) {
  const body = {
    adaptive_enabled: tr.querySelector('[data-f="adaptive_enabled"]').checked,
    min_price: tr.querySelector('[data-f="min_price"]').value.trim() || null,
    max_price: tr.querySelector('[data-f="max_price"]').value.trim() || null,
    pricing_paused: tr.querySelector('[data-f="pricing_paused"]').checked,
  };
  try {
    await put(`pricing/services/${id}`, body, { idempotency: true });
    ok('Saved');
    load({ silent: true });
  } catch (e) {
    fail('Could not save', e.message);
  }
}

async function runLifecycleAction(action, id) {
  const confirmMap = {
    'start-dry': { title: 'Start as a dry run?', message: 'Both cohorts will be shown the SAME current price — a safety rehearsal of the measurement pipeline with zero customer impact.', confirmText: 'Start dry run' },
    'start-live': { title: 'Start live?', message: 'The treatment cohort will immediately begin seeing the candidate price. This needs owner approval and is audited.', confirmText: 'Start live', tone: 'danger' },
    golive: { title: 'Switch to live?', message: 'New visitor assignments from now on may see the candidate price; a visitor already assigned during the dry run keeps the price they were shown.', confirmText: 'Go live', tone: 'danger' },
    resume: { title: 'Resume this experiment?', message: '', confirmText: 'Resume' },
    evaluate: { title: 'Evaluate now?', message: 'Runs the same checks the scheduled job runs: recomputes real cohort metrics and checks every rollback trigger.', confirmText: 'Evaluate' },
    apply: { title: 'Activate the winning price?', message: 'The service’s default price becomes the candidate price, audited as a price change. This cannot be undone except by a new change.', confirmText: 'Activate', tone: 'danger' },
  };
  const reasonMap = {
    pause: 'Pause this experiment', rollback: 'Roll this experiment back', cancel: 'Cancel this proposal',
  };
  try {
    if (confirmMap[action]) {
      const yes = await confirmDialog({ ...confirmMap[action], cancelText: 'Not now' });
      if (!yes) return;
    }
    let reason = '';
    if (reasonMap[action]) {
      const v = await formDialog({ title: reasonMap[action], confirmText: 'Confirm', tone: action === 'cancel' ? 'primary' : 'danger', fields: [{ name: 'reason', label: 'Reason', type: 'text', value: '', required: action !== 'cancel' }] });
      if (!v) return;
      reason = v.reason || '';
    }
    const endpoint = {
      'start-dry': [`pricing/experiments/${id}/start`, { mode: 'dry_run' }], 'start-live': [`pricing/experiments/${id}/start`, { mode: 'live' }],
      golive: [`pricing/experiments/${id}/go-live`, {}], pause: [`pricing/experiments/${id}/pause`, { reason }], resume: [`pricing/experiments/${id}/resume`, {}],
      rollback: [`pricing/experiments/${id}/rollback`, { reason }], apply: [`pricing/experiments/${id}/apply`, {}], cancel: [`pricing/experiments/${id}/cancel`, { reason }],
      evaluate: [`pricing/experiments/${id}/evaluate`, null],
    }[action];
    if (!endpoint) return;
    const r = endpoint[1] === null ? await post(endpoint[0]) : await post(endpoint[0], endpoint[1], { idempotency: true });
    ok('Done', r.result?.reason || 'The experiment was updated.');
    load({ silent: true });
    loadSummary();
  } catch (e) {
    fail('Could not do that', e.message);
  }
}

// ══════════════════════════ boot ════════════════════════════════════════════
export default function init() {
  const q = new URLSearchParams(location.search);
  state.tab = TABS.includes(q.get('tab')) ? q.get('tab') : 'experiments';
  state.status = q.get('status') || '';
  state.page = Math.max(1, Number(q.get('page')) || 1);
  state.historyPage = state.tab === 'history' ? state.page : 1;

  const tabs = $('#pr-tabs');
  on(tabs, 'click', '[data-tab]', (e, b) => { if (b.dataset.tab !== state.tab) { state.tab = b.dataset.tab; load(); } });
  on($('#pr-toolbar'), 'click', '[data-status]', (e, b) => { state.status = b.dataset.status; state.page = 1; load(); });
  on($('#pr-toolbar'), 'click', '#pr-new-btn', () => openProposeDialog());
  on($('#pr-toolbar'), 'click', '#pr-settings-btn', () => openEngineSettings());

  const r = root();
  on(r, 'click', '[data-page]', (e, b) => { if (b.disabled) return; if (state.tab === 'history') state.historyPage = Number(b.dataset.page); else state.page = Number(b.dataset.page); load(); });
  on(r, 'click', '[data-retry]', () => load());
  on(r, 'click', '[data-act]', (e, b) => runLifecycleAction(b.dataset.act, Number(b.dataset.id)));
  on(r, 'click', '[data-save-svc]', (e, b) => saveServiceRow(Number(b.dataset.saveSvc), b.closest('tr')));
  on(r, 'click', '#sim-run', (e, b) => runSimulator(b));
  on(r, 'click', '[data-propose]', (e, b) => openProposeDialog(simState.serviceId, String(Number(b.dataset.propose) / 100)));
  on(r, 'change', '#sim-service', (e, sel) => { simState.serviceId = Number(sel.value); });
  on(r, 'click', '[data-insight]', (e, b) => toggleInsight(Number(b.dataset.insight), b));
  on(r, 'change', '#pr-global-pause', async (e, cb) => {
    const wantsPause = cb.checked;
    if (wantsPause) {
      const yes = await confirmDialog({ title: 'Pause every pricing experiment?', message: 'No visitor will be newly assigned a test price anywhere on the storefront until this is turned back on. A price already shown to an assigned visitor is not affected.', confirmText: 'Pause everything', tone: 'danger' });
      if (!yes) { cb.checked = false; return; }
    }
    try { await post('pricing/global-pause', { paused: wantsPause }, { idempotency: true }); ok(wantsPause ? 'Paused' : 'Resumed'); loadSummary(); } catch (e) { fail('Could not change this', e.message); cb.checked = !wantsPause; }
  });
  on(r, 'change', '[data-svc-pause]', async (e, cb) => {
    try { await put(`pricing/services/${cb.dataset.svcPause}`, { pricing_paused: cb.checked }, { idempotency: true }); ok(cb.checked ? 'Service paused' : 'Service resumed'); } catch (e2) { fail('Could not change this', e2.message); cb.checked = !cb.checked; }
  });

  get('pricing/settings').then((d) => { settings = d.settings; can = d.can || can; }).catch(() => {});
  load();
  loadSummary();
  whenVisible(() => { load({ silent: true }); loadSummary(); }, 45000);
}
