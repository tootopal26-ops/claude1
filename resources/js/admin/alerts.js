// Admin → Business alerts: the alerts the Business Watchdog raised, what each check last saw, the thresholds, and the optional e-mail.
// Every sentence on this page comes from the server's own records; a check with too little data says so instead of showing "healthy".
import { $, $$, html, icon, on, url } from '../core/dom.js';
import { num, fmtDateTime, timeAgo } from '../core/format.js';
import { get, post, put } from './net.js';
import { reloadStatus } from './shell.js';
import { skeletonTable, emptyState, errorState, pager, ok, fail, warn, whenVisible, withBusy, showFieldErrors, clearFieldErrors, autoClearErrors } from './ui.js';
import { confirmDialog } from '../core/dialog.js';

const TABS = ['alerts', 'checks', 'settings', 'mail'];
const SEV = { critical: ['Critical', 'chip-bad'], warning: ['Warning', 'chip-warn'], info: ['Info', 'chip-info'] };
const STATUS = { open: ['Open', 'chip-warn'], acknowledged: ['Acknowledged', 'chip-info'], resolved: ['Resolved', 'chip-ok'] };
const VERDICT = {
  ok: ['OK', 'chip-ok', 'The check ran and found nothing to report'],
  alert: ['Alert', 'chip-bad', 'A threshold was crossed'],
  insufficient: ['Not enough data', 'chip-gray', 'There was too little data to judge — this is not a clean bill of health'],
  unavailable: ['Unavailable', 'chip-gray', 'The thing to look at is missing or could not be read — nothing was assumed'],
  disabled: ['Switched off', 'chip-gray', 'Switched off under Thresholds'],
  never: ['Not run yet', 'chip-gray', 'The watchdog has not run since this check was added'],
};
const KIND = {
  rule: ['Rule', 'chip-gray', 'A fixed rule applied to the shop’s records'],
  heuristic: ['Heuristic', 'chip-info', 'Today compared with recent history — it can be wrong (a quiet Sunday can look like a drop)'],
  provider: ['Provider API', 'chip-info', 'Asks the provider’s documented API'],
};
const JOB_STATE = { ok: ['on schedule', 'chip-ok'], running: ['running now', 'chip-info'], overdue: ['overdue', 'chip-warn'], failing: ['failing', 'chip-bad'], never_run: ['never run', 'chip-gray'] };
/** Where to look first for each kind of alert. */
const LOOK = {
  payment_failures: ['/admin/payments', 'Payments'], webhooks: ['/admin/payment-checks', 'Payment checks'], payment_flag: ['/admin/payment-checks?tab=flags', 'Payment flags'],
  provider_errors: ['/admin/smm', 'SMM API'], provider_balance: ['/admin/smm', 'SMM API'], stuck_orders: ['/admin/recovery', 'Order recovery'], provider_cost: ['/admin/services', 'Services'],
  missing_costs: ['/admin/finance?tab=reconciliation', 'Reconciliation'], sales_drop: ['/admin/dashboard', 'Dashboard'], conversion: ['/admin/ads', 'Ads Intelligence'],
  cron_jobs: ['/admin/system', 'System'], app_errors: ['/admin/system', 'System'], finance: ['/admin/finance?tab=reconciliation', 'Reconciliation'],
};

const state = { tab: 'alerts', status: 'active', sev: '', type: '', page: 1 };
let can = { manage: false, run: false };
let types = {};
let watchdog = null;
let dry = null;
let seq = 0;
let loaded = false;
const root = () => $('#al-root');

function writeUrl() {
  const q = new URLSearchParams();
  if (state.tab !== 'alerts') q.set('tab', state.tab);
  if (state.tab === 'alerts') {
    if (state.status !== 'active') q.set('status', state.status);
    if (state.sev) q.set('severity', state.sev);
    if (state.type) q.set('type', state.type);
    if (state.page > 1) q.set('page', String(state.page));
  }
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

// ── summary ───────────────────────────────────────────────────────────────────
const kpi = (tone, ico, label, value, sub, chip = '', key = '') => html`<article class="kpi" data-tone="${tone}" aria-label="${label}" ${key ? html`data-kpi="${key}"` : ''}>
  <div class="kpi-top"><span class="kpi-label">${icon(ico)}<span>${label}</span></span></div>
  <div class="kpi-row"><div class="kpi-value">${value}</div></div><div class="kpi-sub kpi-sub-wrap" title="${sub}">${sub}</div>${chip ? html`<div class="mt-1">${chip}</div>` : ''}</article>`;

function paintSummary(w) {
  const c = w.counts;
  const job = w.job;
  const [stateLabel, stateCls] = JOB_STATE[job?.state] || JOB_STATE.never_run;
  const last = job?.last_run;
  $('#al-summary').innerHTML = String(html`<div class="kpi-grid">
    ${kpi(c.critical ? 'red' : 'green', 'siren', 'Critical', num(c.critical), c.critical ? 'need attention now' : 'none open', '', 'critical')}
    ${kpi(c.warning ? 'amber' : 'green', 'triangle-alert', 'Warnings', num(c.warning), `${num(c.info)} informational`, '', 'warning')}
    ${kpi(c.unacknowledged ? 'amber' : 'green', 'eye', 'Not acknowledged', num(c.unacknowledged), `of ${num(c.total)} live alert${c.total === 1 ? '' : 's'}`, '', 'unacknowledged')}
    ${kpi(job?.state === 'ok' ? 'green' : 'amber', 'clock', 'Last check', last ? timeAgo(last.at) : 'never', job ? `runs about every ${Math.round(job.every_seconds / 60)} min${last?.dry_run ? ' · last run was a dry run' : ''}` : 'the watchdog job is not registered', html`<span class="chip ${stateCls}">${stateLabel}</span>`, 'last-check')}
  </div>`);
  $('#al-summary').setAttribute('aria-busy', 'false');
  $('#al-run').hidden = !can.run;
  $('#al-dry').hidden = !can.run;
  const badge = $('#al-tab-alerts span');
  if (badge) badge.innerHTML = 'Alerts' + (c.total ? ` <b class="tab-count">${c.total > 99 ? '99+' : c.total}</b>` : '');
}

async function loadSummary() {
  try {
    const d = await get('alerts/checks');
    can = d.can;
    watchdog = d.watchdog;
    paintSummary(watchdog);
  } catch {
    $('#al-summary').innerHTML = '';
    $('#al-summary').setAttribute('aria-busy', 'false');
  }
}

// ── tabs and toolbar ──────────────────────────────────────────────────────────
function paintTabs() {
  $$('#al-tabs [data-tab]').forEach((b) => {
    const on_ = b.dataset.tab === state.tab;
    b.setAttribute('aria-selected', String(on_));
    b.tabIndex = on_ ? 0 : -1;
  });
  root().setAttribute('aria-labelledby', 'al-tab-' + state.tab);
}

let toolbarKey = '';
function paintToolbar() {
  const bar = $('#al-toolbar');
  if (state.tab !== 'alerts') { bar.hidden = true; bar.innerHTML = ''; toolbarKey = ''; return; }
  bar.hidden = false;
  const key = 'alerts:' + Object.keys(types).length;
  if (key !== toolbarKey) {
    toolbarKey = key;
    const opt = (v, l) => html`<option value="${v}">${l}</option>`;
    bar.innerHTML = String(html`<div class="chips" role="group" aria-label="Alert status">${[['active', 'Active'], ['resolved', 'Resolved'], ['all', 'All']].map(([k, l]) => html`<button type="button" class="fchip" data-status="${k}" aria-pressed="false">${l}</button>`)}</div>
      <div class="toolbar-row">
        <label class="sr-only" for="al-sev">Severity</label><select class="select select-sm" id="al-sev">${opt('', 'Any severity')}${opt('critical', 'Critical')}${opt('warning', 'Warning')}${opt('info', 'Info')}</select>
        <label class="sr-only" for="al-type">Kind of alert</label><select class="select select-sm" id="al-type">${opt('', 'Any kind')}${Object.entries(types).map(([k, l]) => opt(k, l))}</select>
      </div>`);
  }
  $$('[data-status]', bar).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.status === state.status)));
  $('#al-sev').value = state.sev;
  $('#al-type').value = state.type;
}

// ── alerts tab ────────────────────────────────────────────────────────────────
const sevChip = (s) => { const [l, c] = SEV[s] || [s, 'chip-gray']; return html`<span class="chip ${c}">${l}</span>`; };

function statusCell(a) {
  const [l, c] = STATUS[a.status] || [a.status, 'chip-gray'];
  if (a.status === 'resolved') {
    return html`<span class="cell-main"><span class="chip ${c}">${l}</span><small class="cell-wide">${a.resolution === 'auto' ? 'by the watchdog (the check ran clean)' : 'by ' + (a.resolved_by || 'an admin')}${a.resolved_at ? ' · ' + fmtDateTime(a.resolved_at) : ''}</small></span>`;
  }
  return html`<span class="cell-main"><span class="chip ${c}">${l}</span>${a.status === 'acknowledged' ? html`<small class="cell-wide">${a.acknowledged_by ? 'by ' + a.acknowledged_by : ''}${a.acknowledged_at ? ' · ' + fmtDateTime(a.acknowledged_at) : ''}</small>` : html`<small class="cell-wide">nobody has seen it yet</small>`}</span>`;
}

function alertRow(a) {
  const look = LOOK[a.type];
  const live = a.status !== 'resolved';
  return html`<tr class="${live && a.severity === 'critical' ? 'is-flagged' : ''}" data-alert="${a.id}">
    <td data-label="Severity">${sevChip(a.severity)}</td>
    <td data-label="Alert" class="span2 col-problem"><span class="cell-main"><b>${a.title}</b><small class="cell-prose cell-full">${a.description}</small><small class="cell-wide">${types[a.type] || a.type}${a.entity_type ? ' · ' + a.entity_type + ' ' + a.entity_id : ''}</small></span></td>
    <td data-label="Seen"><span class="cell-main"><b>${num(a.occurrences)}×</b><small class="cell-wide">first ${fmtDateTime(a.first_detected_at)}</small><small class="cell-wide">last ${fmtDateTime(a.last_detected_at)}</small></span></td>
    <td data-label="Status">${statusCell(a)}</td>
    <td class="row-actions-cell"><span class="row-actions row-actions-wrap">
      ${a.status === 'open' && can.manage ? html`<button type="button" class="btn btn-sm" data-ack="${a.id}" aria-label="Acknowledge: ${a.title}" title="Acknowledge — record that a person has seen this. The alert stays open until the problem is gone or you resolve it.">${icon('eye', 'icon-sm')}<span class="md:sr-only 2xl:not-sr-only">Acknowledge</span></button>` : ''}
      ${live && can.manage ? html`<button type="button" class="btn btn-sm" data-resolve="${a.id}" aria-label="Resolve: ${a.title}" title="Resolve — close this alert">${icon('circle-check', 'icon-sm')}<span class="md:sr-only 2xl:not-sr-only">Resolve</span></button>` : ''}
      ${look ? html`<a class="btn btn-sm" href="${url(look[0])}" aria-label="Open ${look[1]} for: ${a.title}" title="Where to look first: ${look[1]}">${icon('arrow-up-right', 'icon-sm')}<span class="md:sr-only 2xl:not-sr-only">${look[1]}</span></a>` : ''}</span></td></tr>`;
}

function dryHtml() {
  if (!dry) return '';
  const r = dry.result;
  const rows = Object.entries(r.verdicts || {}).map(([k, v]) => {
    const [l, c] = VERDICT[v] || [v, 'chip-gray'];
    const label = watchdog?.checks?.find((x) => x.key === k)?.label || k;
    return html`<li class="flex items-center justify-between gap-3"><span>${label}</span><span class="chip ${c}">${l}</span></li>`;
  });
  return html`<div class="callout callout-info mb-4" role="status" data-dry-result>${icon('flask-conical')}<div class="min-w-0 flex-1"><strong>Dry run — nothing was changed.</strong>
    <p class="mt-1">${num(r.checks_run)} check${r.checks_run === 1 ? '' : 's'} ran: ${num(r.ok)} ok, ${num(r.alerting)} would ${r.alerting === 1 ? 'raise or refresh an alert' : 'raise or refresh alerts'}, ${num(r.no_verdict)} had no verdict, ${num(r.disabled)} switched off. No alert was raised or resolved, no e-mail was sent and no provider was contacted.</p>
    <ul class="mt-2 grid gap-1">${rows}</ul></div>
    <span class="callout-actions"><button type="button" class="btn btn-ghost btn-icon btn-sm" data-dry-dismiss aria-label="Dismiss the dry run result">${icon('x', 'icon-sm')}</button></span></div>`;
}

function renderAlerts(d) {
  can = d.can || can;
  types = d.types || types;
  paintToolbar();
  const filtered = state.sev || state.type || state.status !== 'active';
  root().innerHTML = String(html`${dryHtml()}${d.rows.length
    ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Business alerts"><table class="tbl tbl-resp">
      <thead><tr><th>Severity</th><th>Alert</th><th>Seen</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map(alertRow)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: 25 }, 'alerts')}</div>`
    : html`<div class="tbl-wrap">${emptyState({
      ico: 'shield-check', title: filtered ? 'No alerts match' : 'No active alerts',
      text: filtered ? 'Try another status, severity or kind.' : (watchdog?.job?.last_run ? 'The last check found nothing over its threshold. “Not enough data” on the Checks tab means a check could not judge — that is not the same as healthy.' : 'The watchdog has not run yet. It runs from the cron job; you can also run it now.'),
    })}</div>`}`);
}

// ── checks tab ────────────────────────────────────────────────────────────────
function renderChecks(w) {
  watchdog = w;
  const rows = w.checks.map((c) => {
    const [vl, vc, vt] = VERDICT[c.status] || VERDICT.never;
    const [kl, kc, kt] = KIND[c.kind] || KIND.rule;
    return html`<tr data-check="${c.key}">
      <td data-label="Check" class="span2"><span class="cell-main"><b>${c.label}</b><small class="cell-prose">${c.what}</small></span></td>
      <td data-label="Type"><span class="chip ${kc}" title="${kt}">${kl}</span></td>
      <td data-label="Result"><span class="stackcell"><span class="chip ${vc}" title="${vt}">${vl}</span>${c.live_alerts ? html`<span class="chip chip-warn">${c.live_alerts} live alert${c.live_alerts === 1 ? '' : 's'}</span>` : ''}</span></td>
      <td data-label="What it saw" class="col-problem"><span class="cell-main"><small class="cell-prose cell-full">${c.summary}</small><small class="cell-wide">${c.checked_at ? 'checked ' + timeAgo(c.checked_at) : 'not checked yet'}</small></span></td></tr>`;
  });
  root().innerHTML = String(html`${dryHtml()}<div class="callout callout-info mb-4" role="note">${icon('info')}<div class="min-w-0"><strong>How to read this</strong>
    <p class="mt-1"><b>OK</b> means the check ran and found nothing over its threshold. <b>Not enough data</b> and <b>Unavailable</b> mean it could <em>not</em> judge — no alert is raised or resolved on that basis. Checks marked <b>Heuristic</b> compare today with recent history and can be wrong; none of them uses an AI model. The payment gateway has no status endpoint, so its health is judged from payment and notification records, not asked.</p></div></div>
    <div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Watchdog checks"><table class="tbl tbl-resp">
    <thead><tr><th>Check</th><th>Type</th><th>Result</th><th>What it saw</th></tr></thead><tbody>${rows}</tbody></table></div></div>`);
}

// ── thresholds tab ────────────────────────────────────────────────────────────
function renderSettings(d) {
  can = d.can || can;
  const dis = can.manage ? '' : 'disabled';
  const byCheck = {};
  d.fields.forEach((f) => { (byCheck[f.check] ||= []).push(f); });
  const field = (f) => html`<div class="field"><label class="label" for="ws-${f.name}">${f.label}</label>
    <input class="input" id="ws-${f.name}" name="${f.name}" type="number" inputmode="numeric" min="${f.min}" max="${f.max}" step="1" value="${f.value}" ${dis}>
    <p class="field-hint">${f.unit}. Allowed ${num(f.min)}–${num(f.max)}; default ${num(f.default)}.</p></div>`;
  const card = (c) => {
    const [kl, kc, kt] = KIND[c.kind] || KIND.rule;
    const fs = byCheck[c.key] || [];
    return html`<section class="card" aria-labelledby="h-ws-${c.key}"><div class="card-head"><h2 class="card-title" id="h-ws-${c.key}">${c.label}</h2><span class="chip ${kc}" title="${kt}">${kl}</span></div>
      <p class="text-sm text-ink-soft mb-3">${c.what}</p>
      <label class="check mb-3"><input type="checkbox" name="enabled.${c.key}" ${d.enabled[c.key] ? 'checked' : ''} ${dis}><span>Run this check</span></label>
      ${fs.length ? html`<div class="field-row">${fs.map(field)}</div>` : html`<p class="field-hint">This check has no thresholds: any disagreement is reported.</p>`}</section>`;
  };
  root().innerHTML = String(html`<form class="stack-lg" id="al-settings" novalidate aria-label="Watchdog thresholds">
    <div class="callout callout-info" role="note">${icon('info')}<div class="min-w-0"><strong>Thresholds only decide when an alert is raised.</strong> Nothing here blocks a customer or changes an order. Every change is written to the audit log.${can.manage ? '' : ' Only an owner or manager can change them.'}</div></div>
    ${d.checks.map(card)}
    <section class="card" aria-labelledby="h-ws-general"><div class="card-head"><h2 class="card-title" id="h-ws-general">All checks</h2></div>
      <div class="field-row">${(byCheck['*'] || []).map(field)}</div>
      <p class="field-hint">A repeated detection updates the one open alert instead of creating a new one. An alert is resolved by the watchdog itself only after the check has run clean this many times in a row, so a flapping condition does not open and close alerts all day.</p></section>
    ${can.manage ? html`<div class="form-actions form-actions-sticky"><span class="text-sm text-muted mr-auto">Changes apply from the next run of the checks.</span><button type="submit" class="btn btn-primary" id="al-save">${icon('check', 'icon-sm')}<span>Save thresholds</span></button></div>` : ''}
  </form>`);
  autoClearErrors($('#al-settings'));
}

// ── e-mail tab ────────────────────────────────────────────────────────────────
function renderMail(d) {
  can = d.can || can;
  const m = d.mail;
  const dis = can.manage ? '' : 'disabled';
  if (!can.manage) {
    root().innerHTML = String(html`<section class="card" aria-labelledby="h-mail"><div class="card-head"><h2 class="card-title" id="h-mail">${icon('mail')}E-mail alerts</h2><span class="chip ${m.enabled ? 'chip-ok' : 'chip-gray'}">${m.enabled ? 'on' : 'off'}</span></div>
      <p class="text-sm text-ink-soft">${m.enabled ? `Alerts of ${m.min_severity} severity and above are e-mailed to ${m.recipients} recipient${m.recipients === 1 ? '' : 's'}, at most once every ${m.cooldown_min} minutes per alert.` : 'E-mail is off: alerts are shown here only.'} Only an owner or manager can change this.</p></section>`);
    return;
  }
  root().innerHTML = String(html`<form class="card stack-lg" id="al-mail" novalidate aria-labelledby="h-mail">
    <div class="card-head"><h2 class="card-title" id="h-mail">${icon('mail')}E-mail alerts</h2><span class="chip ${m.enabled ? 'chip-ok' : 'chip-gray'}">${m.enabled ? 'on' : 'off'}</span></div>
    <p class="text-sm text-ink-soft">Off by default. When on, a new alert at or above the chosen severity is e-mailed through your hosting provider’s own mail facility (PHP <span class="kbd">mail()</span>) — at most once per alert every cooldown period. A message contains the alert’s title and description and a link to this page: <b>no credentials, no customer data, no technical traces</b>. Success below only means the message was handed to the mail system; delivery is up to the hosting provider.</p>
    <label class="check"><input type="checkbox" name="enabled" ${m.enabled ? 'checked' : ''}><span>Send e-mail for new alerts</span></label>
    <div class="field"><label class="label" for="am-to">Recipients</label><input class="input" id="am-to" name="to" type="text" maxlength="250" autocomplete="off" value="${m.to}" placeholder="owner@example.com, ops@example.com">
      <p class="field-hint">Up to 5 addresses, separated by commas.</p></div>
    <div class="field-row">
      <div class="field"><label class="label" for="am-from">Sent from (optional)</label><input class="input" id="am-from" name="from" type="email" maxlength="120" autocomplete="off" value="${m.from}" placeholder="no-reply@yourdomain.com">
        <p class="field-hint">Leave empty to use no-reply@ your site’s domain. Use an address on your own domain so the message is not marked as spam.</p></div>
      <div class="field"><label class="label" for="am-sev">Send from this severity</label>
        <select class="select" id="am-sev" name="min_severity"><option value="critical" ${m.min_severity === 'critical' ? 'selected' : ''}>Critical only</option><option value="warning" ${m.min_severity === 'warning' ? 'selected' : ''}>Warning and critical</option></select></div>
      <div class="field"><label class="label" for="am-cool">Cooldown (minutes)</label><input class="input" id="am-cool" name="cooldown_min" type="number" inputmode="numeric" min="5" max="1440" step="1" value="${m.cooldown_min}"><p class="field-hint">The same alert is e-mailed at most once in this time. 5–1440.</p></div>
    </div>
    <div class="form-actions"><button type="button" class="btn" id="am-test" ${m.recipients ? '' : 'disabled'} title="${m.recipients ? 'Send one test message to the saved recipients' : 'Save at least one recipient first'}">${icon('send', 'icon-sm')}<span>Send test e-mail</span></button>
      <button type="submit" class="btn btn-primary" id="am-save">${icon('check', 'icon-sm')}<span>Save e-mail settings</span></button></div>
  </form>`);
  autoClearErrors($('#al-mail'));
}

// ── loading ───────────────────────────────────────────────────────────────────
async function load({ silent = false } = {}) {
  const my = ++seq;
  paintTabs();
  paintToolbar();
  if (!silent || !loaded) root().innerHTML = String(skeletonTable(6));
  root().setAttribute('aria-busy', 'true');
  try {
    if (state.tab === 'alerts') {
      const d = await get('alerts', { status: state.status, severity: state.sev, type: state.type, page: state.page });
      if (my !== seq) return;
      renderAlerts(d);
    } else if (state.tab === 'checks') {
      const d = await get('alerts/checks');
      if (my !== seq) return;
      can = d.can;
      renderChecks(d.watchdog);
    } else {
      const d = await get('alerts/settings');
      if (my !== seq) return;
      if (state.tab === 'settings') renderSettings(d); else renderMail(d);
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

const refresh = (opts) => Promise.all([load(opts), loadSummary()]);

// ── actions ───────────────────────────────────────────────────────────────────
async function acknowledge(btn) {
  await withBusy(btn, async () => {
    try {
      await post(`alerts/${btn.dataset.ack}/acknowledge`, {}, { idempotency: true });
      ok('Alert acknowledged', 'It stays open until the problem is gone or you resolve it.');
      await refresh({ silent: true });
      reloadStatus();
    } catch (e) {
      fail('Could not acknowledge', e.message);
      refresh({ silent: true });
    }
  });
}

async function resolve(btn) {
  const yes = await confirmDialog({
    title: 'Resolve this alert?', message: 'It will be closed. If the watchdog finds the problem again, a new alert is opened.', confirmText: 'Resolve alert', tone: 'primary',
  });
  if (!yes) return;
  await withBusy(btn, async () => {
    try {
      await post(`alerts/${btn.dataset.resolve}/resolve`, {}, { idempotency: true });
      ok('Alert resolved');
      await refresh({ silent: true });
      reloadStatus();
    } catch (e) {
      fail('Could not resolve', e.message);
      refresh({ silent: true });
    }
  });
}

async function runNow(btn, dryRun) {
  await withBusy(btn, async () => {
    try {
      const r = await post('jobs/watchdog/run', { dry_run: dryRun });
      const run = r.run;
      if (run.status === 'skipped') { warn('Already running', 'Another run of the checks is in progress. Try again in a minute.'); return; }
      if (run.status === 'failed') { fail('The checks could not run', run.error || 'See the server log.'); await refresh({ silent: true }); return; }
      if (dryRun) {
        dry = { result: run.result };
        ok('Dry run finished', 'Nothing was changed.');
      } else {
        dry = null;
        const x = run.result;
        ok('Checks finished', `${x.ok} ok · ${x.alerting} alerting · ${x.no_verdict} without a verdict · ${x.alerts_raised} raised, ${x.alerts_updated} updated, ${x.alerts_resolved} resolved.`);
      }
      await refresh({ silent: true });
      reloadStatus();
    } catch (e) {
      fail('Could not run the checks', e.message);
    }
  });
}

async function saveSettings(form) {
  const btn = $('#al-save');
  await withBusy(btn, async () => {
    clearFieldErrors(form);
    const thresholds = {};
    const enabled = {};
    for (const el of form.elements) {
      if (!el.name) continue;
      if (el.name.startsWith('enabled.')) enabled[el.name.slice(8)] = el.checked;
      else if (el.type === 'number') thresholds[el.name] = el.value.trim() === '' ? '' : Number(el.value);
    }
    try {
      const d = await put('alerts/settings', { thresholds, enabled }, { idempotency: true });
      ok('Thresholds saved');
      renderSettings({ ...d, can });
      loadSummary();
    } catch (e) {
      if (e.fields) showFieldErrors(form, e.fields); else fail('Could not save', e.message);
    }
  });
}

async function saveMail(form) {
  const btn = $('#am-save');
  await withBusy(btn, async () => {
    clearFieldErrors(form);
    const f = form.elements;
    try {
      const d = await put('alerts/mail', {
        enabled: f.enabled.checked, to: f.to.value.trim(), from: f.from.value.trim(), min_severity: f.min_severity.value, cooldown_min: Number(f.cooldown_min.value),
      }, { idempotency: true });
      ok(d.mail.enabled ? 'E-mail alerts are on' : 'E-mail settings saved', d.mail.enabled ? 'New alerts will be e-mailed.' : 'E-mail alerts are off.');
      renderMail({ mail: d.mail, can });
    } catch (e) {
      if (e.fields) showFieldErrors(form, e.fields); else fail('Could not save', e.message);
    }
  });
}

async function testMail(btn) {
  await withBusy(btn, async () => {
    try {
      const r = (await post('alerts/mail/test')).result;
      (r.sent ? ok : warn)(r.sent ? 'Test e-mail handed to the mail system' : 'The mail system refused the message', r.message);
    } catch (e) {
      fail('Could not send the test', e.message);
    }
  });
}

// ── boot ──────────────────────────────────────────────────────────────────────
export default function init() {
  const q = new URLSearchParams(location.search);
  state.tab = TABS.includes(q.get('tab')) ? q.get('tab') : 'alerts';
  state.status = ['active', 'resolved', 'all'].includes(q.get('status')) ? q.get('status') : 'active';
  state.sev = ['critical', 'warning', 'info'].includes(q.get('severity')) ? q.get('severity') : '';
  state.type = /^[a-z_]{3,40}$/.test(q.get('type') || '') ? q.get('type') : '';
  state.page = Math.max(1, Number(q.get('page')) || 1);

  const tabs = $('#al-tabs');
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
  const bar = $('#al-toolbar');
  on(bar, 'click', '[data-status]', (e, b) => { state.status = b.dataset.status; state.page = 1; load(); });
  bar.addEventListener('change', (e) => {
    if (e.target.id === 'al-sev') { state.sev = e.target.value; state.page = 1; load(); }
    if (e.target.id === 'al-type') { state.type = e.target.value; state.page = 1; load(); }
  });
  const r = root();
  on(r, 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); load(); });
  on(r, 'click', '[data-retry]', () => refresh());
  on(r, 'click', '[data-ack]', (e, b) => acknowledge(b));
  on(r, 'click', '[data-resolve]', (e, b) => resolve(b));
  on(r, 'click', '[data-dry-dismiss]', () => { dry = null; load({ silent: true }); });
  on(r, 'click', '#am-test', (e, b) => testMail(b));
  r.addEventListener('submit', (e) => {
    if (e.target.id === 'al-settings') { e.preventDefault(); saveSettings(e.target); }
    if (e.target.id === 'al-mail') { e.preventDefault(); saveMail(e.target); }
  });
  $('#al-run').addEventListener('click', (e) => runNow(e.currentTarget, false));
  $('#al-dry').addEventListener('click', (e) => runNow(e.currentTarget, true));

  refresh();
  whenVisible(() => { if (state.tab === 'alerts' || state.tab === 'checks') refresh({ silent: true }); else loadSummary(); }, 45000);
}
