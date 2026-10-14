import { $, html, icon, on, url } from '../core/dom.js';
import { fmtDateTime, timeAgo } from '../core/format.js';
import { get, put, post } from './net.js';
import { ok, fail, errorState, showFieldErrors, clearFieldErrors, autoClearErrors, withBusy, copyText } from './ui.js';
import { secretField, wireSecrets } from './secret.js';
import { reloadStatus } from './shell.js';

let g = null;
const root = () => $('#gateway-root');

function resultBox(r) {
  const tone = r.level === 'ok' ? 'success' : r.level === 'warn' ? 'warning' : 'error';
  const ico = r.level === 'ok' ? 'circle-check' : r.level === 'warn' ? 'triangle-alert' : 'circle-alert';
  return html`<div class="callout callout-${tone}" role="status">${icon(ico)}<div>${r.message}${r.duration_ms ? html`<br><small>${r.duration_ms} ms</small>` : ''}</div></div>`;
}

function render(gw, testResult = null) {
  g = gw;
  const live = g.environment === 'production';
  root().innerHTML = String(html`
  <form id="gw-form" class="stack" novalidate autocomplete="off">
    <section class="card" aria-labelledby="h-gw">
      <div class="card-head"><h2 class="card-title" id="h-gw">${icon('wallet')}${g.name}</h2>
        <span class="flex flex-wrap gap-2"><span class="chip ${g.is_active ? 'chip-ok' : 'chip-gray'}">${g.is_active ? 'Active' : 'Not active'}</span><span class="chip ${live ? 'chip-brand' : 'chip-warn'}">${live ? 'Production' : 'Test mode'}</span>
        ${g.last_test_ok === null ? '' : html`<span class="chip ${g.last_test_ok ? 'chip-ok' : 'chip-bad'}" title="${g.last_test_message || ''}">Last test: ${g.last_test_ok ? 'passed' : 'failed'} ${g.last_tested_at ? timeAgo(g.last_tested_at) : ''}</span>`}</span></div>

      <div class="switch-row"><div><b>Accept payments through this gateway</b><small>Customers see the QR payment page only while a gateway is active.</small></div><label class="switch"><input type="checkbox" name="is_active" ${g.is_active ? 'checked' : ''} aria-label="Gateway active"><span></span></label></div>

      <div class="form-section"><h3>Environment</h3>
        <div class="seg" role="radiogroup" aria-label="Environment">
          <button type="button" role="radio" data-env="test" aria-checked="${!live}" aria-selected="${!live}">Test</button>
          <button type="button" role="radio" data-env="production" aria-checked="${live}" aria-selected="${live}">Production</button></div>
        <input type="hidden" name="environment" value="${g.environment}">
        <p class="field-hint">Test mode is only a label for you — it does not change the gateway URL. Use your provider’s sandbox URL while testing, then switch both to production.</p></div>

      <div class="form-section"><h3>Connection</h3>
        <div class="field-row"><div class="field"><label class="label req" for="gw-url">Gateway base URL</label>
          <input class="input mono" id="gw-url" name="base_url" value="${g.base_url}" placeholder="https://your-alpha-upi-host" spellcheck="false" autocapitalize="none">
          <p class="field-hint">Without a trailing <span class="kbd">/api</span>. Must be https:// in production.</p></div>
        <div class="field"><label class="label" for="gw-pref">Preferred UPI provider</label>
          <select class="select" id="gw-pref" name="provider_pref"><option value="auto" ${g.provider_pref === 'auto' ? 'selected' : ''}>Automatic</option><option value="bharatpe" ${g.provider_pref === 'bharatpe' ? 'selected' : ''}>BharatPe</option><option value="paytm" ${g.provider_pref === 'paytm' ? 'selected' : ''}>Paytm</option></select></div></div>
        ${secretField({ name: 'api_key', label: 'API key', has: g.has_api_key, hint: g.api_key_hint, placeholder: 'Paste the API key from your Alpha UPI dashboard', help: 'Stored encrypted on the server. It is never shown again after saving.' })}
        ${secretField({ name: 'webhook_secret', label: 'Webhook secret', has: g.has_webhook_secret, hint: g.webhook_secret_hint, placeholder: 'Paste the webhook secret', help: 'Used to verify that payment notifications really come from the gateway.' })}
      </div>

      <div class="form-actions"><span class="spacer text-sm text-muted">${g.last_tested_at ? 'Last tested ' + fmtDateTime(g.last_tested_at) : 'Not tested yet'}</span>
        <button type="button" class="btn" id="gw-test">${icon('activity', 'icon-sm')}<span>Test connection</span></button>
        <button type="submit" class="btn btn-primary btn-shine" id="gw-save">${icon('check')}<span>Save gateway</span></button></div>
      <div id="gw-result" class="mt-4" aria-live="polite">${testResult ? resultBox(testResult) : ''}</div>
    </section>
  </form>

  <section class="card mt-5" aria-labelledby="h-wh"><div class="card-head"><h2 class="card-title" id="h-wh">${icon('webhook')}Webhook</h2>
    ${g.last_webhook ? html`<span class="chip ${g.last_webhook.signature_valid ? 'chip-ok' : 'chip-bad'}">Last delivery ${timeAgo(g.last_webhook.at)} · ${g.last_webhook.signature_valid ? g.last_webhook.status : 'bad signature'}</span>` : html`<span class="chip chip-gray">No deliveries yet</span>`}</div>
    <p class="text-sm text-ink-soft mb-3">Give this URL to your payment gateway as the webhook (callback) endpoint. Payments are confirmed by the gateway’s signed notification and double-checked with the gateway before an order is marked paid.</p>
    <div class="copy-field"><input class="input" readonly value="${g.webhook_url}" aria-label="Webhook URL" id="wh-url"><button type="button" class="btn" data-copy-url>${icon('copy', 'icon-sm')}<span>Copy</span></button></div>
    <ol class="mt-4 grid gap-1.5 text-[13px] text-muted list-decimal pl-5"><li>Open your Alpha UPI dashboard and find the webhook / callback setting.</li><li>Paste the URL above and use the same webhook secret you saved here.</li><li>Press “Test connection” — then make a ₹1–₹5 test payment from the store to see a delivery appear here.</li></ol>
    ${g.webhook_url.startsWith('http://') ? html`<div class="callout callout-warning mt-4">${icon('triangle-alert')}<div><strong>This URL is not HTTPS.</strong> Real gateways usually require a public https:// URL. Set <span class="kbd">APP_URL</span> to your https:// domain in <span class="kbd">.env</span>.</div></div>` : ''}
  </section>

  <section class="card mt-5" aria-labelledby="h-ref"><div class="card-head"><h2 class="card-title" id="h-ref">${icon('undo-2')}Refunds</h2></div>
    <p class="text-sm text-ink-soft">Alpha UPI has no refund API, so refunds are made by hand from your bank or UPI app and then recorded on the order (<b>Orders → open an order → Record refund</b>). Reports subtract recorded refunds from revenue and profit.</p></section>`);
  autoClearErrors(root());
  root().setAttribute('aria-busy', 'false');
}

function body() {
  const f = $('#gw-form');
  const out = { base_url: f.elements.base_url.value, provider_pref: f.elements.provider_pref.value, environment: f.elements.environment.value, is_active: f.elements.is_active.checked };
  for (const k of ['api_key', 'webhook_secret']) { const el = f.elements[k]; if (el && !el.disabled && el.value.trim()) out[k] = el.value.trim(); }
  return out;
}

export default async function init() {
  try { render((await get('gateway')).gateway); } catch (e) { root().innerHTML = errorState(e.message).toString(); root().setAttribute('aria-busy', 'false'); return; }
  const r = root();
  wireSecrets(r);
  on(r, 'click', '[data-env]', (e, b) => {
    $('[name="environment"]').value = b.dataset.env;
    r.querySelectorAll('[data-env]').forEach((x) => { const on_ = x === b; x.setAttribute('aria-checked', String(on_)); x.setAttribute('aria-selected', String(on_)); });
  });
  on(r, 'click', '[data-copy-url]', () => copyText(g.webhook_url, 'Webhook URL copied'));
  on(r, 'click', '[data-retry]', () => location.reload());
  r.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearFieldErrors(r);
    await withBusy($('#gw-save'), async () => {
      try {
        const res = await put(`gateway/${g.id}`, body(), { idempotency: true });
        ok('Payment gateway saved', res.gateway.is_active ? 'The gateway is active.' : 'The gateway is not active yet.');
        render(res.gateway);
        reloadStatus();
      } catch (err) {
        if (err.fields) { showFieldErrors(r, err.fields); fail('Please check the highlighted fields', err.message); } else fail('Could not save', err.message);
      }
    });
  });
  on(r, 'click', '#gw-test', async (e, b) => {
    await withBusy(b, async () => {
      try {
        // save first when the form has unsaved connection details, so the test uses what is on screen
        const b0 = body();
        const changed = b0.base_url !== g.base_url || b0.api_key || b0.webhook_secret || b0.provider_pref !== g.provider_pref;
        if (changed) await put(`gateway/${g.id}`, { ...b0, is_active: g.is_active }, { idempotency: true });
        const res = await post(`gateway/${g.id}/test`);
        render(res.gateway, res.result);
        reloadStatus();
        (res.result.ok ? ok : fail)(res.result.ok ? 'Connection test passed' : 'Connection test failed', res.result.message);
      } catch (err) {
        if (err.fields) { showFieldErrors(r, err.fields); fail('Please check the highlighted fields', err.message); } else fail('Test failed', err.message);
      }
    });
  });
}
