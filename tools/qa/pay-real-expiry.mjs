// REAL-TIME check of the 5:00 payment window (no clock tricks): the countdown must run 05:00 → 04:59 → 04:58 …, turn warn at 1:00 and
// critical at 0:30, and at 00:00 the page must say "Payment session expired" — never a success state, QR removed, download disabled —
// and the server must expire the order. Takes ~5½ minutes. Does NOT reset the database (safe next to other work).
import { launch, newPage, BASE, mocksReset, resetLimits, payment, placeOrder, useMockProviderIds, sleep, sql, seedUpiQr } from './site.mjs';
import { suite } from './harness.mjs';

const t = suite('pay-real-expiry');
useMockProviderIds(); resetLimits(); await mocksReset();
const browser = await launch();
const page = await newPage(browser, { width: 1280, height: 900 }); t.page = page;
let ref, startedAt;
const seen = [];                                    // every distinct timer text, in order, with the time it appeared
const successShown = [];

await t.step('open a payment session; the countdown starts at 05:00 and ticks down one second at a time', async () => {
  ({ ref } = await placeOrder(page, 'ig-likes-100', 'https://www.instagram.com/p/RealExpiry01/'));
  startedAt = Date.now();
  seedUpiQr(ref); await page.reload({ waitUntil: 'networkidle' });      // QR flavour: the one whose image and download must die with the session (a hosted page has neither)
  await page.exposeFunction('__tick', (txt, level, paidVisible) => { if (seen[seen.length - 1]?.txt !== txt) seen.push({ txt, level, at: Date.now() }); if (paidVisible) successShown.push(Date.now()); });
  await page.evaluate(() => {
    setInterval(() => window.__tick(document.querySelector('#timer-value')?.textContent.trim(), document.querySelector('#timer')?.dataset.level, !!document.querySelector('[data-view=paid]:not([hidden])')), 100);
  });
  await page.waitForTimeout(6500);
  const first = seen.slice(0, 8).map((s) => s.txt);
  t.ok(first[0] >= '04:58', 'starts at ~05:00: ' + first.join(' '));
  for (let i = 1; i < first.length; i++) t.ok(first[i] < first[i - 1], `counts down: ${first[i - 1]} → ${first[i]}`);
  const gaps = seen.slice(1, 7).map((s, i) => s.at - seen[i].at); t.ok(gaps.every((g) => g > 850 && g < 1150), 'one tick per second: ' + gaps.join(','));
  const server = await (await fetch(`${BASE}/api/pay/${ref}/status`)).json(); t.eq(server.state, 'pending');
  t.ok(server.expires_at - server.server_now > 285000, 'the server window is 5:00: ' + (server.expires_at - server.server_now));
});

await t.step('at 1:00 the timer turns "warn", at 0:30 "critical" (and the page stays pending the whole time)', async () => {
  await page.waitForFunction(() => document.querySelector('#timer')?.dataset.level === 'warn', null, { timeout: 250000, polling: 1000 });
  const w = await page.$eval('#timer-value', (e) => e.textContent.trim()); t.ok(w <= '01:01' && w >= '00:58', 'warn at ' + w);
  await page.waitForFunction(() => document.querySelector('#timer')?.dataset.level === 'critical', null, { timeout: 60000, polling: 500 });
  const c = await page.$eval('#timer-value', (e) => e.textContent.trim()); t.ok(c <= '00:31' && c >= '00:28', 'critical at ' + c);
  t.eq(await page.locator('[data-view=pending]').isVisible(), true);
});

await t.step('at 00:00: "Payment session expired" appears within 2 s, the QR is removed, download is disabled — and success was NEVER shown', async () => {
  await page.waitForSelector('[data-view=expired]:not([hidden])', { timeout: 60000 });
  const took = (Date.now() - startedAt) / 1000;
  t.ok(took >= 295 && took <= 312, `expired after ${took.toFixed(1)} s of real time`);
  t.has(await page.locator('[data-view=expired] h1').innerText(), 'Payment session expired');
  t.eq(await page.$eval('#qr-img', (e) => e.hasAttribute('src')), false);
  t.eq(await page.$eval('#btn-download', (e) => e.getAttribute('aria-disabled')), 'true');
  t.eq(await page.$eval('#btn-open', (e) => [e.getAttribute('aria-disabled'), e.hasAttribute('href')]), ['true', false], 'the UPI-app button is dead too');
  t.eq(successShown, [], 'the success view was never visible at any moment');
  const last = seen[seen.length - 1].txt; t.ok(last === '00:00' || last === '00:01', 'timer ended at ' + last);
  t.eq(page.problems.filter((p) => !/favicon/.test(p)), []);
});

await t.step('the SERVER agrees: order EXPIRED, payment expired, QR image gone, status API says expired (within 15 s)', async () => {
  for (let i = 0; i < 30 && payment(ref).oStatus !== 'EXPIRED'; i++) await sleep(500);
  t.eq(payment(ref).oStatus, 'EXPIRED'); t.eq(payment(ref).pStatus, 'expired');
  t.eq((await (await fetch(`${BASE}/api/pay/${ref}/status`)).json()).state, 'expired');
  const qr = await fetch(`${BASE}/pay/${ref}/qr.png`); t.ok([404, 410].includes(qr.status), 'qr.png → ' + qr.status);
  t.eq(Number(sql(`SELECT COUNT(*) FROM order_status_history WHERE order_id = ${payment(ref).id} AND to_status = 'EXPIRED'`)), 1, 'expired exactly once');
});

const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
