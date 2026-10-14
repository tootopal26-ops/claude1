// Visitor tracking & CPC accounting as a real browser experiences it (brief §visitor analytics): a visitor is counted ONCE per day however
// often they reload, becomes "qualified" only after the configured VISIBLE time (default 3 s), is charged ONE CPC (₹1.08 default, snapshot at
// that moment) per visitor-day, bots are never counted, and the admin dashboard reconciles to the penny with the raw rows.
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, ROOT, sql, rows, resetLimits, mocksReset, useMockProviderIds, placeOrder, payOk, payment, sleep, DESKTOP_UA } from './site.mjs';
import { login } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('site-tracking');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
useMockProviderIds(); resetLimits(); await mocksReset();
const browser = await launch();
const businessToday = async () => { const r = await adminGet('/admin/api/dashboard?range=today'); return r.dashboard.meta.today; };

let adminPage;
const adminGet = async (path) => (await adminPage.request.get(BASE + path)).json();
{ adminPage = await newPage(browser, { width: 1280, height: 800 }); await login(adminPage); }

const visitorCount = () => Number(sql('SELECT COUNT(*) FROM visitors'));
const day = await businessToday();
const dayRows = () => rows(`SELECT COUNT(*), COALESCE(SUM(qualified), 0), COALESCE(SUM(cpc_paise), 0) FROM visitor_days WHERE day = '${day}'`)[0].map(Number);
const newVisitor = async (ua = DESKTOP_UA) => { const ctx = await browser.newContext({ userAgent: ua, viewport: { width: 1280, height: 800 } }); const page = await ctx.newPage(); return { ctx, page }; };
const cookies = async (ctx) => Object.fromEntries((await ctx.cookies()).map((c) => [c.name, c]));
const beacon = (path, body, ua, cookie = '') => fetch(`${BASE}/api/track/${path}`, { method: 'POST', headers: { 'content-type': 'application/json', origin: BASE, 'user-agent': ua, ...(cookie ? { cookie } : {}) }, body: JSON.stringify(body) }).then((r) => r.json());

await t.step('bots, crawlers and headless browsers are never counted (no visitor, no cookie, no ad cost)', async () => {
  const before = visitorCount(); const d0 = dayRows();
  for (const ua of ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0 Safari/537.36', 'curl/8.5.0', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'facebookexternalhit/1.1', 'python-requests/2.31', 'Lighthouse', '']) {
    const r = await beacon('hit', { path: '/', first: true }, ua); t.eq(r.tracked, false, `${ua.slice(0, 40)} → ${JSON.stringify(r)}`);
  }
  t.eq(visitorCount(), before); t.eq(dayRows(), d0);
});

await t.step('a real visitor: counted on arrival, NOT yet qualified before the visible-time threshold', async () => {
  const before = visitorCount(); const d0 = dayRows();
  const { ctx, page } = await newVisitor(); t.page = page;
  await page.goto(BASE + '/', { waitUntil: 'load' });
  await page.waitForFunction(() => true); await sleep(1200);
  t.eq(visitorCount(), before + 1, 'one new visitor');
  const ck = await cookies(ctx); t.ok(ck.smb_v && ck.smb_s, 'visitor + session cookies: ' + Object.keys(ck));
  t.ok(ck.smb_v.httpOnly && ck.smb_v.sameSite === 'Lax', JSON.stringify(ck.smb_v));
  t.ok(ck.smb_v.expires > Date.now() / 1000 + 300 * 86400, 'visitor cookie lasts ~400 days');
  t.eq(ck.smb_s.expires, -1, 'session cookie is a session cookie');
  const d1 = dayRows(); t.eq(d1[0], d0[0] + 1, 'one visitor-day row'); t.eq(d1[1], d0[1], 'not qualified after 1.2 s'); t.eq(d1[2], d0[2], 'no CPC charged yet');
  const row = rows("SELECT browser, os, device_type, first_source FROM visitors ORDER BY id DESC LIMIT 1")[0]; t.ok(row[0] && row[1] && row[2], 'coarse device info only: ' + row);
  t.eq(Number(sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'smb_dev' AND TABLE_NAME IN ('visitors','visitor_sessions','visitor_days') AND COLUMN_NAME IN ('ip','ip_address','user_agent','fingerprint')")), 0, 'no IP address / raw user-agent / fingerprint column exists');
  // stay visible past 3 s → qualified, ONE charge of the current CPC
  await page.waitForFunction(() => true); await sleep(3200);
  const d2 = dayRows(); t.eq(d2[1], d1[1] + 1, 'qualified after 3 s of visible time'); t.eq(d2[2], d1[2] + 108, 'charged exactly ₹1.08 (108 paise)');
  // reloads, a second tab and a later visit the same day cost nothing more and do not create visitors
  for (let i = 0; i < 3; i++) { await page.reload({ waitUntil: 'load' }); await sleep(900); }
  const tab2 = await ctx.newPage(); await tab2.goto(BASE + '/#youtube', { waitUntil: 'load' }); await sleep(4200); await tab2.close();
  t.eq(visitorCount(), before + 1, 'refreshing never creates a visitor'); t.eq(dayRows(), d2, 'same day, same visitor: no second charge');
  t.eq(Number(sql("SELECT COUNT(*) FROM visitor_sessions WHERE visitor_id = (SELECT MAX(id) FROM visitors)")), 1, 'one session for the whole visit');
  await ctx.close();
});

await t.step('leaving before the threshold costs nothing; hidden tabs do not accumulate visible time', async () => {
  const d0 = dayRows();
  let { ctx, page } = await newVisitor(); await page.goto(BASE + '/', { waitUntil: 'load' }); await sleep(1000); await page.goto('about:blank'); await sleep(1500);
  t.eq(dayRows()[2], d0[2], 'bounced at 1 s: not charged'); await ctx.close();
  ({ ctx, page } = await newVisitor()); await page.goto(BASE + '/', { waitUntil: 'load' }); await sleep(500);
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); document.dispatchEvent(new Event('visibilitychange')); });
  await sleep(4500);
  t.eq(dayRows()[2], d0[2], '4.5 s in a HIDDEN tab: not qualified');
  await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable: true, get: () => false }); document.dispatchEvent(new Event('visibilitychange')); });
  await sleep(3800);
  t.eq(dayRows()[2], d0[2] + 108, 'qualified once it has really been visible for 3 s'); await ctx.close();
});

await t.step('the server double-checks the clock: a forged early "qualify" is refused; a replayed one is not charged twice', async () => {
  const d0 = dayRows();
  const hit = await fetch(`${BASE}/api/track/hit`, { method: 'POST', headers: { 'content-type': 'application/json', origin: BASE, 'user-agent': DESKTOP_UA }, body: JSON.stringify({ path: '/', first: true }) });
  const ck = (hit.headers.getSetCookie?.() || []).map((c) => c.split(';')[0]).join('; '); t.ok(/smb_v=/.test(ck));
  const early = await beacon('qualify', {}, DESKTOP_UA, ck); t.eq(early.qualified, false, JSON.stringify(early)); t.ok(early.retry_ms > 0);
  t.eq(dayRows()[2], d0[2], 'no charge for an instant qualify');
  await sleep(3300);
  const ok = await beacon('qualify', {}, DESKTOP_UA, ck); t.eq(ok.qualified, true); t.eq(ok.charged, true);
  const again = await beacon('qualify', {}, DESKTOP_UA, ck); t.eq(again.qualified, true); t.ok(!again.charged, 'replay is not charged again');
  t.eq(dayRows()[2], d0[2] + 108);
  const noCookie = await beacon('qualify', {}, DESKTOP_UA); t.eq(noCookie.qualified, false, 'no cookies → nothing to qualify');
  const forged = await beacon('qualify', {}, DESKTOP_UA, 'smb_v=' + 'a'.repeat(32) + '; smb_s=' + 'b'.repeat(32)); t.eq(forged.qualified, false, 'forged cookies are not a visitor');
});

await t.step('changing the CPC / qualify time applies to NEW visitors only; earlier charges keep the price they had (snapshot)', async () => {
  const before = rows("SELECT cpc_paise FROM visitor_days WHERE qualified = 1 AND day = '" + day + "' ORDER BY id DESC LIMIT 3").flat().map(Number);
  sql("INSERT INTO advertisement_settings (cpc_paise, qualify_seconds, assumed_conversion_bps, set_by, created_at) VALUES (250, 5, 300, 1, UTC_TIMESTAMP())");
  try {
    const d0 = dayRows();
    const { ctx, page } = await newVisitor(); await page.goto(BASE + '/', { waitUntil: 'load' });
    await sleep(3800); t.eq(dayRows()[2], d0[2], 'with a 5 s threshold, 3.8 s is not enough');
    await sleep(2000); t.eq(dayRows()[2], d0[2] + 250, 'qualifies after 5 s and is charged the NEW ₹2.50'); await ctx.close();
    t.eq(rows("SELECT cpc_paise FROM visitor_days WHERE qualified = 1 AND day = '" + day + "' AND cpc_paise <> 250 ORDER BY id DESC LIMIT 3").flat().map(Number), before, 'earlier visitors keep ₹1.08');
  } finally { sql("DELETE FROM advertisement_settings WHERE cpc_paise = 250 AND qualify_seconds = 5 AND assumed_conversion_bps = 300"); }
});

await t.step('a visitor who orders and pays is flagged on the visitor, the session and the day', async () => {
  resetLimits();
  const { ctx, page } = await newVisitor(); t.page = page;
  await page.goto(BASE + '/', { waitUntil: 'load' }); await sleep(900);
  const { ref } = await placeOrder(page, 'ig-likes-100', 'https://www.instagram.com/p/TrackedBuyer1/');
  const vid = sql("SELECT MAX(id) FROM visitors"); const oid = payment(ref).id;
  t.eq(sql(`SELECT visitor_id FROM orders WHERE id = ${oid}`), vid, 'the order is attributed to the visitor');
  t.eq(rows(`SELECT has_order, has_paid FROM visitors WHERE id = ${vid}`)[0], ['1', '0']);
  await payOk(ref); await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 });
  t.eq(rows(`SELECT has_order, has_paid FROM visitors WHERE id = ${vid}`)[0], ['1', '1']);
  t.eq(rows(`SELECT order_created, payment_succeeded FROM visitor_days WHERE visitor_id = ${vid} AND day = '${day}'`)[0], ['1', '1']);
  await ctx.close();
});

await t.step('the admin dashboard reconciles to the penny with the raw tracking rows (visitors, qualified, ad spend, CPC)', async () => {
  const d = dayRows();
  const j = await adminGet('/admin/api/dashboard?range=today'); const k = j.dashboard.kpis.today;
  t.eq(k.visitors.value, d[0], 'visitors today'); t.eq(k.qualified, d[1], 'qualified visitors today'); t.eq(k.ads.value, d[2], 'ad spend today (paise)');
  t.eq(j.dashboard.meta.cpc_paise, 108); t.eq(j.dashboard.meta.qualify_seconds, 3);
  const lifetime = rows("SELECT COUNT(*), COALESCE(SUM(qualified), 0), COALESCE(SUM(cpc_paise), 0) FROM visitor_days")[0].map(Number);
  t.eq(j.dashboard.kpis.lifetime.visitors.value, lifetime[0], 'lifetime visitors'); t.eq(j.dashboard.kpis.lifetime.ads.value, lifetime[2], 'lifetime ad spend');
});

const failed = t.finish();
await adminPage.close(); await browser.close();
process.exit(failed ? 1 : 0);
