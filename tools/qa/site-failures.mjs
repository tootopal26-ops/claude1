// Failure-mode QA (brief §58–59): every dependency that can break must produce a USEFUL state for the customer, never a duplicate order
// and never a misleading success. Covers: double-click / lost response / parallel requests; payment-gateway offline, bad key, quota,
// "no provider" and timeout; browser offline; payment pending; SMM offline / invalid key / invalid service / no balance / garbled / hang;
// database unreachable and database lock timeout. Local dev only (drives the mock gateway, the mock SMM panel and the dev database).
import { spawn } from 'node:child_process';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { launch, newPage, DESKTOP_UA, BASE, ROOT, sql, rows, alpha, smm, mocksReset, resetLimits, payment, payOk, placeOrder, openCard, serviceId, sleep, php, useMockProviderIds } from './site.mjs';
import { suite } from './harness.mjs';

const t = suite('site-failures');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
const browser = await launch();
const ctxPage = async (opts = {}) => { const p = await newPage(browser, { width: 1280, height: 900 }, { allowStatus: [429, 500, 502, 503, 504, 422], ...opts }); t.page = p; return p; };

const healthy = async () => {
  await mocksReset();
  php('tools/qa/smm-provider.php', '--key=smm-key-ok', '--active=1', '--url=http://127.0.0.1:4020/api/v2');
  useMockProviderIds(); resetLimits();
  sql("UPDATE services SET smm_provider_id = (SELECT id FROM smm_providers WHERE is_default = 1 LIMIT 1) WHERE mode = 'AUTO'");
};
await healthy();

const countOrders = (target) => Number(sql(`SELECT COUNT(*) FROM orders WHERE target_url = '${target}'`));
const checkoutApi = async (code, target, { key = randomUUID(), cookie = '' } = {}) => {
  const r = await fetch(BASE + '/api/checkout', {
    method: 'POST', headers: { 'content-type': 'application/json', 'x-requested-with': 'smb', origin: BASE, 'idempotency-key': key, ...(cookie ? { cookie } : {}) },
    body: JSON.stringify({ service_id: serviceId(code), target }),
  });
  let body = null; try { body = await r.json(); } catch { /* not JSON */ }
  return { status: r.status, body, key };
};
const customerJson = async (token) => (await (await fetch(`${BASE}/api/order/${token}`)).json());
const LEAKS = /Invalid API key|Not enough funds|Incorrect service|rejected|provider|smm|SQLSTATE|PDO|Stack trace|\/home\/|mariadb|127\.0\.0\.1|api[_-]?key|secret|bearer/i;
const noLeak = (value, what) => { const s = typeof value === 'string' ? value : JSON.stringify(value); const m = s.match(LEAKS); t.ok(!m, `${what} leaks internals: "${m?.[0]}" in ${s.slice(0, 200)}`); };
const cron = () => php('bin/cron.php');
const paidAuto = async (code = 'ig-followers-50', target = 'https://instagram.com/failcase_' + randomUUID().slice(0, 6)) => {
  resetLimits();
  const c = await checkoutApi(code, target); t.eq(c.status, 200, JSON.stringify(c.body));
  const ref = c.body.url.match(/pay\/([a-f0-9]{32})/)[1];
  const res = await payOk(ref); t.eq(res.webhook?.status, 200, JSON.stringify(res));
  return { ref, token: c.body.token, order: () => payment(ref) };
};

// ═════════════════════════ duplicate protection ═════════════════════════
await t.step('double-click on PROCEED TO PAYMENT sends ONE request and creates ONE order', async () => {
  const page = await ctxPage();
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const card = await openCard(page, 'ig-likes-100');
  const target = 'https://www.instagram.com/p/DoubleClick01/';
  await card.locator('input[name=target]').fill(target);
  await page.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card.elementHandle());
  const reqs = []; page.on('request', (r) => { if (r.url().includes('/api/checkout')) reqs.push(r.headers()['idempotency-key']); });
  const btn = card.locator('[data-action=proceed]');
  await Promise.allSettled([btn.dblclick({ timeout: 3000 }), btn.click({ timeout: 3000, force: true, noWaitAfter: true })]);
  await page.waitForURL(/\/pay\//, { timeout: 20000 });
  t.eq(reqs.length, 1, 'exactly one checkout request: ' + reqs.length);
  t.eq(countOrders(target), 1);
  await page.close();
});

await t.step('response lost after the server created the order → retry reuses it (no duplicate), also across tabs', async () => {
  resetLimits();
  const page = await ctxPage();
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const target = 'https://www.instagram.com/p/LostReply01/';
  const card = await openCard(page, 'ig-likes-100');
  await card.locator('input[name=target]').fill(target);
  await page.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card.elementHandle());
  await page.route('**/api/checkout', async (route) => { await route.fetch(); await route.abort('failed'); });   // the server DID create the order; the browser never hears back
  await card.locator('[data-action=proceed]').click();
  await page.waitForFunction((el) => el.querySelector('.field-msg').dataset.state === 'invalid', await card.elementHandle(), { timeout: 15000 });
  t.has(await t.text(card.locator('.field-msg')).then((s) => s.toLowerCase()), 'network');
  t.eq(await card.locator('[data-action=proceed]').isDisabled(), false, 'button re-enabled so the customer can retry');
  t.eq(countOrders(target), 1, 'the first attempt did create an order');
  await page.unroute('**/api/checkout');
  await Promise.all([page.waitForURL(/\/pay\//, { timeout: 20000 }), card.locator('[data-action=proceed]').click()]);
  t.eq(countOrders(target), 1, 'retry must reuse the pending order, not create a second one');
  const ref = page.url().match(/pay\/([a-f0-9]{32})/)[1];
  // the same visitor in a second tab, same service + same link → the same session again
  const tab2 = await page.context().newPage();
  await tab2.goto(BASE + '/', { waitUntil: 'networkidle' });
  const card2 = await openCard(tab2, 'ig-likes-100');
  await card2.locator('input[name=target]').fill(target);
  await tab2.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card2.elementHandle());
  await Promise.all([tab2.waitForURL(/\/pay\//, { timeout: 20000 }), card2.locator('[data-action=proceed]').click()]);
  t.has(tab2.url(), ref, 'second tab lands on the very same payment session');
  t.eq(countOrders(target), 1);
  await page.close();
});

await t.step('12 parallel checkouts with the SAME Idempotency-Key → one order, identical answers', async () => {
  resetLimits();
  const key = randomUUID(); const target = 'https://www.instagram.com/p/ParallelSame1/';
  const rs = await Promise.all(Array.from({ length: 12 }, () => checkoutApi('ig-likes-100', target, { key })));
  t.eq(new Set(rs.filter((r) => r.status === 200).map((r) => r.body.ref)).size, 1, JSON.stringify(rs.map((r) => r.status)));
  t.ok(rs.every((r) => [200, 409, 429].includes(r.status)), 'statuses ' + rs.map((r) => r.status));
  t.eq(countOrders(target), 1);
});

await t.step('same Idempotency-Key with a DIFFERENT payload is refused (no cross-order replay)', async () => {
  resetLimits();
  const key = randomUUID();
  const a = await checkoutApi('ig-likes-100', 'https://www.instagram.com/p/KeyReplayA1/', { key });
  const b = await checkoutApi('ig-likes-50k', 'https://www.instagram.com/p/KeyReplayA1/', { key });
  t.eq(a.status, 200); t.ok([409, 422].includes(b.status), 'key reuse with another body → ' + b.status);
});

await t.step('12 parallel checkouts from ONE visitor with different keys (same service + link) → still one pending order', async () => {
  resetLimits();
  const ctx = await browser.newContext({ userAgent: DESKTOP_UA }); const p = await ctx.newPage();
  await p.goto(BASE + '/', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);       // the first-party visitor cookie is set by the page's beacon
  const target = 'https://www.instagram.com/p/ParallelVisitor1/';
  const rs = await Promise.all(Array.from({ length: 12 }, () => p.evaluate(async ({ id, target, key }) => {
    const r = await fetch('/api/checkout', { method: 'POST', headers: { 'content-type': 'application/json', 'x-requested-with': 'smb', 'idempotency-key': key }, body: JSON.stringify({ service_id: id, target }) });
    return { status: r.status, body: await r.json().catch(() => null) };
  }, { id: serviceId('ig-likes-100'), target, key: randomUUID() })));
  const ok = rs.filter((r) => r.status === 200);
  t.ok(ok.length >= 1, JSON.stringify(rs.map((r) => r.status)));
  t.eq(countOrders(target), 1, `one order for 12 racing requests (statuses ${rs.map((r) => r.status)})`);
  await ctx.close();
});

// ═════════════════════════ payment gateway failures at checkout ═════════════════════════
const gatewayCase = async (label, setup, { expect, ms = 20000 }) => t.step(`gateway ${label}: customer gets a calm explanation, no stuck order, and checkout recovers afterwards`, async () => {
  await healthy(); await setup();
  const page = await ctxPage();
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const target = 'https://www.instagram.com/p/Gateway' + label.replace(/\W/g, '') + '1/';
  const card = await openCard(page, 'ig-likes-100');
  await card.locator('input[name=target]').fill(target);
  await page.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card.elementHandle());
  const t0 = Date.now();
  await card.locator('[data-action=proceed]').click();
  t.has(await t.text(card.locator('.field-msg')), 'Creating secure payment', 'progress is shown while waiting');
  t.eq(await card.locator('[data-action=proceed]').isDisabled(), true, 'button disabled during the critical request');
  await page.waitForFunction((el) => el.querySelector('.field-msg').dataset.state === 'invalid', await card.elementHandle(), { timeout: ms + 15000 });
  const msg = await t.text(card.locator('.field-msg'));
  expect.test(msg) || t.ok(false, `unexpected message: ${msg}`);
  noLeak(msg, 'checkout message');
  t.ok(page.url() === BASE + '/' || page.url() === BASE + '/#instagram', 'stays on the page: ' + page.url());
  t.eq(await card.locator('[data-action=proceed]').isDisabled(), false, 'button re-enabled');
  t.ok((await page.locator('.toast[data-type=error], .toast[data-type=warning]').count()) >= 1, 'toast shown');
  console.log(`      (${label}: ${((Date.now() - t0) / 1000).toFixed(1)} s) "${msg}"`);
  const dangling = Number(sql(`SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id = o.id WHERE o.target_url = '${target}' AND o.status = 'PENDING_PAYMENT' AND p.status IN ('pending','creating')`));
  t.eq(dangling, 0, 'no live pending order is left behind for a payment that could not be created');
  await healthy();
  resetLimits();
  await Promise.all([page.waitForURL(/\/pay\//, { timeout: 20000 }), card.locator('[data-action=proceed]').click()]);
  t.ok((await page.locator('#qr-img').count()) === 1, 'recovered: QR page opens once the gateway is back');
  await page.close();
});
await gatewayCase('offline', () => alpha('/__control', { mode: { offline: true } }), { expect: /unavailable|try again|moment/i });
await gatewayCase('no-provider', () => alpha('/__control', { mode: { failCreate: true } }), { expect: /unavailable|try again|moment/i });
await gatewayCase('quota-exhausted', () => alpha('/__control', { mode: { quota: true } }), { expect: /unavailable|try again|moment/i });
await gatewayCase('invalid-key', () => alpha('/__control', { apiKey: 'a-different-key' }), { expect: /unavailable|try again|moment/i });
await gatewayCase('timeout', () => alpha('/__control', { mode: { slowMs: 24000 } }), { expect: /unavailable|try again|moment|taking too long/i, ms: 24000 });

// ═════════════════════════ browser-side network errors ═════════════════════════
await t.step('browser offline at checkout: network message, nothing created; back online the retry works', async () => {
  await healthy();
  const page = await ctxPage();
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const target = 'https://www.instagram.com/p/Offline0001/';
  const card = await openCard(page, 'ig-likes-100');
  await card.locator('input[name=target]').fill(target);
  await page.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card.elementHandle());
  await page.context().setOffline(true);
  await card.locator('[data-action=proceed]').click();
  await page.waitForFunction((el) => el.querySelector('.field-msg').dataset.state === 'invalid', await card.elementHandle(), { timeout: 15000 });
  t.has((await t.text(card.locator('.field-msg'))).toLowerCase(), 'network');
  t.eq(countOrders(target), 0);
  await page.context().setOffline(false);
  await Promise.all([page.waitForURL(/\/pay\//, { timeout: 20000 }), card.locator('[data-action=proceed]').click()]);
  t.eq(countOrders(target), 1);
  await page.close();
});

await t.step('payment page keeps working through a connection drop: "Connection problem — retrying…" then recovers and shows the real result', async () => {
  await healthy();
  const page = await ctxPage();
  const { ref } = await placeOrder(page, 'ig-views-1k', 'https://www.instagram.com/reel/PollDrop001/');
  await page.waitForSelector('#qr-img');
  await page.context().setOffline(true);
  await page.waitForFunction(() => !document.querySelector('#pay-offline').hidden, null, { timeout: 30000 });
  t.has(await t.text(page.locator('#pay-offline')), 'Connection problem');
  t.eq(await page.locator('[data-view=paid]').isHidden(), true, 'never claims success while offline');
  await payOk(ref);                                                       // the money arrives while the customer is offline
  await page.context().setOffline(false);
  await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 40000 });
  t.eq(await page.locator('#pay-offline').isHidden(), true, 'banner cleared');
  await page.close();
});

await t.step('payment PENDING: the page keeps waiting (never success, never expired early) and the status API reveals nothing private', async () => {
  await healthy();
  const page = await ctxPage();
  const { ref } = await placeOrder(page, 'yt-views-10', 'https://youtu.be/PendingPol1');
  await page.waitForTimeout(7500);                                         // at least two polls
  t.eq(await page.locator('[data-view=pending]').isVisible(), true);
  t.has(await t.text(page.locator('#pay-status')), 'Waiting for your payment');
  const s = await (await fetch(`${BASE}/api/pay/${ref}/status`)).json();
  t.eq(s.state, 'pending'); noLeak(s, 'pay status');
  t.ok(!('gateway_order_id' in s) && !('payment_url' in s) && !('utr' in s), 'no gateway identifiers in the public status: ' + Object.keys(s));
  await page.close();
});

// ═════════════════════════ SMM provider failures (after the customer has paid) ═════════════════════════
const smmCase = async (label, arrange, { status, kind, retry = false, expectError }) => t.step(`SMM ${label}: customer sees a calm status, admin gets the real reason, no duplicate provider order`, async () => {
  await healthy(); await arrange();
  const o = await paidAuto();
  await sleep(label === 'hang (timeout)' ? 0 : 1500);
  const row = o.order();
  t.eq(row.oStatus, status, `${label}: order status`);
  if (kind) t.eq(row.failureKind, kind, 'failure kind');
  if (expectError) t.ok(expectError.test(row.lastError || ''), `admin-visible reason: "${row.lastError}"`);
  const cj = await customerJson(o.token);
  noLeak(cj, 'customer order JSON');
  const page = await ctxPage();
  await page.goto(`${BASE}/order/${o.token}`, { waitUntil: 'networkidle' });
  const heading = await t.text(page.locator('#o-heading'));
  noLeak(heading, 'order page heading');
  t.ok(!/failed|error|rejected/i.test(heading), 'customer heading is reassuring, not alarming: ' + heading);
  noLeak(await page.locator('body').innerText(), 'order page text');
  console.log(`      (${label}: status ${row.oStatus}, kind ${row.failureKind}, customer sees “${heading}”)`);
  await page.close();
  const sent = (await (await fetch('http://127.0.0.1:4020/__state')).json()).requests.filter((r) => r.action === 'add').length;
  t.ok(sent <= 1, 'at most one provider order was ever sent: ' + sent);
  if (!retry) { cron(); await sleep(300); t.eq(o.order().oStatus, status, 'cron must NOT blindly resubmit an order whose outcome is unclear'); }
  return o;
});

const DEAD = 'http://127.0.0.1:4029/api/v2';          // nothing listens here → "connection refused": the request never left, so a retry is safe
await smmCase('unreachable (connection refused)', () => php('tools/qa/smm-provider.php', `--url=${DEAD}`), { status: 'PAID', kind: 'unreachable', retry: true, expectError: /./ });
await t.step('SMM unreachable → back online: cron retries after the back-off and the order is submitted exactly once', async () => {
  await healthy(); php('tools/qa/smm-provider.php', `--url=${DEAD}`);
  const p = await paidAuto('ig-followers-50', 'https://instagram.com/outage_user');
  await sleep(1200);
  t.eq(p.order().oStatus, 'PAID'); t.ok(sql(`SELECT next_attempt_at FROM orders WHERE id = ${p.order().id}`) !== 'NULL', 'a retry is scheduled');
  cron(); t.eq(p.order().oStatus, 'PAID', 'not due yet → untouched');
  t.eq(Number(sql(`SELECT provider_attempts FROM orders WHERE id = ${p.order().id}`)), 1);
  sql(`UPDATE orders SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE, paid_at = paid_at - INTERVAL 10 MINUTE WHERE id = ${p.order().id}`);
  cron(); t.eq(p.order().oStatus, 'PAID', 'still down → still waiting (attempt 2)');
  t.eq(Number(sql(`SELECT provider_attempts FROM orders WHERE id = ${p.order().id}`)), 2);
  php('tools/qa/smm-provider.php', '--url=http://127.0.0.1:4020/api/v2');
  sql(`UPDATE orders SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE, paid_at = paid_at - INTERVAL 10 MINUTE WHERE id = ${p.order().id}`);
  cron(); await sleep(500);
  t.ok(['SUBMITTED', 'IN_PROGRESS', 'COMPLETED'].includes(p.order().oStatus), 'submitted after recovery: ' + p.order().oStatus);
  const adds = (await (await fetch('http://127.0.0.1:4020/__state')).json()).requests.filter((r) => r.action === 'add' && r.link.includes('outage_user'));
  t.eq(adds.length, 1, 'exactly one provider order');
});
await smmCase('connection dropped mid-request', () => smm('/__control', { offline: true }), { status: 'FAILED', kind: 'ambiguous', expectError: /unclear|verify/i });
await smmCase('invalid API key', () => php('tools/qa/smm-provider.php', '--key=smm-key-bad'), { status: 'FAILED', kind: 'rejected', expectError: /api key/i });
await smmCase('invalid service ID', () => sql("UPDATE services SET smm_service_id = '9999' WHERE code = 'ig-followers-50'"), { status: 'FAILED', kind: 'rejected', expectError: /service/i });
await smmCase('insufficient balance', () => php('tools/qa/smm-provider.php', '--key=smm-key-poor'), { status: 'FAILED', kind: 'rejected', expectError: /funds|balance/i });
await smmCase('garbled reply', () => smm('/__control', { garbled: true }), { status: 'FAILED', kind: 'ambiguous', expectError: /unclear|verify/i });
await smmCase('hang (timeout)', () => smm('/__control', { hang: true }), { status: 'FAILED', kind: 'ambiguous', expectError: /unclear|verify|timed out|timeout/i });

await t.step('after the provider problem is fixed, an admin retry delivers the order (no duplicate)', async () => {
  await healthy();
  const o = await paidAuto();
  sql(`UPDATE orders SET status = 'FAILED', last_error = 'Provider rejected the order: Incorrect service ID', failure_kind = 'rejected', provider_order_id = NULL WHERE id = ${o.order().id}`);
  const out = php('tools/qa/admin-retry.php', String(o.order().id));
  t.has(out, 'submitted');
  t.ok(['SUBMITTED', 'IN_PROGRESS'].includes(o.order().oStatus), o.order().oStatus);
});

// ═════════════════════════ database failures ═════════════════════════
await t.step('DATABASE unreachable: friendly 503 page, no leaks, /health reports it, static assets still load, JSON API answers 503', async () => {
  const port = 8091;
  const child = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tools/router.php'], { cwd: ROOT, env: { ...process.env, DB_PORT: '1', DB_HOST: '127.0.0.1', APP_DEBUG: 'false', APP_URL: `http://127.0.0.1:${port}`, PHP_CLI_SERVER_WORKERS: '2' }, stdio: 'ignore' });
  try {
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${port}/assets/css/app.css`); break; } catch { await sleep(150); } }
    const home = await fetch(`http://127.0.0.1:${port}/`);
    const html = await home.text();
    t.ok([500, 503].includes(home.status), 'status ' + home.status);
    t.has(html.toLowerCase(), 'try again'); noLeak(html, 'error page');
    t.ok(!/SQLSTATE|PDOException|smb_dev|smb_dev_pw|Connection refused/i.test(html), 'no DB details on the page');
    t.has(home.headers.get('content-type') || '', 'text/html');
    const css = await fetch(`http://127.0.0.1:${port}/assets/css/app.css`); t.eq(css.status, 200);
    const health = await fetch(`http://127.0.0.1:${port}/health`); t.eq(health.status, 503);
    const hb = await health.text(); noLeak(hb, '/health body');
    const co = await fetch(`http://127.0.0.1:${port}/api/checkout`, { method: 'POST', headers: { 'content-type': 'application/json', 'x-requested-with': 'smb', origin: `http://127.0.0.1:${port}`, 'idempotency-key': randomUUID() }, body: JSON.stringify({ service_id: 1, target: '@someone' }) });
    t.ok([500, 503].includes(co.status), 'checkout status ' + co.status);
    const cb = await co.text(); noLeak(cb, 'checkout error JSON');
    t.ok((() => { try { return typeof JSON.parse(cb).error?.message === 'string'; } catch { return false; } })(), 'checkout error is JSON with a message: ' + cb.slice(0, 120));
    // the browser shows it nicely too
    const page = await ctxPage(); await page.goto(`http://127.0.0.1:${port}/`, { waitUntil: 'load' });
    t.has((await page.locator('body').innerText()).toLowerCase(), 'try again');
    await page.screenshot({ path: '/tmp/qa/db-down.png' }); await page.close();
  } finally { child.kill('SIGTERM'); }
});

await t.step('DATABASE lock timeout: a blocked table never hangs the site for minutes — the request fails fast with a friendly error, then recovers', async () => {
  await healthy();
  const port = 8092;                                                          // production-like: APP_DEBUG=false (no debug detail in errors)
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'tools/router.php'], { cwd: ROOT, env: { ...process.env, APP_DEBUG: 'false', APP_URL: `http://127.0.0.1:${port}`, PHP_CLI_SERVER_WORKERS: '3' }, stdio: 'ignore' });
  const locker = spawn('mariadb', ['-uroot', 'smb_dev', '-e', 'LOCK TABLES orders WRITE; SELECT SLEEP(40); UNLOCK TABLES;'], { stdio: 'ignore' });
  const release = () => {
    locker.kill('SIGTERM');
    for (const id of rows("SELECT ID FROM information_schema.PROCESSLIST WHERE INFO LIKE 'SELECT SLEEP(40)%'")) { try { execFileSync('mariadb', ['-uroot', '-e', `KILL ${id[0]}`], { stdio: 'ignore' }); } catch { /* already gone */ } }
  };
  try {
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${port}/assets/css/app.css`); break; } catch { await sleep(150); } }
    await sleep(700);
    const t0 = Date.now();
    const r = await fetch(`http://127.0.0.1:${port}/api/checkout`, { method: 'POST', headers: { 'content-type': 'application/json', 'x-requested-with': 'smb', origin: `http://127.0.0.1:${port}`, 'idempotency-key': randomUUID() }, body: JSON.stringify({ service_id: serviceId('ig-likes-100'), target: 'https://www.instagram.com/p/LockTimeout01/' }) });
    const body = await r.json().catch(() => null);
    const secs = (Date.now() - t0) / 1000;
    console.log(`      (blocked checkout answered after ${secs.toFixed(1)} s with HTTP ${r.status}: ${JSON.stringify(body)?.slice(0, 160)})`);
    t.ok(secs < 35, `answered in ${secs.toFixed(1)} s`);
    t.eq(r.status, 503);
    noLeak(body, 'lock timeout response');
    t.ok(typeof body?.error?.message === 'string' && /try again/i.test(body.error.message), 'friendly JSON message');
    t.eq(countOrders('https://www.instagram.com/p/LockTimeout01/'), 0, 'nothing half-created');
    // a page view while the table is locked must not hang either (reads of other tables keep working)
    const home = await fetch(`http://127.0.0.1:${port}/`); t.eq(home.status, 200, 'the storefront itself stays up while orders is locked');
  } finally { release(); server.kill('SIGTERM'); }
  await sleep(800); resetLimits();
  const ok = await checkoutApi('ig-likes-100', 'https://www.instagram.com/p/LockTimeout02/');
  t.eq(ok.status, 200, 'recovered after the lock was released: ' + JSON.stringify(ok.body));
});

await healthy();
const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
