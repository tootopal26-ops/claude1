import { $, html, icon, on, url } from '../core/dom.js';
import { fmtDateTime, timeAgo } from '../core/format.js';
import { get, put, post } from './net.js';
import { confirmDialog } from '../core/dialog.js';
import { ok, fail, warn, errorState, showFieldErrors, clearFieldErrors, autoClearErrors, withBusy, copyText } from './ui.js';
import { secretField, wireSecrets } from './secret.js';
import { reloadStatus } from './shell.js';

let g = null;
const root = () => $('#gateway-root');

function resultBox(r) {
  const warn = r.level === 'warning' || r.level === 'warn';
  const tone = r.level === 'ok' ? 'success' : warn ? 'warning' : 'error';
  const ico = r.level === 'ok' ? 'circle-check' : warn ? 'triangle-alert' : 'circle-alert';
  return html`<div class="callout callout-${tone}" role="status">${icon(ico)}<div>${r.message}${r.duration_ms ? html`<br><small>${r.duration_ms} ms</small>` : ''}</div></div>`;
}

function render(gw, testResult = null) {
  g = gw;
  const live = g.environment === 'production';
  const headers = g.api_style !== 'bearer';        // "current" API: X-API-Key + X-API-Secret; "bearer": the older single-key API
  root().innerHTML = String(html`
  <form id="gw-form" class="stack" novalidate autocomplete="off">
    <section class="card" aria-labelledby="h-gw">
      <div class="card-head"><h2 class="card-title" id="h-gw">${icon('wallet')}${g.name}</h2>
        <span class="flex flex-wrap gap-2"><span class="chip ${g.is_active ? 'chip-ok' : 'chip-gray'}">${g.is_active ? 'Active' : 'Not active'}</span><span class="chip ${live ? 'chip-brand' : 'chip-warn'}">${live ? 'Production' : 'Test mode'}</span>
        ${g.last_test_ok === null ? '' : html`<span class="chip ${g.last_test_ok ? 'chip-ok' : 'chip-bad'}" title="${g.last_test_message || ''}">Last test: ${g.last_test_ok ? 'passed' : 'failed'} ${g.last_tested_at ? timeAgo(g.last_tested_at) : ''}</span>`}</span></div>

      <div class="switch-row"><div><b>Accept payments through this gateway</b><small>Customers see the QR payment page only while a gateway is active.</small></div><label class="switch"><input type="checkbox" name="is_active" ${g.is_active ? 'checked' : ''} aria-label="Gateway active"><span></span></label></div>

      <div class="form-section"><h3>Environment</h3>
        <div class="seg" role="radiogroup" aria-label="Environment">
          <button type="button" role="radio" data-env="test" aria-checked="${live ? 'false' : 'true'}">Test</button>
          <button type="button" role="radio" data-env="production" aria-checked="${live ? 'true' : 'false'}">Production</button></div>
        <input type="hidden" name="environment" value="${g.environment}">
        <p class="field-hint">This is only a label for you — it does not change the gateway URL. Alpha UPI has no sandbox and takes real payments only, so your test is a real ₹5 order (the money goes to your own account).</p></div>

      <div class="form-section"><h3>Connection</h3>
        <div class="field-row"><div class="field"><label class="label req" for="gw-url">Gateway base URL</label>
          <input class="input mono" id="gw-url" name="base_url" value="${g.base_url}" placeholder="https://alphaupi.site" spellcheck="false" autocapitalize="none">
          <p class="field-hint">The API host only — for Alpha UPI: <span class="kbd">https://alphaupi.site</span>. A trailing <span class="kbd">/api</span> is removed for you. Must be https:// in production.</p></div>
        <div class="field"><label class="label" for="gw-style">API type</label>
          <select class="select" id="gw-style" name="api_style"><option value="headers" ${headers ? 'selected' : ''}>Current — API key + API secret</option><option value="bearer" ${headers ? '' : 'selected'}>Older — API key only (Bearer)</option></select>
          <p class="field-hint">Choose “Current” when your Alpha UPI dashboard shows a <b>Production API Key</b> (<span class="kbd">X-API-Key</span>) and a <b>Production API Secret</b> (<span class="kbd">X-API-Secret</span>).</p></div></div>
        <div class="field" data-style-only="bearer" ${headers ? 'hidden' : ''}><label class="label" for="gw-pref">Preferred UPI provider</label>
          <select class="select" id="gw-pref" name="provider_pref"><option value="auto" ${g.provider_pref === 'auto' ? 'selected' : ''}>Automatic</option><option value="bharatpe" ${g.provider_pref === 'bharatpe' ? 'selected' : ''}>BharatPe</option><option value="paytm" ${g.provider_pref === 'paytm' ? 'selected' : ''}>Paytm</option></select></div>
        ${secretField({ name: 'api_key', label: 'API key', has: g.has_api_key, hint: g.api_key_hint, placeholder: 'Paste the Production API Key from your Alpha UPI dashboard', help: 'Stored encrypted on the server. It is never shown again after saving.' })}
        <div data-style-only="headers" ${headers ? '' : 'hidden'}>${secretField({ name: 'api_secret', label: 'API secret', has: g.has_api_secret, hint: g.api_secret_hint, placeholder: 'Paste the Production API Secret', help: 'Sent with the API key on every request. Stored encrypted; never shown again after saving.' })}</div>
        ${secretField({ name: 'webhook_secret', label: 'Webhook secret', has: g.has_webhook_secret, hint: g.webhook_secret_hint, placeholder: 'Paste the webhook secret', help: 'The “webhook” key from your Alpha UPI dashboard. Used to verify that payment notifications really come from the gateway.' })}
      </div>

      <div class="form-section"><h3>Payment page</h3>
        <div class="switch-row"><div><b>Show the gateway’s QR on my payment page</b><small>Reads the UPI request on the gateway’s own checkout page, so customers scan one QR on your page and can download it. When that page carries none — or this is off — customers go straight to the gateway’s checkout page. “Check QR support” tells you which it is.</small></div><label class="switch"><input type="checkbox" name="qr_from_checkout_page" ${g.qr_from_checkout_page ? 'checked' : ''} aria-label="Show the gateway’s QR on my payment page"><span></span></label></div></div>

      <div class="form-actions"><span class="spacer text-sm text-muted">${g.last_tested_at ? 'Last tested ' + fmtDateTime(g.last_tested_at) : 'Not tested yet'}</span>
        <button type="button" class="btn" id="gw-test">${icon('activity', 'icon-sm')}<span>Test connection</span></button>
        <button type="button" class="btn" id="gw-qr" title="Creates a ₹1 test order (never paid) to see whether the gateway shows a scannable QR on your own payment page">${icon('qr-code', 'icon-sm')}<span>Check QR support</span></button>
        <button type="submit" class="btn btn-primary btn-shine" id="gw-save">${icon('check')}<span>Save gateway</span></button></div>
      <div id="gw-result" class="mt-4" aria-live="polite">${testResult ? resultBox(testResult) : ''}</div>
    </section>
  </form>

  <section class="card mt-5" aria-labelledby="h-wh"><div class="card-head"><h2 class="card-title" id="h-wh">${icon('webhook')}Webhook</h2>
    ${g.last_webhook ? html`<span class="chip ${g.last_webhook.signature_valid ? 'chip-ok' : 'chip-bad'}">Last delivery ${timeAgo(g.last_webhook.at)} · ${g.last_webhook.signature_valid ? g.last_webhook.status : 'bad signature'}</span>` : html`<span class="chip chip-gray">No deliveries yet</span>`}</div>
    <p class="text-sm text-ink-soft mb-3">Give this URL to your payment gateway as the webhook (callback) endpoint. An order is marked paid only from the gateway’s signed notification or from our own status lookup, for exactly the order’s amount in INR. After a notification is accepted the shop can ask the gateway to confirm it, and anything that disagrees is flagged under Payment checks.</p>
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
  const out = { base_url: f.elements.base_url.value, api_style: f.elements.api_style.value, provider_pref: f.elements.provider_pref.value, environment: f.elements.environment.value, is_active: f.elements.is_active.checked, qr_from_checkout_page: f.elements.qr_from_checkout_page.checked };
  for (const k of ['api_key', 'api_secret', 'webhook_secret']) { const el = f.elements[k]; if (el && !el.disabled && el.value.trim()) out[k] = el.value.trim(); }
  return out;
}

export default async function init() {
  try { render((await get('gateway')).gateway); } catch (e) { root().innerHTML = errorState(e.message).toString(); root().setAttribute('aria-busy', 'false'); return; }
  const r = root();
  wireSecrets(r);
  on(r, 'click', '[data-env]', (e, b) => {
    $('[name="environment"]').value = b.dataset.env;
    r.querySelectorAll('[data-env]').forEach((x) => { const on_ = x === b; x.setAttribute('aria-checked', String(on_)); });
  });
  on(r, 'change', '#gw-style', (e, sel) => r.querySelectorAll('[data-style-only]').forEach((el) => { el.hidden = el.dataset.styleOnly !== (sel.value === 'bearer' ? 'bearer' : 'headers'); }));
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
  // save first when the form has unsaved connection details, so a test uses what is on screen
  const saveConnectionIfChanged = async () => {
    const b0 = body();
    const changed = b0.base_url !== g.base_url || b0.api_key || b0.api_secret || b0.webhook_secret || b0.api_style !== g.api_style || b0.provider_pref !== g.provider_pref || b0.qr_from_checkout_page !== g.qr_from_checkout_page;
    if (changed) await put(`gateway/${g.id}`, { ...b0, is_active: g.is_active }, { idempotency: true });
  };
  on(r, 'click', '#gw-qr', async (e, b) => {
    if (!(await confirmDialog({
      title: 'Check QR support?',
      message: 'This creates one ₹1 test order at your payment gateway and looks at its checkout page for the UPI payment request behind its QR. The test order is never paid, does not appear in your orders and expires on its own.',
      confirmText: 'Create test order',
    }))) return;
    await withBusy(b, async () => {
      try {
        await saveConnectionIfChanged();
        const { result } = await post(`gateway/${g.id}/qr-check`, {}, { idempotency: true, timeout: 40000 });
        $('#gw-result').innerHTML = String(resultBox(result));
        (result.ok ? (result.level === 'ok' ? ok : warn) : fail)(result.found ? 'Your payment page can show the gateway’s QR' : result.ok ? 'No QR available from the gateway' : 'QR check failed', result.found ? 'Customers will see a scannable QR with a Download button.' : result.ok ? 'Customers are sent to the gateway’s own checkout page instead.' : result.message);
      } catch (err) {
        if (err.fields) { showFieldErrors(r, err.fields); fail('Please check the highlighted fields', err.message); } else fail('QR check failed', err.message);
      }
    });
  });
  on(r, 'click', '#gw-test', async (e, b) => {
    await withBusy(b, async () => {
      try {
        await saveConnectionIfChanged();
        const res = await post(`gateway/${g.id}/test`);
        render(res.gateway, res.result);
        reloadStatus();
        (res.result.ok ? (res.result.level === 'ok' ? ok : warn) : fail)(res.result.ok ? (res.result.level === 'ok' ? 'Connection test passed' : 'Gateway reachable') : 'Connection test failed', res.result.message);
      } catch (err) {
        if (err.fields) { showFieldErrors(r, err.fields); fail('Please check the highlighted fields', err.message); } else fail('Test failed', err.message);
      }
    });
  });
}
