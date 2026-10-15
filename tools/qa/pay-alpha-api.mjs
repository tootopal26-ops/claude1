// The CURRENT Alpha UPI API end to end in a real browser (docs of alphaupi.site: X-API-Key + X-API-Secret headers, POST /api/create-order,
// POST /api/check-status, a webhook with no event_id): the admin form (API type, API secret, masked secrets, Test connection results),
// a real checkout against the mock gateway speaking that API, the signed webhook, the customer returning from the gateway's page
// (?status=success&order_id=… — never trusted, always verified), forged deliveries and the calm customer message when the gateway refuses.
// The mock gateway (tools/mock-alpha-upi) switches flavour with /__control {style:"headers"}; every other suite runs the older Bearer API.
//   NO_BASELINE=1 skips the dev-database reset (use it with BASE=… DB_NAME=… against another copy of the site).
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, ROOT, sql, rows, alpha, mocksReset, resetLimits, gatewayId, payment, payOk, decodeQr, openCard, placeOrder, useMockProviderIds, sleep } from './site.mjs';
import { login, go } from './admin.mjs';
import { suite } from './harness.mjs';

const OUT = process.env.QA_OUT || '/tmp/qa/pay-alpha-api';
const t = suite('pay-alpha-api', OUT);
if (!process.env.NO_BASELINE) execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
useMockProviderIds(); resetLimits(); await mocksReset();
const browser = await launch();
const GW = gatewayId();
const KEY = 'pi_live_testkey_1234567890', SECRET = 'sk_live_testsecret_0987654321', WH = 'whsec_test_abcdef123456';

// the mock gateway now speaks the current API
await alpha('/__control', { style: 'headers', apiKey: KEY, apiSecret: SECRET, webhookSecret: WH, webhookUrl: `${BASE}/webhooks/payments/${GW}` });

const admin = await newPage(browser, { width: 1366, height: 900 }, { allowStatus: [401, 403, 404] });
t.page = admin;
await login(admin);
const toast = (type) => admin.locator(`.toast[data-type="${type}"]`).last();
const state = async () => (await fetch('http://127.0.0.1:4010/__state')).json();
const gatewayJson = async () => (await admin.request.get(BASE + '/admin/api/gateway', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } })).json();
const testConnection = async () => {
  await admin.evaluate(() => { document.querySelector('#gw-result').innerHTML = ''; });   // the previous answer must not be mistaken for this one
  await admin.click('#gw-test');
  await admin.waitForSelector('#gw-result .callout');
  return { text: await t.text(admin.locator('#gw-result')), cls: await admin.locator('#gw-result .callout').first().getAttribute('class') };
};

// ═══════════════════════════ admin form ═══════════════════════════
await t.step('Admin → Payment gateway: choose the API type; "Current" asks for the API secret and hides the older provider choice', async () => {
  await go(admin, '/admin/gateway');
  t.eq(await admin.locator('#gw-style').inputValue(), 'bearer', 'the dev gateway was set up as an older (Bearer) one');
  t.eq(await admin.locator('[data-style-only="headers"]').isVisible(), false);
  t.eq(await admin.locator('#gw-pref').isVisible(), true);
  await admin.selectOption('#gw-style', 'headers');
  t.eq(await admin.locator('[data-style-only="headers"]').isVisible(), true, 'API secret field appears');
  t.eq(await admin.locator('#gw-pref').isVisible(), false, 'provider choice is for the older API only');
  t.has((await t.text(admin.locator('[data-style-only="headers"]'))).toLowerCase(), 'api secret');   // labels are upper-cased by CSS
  t.has(await t.text(admin.locator('#gw-style + .field-hint')), 'X-API-Key');
  t.has(await admin.locator('#gw-url').getAttribute('placeholder'), 'https://alphaupi.site');
  await admin.screenshot({ path: `${OUT}/gateway-form.png`, fullPage: true });
});

await t.step('saving the three credentials: encrypted, masked everywhere, never in the page or the JSON', async () => {
  await admin.click('[data-secret-replace="api_key"]'); await admin.fill('#s-api_key', KEY);
  await admin.fill('#s-api_secret', SECRET);
  await admin.click('[data-secret-replace="webhook_secret"]'); await admin.fill('#s-webhook_secret', WH);
  await admin.click('#gw-save');
  await toast('success').waitFor();
  t.eq(await admin.locator('#gw-style').inputValue(), 'headers');
  for (const f of ['api_key', 'api_secret', 'webhook_secret']) t.has(await t.text(admin.locator(`[data-secret="${f}"]`)), 'Saved and encrypted', f);
  const html = await admin.content(), json = JSON.stringify(await gatewayJson());
  for (const s of [KEY, SECRET, WH]) { t.ok(!html.includes(s), 'HTML leaks a secret'); t.ok(!json.includes(s), 'JSON leaks a secret'); }
  t.eq(sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(config_json, '$.api_style')) FROM payment_gateways WHERE id = ${GW}`), 'headers');
  const enc = sql(`SELECT secrets_enc FROM payment_gateways WHERE id = ${GW}`);
  for (const s of [KEY, SECRET, WH]) t.ok(!enc.includes(s), 'secrets are encrypted at rest');
  t.has(sql(`SELECT GROUP_CONCAT(meta_json) FROM audit_logs WHERE action = 'gateway.saved'`), 'api_secret', 'the audit log names the field …');
  t.ok(!sql(`SELECT GROUP_CONCAT(meta_json) FROM audit_logs WHERE action = 'gateway.saved'`).includes(SECRET), '… and never the value');
});

await t.step('Test connection: reachable (warning with the ₹5 hint) · wrong secret rejected · a wrong address says so in plain words', async () => {
  try {
  let r = await testConnection();
  t.has(r.cls, 'callout-warning', r.text);
  t.has(r.text, '₹5 test order');
  const calls = (await state()).requests.filter((c) => c.path === '/api/check-status');
  t.ok(calls.length >= 1 && calls.every((c) => c.auth === 'headers'), 'the documented /api/check-status endpoint with header auth');

  await alpha('/__control', { apiSecret: 'sk_live_somebody_else' });
  r = await testConnection();
  t.has(r.cls, 'callout-error', r.text);
  t.has(r.text, 'rejected the API key or API secret');
  await alpha('/__control', { apiSecret: SECRET });

  await admin.fill('#gw-url', 'http://127.0.0.1:4010/html404');
  r = await testConnection();
  t.has(r.cls, 'callout-error', r.text);
  t.has(r.text, 'web page (HTTP 404)'); t.has(r.text, 'https://alphaupi.site');
  await admin.screenshot({ path: `${OUT}/gateway-wrong-url.png`, fullPage: true });
  await admin.fill('#gw-url', 'http://127.0.0.1:4010');
  await admin.click('#gw-save'); await toast('success').waitFor();
  r = await testConnection();
  t.has(r.cls, 'callout-warning', 'the right address works again: ' + r.text);
  } finally {
    await alpha('/__control', { apiSecret: SECRET });
    sql(`UPDATE payment_gateways SET config_json = JSON_SET(config_json, '$.base_url', 'http://127.0.0.1:4010') WHERE id = ${GW}`);
  }
});

// ═══════════════════════════ a real checkout on the current API ═══════════════════════════
const shop = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [503] });
let ORDER;
await t.step('checkout: the order is created with the documented request, and the QR encodes the gateway\'s hosted payment link', async () => {
  t.page = shop; await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
  ORDER = await placeOrder(shop, 'ig-followers-50', '@alphaapi_check');
  const p = payment(ORDER.ref);
  const call = (await state()).requests.filter((c) => c.path === '/api/create-order').at(-1);
  t.eq(call.auth, 'headers');
  t.eq(call.body.amount, '5.00'); t.eq(call.body.order_id, p.gwOrder); t.eq(call.body.customer_name, 'Customer');
  t.has(call.body.callback_url, `/pay/${ORDER.ref}`); t.has(call.body.description, p.publicId);
  t.eq(p.pAmount, 500, 'the amount is the SERVER price, in paise');
  const url = sql(`SELECT payment_url FROM payments WHERE ref = '${ORDER.ref}'`);
  t.has(url, `/pay/${p.gwOrder}`);
  const png = Buffer.from(await (await fetch(`${BASE}/pay/${ORDER.ref}/qr.png`)).arrayBuffer());
  t.eq(decodeQr(png), url, 'scanning the QR opens the gateway page');
  t.eq((await t.text(shop.locator('[data-view=pending] h1'))).toUpperCase(), 'SCAN AND PAY');
  await shop.screenshot({ path: `${OUT}/pay-page.png` });
});

await t.step('the signed webhook (no event_id, X-PayIndia-Signature) flips the page to "paid"; a redelivery is acknowledged and ignored', async () => {
  const sent = await payOk(ORDER.ref);
  t.eq(sent.webhook.status, 200, JSON.stringify(sent));
  await shop.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 });
  const p = payment(ORDER.ref);
  t.eq(p.pStatus, 'success'); t.ok(p.oStatus !== 'PENDING_PAYMENT', 'the order moved on: ' + p.oStatus);
  const [status, sigValid, eventId, verified] = rows(`SELECT w.status, w.signature_valid, w.event_id, pay.verified_via FROM webhook_events w JOIN payments pay ON pay.ref = '${ORDER.ref}' ORDER BY w.id DESC LIMIT 1`)[0];
  t.eq([status, sigValid, verified], ['processed', '1', 'webhook']);
  t.ok(String(eventId).startsWith('body:'), 'no event_id in the payload → identical redeliveries are recognised by their body: ' + eventId);
  const again = await alpha(`/__resend/${p.gwOrder}`);
  t.eq([again.status, again.text], [200, 'duplicate']);
  t.eq(Number(sql(`SELECT COUNT(*) FROM order_status_history WHERE order_id = ${p.id} AND to_status = 'PAID'`)), 1, 'paid exactly once');
});

await t.step('the customer returns from the gateway (?status=success&order_id=…): the server verifies with /api/check-status — the URL itself is never believed', async () => {
  // (a) really paid, but the webhook never arrives → the return verifies it; the UTR in the URL is ignored
  t.page = shop; await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
  const a = await placeOrder(shop, 'ig-likes-100', 'https://www.instagram.com/p/AlphaApiReturn1/');
  const pa = payment(a.ref);
  await payOk(a.ref, { noWebhook: true, utr: 'UTRREAL12345' });
  await shop.goto(`${BASE}/pay/${a.ref}?status=success&order_id=${pa.gwOrder}&amount=5.00&utr=FORGED-UTR`, { waitUntil: 'networkidle' });
  await shop.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 12000 });
  t.eq(sql(`SELECT provider_txn_id FROM payments WHERE ref = '${a.ref}'`), 'UTRREAL12345', 'the gateway\'s UTR, not the one in the address bar');
  t.eq(sql(`SELECT verified_via FROM payments WHERE ref = '${a.ref}'`), 'poll');
  // (b) NOT paid: a hand-made "success" URL must not show success
  await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
  const b = await placeOrder(shop, 'ig-views-1k', 'https://www.instagram.com/p/AlphaApiForged1/');
  const pb = payment(b.ref);
  await shop.goto(`${BASE}/pay/${b.ref}?status=success&order_id=${pb.gwOrder}&amount=5.00&utr=FORGED`, { waitUntil: 'networkidle' });
  await sleep(2500);
  t.eq(await shop.locator('[data-view=paid]').isHidden(), true, 'a forged redirect shows no success');
  t.eq(sql(`SELECT status FROM payments WHERE ref = '${b.ref}'`), 'pending');
  await shop.screenshot({ path: `${OUT}/forged-return.png` });
});

await t.step('forged and unknown deliveries: bad signature → 401 and nothing changes; a signed event for an unknown order is acknowledged', async () => {
  const c = await placePendingOrder();
  const body = { event: 'payment.success', status: 'success', order_id: c.gwOrder, amount: 5.0, utr: 'UTRFORGE1' };
  let r = await alpha('/__webhook', { body, signature: 'deadbeef' });
  t.eq(r.status, 401);
  r = await alpha('/__webhook', { body, secret: 'not-the-webhook-secret' });
  t.eq(r.status, 401, 'signed with the wrong secret');
  t.eq(sql(`SELECT status FROM payments WHERE gateway_order_id = '${c.gwOrder}'`), 'pending', 'nothing was settled');
  t.ok(Number(sql(`SELECT COUNT(*) FROM webhook_events WHERE signature_valid = 0`)) >= 2, 'both are on record as invalid');
  r = await alpha('/__webhook', { body: { ...body, order_id: 'NO-SUCH-ORDER' } });
  t.eq(r.status, 200, 'a signed event for an order we do not know cannot be helped by a retry');
  r = await alpha('/__webhook', { body: { ...body, amount: 1.0 } });
  t.eq(r.status, 200); t.eq(sql(`SELECT status FROM payments WHERE gateway_order_id = '${c.gwOrder}'`), 'mismatch', 'a wrong amount is held for review');
});

/** A pending payment created through the real checkout code path of the site, without a browser. */
async function placePendingOrder() {
  await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
  const o = await placeOrder(shop, 'ig-likes-50k', 'https://www.instagram.com/p/AlphaApiPending1/');
  return { ref: o.ref, ...payment(o.ref) };
}

await t.step('when the gateway refuses (plan quota / wrong secret / offline) the customer gets one calm message and the order is not left hanging', async () => {
  for (const [label, setup, reset] of [
    ['quota', () => alpha('/__control', { mode: { quota: true } }), () => alpha('/__control', { mode: { quota: false } })],
    ['wrong secret', () => alpha('/__control', { apiSecret: 'sk_live_somebody_else' }), () => alpha('/__control', { apiSecret: SECRET })],
    ['offline', () => alpha('/__control', { mode: { offline: true } }), () => alpha('/__control', { mode: { offline: false } })],
  ]) {
    await setup();
    await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
    const card = await openCard(shop, 'ig-likes-100');
    await card.locator('input[name=target]').fill('https://www.instagram.com/p/AlphaApi' + label.replace(/\W/g, '') + '1/');
    await shop.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card.elementHandle());
    await card.locator('[data-action=proceed]').click();
    await shop.waitForFunction((el) => el.querySelector('.field-msg').dataset.state === 'invalid', await card.elementHandle(), { timeout: 40000 });
    const msg = await t.text(card.locator('.field-msg'));
    t.ok(/unavailable|try again|moment/i.test(msg), `${label}: ${msg}`);
    t.ok(!/quota|secret|credential|X-API/i.test(msg), `${label}: the gateway's wording never reaches the customer: ${msg}`);
    t.ok(/\/$/.test(shop.url()) || !/\/pay\//.test(shop.url()), `${label}: stayed on the store`);
    await reset();
  }
  t.ok(Number(sql(`SELECT COUNT(*) FROM orders WHERE status = 'PENDING_PAYMENT' AND target_url REGEXP 'AlphaApi(quota|wrongsecret|offline)1'`)) === 0, 'no order left waiting for a payment that cannot happen');
  const reasons = sql(`SELECT GROUP_CONCAT(failure_reason SEPARATOR ' | ') FROM payments WHERE failure_reason IS NOT NULL AND created_at > UTC_TIMESTAMP() - INTERVAL 15 MINUTE`);
  t.has(reasons, 'Plan quota exhausted'); t.has(reasons, 'Invalid API credentials');
  await shop.goto(BASE + '/', { waitUntil: 'networkidle' });
  const ok = await placeOrder(shop, 'ig-likes-100', 'https://www.instagram.com/p/AlphaApiAfter1/');
  t.ok(!!ok.ref, 'checkout works again once the gateway is back');
});

await t.step('no console errors, CSP violations or failed requests (the expected 503 answers of the refusal step aside)', async () => {
  const bad = [...admin.problems, ...shop.problems].filter((p) => !/status of 503/.test(p) && !/\[http 503\]/.test(p));
  t.eq(bad, [], bad.join('\n'));
});

await mocksReset();
await browser.close();
process.exit(t.finish() ? 1 : 0);
