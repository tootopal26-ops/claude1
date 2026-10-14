import { $, html, icon, on } from '../core/dom.js';
import { fmtDateTime, timeAgo, num } from '../core/format.js';
import { get, post } from './net.js';
import { ok, fail, errorState, withBusy, copyText } from './ui.js';
import { reloadStatus } from './shell.js';

let sys = null;
const root = () => $('#system-root');

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
      <p class="text-sm text-ink-soft mb-3">The cron job expires unpaid payment sessions, retries and syncs provider orders, refreshes yesterday’s reports and cleans old data. Run it <b>every minute</b>.</p>
      <div class="field"><span class="label">cPanel → Cron Jobs → command</span>
        <div class="copy-field"><input class="input" readonly value="* * * * * ${s.cron.command} >/dev/null 2>&1" aria-label="Cron command"><button type="button" class="btn" data-copy="* * * * * ${s.cron.command} >/dev/null 2>&1">${icon('copy', 'icon-sm')}<span>Copy</span></button></div></div>
      ${s.cron.http_enabled ? html`<div class="field"><span class="label">Or ping this URL every minute (cron-job.org, UptimeRobot…)</span><div class="copy-field"><input class="input" readonly value="${s.cron.url}" aria-label="Cron URL"><button type="button" class="btn" data-copy="${s.cron.url}">${icon('copy', 'icon-sm')}<span>Copy</span></button></div></div>` : html`<p class="field-hint">No HTTP cron key is set. Add <span class="kbd">CRON_KEY=some-long-random-text</span> to <span class="kbd">.env</span> to enable the URL variant.</p>`}
      <div class="form-actions"><button type="button" class="btn" id="run-cron">${icon('refresh-cw', 'icon-sm')}<span>Run background tasks now</span></button></div></section>

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
  try { render((await get('system')).system); } catch (e) { root().innerHTML = errorState(e.message).toString(); root().setAttribute('aria-busy', 'false'); return; }
  const r = root();
  on(r, 'click', '[data-copy]', (e, b) => copyText(b.dataset.copy, 'Copied'));
  on(r, 'click', '[data-retry]', () => location.reload());
  on(r, 'click', '#run-cron', async (e, b) => {
    await withBusy(b, async () => {
      try { const res = await post('system/cron'); ok(res.message); render((await get('system')).system); reloadStatus(); } catch (err) { fail('Could not run tasks', err.message); }
    });
  });
}
