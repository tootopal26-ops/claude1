// FINAL SECURITY AUDIT (brief §61) — an attacker's view of the running site. Every question in the brief is answered by actually trying it:
//   1 normal user → admin?   2 manipulate price?   3 fake payment success?   4 change service id?   5 read another order?
//   6 call the SMM API directly?   7 expose API keys?   8 duplicate webhook → duplicate order?   9 negative quantity?   10 negative price?
//   11 order an invalid service?   12 reuse an expired payment?   13 unauthenticated admin APIs?
// plus the usual hardening checks (headers, cookies, CSRF/origin, host-header injection, open redirects, reflected XSS, body limits, CORS, file exposure).
// The target is a PRODUCTION-LIKE server (APP_DEBUG=false) started by this script on :8093 against the dev database. Local dev only.
import { spawn, execFileSync } from 'node:child_process';
import http from 'node:http';
import { createHmac, randomBytes, randomUUID } from 'node:crypto';
import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs';

const PORT = 8093;
process.env.BASE = `http://127.0.0.1:${PORT}`;                 // must be set before the helpers are imported
const { launch, newPage, BASE, ROOT, sql, rows, alpha, smm, mocksReset, resetLimits, payment, serviceId, sleep, php, useMockProviderIds, DESKTOP_UA, waitUntil } = await import('./site.mjs');
const { login, CREDS } = await import('./admin.mjs');
const { suite } = await import('./harness.mjs');

const t = suite('security-audit');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
useMockProviderIds(); resetLimits();
const server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', 'public', 'tools/router.php'], { cwd: ROOT, env: { ...process.env, APP_DEBUG: 'false', APP_URL: BASE, PHP_CLI_SERVER_WORKERS: '6' }, stdio: 'ignore' });
process.on('exit', () => server.kill('SIGTERM'));
for (let i = 0; i < 60; i++) { try { await fetch(BASE + '/health'); break; } catch { await sleep(150); } }
await mocksReset();
const GID = Number(sql('SELECT id FROM payment_gateways ORDER BY id LIMIT 1'));

// ───────────────────────── helpers ─────────────────────────
const ENV = Object.fromEntries(readFileSync(`${ROOT}/.env`, 'utf8').split('\n').filter((l) => /^[A-Z_]+=/.test(l)).map((l) => { const i = l.indexOf('='); return [l.slice(0, i), l.slice(i + 1).replace(/^"|"$/g, '')]; }));
const SECRETS = { 'gateway API key': 'test-api-key-123', 'webhook secret': 'test-webhook-secret-456', 'SMM API key': 'smm-key-ok', 'APP_KEY': ENV.APP_KEY, 'DB password': ENV.DB_PASS, 'CRON_KEY': ENV.CRON_KEY, 'admin password': CREDS.pass };
const adminHashes = rows('SELECT password_hash FROM admins').map((r) => r[0]);
const noSecrets = (text, what, { paths = false } = {}) => {
  for (const [name, value] of Object.entries(SECRETS)) if (value && String(text).includes(value)) throw new Error(`${what} exposes the ${name}`);
  for (const h of adminHashes) if (h && String(text).includes(h)) throw new Error(`${what} exposes an admin password hash`);
  if (/SQLSTATE|PDOException|Stack trace|on line \d+|Fatal error|Warning:|Notice:|Deprecated:/.test(String(text)) || (!paths && /\/home\/user\//.test(String(text)))) throw new Error(`${what} leaks debug detail: ${String(text).match(/SQLSTATE[^"<]{0,60}|PDOException|Stack trace|on line \d+|\/home\/user\/[^ "<]{0,40}|Fatal error|Warning:|Notice:/)?.[0]}`);
};
const JSONH = { 'content-type': 'application/json', 'x-requested-with': 'smb', origin: BASE };

async function req(method, path, { headers = {}, json, body, cookie, redirect = 'manual' } = {}) {
  const r = await fetch(BASE + path, { method, redirect, signal: AbortSignal.timeout(30000), headers: { ...(cookie ? { cookie } : {}), ...(json !== undefined ? JSONH : {}), ...headers }, body: json !== undefined ? JSON.stringify(json) : body });
  const text = await r.text();
  let data = null; try { data = JSON.parse(text); } catch { /* html */ }
  return { status: r.status, headers: r.headers, text, json: data };
}
const post = (path, json, opts = {}) => req('POST', path, { json, ...opts });
const checkout = (code, target, extra = {}, headers = {}) => req('POST', '/api/checkout', { json: { service_id: serviceId(code), target, ...extra }, headers: { 'idempotency-key': randomUUID(), ...headers } });
const refOf = (r) => r.json?.url?.match(/pay\/([a-f0-9]{32})/)?.[1];
const SECRET = 'test-webhook-secret-456';
const sign = (raw, secret = SECRET) => createHmac('sha256', secret).update(raw).digest('hex');
const webhook = (body, { secret = SECRET, signature, raw, gid = GID } = {}) => {
  const payload = raw ?? JSON.stringify(body);
  const headers = { 'content-type': 'application/json' };
  if (signature !== null) headers['x-alpha-signature'] = signature ?? sign(payload, secret);
  return req('POST', `/webhooks/payments/${gid}`, { headers, body: payload });
};
const evt = (p, over = {}) => ({ event_id: 'evt_' + randomBytes(6).toString('hex'), order_id: p.gwOrder, status: 'SUCCESS', amount: (p.pAmount / 100).toFixed(2), utr: 'UTR' + randomBytes(4).toString('hex').toUpperCase(), provider: 'bharatpe', paid_at: '2026-10-14 12:00:00', ...over });
let n = 0; const uniq = () => `${Date.now().toString(36)}${(n++).toString(36)}`;
const newOrder = async (code = 'ig-likes-100', target = `https://www.instagram.com/p/Aud${uniq()}/`) => {
  resetLimits();
  const r = await checkout(code, target); if (r.status !== 200) throw new Error(`could not create an order: ${r.status} ${r.text.slice(0, 120)}`);
  const ref = refOf(r); return { ref, token: r.json.token, p: payment(ref), target };
};
const orderRow = (id) => rows(`SELECT status, mode, amount_paise, quantity, service_id, smm_service_id, payment_status, late_payment, last_error FROM orders WHERE id = ${id}`)[0];
// real transitions only (informational notes such as "duplicate payment received" re-use the current status as from AND to)
const historyCount = (id, to) => Number(sql(`SELECT COUNT(*) FROM order_status_history WHERE order_id = ${id} AND to_status = '${to}' AND (from_status IS NULL OR from_status <> to_status)`));
const rawHttp = (method, path, headers = {}, body = '') => new Promise((resolve, reject) => {
  const r = http.request({ host: '127.0.0.1', port: PORT, method, path, timeout: 8000, headers: { ...headers, 'content-length': Buffer.byteLength(body) } }, (res) => { let b = ''; res.on('data', (c) => (b += c)); res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, text: b })); });
  r.on('timeout', () => { r.destroy(); resolve({ status: 0, headers: {}, text: '(no answer within 8 s — connection dropped)' }); });
  r.on('close', () => resolve({ status: 0, headers: {}, text: '(connection closed without a response)' }));   // e.g. CONNECT never yields a normal response
  r.on('error', reject); r.end(body);
});

// the route table, as the router sees it
const ROUTES = execFileSync('php', ['tools/qa/dump-routes.php'], { cwd: ROOT, encoding: 'utf8' }).trim().split('\n').map((l) => { const [method, path, mw] = l.split('\t'); return { method, path, mw: (mw || '').split(',').filter(Boolean) }; });

// ═════════════════════════ 1. Can a normal user access Admin? ═════════════════════════
await t.step('Q1 · can a normal user access Admin? NO — every admin page redirects to sign-in, path tricks and forged cookies change nothing', async () => {
  const pages = ['', 'dashboard', 'orders', 'payments', 'services', 'tags', 'website', 'advertising', 'gateway', 'smm', 'security', 'audit', 'system', 'doesnotexist'];
  for (const p of pages) {
    let r = await req('GET', '/admin' + (p ? '/' + p : ''));
    if (r.status === 302 && /\/admin\/dashboard$/.test(r.headers.get('location') || '')) r = await req('GET', '/admin/dashboard');   // "/admin" itself just forwards to the dashboard
    t.ok([301, 302, 303].includes(r.status) && /\/admin\/login/.test(r.headers.get('location') || ''), `/admin/${p} → ${r.status} ${r.headers.get('location')}`);
    t.ok(!/data-page=|admin-nav|csrf-token/.test(r.text), `/admin/${p} must not render admin markup`);
  }
  const tricks = ['/admin/../admin/dashboard', '/ADMIN/dashboard', '/admin//dashboard', '/admin/dashboard/', '/admin/dashboard%00', '/admin/dashboard.php', '/admin/./dashboard', '/%61dmin/dashboard', '/admin/dashboard;.css', '/admin/dashboard?next=/admin/login', '/admin%2Fdashboard', '/./admin/dashboard', '/admin/dashboard%2e', '//admin/dashboard'];
  for (const p of tricks) {
    const r = await rawHttp('GET', p, { host: `127.0.0.1:${PORT}`, 'x-original-url': '/admin/login', 'x-rewrite-url': '/admin/login', 'x-forwarded-for': '127.0.0.1', 'x-real-ip': '127.0.0.1', 'x-forwarded-host': 'localhost' });
    t.ok(!(r.status === 200 && /data-page=|admin-nav|csrf-token/.test(r.text)), `${p} → ${r.status}`);
  }
  for (const name of ['smb_admin', '__Host-smb_admin', 'smb_session', 'smb_s', 'PHPSESSID', 'admin', 'session']) {
    const r = await req('GET', '/admin/dashboard', { cookie: `${name}=${randomBytes(32).toString('hex')}` });
    t.ok([301, 302].includes(r.status), `forged ${name} cookie → ${r.status}`);
  }
  const ov = await req('GET', '/admin/dashboard', { headers: { 'x-http-method-override': 'GET', 'x-forwarded-proto': 'https', authorization: 'Basic YWRtaW46YWRtaW4=' } });
  t.ok([301, 302].includes(ov.status), 'headers do not authenticate: ' + ov.status);
  const lp = await req('GET', '/admin/login');
  t.eq(lp.status, 200); t.has(lp.headers.get('cache-control') || '', 'no-store'); t.has(lp.headers.get('x-robots-tag') || '', 'noindex');
  noSecrets(lp.text, 'login page');
});

// ═════════════════════════ 2. Can the user manipulate the price? ═════════════════════════
await t.step('Q2 · can the user manipulate the price? NO — checkout accepts only service id + link; the amount, quantity, mode and provider come from the database', async () => {
  resetLimits();
  const target = `https://www.instagram.com/p/Price${uniq()}/`;
  const r = await checkout('ig-likes-100', target, { price: 1, amount: 0.01, amount_paise: 1, total: 0, quantity: 99999999, qty: -5, mode: 'MANUAL', cost: 0, currency: 'USD', discount: 100, coupon: 'FREE', smm_service_id: '9999', smm_provider_id: 7, status: 'PAID', paid: true });
  t.eq(r.status, 200, r.text);
  const ref = refOf(r); const p = payment(ref);
  t.eq(p.oAmount, 500, 'order amount is the catalogue price (₹5)'); t.eq(p.pAmount, 500); t.eq(p.quantity, 100, 'quantity from the catalogue'); t.eq(p.mode, 'AUTO', 'mode from the catalogue');
  t.eq(p.oStatus, 'PENDING_PAYMENT');
  t.eq(String(orderRow(p.id)[5]), '1002', 'provider service id from the catalogue, not the injected 9999');
  const gw = await (await fetch('http://127.0.0.1:4010/__state')).json();
  t.eq(gw.orders[p.gwOrder].amount, '5.00', 'the gateway was asked for the real price');
  // catalogue price changes after the order was created: the pending order keeps its snapshot
  const before = sql("SELECT price_paise FROM services WHERE code = 'ig-likes-100'");
  sql("UPDATE services SET price_paise = 100 WHERE code = 'ig-likes-100'");
  try {
    const cheap = await webhook(evt(p, { amount: '1.00' }));
    t.eq(cheap.status, 200); t.eq(payment(ref).pStatus, 'mismatch', 'paying the NEW cheaper price for an old session is flagged, not accepted');
    t.ok(payment(ref).oStatus !== 'PAID', 'order not fulfilled: ' + payment(ref).oStatus);
  } finally { sql(`UPDATE services SET price_paise = ${before} WHERE code = 'ig-likes-100'`); }
  // tampering with the pay page / status URLs
  const o2 = await newOrder();
  const page1 = await req('GET', `/pay/${o2.ref}`); const page2 = await req('GET', `/pay/${o2.ref}?amount=1&price=1&paid=1&state=paid&returned=1&status=success`);
  t.has(page2.text, '₹5'); t.has(page2.text, '<section data-view="paid" hidden', 'the paid view stays hidden whatever the query string says');
  t.eq(payment(o2.ref).pStatus, 'pending', 'query-string tampering changes nothing');
  const st = await req('GET', `/api/pay/${o2.ref}/status?state=paid&force=1`); t.eq(st.json.state, 'pending');
  const st2 = await post(`/api/pay/${o2.ref}/status`, { state: 'paid' }); t.ok([404, 405].includes(st2.status), 'no write access to the status endpoint: ' + st2.status);
});

await t.step('Q2b · malformed checkout input is refused cleanly (422/4xx, never a 500, never an order)', async () => {
  resetLimits();
  const before = Number(sql('SELECT COUNT(*) FROM orders'));
  const bad = [
    { service_id: '1abc', target: '@x' }, { service_id: 1.5, target: '@x' }, { service_id: -1, target: '@x' }, { service_id: 0, target: '@x' }, { service_id: 1e12, target: '@x' },
    { service_id: [1], target: '@x' }, { service_id: { $gt: 0 }, target: '@x' }, { service_id: '1; DROP TABLE orders', target: '@x' }, { service_id: null, target: '@x' }, { service_id: true, target: '@x' },
    { service_id: serviceId('ig-followers-50'), target: ['@x'] }, { service_id: serviceId('ig-followers-50'), target: { a: 1 } }, { service_id: serviceId('ig-followers-50'), target: 12345 },
    { service_id: serviceId('ig-followers-50'), target: null }, { service_id: serviceId('ig-followers-50'), target: 'x'.repeat(100000) }, { service_id: serviceId('ig-followers-50'), target: "' OR 1=1 --" },
    { service_id: serviceId('ig-followers-50'), target: '<script>alert(1)</script>' }, { service_id: serviceId('ig-followers-50'), target: 'javascript:alert(1)' }, { service_id: serviceId('ig-followers-50'), target: 'https://evil.example/' + 'a'.repeat(50) },
    { service_id: serviceId('ig-followers-50'), target: 'http://user:pass@instagram.com/x' }, { service_id: serviceId('ig-followers-50'), target: 'https://instagram.com.evil.example/x' }, {}, [], 'text', 42,
  ];
  for (const b of bad) {
    resetLimits();
    const r = await req('POST', '/api/checkout', { json: b, headers: { 'idempotency-key': randomUUID() } });
    t.ok(r.status >= 400 && r.status < 500, `${JSON.stringify(b).slice(0, 70)} → ${r.status}`);
    noSecrets(r.text, 'validation error');
  }
  t.eq(Number(sql('SELECT COUNT(*) FROM orders')), before, 'no order was created by any of them');
  // control characters pasted around an otherwise valid profile link are stripped, never stored; a bidi override INSIDE the link is refused
  resetLimits();
  const ctl = await req('POST', '/api/checkout', { json: { service_id: serviceId('ig-followers-50'), target: '\u0000\u200bhttps://www.instagram.com/ctl_user/\u0007\n' }, headers: { 'idempotency-key': randomUUID() } });
  if (ctl.status === 200) t.eq(sql(`SELECT HEX(target) FROM orders WHERE target_url LIKE '%ctl_user%' ORDER BY id DESC LIMIT 1`), Buffer.from('https://www.instagram.com/ctl_user/').toString('hex').toUpperCase(), 'only the visible link is stored'); else t.ok(ctl.status === 422);
  resetLimits();
  const rlo = await req('POST', '/api/checkout', { json: { service_id: serviceId('ig-followers-50'), target: 'https://www.instagram.com/\u202ectl_user2/' }, headers: { 'idempotency-key': randomUUID() } });
  t.eq(rlo.status, 422, 'a right-to-left override inside the link is refused');
  const raw = await req('POST', '/api/checkout', { headers: { ...JSONH, 'idempotency-key': randomUUID() }, body: '{"service_id": 1, "target": ' });
  t.ok(raw.status >= 400 && raw.status < 500, 'broken JSON → ' + raw.status);
  const big = await req('POST', '/api/checkout', { headers: { ...JSONH, 'idempotency-key': randomUUID() }, body: JSON.stringify({ service_id: 1, target: '@' + 'a'.repeat(3_000_000) }) });
  t.ok([400, 413, 422].includes(big.status), '3 MB body → ' + big.status);
  const deep = await req('POST', '/api/checkout', { headers: { ...JSONH, 'idempotency-key': randomUUID() }, body: '['.repeat(5000) + ']'.repeat(5000) });
  t.ok(deep.status >= 400 && deep.status < 500, 'deeply nested JSON → ' + deep.status);
  const form = await req('POST', '/api/checkout', { headers: { 'content-type': 'application/x-www-form-urlencoded', 'x-requested-with': 'smb', origin: BASE, 'idempotency-key': randomUUID() }, body: `service_id=${serviceId('ig-likes-100')}&target=@x` });
  t.ok([400, 415, 422].includes(form.status), 'form-encoded body → ' + form.status);
  const noKey = await req('POST', '/api/checkout', { json: { service_id: serviceId('ig-likes-100'), target: 'https://instagram.com/p/NoKey12345/' } });
  t.ok(noKey.status === 400, 'a missing Idempotency-Key is refused: ' + noKey.status);
});

// ═════════════════════════ 3. Can the user fake payment success? ═════════════════════════
await t.step('Q3 · can the user fake payment success? NO — only a correctly SIGNED, amount-matching gateway event can settle an order', async () => {
  const o = await newOrder();
  const body = evt(o.p);
  const cases = [
    ['no signature header', () => webhook(body, { signature: null })],
    ['empty signature', () => webhook(body, { signature: '' })],
    ['garbage signature', () => webhook(body, { signature: 'deadbeef'.repeat(8) })],
    ['signed with the wrong secret', () => webhook(body, { secret: 'guessed-secret' })],
    ['signed with the API key instead of the webhook secret', () => webhook(body, { secret: 'test-api-key-123' })],
    ['body tampered after signing', () => webhook(null, { raw: JSON.stringify({ ...body, amount: '0.01' }), signature: sign(JSON.stringify(body)) })],
    ['signature in the wrong case/prefix', () => webhook(body, { signature: 'sha256=' + sign(JSON.stringify(body)) })],
    ['unknown gateway id', () => webhook(body, { gid: 9999 })],
  ];
  for (const [name, fn] of cases) {
    const r = await fn(); t.ok([400, 401, 403, 404].includes(r.status), `${name} → ${r.status}`);
    t.eq(payment(o.ref).pStatus, 'pending', `${name}: payment must stay pending`);
  }
  t.ok([404, 405].includes((await req('GET', `/webhooks/payments/${GID}`)).status), 'GET on the webhook URL');
  t.ok([404, 405].includes((await req('PUT', `/webhooks/payments/${GID}`, { body: JSON.stringify(body) })).status));
  const junk = await webhook(null, { raw: 'not json at all' }); t.ok([400, 401].includes(junk.status), 'unsigned junk → ' + junk.status);
  const junk2 = await webhook(null, { raw: '[1,2,3]' }); t.ok([200, 400, 401].includes(junk2.status), 'signed JSON array → ' + junk2.status);
  const junk3 = await webhook(null, { raw: '{}' }); t.ok([200, 400, 401].includes(junk3.status), 'signed empty object → ' + junk3.status);
  t.eq(payment(o.ref).pStatus, 'pending', 'meaningless-but-signed payloads change nothing');
  const invalid = Number(sql("SELECT COUNT(*) FROM webhook_events WHERE status = 'invalid'")); t.ok(invalid >= 6, 'forged attempts are logged for the admin: ' + invalid);
  const unknown = await webhook(evt(o.p, { order_id: 'SMBDOESNOTEXIST00000' })); t.eq(unknown.status, 200, 'a signed event for an unknown order is acknowledged and ignored'); t.eq(payment(o.ref).pStatus, 'pending');
  // wrong amount, even though the signature is valid → flagged for a human, NEVER fulfilled
  const wrong = await webhook(evt(o.p, { amount: '1.00' })); t.eq(wrong.status, 200);
  t.eq(payment(o.ref).pStatus, 'mismatch'); t.ok(!['PAID', 'SUBMITTED', 'COMPLETED', 'MANUAL_PENDING'].includes(payment(o.ref).oStatus), 'order not fulfilled on a short payment: ' + payment(o.ref).oStatus);
  const pg = await req('GET', `/api/pay/${o.ref}/status`); t.eq(pg.json.state, 'review', 'the customer is told it is being reviewed, not that it succeeded');
  // the return URL is not proof of payment
  const o2 = await newOrder();
  const ret = await req('GET', `/pay/${o2.ref}?returned=1&status=success&txn=999&payment=done`); t.eq(ret.status, 200);
  await sleep(300);
  t.eq(payment(o2.ref).pStatus, 'pending', '?returned=1 makes the server ASK the gateway; it does not believe the URL');
  const st = await req('GET', `/api/pay/${o2.ref}/status`); t.eq(st.json.state, 'pending');
  // a replayed valid event for payment A must not settle payment B
  const A = await newOrder(), B = await newOrder();
  const eA = evt(A.p); const rawA = JSON.stringify(eA);
  t.eq((await webhook(null, { raw: rawA })).status, 200); t.eq(payment(A.ref).pStatus, 'success');
  t.eq((await webhook(null, { raw: rawA })).status, 200);
  t.eq(payment(B.ref).pStatus, 'pending', 'replaying A\'s signed event does not touch B');
  // a late FAILED / EXPIRED event must not undo a successful payment
  await webhook(evt(A.p, { status: 'FAILED', utr: '' })); await webhook(evt(A.p, { status: 'EXPIRED', utr: '' }));
  t.eq(payment(A.ref).pStatus, 'success', 'success is final');
  t.ok(!['FAILED', 'EXPIRED', 'CANCELLED'].includes(payment(A.ref).oStatus), 'order is not downgraded: ' + payment(A.ref).oStatus);
});

// ═════════════════════════ 4. Can the user change the service id? ═════════════════════════
await t.step('Q4 · can the user change the service id? NO — only active, catalogued services can be ordered and the provider id is never taken from the client', async () => {
  resetLimits();
  const target = `https://www.instagram.com/p/Svc${uniq()}/`;
  const cases = { 'non-existent id': 999999, 'zero': 0, 'negative': -3 };
  for (const [name, id] of Object.entries(cases)) {
    const r = await req('POST', '/api/checkout', { json: { service_id: id, target }, headers: { 'idempotency-key': randomUUID() } });
    t.ok(r.status === 422, `${name} → ${r.status}`); t.eq(r.json?.error?.code, 'service_unavailable');
  }
  sql("UPDATE services SET status = 'inactive' WHERE code = 'ig-views-1m'");
  const off = await req('POST', '/api/checkout', { json: { service_id: serviceId('ig-views-1m'), target }, headers: { 'idempotency-key': randomUUID() } });
  t.eq(off.status, 422, 'inactive service'); sql("UPDATE services SET status = 'active' WHERE code = 'ig-views-1m'");
  sql("UPDATE services SET deleted_at = UTC_TIMESTAMP() WHERE code = 'ig-views-1m'");
  const del = await req('POST', '/api/checkout', { json: { service_id: serviceId('ig-views-1m'), target }, headers: { 'idempotency-key': randomUUID() } });
  t.eq(del.status, 422, 'deleted service'); sql("UPDATE services SET deleted_at = NULL WHERE code = 'ig-views-1m'");
  sql("UPDATE platforms SET is_active = 0 WHERE slug = 'youtube'");
  const plat = await req('POST', '/api/checkout', { json: { service_id: serviceId('yt-subs-50'), target: 'https://www.youtube.com/@somebody' }, headers: { 'idempotency-key': randomUUID() } });
  t.eq(plat.status, 422, 'service of a disabled platform'); sql("UPDATE platforms SET is_active = 1 WHERE slug = 'youtube'");
  // link must match the service kind: a YouTube link for an Instagram service is refused even if the id is valid
  const wrong = await checkout('ig-followers-50', 'https://youtube.com/@someone'); t.eq(wrong.status, 422);
  const wrong2 = await checkout('yt-views-10', 'https://www.instagram.com/p/AbCdEf12345/'); t.eq(wrong2.status, 422);
  // the provider's service id is private: nothing public reveals it
  const home = await req('GET', '/'); const ids = rows("SELECT smm_service_id FROM services WHERE smm_service_id IS NOT NULL").map((r) => r[0]);
  t.ok(!/smm_service_id|smm-|provider/i.test(home.text), 'no provider vocabulary in the storefront HTML');
  const o = await newOrder('ig-followers-50', 'https://instagram.com/svc_check_' + uniq());
  const view = await req('GET', `/api/order/${o.token}`); t.ok(!/smm|provider|cost|1001|margin|profit/i.test(view.text.replace(/instagram\.com\/svc_check_\w+/, '')), 'order JSON has no provider/cost data: ' + view.text.slice(0, 200));
});

// ═════════════════════════ 5. Can the user access another order? ═════════════════════════
await t.step('Q5 · can the user access another order? NO — orders and payment sessions are reachable only through their 128-bit secret; nothing can be enumerated', async () => {
  const mine = await newOrder(); const victim = await newOrder('ig-likes-50k', 'https://www.instagram.com/p/Victim' + uniq() + '/');
  sql(`UPDATE orders SET admin_note = 'TOP-SECRET-NOTE', last_error = 'PROVIDER-INTERNAL-ERROR' WHERE access_token = '${victim.token}'`);
  const pubId = victim.p.publicId, numId = String(victim.p.id);
  for (const path of [`/order/${pubId}`, `/order/${numId}`, `/order/${victim.token.toUpperCase()}`, `/order/${victim.token.slice(0, 31)}`, `/order/${victim.token}x`, `/order/${victim.token}%00`, `/api/order/${pubId}`, `/api/order/${numId}`, `/pay/${pubId}`, `/pay/${numId}`, `/pay/${victim.p.gwOrder}`, `/api/pay/${pubId}/status`, `/pay/${pubId}/qr.png`, '/order/', '/order', '/orders', '/orders/1', '/api/orders', '/api/orders/1', '/api/order']) {
    const r = await req('GET', path); t.ok([301, 302, 404, 405].includes(r.status) && !r.text.includes(victim.p.publicId.slice(4)) || r.status === 404, `${path} → ${r.status}`);
  }
  const ok = await req('GET', `/order/${victim.token}`); t.eq(ok.status, 200, 'the holder of the token can see it');
  for (const text of [ok.text, (await req('GET', `/api/order/${victim.token}`)).text, (await req('POST', '/api/orders/lookup', { json: { tokens: [victim.token] } })).text]) {
    t.ok(!/TOP-SECRET-NOTE|PROVIDER-INTERNAL-ERROR/.test(text), 'internal notes/errors never reach the customer'); noSecrets(text, 'customer order view');
  }
  t.has(ok.headers.get('cache-control') || '', 'no-store'); t.has((ok.headers.get('x-robots-tag') || '') + ok.text, 'noindex');
  const pay = await req('GET', `/pay/${victim.ref}`); t.has(pay.headers.get('cache-control') || '', 'no-store'); t.has((pay.headers.get('x-robots-tag') || '') + pay.text, 'noindex');
  // lookup cannot be used to enumerate: ids are not accepted, the list is capped, junk is ignored
  const many = Array.from({ length: 200 }, () => randomBytes(16).toString('hex'));
  const l1 = await post('/api/orders/lookup', { tokens: [...many, victim.token] }); t.ok(l1.json.orders.length <= 10 && !l1.json.orders.some((x) => x.order_id === victim.p.publicId), 'only the first 10 tokens are even looked at');
  const l2 = await post('/api/orders/lookup', { tokens: [pubId, numId, victim.p.gwOrder, '*', '%', "' OR 1=1 --"] }); t.eq(l2.json.orders.length, 0);
  const l3 = await post('/api/orders/lookup', { tokens: 'x' }); t.ok(l3.status === 422, 'non-array → ' + l3.status);
  const l4 = await post('/api/orders/lookup', { tokens: [[victim.token], { a: victim.token }, 5, null] }); t.eq(l4.json.orders.length, 0);
  // brute-force guessing is rate limited
  resetLimits(); let limited = 0; for (let i = 0; i < 130; i++) { const r = await req('GET', `/order/${randomBytes(16).toString('hex')}`); if (r.status === 429) { limited++; t.ok(r.headers.get('retry-after'), 'Retry-After present'); break; } }
  t.ok(limited > 0, 'token guessing hits the rate limit');
  resetLimits();
  void mine;
});

// ═════════════════════════ 6. Can the user call the SMM API directly? ═════════════════════════
await t.step('Q6 · can the user call the SMM API directly? NO — the provider is only ever contacted by the server; no public route, script or response reveals its URL or key', async () => {
  const publicRoutes = ROUTES.filter((r) => !r.mw.includes('adminApi') && !r.mw.includes('adminPage') && !r.path.startsWith('/admin'));
  t.ok(!publicRoutes.some((r) => /smm|provider|panel|api\/v2/i.test(r.path)), 'no public route is named after the SMM provider: ' + publicRoutes.map((r) => r.path).filter((p) => /smm|provider/i.test(p)));
  for (const p of ['/api/smm', '/smm', '/api/v2', '/api/provider', '/api/services', '/api/order/add', '/api/add', '/admin/api/smm', '/admin/api/smm/1/services', '/api/smm/balance']) {
    for (const method of ['GET', 'POST']) {
      const r = method === 'POST' ? await post(p, { action: 'balance', key: 'x' }) : await req('GET', p);
      t.ok([301, 302, 401, 403, 404, 405].includes(r.status), `${method} ${p} → ${r.status}`);
      t.ok(!/"balance"|"order":\s*\d+|Not enough funds/.test(r.text), `${method} ${p} reveals nothing from the provider`);
    }
  }
  // everything the browser can download
  const urls = new Set(['/', '/theme.css', '/manifest.webmanifest', '/robots.txt', '/sitemap.xml']);
  const home = await req('GET', '/');
  for (const m of home.text.matchAll(/(?:src|href)="([^"]+\.(?:js|css|svg|webmanifest))"/g)) urls.add(new URL(m[1], BASE).pathname);
  const walk = (dir, base) => readdirSync(dir).flatMap((f) => { const p = `${dir}/${f}`; return statSync(p).isDirectory() ? walk(p, `${base}/${f}`) : [`${base}/${f}`]; });
  for (const f of walk(`${ROOT}/public/assets/js`, '/assets/js')) urls.add(f);
  for (const f of walk(`${ROOT}/public/assets/css`, '/assets/css')) urls.add(f);
  // the files a CUSTOMER's browser loads (storefront, payment, tracking bundles and the chunks they import) must not know the provider exists
  const customerFiles = new Set(['/', '/assets/js/site.js', '/assets/js/pay.js', '/assets/js/order.js', '/assets/js/track.js']);
  for (const f of [...customerFiles].filter((x) => x.endsWith('.js'))) { const js = await req('GET', f); for (const m of js.text.matchAll(/from\s*"(\.\/[^"]+\.js)"|import\("(\.\/[^"]+\.js)"\)/g)) customerFiles.add(new URL(m[1] || m[2], BASE + f).pathname); }
  let scanned = 0;
  for (const u of urls) {
    const r = await req('GET', u); if (r.status !== 200) continue; scanned++;
    if (customerFiles.has(u) || u.endsWith('.css') || u === '/theme.css') t.ok(!/127\.0\.0\.1:4020|\/api\/v2|smm-key|api_key_enc|rate_to_inr|smm_service|provider_cost|smm_cost/i.test(r.text), `${u} mentions the provider`);
    noSecrets(r.text, u);
    t.ok(!/sourceMappingURL/.test(r.text), `${u} publishes a source map reference`);
  }
  t.ok(scanned >= 25, 'scanned ' + scanned + ' public files');
  // the request log the admin sees never contains the key
  const logs = rows("SELECT request_json FROM smm_transactions").map((r) => r[0]).join('\n'); t.ok(!logs.includes('smm-key-ok'));
});

// ═════════════════════════ 7. Can the user expose API keys? ═════════════════════════
await t.step('Q7 · can the user expose API keys? NO — secrets are server-side, encrypted at rest, write-only in the admin UI, and absent from every response, log and error page', async () => {
  const o = await newOrder();
  const pages = ['/', '/theme.css', '/manifest.webmanifest', '/robots.txt', '/sitemap.xml', '/health', `/pay/${o.ref}`, `/order/${o.token}`, `/api/pay/${o.ref}/status`, `/api/order/${o.token}`, '/admin/login', '/install', '/cron/run', '/no-such-page', `/pay/${'0'.repeat(32)}`, '/admin/api/status'];
  for (const p of pages) { const r = await req('GET', p); noSecrets(r.text, p); noSecrets(JSON.stringify([...r.headers.entries()]), p + ' headers'); }
  // error pages: wrong method, broken URL, huge URL, bad encoding
  for (const [m, p] of [['DELETE', '/'], ['PUT', '/api/checkout'], ['PATCH', '/pay/' + o.ref], ['GET', '/api/checkout'], ['GET', '/%'], ['GET', '/%ff%fe'], ['GET', '/' + 'a'.repeat(9000)], ['GET', '/?' + 'x='.repeat(3000)], ['POST', '/pay/' + o.ref]]) {
    const r = await rawHttp(m, p, { host: `127.0.0.1:${PORT}` }).catch((e) => ({ status: 0, text: String(e), headers: {} }));
    t.ok(r.status === 0 || (r.status >= 400 && r.status < 500) || r.status === 200, `${m} ${p.slice(0, 40)} → ${r.status}`); noSecrets(r.text, `${m} ${p.slice(0, 30)}`);
  }
  // files that must never be served (docroot = public/, plus what the root .htaccess has to block on layout B)
  for (const f of ['/.env', '/.env.example', '/.env.local', '/.git/HEAD', '/.git/config', '/.gitignore', '/composer.json', '/composer.lock', '/package.json', '/package-lock.json', '/README.md', '/app/bootstrap.php', '/app/Core/Db.php', '/app/routes.php', '/bin/cron.php', '/bin/install.php', '/database/schema.sql', '/database/migrations/', '/storage/logs/', '/storage/installed.lock', '/storage/install-token.txt', '/storage/cache/', '/tools/router.php', '/tests/run.php', '/resources/js/site.js', '/node_modules/playwright/package.json', '/vendor/autoload.php', '/index.php.bak', '/phpinfo.php', '/info.php', '/.htaccess', '/public/index.php', '/uploads/', '/assets/', '/assets/js/', '/assets/css/', '/uploads/../.env', '/assets/../../.env', '/assets/../../resources/css/app.css', '/assets/../../tools/router.php', '/assets/../../package.json', '/assets/%2e%2e/%2e%2e/.env', '/..%2f.env', '/%2e%2e/.env']) {
    const r = await rawHttp('GET', f, { host: `127.0.0.1:${PORT}` });
    t.ok(r.status !== 200 || (!/DB_PASS|APP_KEY|<\?php|password_hash|CREATE TABLE/i.test(r.text) && !/Index of/.test(r.text)), `${f} → ${r.status} (${r.text.slice(0, 40).replace(/\s+/g, ' ')})`);
  }
  // no CORS for API endpoints, preflight reveals nothing
  const cors = await req('OPTIONS', '/api/checkout', { headers: { origin: 'https://evil.example', 'access-control-request-method': 'POST', 'access-control-request-headers': 'content-type,x-requested-with' } });
  t.ok(!cors.headers.get('access-control-allow-origin'), 'no CORS allow-origin: ' + cors.headers.get('access-control-allow-origin'));
  const cors2 = await req('GET', `/api/order/${o.token}`, { headers: { origin: 'https://evil.example' } }); t.ok(!cors2.headers.get('access-control-allow-origin'));
  // stored data: no secrets in logs / audit trail / webhook log / smm log
  const dbText = ['audit_logs', 'webhook_events', 'smm_transactions', 'order_status_history'].map((tb) => rows(`SELECT * FROM ${tb}`).flat().join(' ')).join('\n');
  for (const [name, value] of Object.entries(SECRETS)) if (value && name !== 'admin password') t.ok(!dbText.includes(value), `${name} appears in the audit/webhook/provider logs`);
  const logDir = `${ROOT}/storage/logs`; if (existsSync(logDir)) for (const f of readdirSync(logDir)) { const txt = readFileSync(`${logDir}/${f}`, 'utf8'); for (const [name, value] of Object.entries(SECRETS)) if (value && !['admin password'].includes(name)) t.ok(!txt.includes(value), `${name} appears in ${f}`); }
  // secrets are encrypted at rest
  const enc = rows('SELECT secrets_enc FROM payment_gateways').flat().join() + rows('SELECT api_key_enc FROM smm_providers').flat().join();
  t.ok(!enc.includes('test-api-key-123') && !enc.includes('smm-key-ok') && !enc.includes('test-webhook-secret-456'), 'encrypted at rest');
});

// ═════════════════════════ 8. Duplicate webhook → duplicate order? ═════════════════════════
await t.step('Q8 · can a duplicate webhook create a duplicate order? NO — 20 concurrent deliveries (same event, new events, same UTR, new UTR) settle exactly once', async () => {
  const o = await newOrder('ig-followers-50', 'https://instagram.com/dupe_' + uniq());
  const e = evt(o.p); const raw = JSON.stringify(e);
  const before = await (await fetch('http://127.0.0.1:4020/__state')).json();
  const rs = await Promise.all([
    ...Array.from({ length: 12 }, () => webhook(null, { raw })),                                                         // the very same event, 12 times at once
    ...Array.from({ length: 4 }, () => webhook(evt(o.p, { utr: e.utr }))),                                               // new event ids, same bank reference
    ...Array.from({ length: 4 }, () => webhook(evt(o.p))),                                                               // new event ids, new bank references (customer paid again?)
  ]);
  t.ok(rs.every((r) => r.status === 200), 'all acknowledged (so the gateway stops retrying): ' + rs.map((r) => r.status));
  await waitUntil(() => historyCount(o.p.id, 'SUBMITTED') >= 1, { timeout: 15000 });   // the provider call happens after the webhook has been answered
  await sleep(1000);                                                                 // …and a duplicate (there must be none) would show up by now
  t.eq(Number(sql(`SELECT COUNT(*) FROM payments WHERE gateway_order_id = '${o.p.gwOrder}'`)), 1, 'one payment row');
  t.eq(payment(o.ref).pStatus, 'success');
  t.eq(historyCount(o.p.id, 'PAID'), 1, 'the order became PAID exactly once');
  t.eq(Number(sql(`SELECT COUNT(*) FROM orders WHERE target_url LIKE '%${o.target.split('/').pop()}%'`)), 1, 'still one order');
  const after = await (await fetch('http://127.0.0.1:4020/__state')).json();
  t.eq(after.requests.filter((r) => r.action === 'add').length - before.requests.filter((r) => r.action === 'add').length, 1, 'ONE provider order');
  t.eq(historyCount(o.p.id, 'SUBMITTED'), 1, 'submitted to the provider once');
  t.eq(Number(sql(`SELECT paid_orders_count FROM users u JOIN orders o ON o.user_id = u.id WHERE o.id = ${o.p.id}`) || 1), 1);
  const note = sql(`SELECT COUNT(*) FROM order_status_history WHERE order_id = ${o.p.id} AND note LIKE 'Duplicate payment received%'`);
  t.ok(Number(note) >= 1, 'a second payment with a different bank reference is flagged on the order for a refund: ' + note);
  t.eq(Number(sql(`SELECT COUNT(*) FROM order_status_history WHERE order_id = ${o.p.id} AND note LIKE 'Duplicate payment received%' AND note LIKE '%${e.utr}%'`)), 0, 'the same bank reference is NOT reported as a duplicate payment');
  const ev = rows(`SELECT status, COUNT(*) FROM webhook_events WHERE payload LIKE '%${o.p.gwOrder}%' GROUP BY status`); console.log('      webhook events by status:', JSON.stringify(ev));
});

// ═════════════════════════ 9 / 10 / 11. quantity, price, invalid service ═════════════════════════
await t.step('Q9/Q10/Q11 · negative quantity, negative price, invalid service — refused by the PUBLIC API (ignored) and by the ADMIN API (validated)', async () => {
  // public: any quantity/price the client sends is ignored (see Q2) — and the catalogue itself must always hold sane numbers
  resetLimits();
  const r = await checkout('ig-likes-100', `https://www.instagram.com/p/Neg${uniq()}/`, { quantity: -100, price: -5, amount: -500, qty: 0 });
  t.eq(r.status, 200); const p = payment(refOf(r)); t.eq(p.quantity, 100); t.eq(p.oAmount, 500);
  // the schema itself refuses negative money / quantities (UNSIGNED columns) …
  for (const stmt of ['price_paise = -500', 'quantity = -5', 'min_quantity = -1']) {
    let refused = false; try { sql(`UPDATE services SET ${stmt} WHERE code = 'ig-likes-100'`); } catch { refused = true; }
    t.ok(refused, `the database refuses ${stmt}`);
  }
  // … and an unsellable catalogue row (zero / sub-₹1 price, zero quantity) written straight into the DB is refused at checkout, not sold
  const row = rows("SELECT price_paise, quantity FROM services WHERE code = 'ig-likes-100'")[0];
  try {
    for (const [price, qty] of [[0, 100], [99, 100], [500, 0], [500, 101]]) {
      sql(`UPDATE services SET price_paise = ${price}, quantity = ${qty} WHERE code = 'ig-likes-100'`);
      resetLimits();
      const x = await checkout('ig-likes-100', `https://www.instagram.com/p/Neg${uniq()}/`); t.eq(x.status, 422, `price ${price} / quantity ${qty} must not be sellable`);
    }
  } finally { sql(`UPDATE services SET price_paise = ${row[0]}, quantity = ${row[1]} WHERE code = 'ig-likes-100'`); }
});

// ═════════════════════════ 12. Expired payment reuse ═════════════════════════
await t.step('Q12 · can an expired payment be reused? NO — an expired session shows no QR, is never re-offered, and a new attempt always gets a fresh session', async () => {
  const o = await newOrder('ig-likes-100', 'https://www.instagram.com/p/Expire' + uniq() + '/');
  t.eq((await req('GET', `/pay/${o.ref}/qr.png`)).status, 410, 'a hosted-checkout payment has no QR at all');
  const upi = `upi://pay?pa=merchant.demo@okbizaxis&am=${(payment(o.ref).pAmount / 100).toFixed(2)}&cu=INR&tr=${payment(o.ref).gwOrder}`;
  sql(`UPDATE payments SET qr_payload = '${upi}' WHERE ref = '${o.ref}'`);
  t.eq((await req('GET', `/pay/${o.ref}/qr.png`)).status, 200, 'a live session with a real UPI request serves its QR');
  sql(`UPDATE payments SET qr_payload = REPLACE(qr_payload, '&cu=INR', '&cu=USD') WHERE ref = '${o.ref}'`);
  t.eq((await req('GET', `/pay/${o.ref}/qr.png`)).status, 410, 'a request in another currency is never served');
  sql(`UPDATE payments SET qr_payload = '${upi}' WHERE ref = '${o.ref}'`);
  sql(`UPDATE payments SET expires_at = UTC_TIMESTAMP() - INTERVAL 5 MINUTE WHERE ref = '${o.ref}'`);
  const st = await req('GET', `/api/pay/${o.ref}/status`); t.eq(st.json.state, 'expired');
  const pg = await req('GET', `/pay/${o.ref}`); t.has(pg.text, 'Payment session expired'); t.ok(!/id="qr-img"[^>]*src=/.test(pg.text), 'no QR in the expired page');
  const qr = await req('GET', `/pay/${o.ref}/qr.png`); t.ok([404, 410].includes(qr.status), 'qr.png → ' + qr.status);
  t.eq(payment(o.ref).oStatus, 'EXPIRED');
  // trying again with the same service + link creates a NEW session (the expired one is not reused)
  resetLimits();
  const again = await checkout('ig-likes-100', o.target); t.eq(again.status, 200);
  t.ok(refOf(again) !== o.ref, 'a different payment session'); t.eq(payment(refOf(again)).pStatus, 'pending');
  // the same Idempotency-Key does not resurrect it either
  const k = randomUUID(); const a = await checkout('ig-likes-100', o.target + '?x=1', {}, { 'idempotency-key': k });
  sql(`UPDATE payments SET expires_at = UTC_TIMESTAMP() - INTERVAL 5 MINUTE WHERE ref = '${refOf(a)}'`); await req('GET', `/api/pay/${refOf(a)}/status`);
  const b = await checkout('ig-likes-100', o.target + '?x=1', {}, { 'idempotency-key': k }); t.ok(b.status === 200 || b.status === 409, 'replay → ' + b.status);
  // a signed event for the expired session means money really arrived: credited ONCE, flagged late, never lost, never duplicated
  sql(`UPDATE payments SET expires_at = UTC_TIMESTAMP() - INTERVAL 30 MINUTE WHERE ref = '${o.ref}'`);
  const late = evt(o.p); t.eq((await webhook(late)).status, 200);
  t.eq(payment(o.ref).pStatus, 'success'); t.eq(payment(o.ref).late, true, 'flagged late');
  t.eq(historyCount(o.p.id, 'PAID'), 1);
  t.eq((await webhook(evt(o.p))).status, 200); t.eq(historyCount(o.p.id, 'PAID'), 1, 'paying the same session twice never creates a second order');
  // one QR cannot be used to buy another order: each session is bound to its own gateway order id and amount
  const x = await newOrder('ig-likes-50k', 'https://www.instagram.com/p/Bound' + uniq() + '/'), y = await newOrder('ig-likes-100', 'https://www.instagram.com/p/Bound' + uniq() + '/');
  await webhook(evt(y.p, { order_id: x.p.gwOrder, amount: '5.00' }));
  t.eq(payment(x.ref).pStatus, 'mismatch', 'paying ₹5 against the ₹25 order is a mismatch'); t.eq(payment(y.ref).pStatus, 'pending');
});

// ═════════════════════════ 13. Unauthenticated admin APIs ═════════════════════════
await t.step('Q13 · can an unauthenticated user reach admin APIs? NO — all ' + ROUTES.filter((r) => r.mw.includes('adminApi')).length + ' admin API routes answer 401 JSON to anonymous callers (every verb, forged cookies and CSRF tokens included)', async () => {
  const api = ROUTES.filter((r) => r.mw.includes('adminApi'));
  t.ok(api.length >= 40, 'admin API routes: ' + api.length);
  for (const r of api) {
    for (const extra of [{}, { cookie: `smb_admin=${randomBytes(32).toString('hex')}; __Host-smb_admin=${randomBytes(32).toString('hex')}`, headers: { 'x-csrf-token': randomBytes(32).toString('hex') } }]) {
      const res = await req(r.method, r.path, { json: r.method === 'GET' ? undefined : {}, headers: { ...(extra.headers || {}), 'idempotency-key': randomUUID() }, cookie: extra.cookie });
      t.eq(res.status, 401, `${r.method} ${r.path} → ${res.status} ${res.text.slice(0, 80)}`);
      t.ok(res.json && res.json.ok === false, `${r.method} ${r.path} answers JSON`);
      noSecrets(res.text, `${r.method} ${r.path}`);
    }
  }
  for (const r of ROUTES.filter((x) => x.mw.includes('adminPage'))) { const res = await req(r.method, r.path); t.ok([301, 302].includes(res.status), `${r.path} → ${res.status}`); }
  // other privileged entry points
  t.eq((await req('GET', '/cron/run')).status, 403); t.eq((await req('GET', '/cron/run?key=wrong')).status, 403); t.eq((await post('/cron/run', {})).status, 403);
  t.ok([403, 404].includes((await req('GET', '/cron/run?key=' + 'a'.repeat(64))).status));
  for (const p of ['/install', '/install/unlock', '/install/test-db', '/install/run']) { const r = await req(p === '/install' ? 'GET' : 'POST', p, { json: p === '/install' ? undefined : {} }); t.eq(r.status, 404, `${p} must not exist after installation`); }
  // sign-in itself: no user enumeration, CSRF-protected, throttled
  const noCsrf = await post('/admin/login', { username: 'owner', password: 'x' }); t.ok([401, 403, 419].includes(noCsrf.status), 'sign-in without a CSRF token → ' + noCsrf.status);
  const wrong = await post('/admin/login', { username: 'owner', password: 'x' }, { headers: { 'x-csrf-token': 'nope' } }); t.ok([401, 403, 419].includes(wrong.status), 'sign-in with a bogus CSRF token → ' + wrong.status);
});

// ═════════════════════════ authenticated admin session ═════════════════════════
const browser = await launch();
let adminCookie = '', csrf = '';
await t.step('admin session: cookie flags, session rotation, logout kills the session server-side (replayed cookie → 401), idle/absolute expiry', async () => {
  resetLimits();
  const page = await newPage(browser, { width: 1280, height: 800 }); t.page = page;
  const pre = await page.context().cookies();
  await login(page);
  const cookies = await page.context().cookies();
  const admin = cookies.find((c) => /admin|sess/i.test(c.name) && c.httpOnly);
  t.ok(admin, 'an HttpOnly session cookie exists: ' + cookies.map((c) => c.name));
  t.ok(admin.httpOnly && ['Lax', 'Strict'].includes(admin.sameSite), JSON.stringify(admin));
  t.ok(!pre.some((c) => c.value === admin.value), 'the session id is NEW after sign-in (no fixation)');
  adminCookie = `${admin.name}=${admin.value}`;
  csrf = await page.$eval('meta[name="csrf-token"]', (m) => m.content);
  const me = await req('GET', '/admin/api/status', { cookie: adminCookie }); t.eq(me.status, 200);
  const sessionsDb = sql("SELECT COUNT(*) FROM sessions WHERE revoked_at IS NULL"); t.ok(Number(sessionsDb) >= 1);
  // the token in the DB is a hash, not the cookie value
  t.eq(Number(sql(`SELECT COUNT(*) FROM sessions WHERE token_hash = '${admin.value}'`)), 0, 'raw session token is never stored');
  // logout
  await Promise.all([page.waitForURL('**/admin/login**'), page.evaluate(async () => { const m = document.querySelector('meta[name="csrf-token"]').content; await fetch('/admin/logout', { method: 'POST', headers: { 'X-CSRF-Token': m, 'X-Requested-With': 'smb', Accept: 'application/json' } }); location.href = '/admin/login'; })]);
  const replay = await req('GET', '/admin/api/status', { cookie: adminCookie }); t.eq(replay.status, 401, 'a captured cookie is useless after logout');
  const replayPage = await req('GET', '/admin/dashboard', { cookie: adminCookie }); t.ok([301, 302].includes(replayPage.status), 'admin page after logout → ' + replayPage.status);
  await page.close();
  // idle + absolute expiry are enforced from the database
  resetLimits();
  const p2 = await newPage(browser, { width: 1280, height: 800 }); await login(p2);
  const c2 = (await p2.context().cookies()).find((c) => c.httpOnly && /admin|sess/i.test(c.name)); const ck = `${c2.name}=${c2.value}`;
  t.eq((await req('GET', '/admin/api/status', { cookie: ck })).status, 200);
  const cols = rows("SHOW COLUMNS FROM sessions").map((r) => r[0]); console.log('      sessions columns:', cols.join(','));
  sql("UPDATE sessions SET idle_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE revoked_at IS NULL"); t.eq((await req('GET', '/admin/api/status', { cookie: ck })).status, 401, 'idle session expired');
  await p2.close();
  resetLimits();
  const p3 = await newPage(browser, { width: 1280, height: 800 }); await login(p3);
  const c3 = (await p3.context().cookies()).find((c) => c.httpOnly && /admin|sess/i.test(c.name)); const ck3 = `${c3.name}=${c3.value}`;
  sql("UPDATE sessions SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE revoked_at IS NULL");
  t.eq((await req('GET', '/admin/api/status', { cookie: ck3 })).status, 401, 'absolutely-expired session');
  await p3.close();
});

await t.step('admin API (signed in): CSRF + origin enforced, secrets never returned, negative/zero/absurd prices & quantities refused (Q9/Q10)', async () => {
  resetLimits();
  const page = await newPage(browser, { width: 1280, height: 800 }); t.page = page; await login(page);
  const c = (await page.context().cookies()).find((x) => x.httpOnly && /admin|sess/i.test(x.name)); const cookie = `${c.name}=${c.value}`;
  const token = await page.$eval('meta[name="csrf-token"]', (m) => m.content);
  const A = (method, path, json, headers = {}) => req(method, path, { json, cookie, headers: { 'x-csrf-token': token, 'idempotency-key': randomUUID(), ...headers } });
  // every admin GET: no secrets in the response
  for (const r of ROUTES.filter((x) => x.mw.includes('adminApi') && x.method === 'GET')) { const res = await A('GET', r.path); t.ok(res.status < 500, `${r.path} → ${res.status}`); noSecrets(res.text, `admin GET ${r.path}`, { paths: true }); }
  for (const p of ['dashboard', 'orders', 'payments', 'services', 'tags', 'website', 'advertising', 'gateway', 'smm', 'security', 'audit', 'system']) { const res = await req('GET', '/admin/' + p, { cookie }); t.eq(res.status, 200, p); noSecrets(res.text, `admin page ${p}`, { paths: true }); }
  const gw = await A('GET', '/admin/api/gateway'); t.ok(/\*|•|hint|masked|configured/i.test(gw.text), 'secrets are shown masked/as configured-only: ' + gw.text.slice(0, 160));
  // CSRF
  t.eq((await req('POST', '/admin/api/orders/approve-all', { json: {}, cookie, headers: { 'idempotency-key': randomUUID() } })).status, 403, 'missing CSRF token');
  t.eq((await req('POST', '/admin/api/orders/approve-all', { json: {}, cookie, headers: { 'x-csrf-token': 'x'.repeat(64), 'idempotency-key': randomUUID() } })).status, 403, 'wrong CSRF token');
  t.eq((await req('POST', '/admin/api/orders/approve-all', { json: {}, cookie, headers: { 'x-csrf-token': token, origin: 'https://evil.example', 'idempotency-key': randomUUID() } })).status, 403, 'cross-origin request with a valid token');
  t.eq((await req('POST', '/admin/api/orders/approve-all', { json: {}, cookie, headers: { 'x-csrf-token': token, origin: 'null', 'idempotency-key': randomUUID() } })).status, 403, 'Origin: null');
  const form = await req('POST', '/admin/api/orders/approve-all', { body: 'a=b', cookie, headers: { 'content-type': 'application/x-www-form-urlencoded', 'x-csrf-token': token, origin: BASE } }); t.ok([400, 403, 415].includes(form.status), 'form post → ' + form.status);
  const gets = await req('GET', '/admin/api/orders/approve-all', { cookie }); t.ok([404, 405].includes(gets.status), 'state-changing route is not reachable by GET: ' + gets.status);
  // approve-all needs an idempotency key
  const approvedBefore = sql("SELECT COUNT(*) FROM orders WHERE status = 'MANUAL_APPROVED'");
  const noIdem = await req('POST', '/admin/api/orders/approve-all', { json: { confirm: true }, cookie, headers: { 'x-csrf-token': token } });
  t.ok([400, 422].includes(noIdem.status), 'Approve All without an idempotency key → ' + noIdem.status + ' ' + noIdem.text.slice(0, 100));
  t.eq(sql("SELECT COUNT(*) FROM orders WHERE status = 'MANUAL_APPROVED'"), approvedBefore, 'and nothing was approved');
  // services: negative / zero / absurd values (Q9, Q10)
  const cur = (await A('GET', `/admin/api/services/${serviceId('ig-likes-100')}`)).json; const svc = cur.service ?? cur; t.ok(svc && svc.code, 'service payload: ' + JSON.stringify(cur).slice(0, 160));
  const put = (over) => A('PUT', `/admin/api/services/${svc.id}`, { ...svc, ...over });
  const bad = [
    ['negative price', { price: '-5' }], ['zero price', { price: '0' }], ['sub-rupee price', { price: '0.5' }], ['three decimals', { price: '5.001' }], ['scientific', { price: '1e3' }], ['huge price', { price: '99999999999' }],
    ['comma decimal', { price: '5,00' }], ['text price', { price: 'free' }], ['array price', { price: [5] }], ['unicode digits', { price: '٥' }],
    ['negative quantity', { quantity: -100 }], ['zero quantity', { quantity: 0 }], ['fractional quantity', { quantity: '1.5' }], ['text quantity', { quantity: 'many' }], ['huge quantity', { quantity: 99999999999 }],
    ['negative min', { min_quantity: -1 }], ['negative max', { max_quantity: -1 }], ['min above max', { min_quantity: 500, max_quantity: 10 }], ['quantity outside min/max', { min_quantity: 1, max_quantity: 50 }],
    ['negative provider cost', { smm_cost: '-1' }], ['bad mode', { mode: 'FREE' }], ['bad status', { status: 'deleted' }], ['bad type', { type: 'hack' }], ['unknown platform', { platform_id: 99999 }], ['XSS title length', { title: 'x'.repeat(500) }],
    ['AUTO without SMM id', { mode: 'AUTO', smm_service_id: '' }], ['SMM id with spaces/injection', { mode: 'AUTO', smm_service_id: "1; DROP" }],
  ];
  for (const [name, over] of bad) { const r = await put(over); t.ok(r.status === 422, `${name} → ${r.status} ${r.text.slice(0, 90)}`); }
  t.eq(sql(`SELECT price_paise FROM services WHERE id = ${svc.id}`), '500', 'the stored price is untouched by all of the above');
  const created = await A('POST', '/admin/api/services', { ...svc, id: undefined, code: 'neg-test-' + uniq(), price: '-1' }); t.eq(created.status, 422, 'creating a service with a negative price');
  // stored XSS: titles are escaped on output
  const xssTitle = '<img src=x onerror=window.__x=1>'; const okPut = await put({ title: xssTitle }); t.eq(okPut.status, 200, okPut.text.slice(0, 120));
  const home = await req('GET', '/'); t.ok(!home.text.includes(xssTitle) && home.text.includes('&lt;img src=x onerror=window.__x=1&gt;'), 'service titles are HTML-escaped on the storefront');
  await put({ title: svc.title });
  await page.close();
});

// ═════════════════════════ hardening ═════════════════════════
await t.step('hardening · security headers on every kind of response, cookie flags, host-header injection, open redirects, reflected XSS, method handling', async () => {
  const o = await newOrder();
  const targets = ['/', '/admin/login', `/pay/${o.ref}`, `/order/${o.token}`, '/api/order/' + o.token, '/no-such-page', '/assets/css/app.css', '/manifest.webmanifest', '/robots.txt', '/health', '/theme.css'];
  for (const p of targets) {
    const r = await req('GET', p); const h = (k) => r.headers.get(k) || '';
    t.eq(h('x-content-type-options'), 'nosniff', `${p} nosniff`);
    if (/text\/html/.test(h('content-type'))) {
      const csp = h('content-security-policy'); t.has(csp, "default-src 'self'", p); t.ok(!/unsafe-(inline|eval)/.test(csp), `${p} CSP has unsafe-*: ${csp}`); t.has(csp, "frame-ancestors 'none'", p); t.has(csp, "object-src 'none'", p); t.has(csp, "base-uri 'self'", p); t.has(csp, "form-action 'self'", p);
      t.ok(/DENY|SAMEORIGIN/.test(h('x-frame-options')), `${p} X-Frame-Options`); t.ok(h('referrer-policy'), `${p} Referrer-Policy`); t.ok(h('permissions-policy'), `${p} Permissions-Policy`);
    }
    t.ok(!/PHP|Apache|nginx/i.test(h('x-powered-by') + h('server').replace(/PHP [\d.]+ Development Server/, '')), `${p} banner: ${h('x-powered-by')} ${h('server')}`);
  }
  // cookies
  const hit = await fetch(BASE + '/api/track/hit', { method: 'POST', headers: { ...JSONH, 'user-agent': DESKTOP_UA }, body: JSON.stringify({ path: '/', first: true }) });
  const sc = hit.headers.getSetCookie?.() || []; t.ok(sc.length >= 1, 'visitor cookies are set'); for (const c of sc) { t.has(c, 'HttpOnly'); t.ok(/SameSite=(Lax|Strict)/i.test(c), 'SameSite: ' + c); }
  // Host header injection: absolute URLs come from APP_URL, never from the Host header
  const poisoned = await rawHttp('GET', '/', { host: 'evil.example', 'x-forwarded-host': 'evil.example', 'x-forwarded-proto': 'https' });
  t.ok(!/evil\.example/.test(poisoned.text), 'Host / X-Forwarded-Host must not appear in links or canonical tags');
  const pr = await rawHttp('GET', `/pay/${o.ref}`, { host: 'evil.example' }); t.ok(!/evil\.example/.test(pr.text) || pr.status !== 200, 'payment page with a poisoned Host header');
  // open redirect
  for (const q of ['next', 'redirect', 'return', 'url', 'to', 'continue', 'r']) { const r = await req('GET', `/admin/login?${q}=https://evil.example/x`); t.ok(!/evil\.example/.test(r.headers.get('location') || '') && !/evil\.example/.test(r.text), `${q}= is not an open redirect`); }
  const rp = await req('GET', '/admin/dashboard?next=//evil.example'); t.ok(!/evil\.example/.test(rp.headers.get('location') || ''));
  // reflected XSS
  for (const p of ['/<script>alert(1)</script>', '/?q="><img src=x onerror=alert(1)>', '/admin/login?error=<svg/onload=alert(1)>', `/pay/${o.ref}?returned="><script>alert(1)</script>`, '/order/<img src=x onerror=alert(1)>']) {
    const r = await rawHttp('GET', encodeURI(p).replace(/%25/g, '%'), { host: `127.0.0.1:${PORT}` }); t.ok(!/<script>alert\(1\)|<img src=x onerror|<svg\/onload/i.test(r.text), `${p} is not reflected unescaped`);
  }
  // methods
  for (const m of ['TRACE', 'TRACK', 'PROPFIND', 'DEBUG']) { const r = await rawHttp(m, '/', { host: `127.0.0.1:${PORT}` }).catch(() => ({ status: 0, text: '' })); t.ok([0, 400, 404, 405, 501].includes(r.status) && !/TRACE \//.test(r.text), `${m} → ${r.status}`); }
  const head = await req('HEAD', '/'); t.eq(head.status, 200);
  // origin / same-site protection of the public JSON API
  const evilOrigin = await req('POST', '/api/checkout', { headers: { 'content-type': 'application/json', 'x-requested-with': 'smb', origin: 'https://evil.example', 'idempotency-key': randomUUID() }, body: JSON.stringify({ service_id: serviceId('ig-likes-100'), target: 'https://www.instagram.com/p/Evil' + uniq() + '/' }) });
  t.eq(evilOrigin.status, 403, 'cross-site checkout is refused');
  const noXrw = await req('POST', '/api/checkout', { headers: { 'content-type': 'application/json', origin: BASE, 'idempotency-key': randomUUID() }, body: JSON.stringify({ service_id: serviceId('ig-likes-100'), target: 'https://www.instagram.com/p/NoXrw' + uniq() + '/' }) });
  t.ok([403, 415].includes(noXrw.status), 'checkout without the X-Requested-With marker is refused: ' + noXrw.status);
  const beacon = await req('POST', '/api/track/hit', { headers: { 'content-type': 'text/plain', origin: 'https://evil.example' }, body: '{"path":"/"}' }); t.ok([400, 403].includes(beacon.status), 'cross-site beacon → ' + beacon.status);
});

await t.step('hardening · public rate limits (checkout, order lookup, webhook flood) answer 429 with Retry-After and recover', async () => {
  resetLimits();
  const seen = []; for (let i = 0; i < 12; i++) seen.push((await checkout('ig-likes-100', `https://www.instagram.com/p/Rate${uniq()}/`)).status);
  t.ok(seen.includes(429), 'checkout is rate limited: ' + seen);
  const r = await checkout('ig-likes-100', `https://www.instagram.com/p/Rate${uniq()}/`); t.eq(r.status, 429); t.ok(Number(r.headers.get('retry-after')) > 0, 'Retry-After');
  t.eq(r.json?.error?.code, 'rate_limited');
  resetLimits();
  const l = []; for (let i = 0; i < 35; i++) l.push((await post('/api/orders/lookup', { tokens: [] })).status); t.ok(l.includes(429), 'lookup is rate limited');
  resetLimits();
});

await browser.close();
server.kill('SIGTERM');
const failed = t.finish();
await mocksReset();
process.exit(failed ? 1 : 0);
