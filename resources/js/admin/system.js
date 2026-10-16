import { $, html, icon, on } from '../core/dom.js';
import { fmtDateTime, timeAgo, num } from '../core/format.js';
import { get, post } from './net.js';
import { ok, fail, warn, errorState, withBusy, copyText } from './ui.js';
import { reloadStatus } from './shell.js';

let sys = null;
let jobsData = null;
let canRun = false;
const root = () => $('#system-root');

const JOB_STATE = { ok: ['on schedule', 'chip-ok'], running: ['running now', 'chip-info'], overdue: ['overdue', 'chip-warn'], failing: ['failing', 'chip-bad'], never_run: ['never run', 'chip-gray'] };

async function loadJobs() {
  try { const j = await get('jobs'); jobsData = j.jobs; canRun = !!j.can?.run; } catch { jobsData = null; }
}
async function refresh() {
  await loadJobs();
  render((await get('system')).system);
}

/** Every scheduled job with its last run, last success and failures — straight from the job history (job_runs). */
function jobsCard() {
  if (!jobsData) return '';
  const act = (j, dry) => html`<button type="button" class="btn btn-sm" data-job-run="${j.job}" ${dry ? html`data-dry="1"` : ''} aria-label="${dry ? 'Dry run of ' : 'Run '}${j.label}${dry ? '' : ' now'}" title="${dry ? 'Dry run — show what it would do, change nothing' : 'Run now'}">${icon(dry ? 'flask-conical' : 'play', 'icon-sm')}<span class="md:sr-only 2xl:not-sr-only">${dry ? 'Dry run' : 'Run now'}</span></button>`;
  return html`<section class="card" aria-labelledby="h-jobs"><div class="card-head"><h2 class="card-title" id="h-jobs">${icon('list-checks')}Scheduled jobs</h2><span class="card-sub">each takes a lock, has a time limit and records how it ended</span></div>
    <p class="text-sm text-ink-soft mb-3">“Overdue” means no successful run for much longer than the schedule — check the cron line above. A <b>dry run</b> shows what a job would do and changes nothing.</p>
    <div class="tbl-scroll" tabindex="0" role="region" aria-label="Table of scheduled jobs"><table class="tbl tbl-resp" id="jobs-table"><thead><tr><th>Job</th><th>State</th><th>Last run</th><th>Last success</th><th class="num">Failures (24 h)</th><th><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>${jobsData.map((j) => { const [sl, sc] = JOB_STATE[j.state] || JOB_STATE.never_run; return html`<tr data-job="${j.job}">
      <td data-label="Job" class="span2"><span class="cell-main"><b>${j.label}</b><small class="cell-prose cell-full">${j.what}</small><small class="cell-wide mt-1"><span class="mono">${j.cron}</span>${j.schedule ? ' · ' + j.schedule : ''}</small></span></td>
      <td data-label="State"><span class="chip ${sc}">${sl}</span></td>
      <td data-label="Last run">${j.last_run ? html`<span class="cell-main"><b>${timeAgo(j.last_run.at)}${j.last_run.dry_run ? ' · dry run' : ''}</b><small class="cell-wide">${j.last_run.status}${j.last_run.duration_ms != null ? ' · ' + j.last_run.duration_ms + ' ms' : ''} · ${j.last_run.source}</small>${j.last_run.error ? html`<small class="cell-prose cell-full amt-neg">${j.last_run.error_category}: ${j.last_run.error}</small>` : ''}</span>` : html`<span class="text-muted">never</span>`}</td>
      <td data-label="Last success">${j.last_success_at ? timeAgo(j.last_success_at) : html`<span class="text-muted">never</span>`}</td>
      <td data-label="Failures (24 h)" class="num"><span class="${j.failures_24h ? 'amt-neg' : ''}">${num(j.failures_24h)}</span> <small class="text-muted">of ${num(j.runs_24h)} runs</small></td>
      <td class="row-actions-cell"><span class="row-actions row-actions-wrap">${canRun ? html`${act(j, false)}${j.dry_run_supported ? act(j, true) : ''}` : ''}</span></td></tr>`; })}</tbody></table></div></section>`;
}

const item = (okk, label, detail = '') => html`<div class="health-item ${okk ? 'ok' : 'bad'}">${icon(okk ? 'circle-check' : 'circle-alert')}<span><b>${label}</b>${detail ? html`<br><small class="text-muted">${detail}</small>` : ''}</span></div>`;

function render(s) {
  sys = s;
  const age = s.cron.age_seconds;
  const cronOk = age !== null && age <= 900;
  root().innerHTML = String(html`
  <div class="stack-lg">
    <section class="card" aria-labelledby="h-env"><div class="card-head"><h2 class="card-title" id="h-env">${icon('server')}Server</h2></div>
      <div class="health">
        ${item(s.php_ok, 'PHP ' + s.php, s.php_ok ? 'Meets the 8.1+ requirement' : 'PHP 8.1 or newer is required')}
        ${item(true, 'Database', s.database)}
        ${item(s.https, s.https ? 'HTTPS enabled' : 'Not using HTTPS', s.https ? 'Secure cookies and HSTS are active' : 'Set APP_URL to your https:// address in .env before going live')}
        ${item(s.env === 'production' && !s.debug, 'Environment: ' + s.env, s.debug ? 'Debug output is ON — turn APP_DEBUG off in production' : 'Debug output is off')}
        ${s.extensions.map((x) => item(x.ok || !x.required, `PHP ${x.name}`, x.ok ? 'loaded' : x.required ? 'MISSING — enable it in cPanel → Select PHP Version' : 'optional (faster password hashing)'))}
        ${s.paths.map((p) => item(p.ok, p.path, p.ok ? 'writable' : 'NOT writable — set permissions to 755/775'))}
        ${item(true, 'Business time zone', s.timezone)}
      </div>
      <p class="field-hint mt-3">Site address: <span class="mono">${s.app_url || 'not set'}</span> · Server: ${s.server}</p>
    </section>

    <section class="card" aria-labelledby="h-cron"><div class="card-head"><h2 class="card-title" id="h-cron">${icon('clock')}Background tasks (cron)</h2>
      <span class="chip ${cronOk ? 'chip-ok' : 'chip-warn'}">${s.cron.last_run_at ? 'Last run ' + timeAgo(s.cron.last_run_at) : 'Never run'}</span></div>
      <p class="text-sm text-ink-soft mb-3">The cron job expires unpaid payment sessions, retries and syncs provider orders, runs the business watchdog, writes the daily report and cleans old data. Run it <b>every minute</b>.</p>
      <div class="field"><span class="label">cPanel → Cron Jobs → command</span>
        <div class="copy-field"><input class="input" readonly value="* * * * * ${s.cron.command} >/dev/null 2>&1" aria-label="Cron command"><button type="button" class="btn" data-copy="* * * * * ${s.cron.command} >/dev/null 2>&1">${icon('copy', 'icon-sm')}<span>Copy</span></button></div></div>
      ${s.cron.http_enabled ? html`<div class="field"><span class="label">Or ping this URL every minute (cron-job.org, UptimeRobot…)</span><div class="copy-field"><input class="input" readonly value="${s.cron.url}" aria-label="Cron URL"><button type="button" class="btn" data-copy="${s.cron.url}">${icon('copy', 'icon-sm')}<span>Copy</span></button></div></div>` : html`<p class="field-hint">No HTTP cron key is set. Add <span class="kbd">CRON_KEY=some-long-random-text</span> to <span class="kbd">.env</span> to enable the URL variant.</p>`}
      <div class="form-actions"><button type="button" class="btn" id="run-cron">${icon('refresh-cw', 'icon-sm')}<span>Run background tasks now</span></button></div></section>

    ${jobsCard()}

    <section class="card" aria-labelledby="h-db"><div class="card-head"><h2 class="card-title" id="h-db">${icon('database')}Database updates</h2>
      <span class="chip ${s.migrations.pending.length ? 'chip-warn' : 'chip-ok'}">${s.migrations.pending.length ? s.migrations.pending.length + ' pending' : 'Up to date'}</span></div>
      <p class="text-sm text-ink-soft mb-3">${s.migrations.pending.length
        ? html`A newer release needs these changes: <span class="mono">${s.migrations.pending.join(', ')}</span>. Take a backup, then apply them.`
        : html`${s.migrations.applied} update${s.migrations.applied === 1 ? ' has' : 's have'} been applied. After uploading a newer release, come back here (or run <span class="kbd">php bin/migrate.php</span>) if it ships new files in <span class="kbd">database/migrations/</span>.`}</p>
      <div class="form-actions"><button type="button" class="btn ${s.migrations.pending.length ? 'btn-primary' : ''}" id="run-migrate" ${s.migrations.pending.length ? '' : 'disabled'}>${icon('database', 'icon-sm')}<span>Apply database updates</span></button></div></section>

    <section class="card" aria-labelledby="h-q"><div class="card-head"><h2 class="card-title" id="h-q">${icon('activity')}Queues</h2></div>
      <div class="stat-grid-4">
        <div class="stat"><small>Open payment sessions</small><strong>${num(s.queue.payments_open)}</strong><span>waiting for the customer</span></div>
        <div class="stat"><small>AUTO orders to submit</small><strong>${num(s.queue.orders_submit_due)}</strong><span>paid, not yet at the provider</span></div>
        <div class="stat"><small>Orders at the provider</small><strong>${num(s.queue.orders_in_flight)}</strong><span>being status-checked</span></div>
        <div class="stat"><small>Webhook problems (24 h)</small><strong class="${s.queue.webhook_errors_24h ? 'amt-neg' : ''}">${num(s.queue.webhook_errors_24h)}</strong><span>invalid or failed deliveries</span></div></div></section>
  </div>`);
  root().setAttribute('aria-busy', 'false');
}

export default async function init() {
  try { await refresh(); } catch (e) { root().innerHTML = errorState(e.message).toString(); root().setAttribute('aria-busy', 'false'); return; }
  const r = root();
  on(r, 'click', '[data-copy]', (e, b) => copyText(b.dataset.copy, 'Copied'));
  on(r, 'click', '[data-retry]', () => location.reload());
  on(r, 'click', '#run-migrate', async (e, b) => {
    await withBusy(b, async () => {
      try { const res = await post('system/migrate'); ok(res.message); await refresh(); reloadStatus(); } catch (err) { fail('Could not apply the update', err.message); }
    });
  });
  on(r, 'click', '[data-job-run]', async (e, b) => {
    const dry = b.dataset.dry === '1';
    await withBusy(b, async () => {
      try {
        const res = await post(`jobs/${b.dataset.jobRun}/run`, { dry_run: dry });
        const run = res.run;
        if (run.status === 'skipped') warn('Already running', 'Another run of this job is in progress.');
        else if (run.status === 'failed') fail('The job failed', run.error || 'See the server log.');
        else ok(dry ? 'Dry run finished' : 'Job finished', dry ? 'Nothing was changed.' : `Finished in ${run.duration_ms} ms.`);
        jobsData = res.jobs;
        render(sys);
      } catch (err) { fail('Could not run the job', err.message); }
    });
  });
  on(r, 'click', '#run-cron', async (e, b) => {
    await withBusy(b, async () => {
      try { const res = await post('system/cron'); ok(res.message); await refresh(); reloadStatus(); } catch (err) { fail('Could not run tasks', err.message); }
    });
  });
}
