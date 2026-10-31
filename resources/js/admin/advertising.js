import { $, html, icon, on } from '../core/dom.js';
import { inr, fmtDate, fmtDateTime, parseRupees, pct, num } from '../core/format.js';
import { get, put, post } from './net.js';
import { ok, fail, errorState, showFieldErrors, clearFieldErrors, autoClearErrors, withBusy } from './ui.js';
import { confirmDialog } from '../core/dialog.js';

let ads = null;
let target = null;
let budget = null;
let showTargetForm = false;
let showBudgetForm = false;
const root = () => $('#advertising-root');

const STATUS_LABEL = { not_started: 'Not started', in_progress: 'In progress', ended: 'Ended' };
const PACE = { ahead: ['Ahead of target', 'chip-ok'], on_track: ['On track', 'chip-info'], behind: ['Behind pace', 'chip-warn'] };

// ═══════════════════════════ sales target ═══════════════════════════

function targetForm(t) {
  const p = t?.target;
  return html`
  <form id="target-form" class="stack" novalidate autocomplete="off" aria-label="Set a sales target">
    <div class="grid-2">
      <div class="field"><label class="label req" for="tg-start">Period starts</label><input class="input" type="date" id="tg-start" name="period_start" value="${p?.period_start || ''}"></div>
      <div class="field"><label class="label req" for="tg-end">Period ends</label><input class="input" type="date" id="tg-end" name="period_end" value="${p?.period_end || ''}"></div>
    </div>
    <div class="field"><label class="label req" for="tg-amount">Revenue target</label>
      <div class="input-affix"><span class="affix">₹</span><input class="input" id="tg-amount" name="target" inputmode="decimal" value="${p ? p.target : ''}" placeholder="50000"></div></div>
    <div class="field"><label class="label" for="tg-note">Note</label><input class="input" id="tg-note" name="note" maxlength="255" value="${p?.note || ''}" placeholder="Optional — e.g. “Diwali push”"></div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-shine" id="tg-save">${icon('check')}<span>Save target</span></button>
      <button type="button" class="btn btn-ghost" id="tg-cancel">Cancel</button>
    </div>
  </form>`;
}

function targetSection(t) {
  if (!t.has_target) {
    return html`<section class="card" aria-labelledby="h-target"><div class="card-head"><h2 class="card-title" id="h-target">${icon('target')}Sales target</h2></div>
      <p class="text-sm text-ink-soft mb-3">No revenue target is set yet. Set one to see real pacing against it — actual revenue so far, a projection to the end of the period, and the daily pace still needed.</p>
      ${showTargetForm ? targetForm(t) : html`<button type="button" class="btn btn-primary" id="tg-open">${icon('target', 'icon-sm')}<span>Set a target</span></button>`}
    </section>`;
  }
  const p = t.target;
  const barPct = Math.max(0, Math.min(100, Math.round((t.pct_of_target_tenths || 0) / 10)));
  const [paceLabel, paceCls] = t.pace_label ? PACE[t.pace_label] : ['', 'chip-gray'];
  return html`<section class="card" aria-labelledby="h-target"><div class="card-head"><h2 class="card-title" id="h-target">${icon('target')}Sales target</h2>
      <span class="chip ${t.status === 'not_started' ? 'chip-gray' : paceCls}">${t.status === 'not_started' ? STATUS_LABEL.not_started : paceLabel}</span></div>
    <div class="stack">
      <div class="kv"><span>Period</span><b>${fmtDate(p.period_start)} – ${fmtDate(p.period_end)}</b></div>
      <div class="kv"><span>Target</span><b>${inr(p.target_paise, true)}</b></div>
      ${p.note ? html`<div class="kv"><span>Note</span><b>${p.note}</b></div>` : ''}
      <div class="kv"><span>Actual so far</span><b>${inr(t.actual_paise, true)}</b><span class="text-xs text-muted"> (${pct(t.pct_of_target_tenths)} of target)</span></div>
      <div class="hbar-track" role="img" aria-label="${pct(t.pct_of_target_tenths)} of target reached"><div class="hbar-fill" data-w="${barPct}"></div></div>
      ${t.status === 'in_progress' ? html`
        <div class="kv"><span>Projected finish</span><b>${inr(t.projected_paise, true)}</b><span class="text-xs text-muted"> (${pct(t.projected_pct_tenths)})</span></div>
        ${t.required_daily_pace_paise !== null && t.required_daily_pace_paise > 0 ? html`<div class="kv"><span>Needed per day to hit it</span><b>${inr(t.required_daily_pace_paise, true)}</b></div>` : ''}
        <p class="text-xs text-muted">Day ${t.elapsed_days} of ${t.total_days} — ${t.remaining_days} day${t.remaining_days === 1 ? '' : 's'} left.</p>
      ` : ''}
      ${t.status === 'not_started' ? html`<p class="text-xs text-muted">Starts ${fmtDate(p.period_start)} — ${t.total_days} day period.</p>` : ''}
      ${t.status === 'ended' ? html`<p class="text-xs text-muted">The period ended with ${inr(t.actual_paise, true)} against a target of ${inr(p.target_paise, true)}.</p>` : ''}
    </div>
    <div class="form-actions mt-3">
      <button type="button" class="btn btn-sm btn-ghost" id="tg-open">${icon('pencil', 'icon-sm')}<span>Set a new target</span></button>
      ${t.status !== 'ended' ? html`<button type="button" class="btn btn-sm btn-ghost" id="tg-end">${icon('undo-2', 'icon-sm')}<span>End this target early</span></button>` : ''}
    </div>
    ${showTargetForm ? html`<div class="mt-3">${targetForm(t)}</div>` : ''}
  </section>`;
}

// ═══════════════════════════ ad budget ═══════════════════════════

function budgetForm(b) {
  const isOverride = b?.source === 'override';
  return html`
  <form id="budget-form" class="stack" novalidate autocomplete="off" aria-label="Set an advertising budget">
    <div class="seg" role="radiogroup" aria-label="Budget scope">
      <button type="button" class="seg-btn" data-scope="default" aria-selected="${!isOverride}">Standing budget</button>
      <button type="button" class="seg-btn" data-scope="override" aria-selected="${isOverride}">For a date range</button>
    </div>
    <div id="bg-period" class="grid-2" hidden>
      <div class="field"><label class="label req" for="bg-start">Starts</label><input class="input" type="date" id="bg-start" name="period_start" value="${isOverride ? b.period_start : ''}"></div>
      <div class="field"><label class="label req" for="bg-end">Ends</label><input class="input" type="date" id="bg-end" name="period_end" value="${isOverride ? b.period_end : ''}"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label class="label" for="bg-daily">Daily budget</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="bg-daily" name="daily_budget" inputmode="decimal" value="${b?.daily_budget ?? ''}" placeholder="No cap">
        </div><p class="field-hint">Informational for now — planning only, spend is never blocked.</p></div>
      <div class="field"><label class="label" for="bg-monthly">Monthly budget</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="bg-monthly" name="monthly_budget" inputmode="decimal" value="${b?.monthly_budget ?? ''}" placeholder="No cap"></div></div>
    </div>
    <div class="field"><label class="label" for="bg-note">Note</label><input class="input" id="bg-note" name="note" maxlength="255" value="${b?.note || ''}" placeholder="Optional"></div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-shine" id="bg-save">${icon('check')}<span>Save budget</span></button>
      <button type="button" class="btn btn-ghost" id="bg-cancel">Cancel</button>
    </div>
  </form>`;
}

function budgetSection(b) {
  const hasCap = b.daily_budget_paise !== null || b.monthly_budget_paise !== null;
  return html`<section class="card" aria-labelledby="h-budget"><div class="card-head"><h2 class="card-title" id="h-budget">${icon('wallet')}Advertising budget</h2>
      <span class="chip ${b.source === 'override' ? 'chip-brand' : hasCap ? 'chip-info' : 'chip-gray'}">${b.source === 'override' ? 'date-range budget' : hasCap ? 'standing budget' : 'no cap set'}</span></div>
    <div class="stack">
      ${b.source === 'override' ? html`<div class="kv"><span>Applies</span><b>${fmtDate(b.period_start)} – ${fmtDate(b.period_end)}</b></div>` : ''}
      <div class="kv"><span>Daily</span><b>${b.daily_budget_paise !== null ? inr(b.daily_budget_paise, true) : 'No cap'}</b></div>
      <div class="kv"><span>Monthly</span><b>${b.monthly_budget_paise !== null ? inr(b.monthly_budget_paise, true) : 'No cap'}</b></div>
      ${b.note ? html`<div class="kv"><span>Note</span><b>${b.note}</b></div>` : ''}
    </div>
    <div class="form-actions mt-3">
      <button type="button" class="btn btn-sm btn-ghost" id="bg-open">${icon('pencil', 'icon-sm')}<span>Change budget</span></button>
      ${b.source === 'override' ? html`<button type="button" class="btn btn-sm btn-ghost" id="bg-end-override">${icon('undo-2', 'icon-sm')}<span>End this override early</span></button>` : ''}
      ${hasCap ? html`<button type="button" class="btn btn-sm btn-ghost" id="bg-reset">${icon('rotate-ccw', 'icon-sm')}<span>Remove all caps</span></button>` : ''}
    </div>
    ${showBudgetForm ? html`<div class="mt-3">${budgetForm(b)}</div>` : ''}
  </section>`;
}

function forecastSection() {
  return html`<section class="card" aria-labelledby="h-forecast"><div class="card-head"><h2 class="card-title" id="h-forecast">${icon('trending-up')}Budget forecast</h2></div>
    <p class="text-sm text-ink-soft mb-3">What a planned spend is expected to buy, projected from the last 30 days' real cost per qualified visitor, conversion rate and revenue per customer — never a demand forecast.</p>
    <form id="forecast-form" class="grid-2" novalidate autocomplete="off" aria-label="Forecast a budget">
      <div class="field"><label class="label req" for="fc-budget">Planned spend</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="fc-budget" name="budget" inputmode="decimal" placeholder="10000"></div></div>
      <div class="field"><label class="label" for="fc-campaign">Campaign (optional)</label><input class="input" id="fc-campaign" name="campaign" placeholder="Leave blank for the whole shop"></div>
    </form>
    <div class="form-actions"><button type="button" class="btn btn-primary" id="fc-run">${icon('trending-up', 'icon-sm')}<span>Forecast</span></button></div>
    <div id="fc-result" aria-live="polite"></div>
  </section>`;
}

function forecastResult(f) {
  if (f.customers === null) {
    return html`<div class="callout callout-warning mt-3">${icon('triangle-alert')}<span>Not enough recent data${f.campaign ? ` for "${f.campaign}"` : ''} to estimate a conversion rate — showing visitors only.</span></div>
      <div class="kv mt-2"><span>Qualified visitors</span><b>${num(f.qualified_visitors)}</b></div>`;
  }
  return html`
  <div class="grid-2 mt-3">
    <div class="kv"><span>Qualified visitors</span><b>${num(f.qualified_visitors)}</b></div>
    <div class="kv"><span>Customers</span><b>${num(f.customers)}</b></div>
    <div class="kv"><span>Revenue</span><b>${f.revenue_paise !== null ? inr(f.revenue_paise, true) : '—'}</b></div>
    <div class="kv"><span>Contribution after this spend</span><b class="${f.contribution_paise !== null && f.contribution_paise < 0 ? 'text-bad-700' : ''}">${f.contribution_paise !== null ? inr(f.contribution_paise, true) : '—'}</b></div>
  </div>
  <p class="text-xs text-muted mt-2">Basis: last ${f.window.days} days (${fmtDate(f.window.from)} – ${fmtDate(f.window.to)}) — ₹${(f.basis.cost_per_qualified_paise / 100).toFixed(2)} per qualified visitor
    (${f.basis.cost_per_qualified_is_real ? 'real spend' : 'CPC estimate, no actual spend entered yet'}), ${f.basis.conversion_tenths !== null ? (f.basis.conversion_tenths / 10).toFixed(1) + '%' : 'no'} conversion from ${f.basis.customers_in_window} customer${f.basis.customers_in_window === 1 ? '' : 's'}.</p>`;
}

// ═══════════════════════════ render ═══════════════════════════

function render(a, t, b) {
  ads = a; target = t; budget = b;
  root().innerHTML = String(html`
  <div class="grid-2">
    <form id="ads-form" class="card" novalidate autocomplete="off" aria-labelledby="h-cpc">
      <div class="card-head"><h2 class="card-title" id="h-cpc">${icon('megaphone')}Advertising cost</h2></div>
      <div class="field"><label class="label req" for="ad-cpc">Cost per click (CPC)</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="ad-cpc" name="cpc" inputmode="decimal" value="${a.cpc}" placeholder="1.08"></div>
        <p class="field-hint">What one qualified visitor costs you in ads. Default ₹1.08. Changing it only affects visitors from now on — past days keep the CPC they were charged at.</p></div>
      <div class="field"><label class="label req" for="ad-secs">Qualified visitor after</label>
        <div class="input-affix"><input class="input" id="ad-secs" name="qualify_seconds" inputmode="numeric" value="${a.qualify_seconds}"><span class="affix-r">seconds</span></div>
        <p class="field-hint">A visitor counts (and is charged) once they stay on the page this long — 3 seconds by default. Quick bounces cost nothing.</p></div>
      <div class="field"><label class="label req" for="ad-conv">Assumed conversion rate</label>
        <div class="input-affix"><input class="input" id="ad-conv" name="assumed_conversion" inputmode="decimal" value="${a.assumed_conversion}"><span class="affix-r">% of visitors buy</span></div>
        <p class="field-hint">Only used for the profit preview in Services until you have 10 paid orders in 30 days of real data.</p></div>
      <div class="form-actions"><button type="submit" class="btn btn-primary btn-shine" id="ad-save">${icon('check')}<span>Save advertising settings</span></button></div>
    </form>

    <section class="card" aria-labelledby="h-how"><div class="card-head"><h2 class="card-title" id="h-how">${icon('percent')}How ad spend is calculated</h2></div>
      <div class="stack text-sm text-ink-soft">
        <div class="profit-box"><div class="profit-line total !border-0 !mt-0 !pt-0"><span>Ads spend</span><b>qualified visitors × CPC</b></div></div>
        <p>Each qualified visitor is charged <b>once per day</b>, however many times they come back that day. The charge is stored with the visitor, so editing the CPC later never rewrites old reports.</p>
        <p>Net profit = <b>revenue − SMM cost − ads spend</b>. Manual orders have no SMM cost, so their full price counts before ads.</p>
        <div class="kv"><span>Current CPC</span><b>${inr(a.cpc_paise, true)}</b></div>
        <div class="kv"><span>Estimated ad cost carried by one order</span><b>${inr(a.est_per_order_paise, true)}</b></div>
        <p class="text-xs text-muted">Basis: ${a.est_basis}</p></div></section>
  </div>

  <div class="grid-2 mt-5">${targetSection(t)}${budgetSection(b)}</div>
  <div class="mt-5">${forecastSection()}</div>

  <section class="card mt-5" aria-labelledby="h-hist"><div class="card-head"><h2 class="card-title" id="h-hist">${icon('history')}CPC history</h2></div>
    <div class="tbl-scroll" tabindex="0" role="region" aria-label="Earlier CPC settings"><table class="tbl tbl-resp"><thead><tr><th>Set on</th><th class="num">CPC</th><th class="num">Qualify after</th><th>Changed by</th></tr></thead>
    <tbody>${a.history.map((h) => html`<tr><td data-label="Set on">${fmtDateTime(h.at)}</td><td data-label="CPC" class="num"><b>${h.cpc}</b></td><td data-label="Qualify after" class="num">${h.qualify_seconds} s</td><td data-label="By">${h.by}</td></tr>`)}</tbody></table></div></section>`);
  requestAnimationFrame(() => root().querySelectorAll('[data-w]').forEach((n) => { n.style.width = n.dataset.w + '%'; }));
  const periodWrap = $('#bg-period');
  if (periodWrap) periodWrap.hidden = budget.source !== 'override';
  autoClearErrors(root());
  root().setAttribute('aria-busy', 'false');
}

async function reload() {
  const res = await get('advertising');
  render(res.ads, res.sales_target, res.ad_budget);
}

export default async function init() {
  try { await reload(); } catch (e) { root().innerHTML = errorState(e.message).toString(); root().setAttribute('aria-busy', 'false'); return; }
  const r = root();
  on(r, 'click', '[data-retry]', () => location.reload());

  // ── advertising cost ──
  r.addEventListener('submit', async (e) => {
    if (e.target.id !== 'ads-form') return;
    e.preventDefault();
    const f = $('#ads-form');
    clearFieldErrors(f);
    const cpc = f.elements.cpc.value.trim();
    if (parseRupees(cpc) === null) { showFieldErrors(f, { cpc: 'Enter the CPC in rupees with at most two decimals (for example 1.08).' }); return; }
    await withBusy($('#ad-save'), async () => {
      try {
        const res = await put('advertising', { cpc, qualify_seconds: f.elements.qualify_seconds.value, assumed_conversion: f.elements.assumed_conversion.value }, { idempotency: true });
        ok('Advertising settings saved', `CPC is now ${inr(res.ads.cpc_paise, true)}`);
        render(res.ads, target, budget);
      } catch (err) {
        if (err.fields) { showFieldErrors(f, err.fields); fail('Please check the highlighted fields', err.message); } else fail('Could not save', err.message);
      }
    });
  });

  // ── sales target ──
  on(r, 'click', '#tg-open', () => { showTargetForm = true; render(ads, target, budget); $('#tg-start')?.focus(); });
  on(r, 'click', '#tg-cancel', () => { showTargetForm = false; render(ads, target, budget); });
  on(r, 'click', '#tg-end', async () => {
    const yes = await confirmDialog({ title: 'End this target early?', message: 'The period ends today instead of its planned end date. This cannot be undone, though you can always set a new target.', confirmText: 'End target', tone: 'danger' });
    if (!yes) return;
    try {
      const res = await post('sales-target/revoke');
      target = res.sales_target; showTargetForm = false;
      ok('Target ended', 'The sales target no longer applies.');
      render(ads, target, budget);
    } catch (err) { fail('Could not end the target', err.message); }
  });
  r.addEventListener('submit', async (e) => {
    if (e.target.id !== 'target-form') return;
    e.preventDefault();
    const f = $('#target-form');
    clearFieldErrors(f);
    await withBusy($('#tg-save'), async () => {
      try {
        const res = await put('sales-target', { period_start: f.elements.period_start.value, period_end: f.elements.period_end.value, target: f.elements.target.value.trim(), note: f.elements.note.value.trim() }, { idempotency: true });
        target = res.sales_target; showTargetForm = false;
        ok('Target saved', `${inr(target.target.target_paise, true)} by ${target.target.period_end}`);
        render(ads, target, budget);
      } catch (err) {
        if (err.fields) { showFieldErrors(f, err.fields); fail('Please check the highlighted fields', err.message); } else fail('Could not save', err.message);
      }
    });
  });

  // ── ad budget ──
  on(r, 'click', '#bg-open', () => { showBudgetForm = true; render(ads, target, budget); });
  on(r, 'click', '#bg-cancel', () => { showBudgetForm = false; render(ads, target, budget); });
  on(r, 'click', '.seg-btn', (e, b) => {
    const f = $('#budget-form');
    f.querySelectorAll('.seg-btn').forEach((x) => x.setAttribute('aria-selected', String(x === b)));
    $('#bg-period').hidden = b.dataset.scope !== 'override';
  });
  on(r, 'click', '#bg-reset', async () => {
    const yes = await confirmDialog({ title: 'Remove all budget caps?', message: 'Both the daily and monthly budgets go back to “no cap”. This never changes what has already been spent.', confirmText: 'Remove caps' });
    if (!yes) return;
    try {
      const res = await post('ad-budget/reset');
      budget = res.ad_budget; showBudgetForm = false;
      ok('Budget caps removed', 'No daily or monthly cap is set.');
      render(ads, target, budget);
    } catch (err) { fail('Could not reset the budget', err.message); }
  });
  on(r, 'click', '#bg-end-override', async () => {
    const yes = await confirmDialog({ title: 'End this budget override early?', message: 'The standing budget (if any) applies again from today.', confirmText: 'End override', tone: 'danger' });
    if (!yes) return;
    try {
      const res = await post('ad-budget/revoke-override');
      budget = res.ad_budget; showBudgetForm = false;
      ok('Override ended', 'The standing budget applies again.');
      render(ads, target, budget);
    } catch (err) { fail('Could not end the override', err.message); }
  });
  r.addEventListener('submit', async (e) => {
    if (e.target.id !== 'budget-form') return;
    e.preventDefault();
    const f = $('#budget-form');
    clearFieldErrors(f);
    const scope = f.querySelector('.seg-btn[aria-selected="true"]')?.dataset.scope || 'default';
    const body = { scope, daily_budget: f.elements.daily_budget.value.trim(), monthly_budget: f.elements.monthly_budget.value.trim(), note: f.elements.note.value.trim() };
    if (scope === 'override') { body.period_start = f.elements.period_start.value; body.period_end = f.elements.period_end.value; }
    await withBusy($('#bg-save'), async () => {
      try {
        const res = await put('ad-budget', body, { idempotency: true });
        budget = res.ad_budget; showBudgetForm = false;
        ok('Budget saved', budget.daily_budget_paise !== null || budget.monthly_budget_paise !== null ? 'The new budget is in force.' : 'No cap is set.');
        render(ads, target, budget);
      } catch (err) {
        if (err.fields) { showFieldErrors(f, err.fields); fail('Please check the highlighted fields', err.message); } else fail('Could not save', err.message);
      }
    });
  });

  // ── forecast ──
  on(r, 'click', '#fc-run', async () => {
    const f = $('#forecast-form');
    clearFieldErrors(f);
    const amount = f.elements.budget.value.trim();
    if (parseRupees(amount) === null) { showFieldErrors(f, { budget: 'Enter the budget in rupees with at most two decimals.' }); return; }
    await withBusy($('#fc-run'), async () => {
      try {
        const res = await post('ad-budget/forecast', { budget: amount, campaign: f.elements.campaign.value.trim() });
        $('#fc-result').innerHTML = String(forecastResult(res.forecast));
      } catch (err) {
        if (err.fields) showFieldErrors(f, err.fields);
        fail('Could not forecast', err.message);
      }
    });
  });
}
