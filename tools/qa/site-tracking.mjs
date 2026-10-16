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

await t.step('Ads Intelligence tracking: all five campaign tags, page views, another campaign starts a new session, a service card + the qualifying time = an engaged session — billing unchanged', async () => {
  resetLimits();
  const d0 = dayRows();
  const { ctx, page } = await newVisitor(); t.page = page;
  const sess = () => rows("SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term, page_views, service_views, engaged_at IS NOT NULL, qualified_at IS NOT NULL, returning_visitor FROM visitor_sessions WHERE visitor_id = (SELECT MAX(id) FROM visitors) ORDER BY id");
  const vday = () => sql(`SELECT COALESCE(utm_campaign, '-') FROM visitor_days WHERE visitor_id = (SELECT MAX(id) FROM visitors) AND day = '${day}'`);
  await page.goto(BASE + '/?utm_source=Meta&utm_medium=CPC&utm_campaign=Diwali%20Reels&utm_content=reel-a&utm_term=followers', { waitUntil: 'load' }); await sleep(1300);
  let s1 = sess();
  t.eq(s1.length, 1);
  t.eq(s1[0].slice(0, 5), ['meta', 'cpc', 'diwali reels', 'reel-a', 'followers'], 'every tag is kept, lower-cased and cleaned');
  t.eq([s1[0][5], s1[0][6], s1[0][7], s1[0][8]], ['1', '0', '0', '0'], 'one page view, no card, not engaged, not yet qualified');
  t.eq(vday(), 'diwali reels', 'the visitor-day is named after the campaign');
  await page.reload({ waitUntil: 'load' }); await sleep(900);
  t.eq(sess().length, 1, 'a reload of the tagged address stays in the session'); t.eq(sess()[0][5], '2', 'and is a second page view');
  // open a service card: the browser reports it; the qualifying time then makes the session engaged
  await page.locator('[data-service] [data-action=buy]').first().click();
  await sleep(3800);
  s1 = sess();
  t.eq([s1[0][6], s1[0][7], s1[0][8]], ['1', '1', '1'], 'one card opened, engaged, qualified');
  const d1 = dayRows(); t.eq([d1[1] - d0[1], d1[2] - d0[2]], [1, 108], 'one qualified visitor, ONE ₹1.08 charge — engagement bills nothing');
  // a click on ANOTHER campaign starts a new session; the day keeps the first campaign and is not charged again
  await page.goto(BASE + '/?utm_campaign=winter', { waitUntil: 'load' }); await sleep(1300);
  s1 = sess();
  t.eq(s1.map((r) => r[2]), ['diwali reels', 'winter'], 'two sessions, two campaigns');
  t.eq([s1[1][5], s1[1][7]], ['1', '0'], 'the new session starts at one page view and is not engaged');
  t.eq(s1[1][9], '0', 'the same visit is not a returning visitor');
  t.eq(vday(), 'diwali reels', 'nothing later renames the visitor-day');
  await sleep(3600);
  t.eq(dayRows()[2] - d0[2], 108, 'no second charge the same day');
  // a hostile tag is cleaned before it is stored
  const { ctx: c2, page: p2 } = await newVisitor();
  await p2.goto(BASE + '/?utm_campaign=%22%3E%3Cimg%20src%3Dx%20onerror%3Dalert(1)%3E&utm_content=' + encodeURIComponent('x'.repeat(200)), { waitUntil: 'load' }); await sleep(1300);
  const hostile = rows("SELECT utm_campaign, CHAR_LENGTH(utm_content) FROM visitor_sessions WHERE visitor_id = (SELECT MAX(id) FROM visitors)")[0];
  t.ok(/^[a-z0-9_.\- ]{1,80}$/.test(hostile[0]), 'campaign: ' + hostile[0]); t.eq(hostile[1], '80', 'content is cut at 80 characters');
  await c2.close(); await ctx.close();
});

await t.step('a visitor from a campaign who pays is credited to it at verification — once, from server-side data — and Ads Intelligence shows it', async () => {
  resetLimits();
  const { ctx, page } = await newVisitor(); t.page = page;
  await page.goto(BASE + '/?utm_source=meta&utm_medium=cpc&utm_campaign=spring-sale', { waitUntil: 'load' }); await sleep(1100);
  const { ref } = await placeOrder(page, 'ig-likes-100', 'https://www.instagram.com/p/AttributedBuyer1/');
  const oid = payment(ref).id;
  t.eq(Number(sql(`SELECT COUNT(*) FROM campaign_attribution WHERE order_id = ${oid}`)), 0, 'nothing is credited before the payment is verified');
  await payOk(ref); await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 9000 });
  t.eq(rows(`SELECT model, window_days, touch_kind, utm_source, utm_medium, utm_campaign FROM campaign_attribution WHERE order_id = ${oid}`)[0], ['last_non_direct', '7', 'campaign', 'meta', 'cpc', 'spring-sale']);
  // the browser cannot change it afterwards: a later visit through another campaign leaves the credited row alone
  await page.goto(BASE + '/?utm_campaign=someone-else', { waitUntil: 'load' }); await sleep(1100);
  t.eq(sql(`SELECT utm_campaign FROM campaign_attribution WHERE order_id = ${oid}`), 'spring-sale');
  const j = await adminGet('/admin/api/ads?range=today');
  const row = j.ads.campaigns.find((c) => c.key === 'spring-sale');
  t.ok(row, 'the campaign is on the Ads page'); t.eq([row.orders, row.customers, row.attributed_revenue], [1, 1, 500], 'one verified order, one customer, ₹5 of attributed revenue');
  await ctx.close();
});

await t.step('the admin dashboard reconciles to the penny with the raw tracking rows (visitors, qualified, ad spend, CPC)', async () => {
  const d = dayRows();
  const j = await adminGet('/admin/api/dashboard?range=today'); const k = j.dashboard.kpis.today;
  // advertising cost = the ACTUAL spend entered for a day, else the CPC estimate (qualified × CPC) — never both; restated here from the raw rows
  const spend = (from, to) => Number(sql(`SELECT COALESCE((SELECT SUM(amount_paise + gst_paise) FROM ad_daily_spend WHERE voided_at IS NULL AND spend_day BETWEEN '${from}' AND '${to}'), 0)`))
    + Number(sql(`SELECT COALESCE(SUM(cpc_paise), 0) FROM visitor_days WHERE qualified = 1 AND day BETWEEN '${from}' AND '${to}' AND day NOT IN (SELECT spend_day FROM ad_daily_spend WHERE voided_at IS NULL)`));
  t.eq(k.visitors.value, d[0], 'visitors today'); t.eq(k.qualified, d[1], 'qualified visitors today'); t.eq(k.ads.value, spend(day, day), 'ad spend today (paise)');
  t.eq(spend(day, day), d[2], 'with no actual spend entered for today, that is exactly the CPC charges');
  t.eq(j.dashboard.meta.cpc_paise, 108); t.eq(j.dashboard.meta.qualify_seconds, 3);
  const lifetime = rows("SELECT COUNT(*), COALESCE(SUM(qualified), 0) FROM visitor_days")[0].map(Number);
  t.eq(j.dashboard.kpis.lifetime.visitors.value, lifetime[0], 'lifetime visitors'); t.eq(j.dashboard.kpis.lifetime.ads.value, spend('2000-01-01', day), 'lifetime ad spend');
});

const failed = t.finish();
await adminPage.close(); await browser.close();
process.exit(failed ? 1 : 0);
