// Admin → Ads Intelligence, end to end in a real browser: every card and every campaign row checked against INDEPENDENT SQL over the raw tracking, spend and order
// rows (the demo history carries three campaigns, actual and estimated spend, and credited orders), the filters, entering / importing / voiding spend, the
// attribution window and attributing older orders, what each ROLE sees, and a phone.
// Local dev only (resets the dev database). Run:  node tools/qa/admin-ads.mjs
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, ROOT, sql, rows, resetLimits } from './site.mjs';
import { layoutReport, coveredControls, login } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-ads');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
resetLimits();

// ── helpers ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const istToday = () => new Date(Date.now() + 5.5 * 3600e3).toISOString().slice(0, 10);
const addDays = (d, n) => new Date(Date.parse(d + 'T00:00:00Z') + n * 86400e3).toISOString().slice(0, 10);
const cents = (s) => (/\d/.test(String(s)) ? Math.round(parseFloat(String(s).replace(/[^0-9.\-]/g, '')) * 100) : null);
const ints = (s) => Number(String(s).replace(/[^0-9]/g, ''));
const n = (q) => Number(sql(q));
const nSigned = (q) => Number(sql("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_UNSIGNED_SUBTRACTION'); " + q));
const TODAY = istToday();
const NONE = '(no campaign)';

/** What the raw rows say for a period and one campaign key ('' = the whole shop) — restated here in SQL, independent of the PHP that serves the page. */
function expected(from, to, key = '') {
  const camp = (col) => (key === '' ? '1 = 1' : key === NONE ? `${col} IS NULL` : `${col} = '${key}'`);
  const noActual = (day) => `${day} NOT IN (SELECT spend_day FROM ad_daily_spend WHERE voided_at IS NULL)`;
  const vd = `FROM visitor_days vd WHERE vd.day BETWEEN '${from}' AND '${to}' AND ${camp('vd.utm_campaign')}`;
  const ss = `FROM visitor_sessions s WHERE s.day BETWEEN '${from}' AND '${to}' AND ${camp('s.utm_campaign')}`;
  const at = `FROM campaign_attribution ca JOIN orders o ON o.id = ca.order_id WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN '${from}' AND '${to}' AND ${camp('ca.utm_campaign')}`;
  const actualWhere = key === '' ? '1 = 1' : key === NONE ? '1 = 0' : `campaign = '${key}'`;
  const actual = n(`SELECT COALESCE(SUM(amount_paise + gst_paise), 0) FROM ad_daily_spend WHERE voided_at IS NULL AND spend_day BETWEEN '${from}' AND '${to}' AND ${actualWhere}`);
  const est = n(`SELECT COALESCE(SUM(vd.cpc_paise), 0) ${vd} AND vd.qualified = 1 AND ${noActual('vd.day')}`);
  const customers = n(`SELECT COUNT(DISTINCT COALESCE(CONCAT('v', o.visitor_id), CONCAT('o', o.id))) ${at}`);
  const revenue = nSigned(`SELECT COALESCE(SUM(o.amount_paise - o.refunded_paise), 0) ${at}`);
  const refunds = n(`SELECT COALESCE(SUM(o.refunded_paise), 0) ${at}`);
  const costs = n(`SELECT COALESCE(SUM((CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END) + COALESCE((SELECT SUM(lc.amount_paise) FROM order_costs lc WHERE lc.order_id = o.id AND lc.superseded_at IS NULL), 0)), 0) ${at}`);
  const qualified = n(`SELECT COALESCE(SUM(vd.qualified), 0) ${vd}`);
  const spend = actual + est;
  return {
    visitors: n(`SELECT COUNT(*) ${vd}`), qualified, checkouts: n(`SELECT COALESCE(SUM(vd.order_created), 0) ${vd}`),
    sessions: n(`SELECT COUNT(*) ${ss}`), engaged: n(`SELECT COALESCE(SUM(s.engaged_at IS NOT NULL), 0) ${ss}`), pageViews: n(`SELECT COALESCE(SUM(s.page_views), 0) ${ss}`),
    returning: n(`SELECT COALESCE(SUM(s.returning_visitor), 0) ${ss}`),
    orders: n(`SELECT COUNT(*) ${at}`), customers, revenue, refunds, costs, spend, actual, est,
    conversion: qualified > 0 ? Math.floor((customers * 1000) / qualified + 0.5) : null,
    costPerCustomer: customers > 0 && spend > 0 ? Math.floor(spend / customers + 0.5) : null,
    contribution: revenue - costs - spend,
  };
}

const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [403, 409, 422, 429] });
t.page = page;
await login(page);
const toastLast = () => page.locator('.toast').last();
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((x) => x.remove()));
const closeDialogs = async () => { for (let i = 0; i < 3; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeDialogs(); await clearToasts(); await fn(); });
const settle = async (p = page) => { await p.waitForFunction(() => document.querySelector('#ads-root')?.getAttribute('aria-busy') === 'false'); await p.waitForTimeout(300); };
const open = async (q = '', p = page) => { await p.goto(BASE + '/admin/ads' + q, { waitUntil: 'networkidle' }); await settle(p); };
const tab = async (name, p = page) => { await p.click(`#ads-tab-${name}`); await settle(p); };
const dlg = (p = page) => p.locator('dialog.dlg-modal[open]');
const submit = (p = page) => dlg(p).locator('[data-act="yes"]').click();
const fieldErr = (name, p = page) => dlg(p).locator(`[data-err="${name}"]`);
const successToast = async (part) => { await page.waitForSelector('.toast[data-type="success"]'); if (part) t.has(await t.text(toastLast()), part); };
const kpi = async (key, p = page) => ({ value: (await p.locator(`.kpi[data-kpi="${key}"] .kpi-value`).innerText()).replace(/\s+/g, ' ').trim(), sub: (await p.locator(`.kpi[data-kpi="${key}"] .kpi-sub`).innerText()).replace(/\s+/g, ' ').trim() });
const campaignRow = (key, p = page) => p.locator(`#ads-campaigns tbody tr[data-campaign="${key}"]`);
const rowCells = async (key, p = page) => campaignRow(key, p).locator('td').evaluateAll((tds) => tds.map((td) => td.innerText.replace(/\s+/g, ' ').trim()));

// ───────────────────────────── the page and its numbers ─────────────────────────────
await t.step('the page: four tabs, the filters, twelve cards with their source and confidence labels, the notes, two charts', async () => {
  await open();
  const tabs = (await page.locator('#ads-tabs [data-tab]').allInnerTexts()).map((x) => x.replace(/\s+/g, ' ').trim());
  t.ok(['Overview', 'Campaigns', 'Spend', 'Settings'].every((l, i) => tabs[i].startsWith(l)), JSON.stringify(tabs));
  t.eq(await page.locator('#ads-range [aria-pressed="true"]').innerText(), '30 days');
  t.eq((await page.locator('.kpi .kpi-label').allTextContents()).map((x) => x.trim()),
    ['Spend today', 'Spend lifetime', 'Spend in period', 'Visits', 'Qualified visitors', 'Checkout starts', 'Verified customers', 'Conversion rate', 'Cost per customer', 'Attributed revenue', 'Contribution after ads', 'Source & confidence']);
  for (const k of ['spend-today', 'spend-life', 'spend-period']) t.ok(/actual|CPC estimate|no spend/.test((await page.locator(`.kpi[data-kpi="${k}"] .chip`).innerText())), 'a source label on ' + k);
  t.ok(await page.locator('.kpi[data-kpi="cpcust"] .chip').isVisible() && await page.locator('.kpi[data-kpi="contribution"] .chip').isVisible(), 'a confidence label on the ratios');
  const notes = await t.text(page.locator('#ads-root .callout-info'));
  t.has(notes, 'not incremental revenue'); t.has(notes, 'last non-direct touch');
  t.eq(await page.locator('[data-chart] svg').count() >= 2, true, 'two charts');
  t.eq(await page.locator('.legend i[data-c]').count(), 4);
  t.has(await t.text(page.locator('details.fold summary')), 'How these numbers are defined');
  t.ok(!/last_non_direct/.test(await page.locator('#ads-root').innerText()), 'no raw model key on the screen');
});

await t.step('every card is what the raw rows say — independently computed in SQL — for the default period, today, and lifetime', async () => {
  const from = addDays(TODAY, -29);
  const e = expected(from, TODAY);
  t.eq(ints((await kpi('qualified')).value), e.qualified, 'qualified visitors');
  t.has((await kpi('qualified')).sub, `of ${new Intl.NumberFormat('en-IN').format(e.visitors)} visitors`);
  t.eq(ints((await kpi('checkouts')).value), e.checkouts, 'checkout starts');
  t.eq(ints((await kpi('customers')).value), e.customers, 'verified customers');
  t.has((await kpi('customers')).sub, `${new Intl.NumberFormat('en-IN').format(e.orders)} verified order`);
  t.eq(ints((await kpi('visits')).value), e.sessions, 'visits (sessions)');
  t.has((await kpi('visits')).sub, `${new Intl.NumberFormat('en-IN').format(e.engaged)} engaged`);
  t.eq(cents((await kpi('spend-period')).value), e.spend, 'spend in the period (actual where entered, the CPC estimate elsewhere)');
  t.eq(cents((await kpi('revenue')).value), e.revenue, 'attributed revenue, refund-adjusted');
  t.eq(cents((await kpi('contribution')).value), e.contribution, 'contribution after ads');
  t.eq((await kpi('conversion')).value, `${(e.conversion / 10).toFixed(1)}%`, 'conversion = customers ÷ qualified visitors');
  t.eq(cents((await kpi('cpcust')).value), e.costPerCustomer, 'cost per customer = advertising ÷ customers');
  const today = expected(TODAY, TODAY);
  t.eq(cents((await kpi('spend-today')).value), today.spend, 'spend today');
  const life = expected(sql('SELECT MIN(day) FROM visitor_days'), TODAY);
  t.eq(cents((await kpi('spend-life')).value), life.spend, 'spend lifetime');
  t.ok(e.actual > 0 && e.est > 0, 'the demo history has both kinds of spend: actual ' + e.actual + ' / estimated ' + e.est);
  t.has(await t.text(page.locator('.kpi[data-kpi="spend-period"] .chip')), 'actual + estimate');
});

await t.step('the campaign table: every row is its own raw rows; the rows add up to the cards (visitors, qualified, spend, orders) — never double counted', async () => {
  await tab('campaigns');
  const from = addDays(TODAY, -29);
  const keys = await page.locator('#ads-campaigns tbody tr').evaluateAll((trs) => trs.map((tr) => tr.dataset.campaign));
  t.ok(keys.includes(NONE) && keys.includes('diwali-reels') && keys.includes('yt-shorts-push') && keys.includes('creator-collab'), JSON.stringify(keys));
  let sums = { spend: 0, qualified: 0, visitors: 0, orders: 0, revenue: 0, checkouts: 0 };
  for (const key of keys) {
    const e = expected(from, TODAY, key);
    const c = await rowCells(key);                                            // Campaign · Spend · Qualified · Checkouts · Customers · Conversion · Cost/customer · Revenue · After ads
    t.eq(cents(c[1].split(' ')[0]), e.spend, `${key}: spend`);
    t.eq(ints(c[2].split(' ')[0]), e.qualified, `${key}: qualified`);
    t.has(c[2], `of ${new Intl.NumberFormat('en-IN').format(e.visitors)}`);
    t.eq(ints(c[3]), e.checkouts, `${key}: checkouts`);
    t.eq(ints(c[4].split(' ')[0]), e.customers, `${key}: customers`);
    t.eq(c[5], e.conversion === null ? '—' : `${(e.conversion / 10).toFixed(1)}%`, `${key}: conversion`);
    t.eq(c[6], e.costPerCustomer === null ? '—' : c[6], `${key}: cost per customer`);
    if (e.costPerCustomer !== null) t.eq(cents(c[6]), e.costPerCustomer, `${key}: cost per customer value`);
    t.eq(cents(c[7].split(' ')[0]), e.revenue, `${key}: attributed revenue`);
    t.eq(cents(c[8].split(' ')[0].replace('−', '-')), e.contribution, `${key}: after ads`);
    sums = { spend: sums.spend + e.spend, qualified: sums.qualified + e.qualified, visitors: sums.visitors + e.visitors, orders: sums.orders + e.orders, revenue: sums.revenue + e.revenue, checkouts: sums.checkouts + e.checkouts };
  }
  const all = expected(from, TODAY);
  t.eq(sums, { spend: all.spend, qualified: all.qualified, visitors: all.visitors, orders: all.orders, revenue: all.revenue, checkouts: all.checkouts }, 'the rows add up to the whole shop');
  // …and to what the dashboard says about the same days
  const dash = await (await page.request.get(BASE + `/admin/api/dashboard?range=custom&from=${from}&to=${TODAY}`)).json();
  t.eq([dash.dashboard.totals.visitors, dash.dashboard.totals.qualified_visitors, dash.dashboard.totals.ads_spend_paise], [all.visitors, all.qualified, all.spend], 'the dashboard agrees — visitors, qualified visitors and advertising cost');
  // the order costs
  t.eq(all.costs, n(`SELECT COALESCE(SUM((CASE WHEN o.mode = 'AUTO' THEN o.provider_cost_paise ELSE 0 END) + COALESCE((SELECT SUM(lc.amount_paise) FROM order_costs lc WHERE lc.order_id = o.id AND lc.superseded_at IS NULL), 0)), 0) FROM campaign_attribution ca JOIN orders o ON o.id = ca.order_id WHERE o.paid_at IS NOT NULL AND o.paid_day BETWEEN '${from}' AND '${TODAY}'`));
  t.has(await t.text(page.locator('#ads-root')), 'not incremental revenue');
});

await t.step('ranges and the campaign filter: Today, 7 days, Custom, one campaign — the cards follow, so does the URL, and a reload keeps the view', async () => {
  await tab('overview');
  await page.click('#ads-range [data-range="today"]'); await settle();
  t.has(page.url(), 'range=today');
  const e = expected(TODAY, TODAY);
  t.eq([ints((await kpi('qualified')).value), cents((await kpi('spend-period')).value)], [e.qualified, e.spend]);
  await page.click('#ads-range [data-range="7d"]'); await settle();
  const e7 = expected(addDays(TODAY, -6), TODAY);
  t.eq([ints((await kpi('qualified')).value), ints((await kpi('customers')).value), cents((await kpi('revenue')).value)], [e7.qualified, e7.customers, e7.revenue]);
  await page.selectOption('#ads-campaign', 'diwali-reels'); await settle();
  const ec = expected(addDays(TODAY, -6), TODAY, 'diwali-reels');
  t.has(page.url(), 'campaign=diwali-reels');
  t.eq([ints((await kpi('qualified')).value), ints((await kpi('customers')).value), cents((await kpi('spend-period')).value), cents((await kpi('contribution')).value)], [ec.qualified, ec.customers, ec.spend, ec.contribution], 'the cards are that campaign');
  t.has((await kpi('spend-today')).sub, '“diwali-reels”');
  t.eq(cents((await kpi('spend-today')).value), expected(TODAY, TODAY, 'diwali-reels').spend, 'today\'s spend follows the filter');
  t.ok(await page.locator('#ads-reset').isVisible());
  await page.reload({ waitUntil: 'networkidle' }); await settle();
  t.eq([await page.locator('#ads-campaign').inputValue(), await page.locator('#ads-range [aria-pressed="true"]').innerText()], ['diwali-reels', '7 days'], 'a reload keeps the view');
  await page.click('#ads-reset'); await settle();
  t.eq(await page.locator('#ads-campaign').inputValue(), '');
  await page.click('#ads-range [data-range="custom"]');
  await page.fill('#ads-from', addDays(TODAY, -2)); await page.fill('#ads-to', TODAY); await page.click('#ads-apply'); await settle();
  t.eq(ints((await kpi('qualified')).value), expected(addDays(TODAY, -2), TODAY).qualified, 'a custom range');
  // "Details" on the Campaigns tab opens that campaign on the Overview
  await page.click('#ads-range [data-range="30d"]'); await settle();
  await tab('campaigns');
  await campaignRow('yt-shorts-push').locator('[data-focus]').click(); await settle();
  t.eq([await page.locator('#ads-tab-overview').getAttribute('aria-selected'), await page.locator('#ads-campaign').inputValue()], ['true', 'yt-shorts-push']);
  await page.click('#ads-reset'); await settle();
});

// ───────────────────────────── spend ─────────────────────────────
await t.step('Spend: validation in place, an entry replaces the CPC estimate of its day (never added), the same key replaces the entry, and it is audited', async () => {
  const day = addDays(TODAY, -40);                                                // a day of the demo history with no actual spend yet
  t.eq(n(`SELECT COUNT(*) FROM ad_daily_spend WHERE spend_day = '${day}'`), 0);
  const estimateOfDay = n(`SELECT COALESCE(SUM(cpc_paise), 0) FROM visitor_days WHERE qualified = 1 AND day = '${day}'`);
  t.ok(estimateOfDay > 0, 'that day carries a CPC estimate');
  const dashDay = async () => (await (await page.request.get(BASE + `/admin/api/dashboard?range=custom&from=${day}&to=${day}`)).json()).dashboard.totals.ads_spend_paise;
  t.eq(await dashDay(), estimateOfDay, 'the dashboard shows the estimate');
  await tab('spend');
  t.eq(await page.locator('#ads-spend tbody tr').count(), 25, 'a page of 25 entries');
  t.eq(await page.locator('#ads-add').isVisible(), true);
  await page.click('#ads-add');
  await submit();                                                                  // the date is pre-filled; the amount is missing
  t.has(await t.text(fieldErr('amount')), 'required');
  await dlg().locator('[name="amount"]').fill('abc'); await dlg().locator('[name="day"]').fill(addDays(TODAY, 3));
  await submit(); await fieldErr('amount').waitFor();
  t.has(await t.text(fieldErr('day')), 'not in the future');
  t.has(await t.text(fieldErr('amount')), 'two decimals');
  await dlg().locator('[name="day"]').fill(day); await dlg().locator('[name="amount"]').fill('10'); await dlg().locator('[name="clicks"]').fill('-4');
  await submit(); await fieldErr('clicks').waitFor();
  t.eq(n('SELECT COUNT(*) FROM ad_daily_spend WHERE campaign = \'qa-camp\''), 0, 'nothing was saved by the refused attempts');
  await dlg().locator('[name="clicks"]').fill('5'); await dlg().locator('[name="campaign"]').fill('QA Camp!'); await dlg().locator('[name="platform"]').fill('Meta'); await dlg().locator('[name="gst"]').fill('1.80');
  await submit(); await successToast('Spend saved');
  await page.waitForFunction(() => document.querySelector('#ads-spend'));
  t.eq(rows(`SELECT spend_day, campaign, platform, amount_paise, gst_paise, clicks, source FROM ad_daily_spend WHERE spend_day = '${day}'`), [[day, 'qa camp', 'meta', '1000', '180', '5', 'manual']], 'cleaned to lower-case words, rupees to paise');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.spend_added'"), 1);
  t.eq(await dashDay(), 1180, 'the day is now ACTUAL: ₹10 + ₹1.80 GST — the estimate is gone, not added');
  // the same day + campaign + platform replaces the entry
  await clearToasts();
  await page.click('#ads-add');
  await dlg().locator('[name="day"]').fill(day); await dlg().locator('[name="campaign"]').fill('qa camp'); await dlg().locator('[name="platform"]').fill('meta'); await dlg().locator('[name="amount"]').fill('12');
  await submit(); await successToast();
  t.eq(rows(`SELECT amount_paise, gst_paise FROM ad_daily_spend WHERE spend_day = '${day}'`), [['1200', '0']], 'replaced, not duplicated');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.spend_changed'"), 1, 'the change is audited (the old value with it)');
  t.eq(await dashDay(), 1200);
});

await t.step('Spend: a pasted report is checked line by line, previewed, then imported all-or-nothing; a wrong entry is voided with a reason and the estimate returns', async () => {
  const d1 = addDays(TODAY, -41); const d2 = addDays(TODAY, -42); const dq = addDays(TODAY, -40);
  await page.click('#ads-import');
  await dlg().locator('[name="csv"]').fill(`${d1},diwali-reels,meta,100.50,18.09,230,9000\nnot-a-date,x,y,5\n${d2},diwali-reels,meta,abc`);
  await submit(); await fieldErr('csv').waitFor();
  const err = await t.text(fieldErr('csv'));
  t.has(err, 'Line 2'); t.has(err, 'Line 3');
  t.eq(n(`SELECT COUNT(*) FROM ad_daily_spend WHERE spend_day IN ('${d1}', '${d2}')`), 0, 'one bad line rejects the whole paste');
  await dlg().locator('[name="csv"]').fill(`date,campaign,platform,spend,gst,clicks,impressions\n${d1},diwali-reels,meta,100.50,18.09,230,9000\n${d2};yt-shorts-push;google;40;;;\n${dq},qa camp,meta,15,,,`);
  await submit();                                                                  // the check
  await page.waitForFunction(() => document.querySelectorAll('dialog[open]').length === 1 && /This will save 3 lines/.test(document.querySelector('dialog[open]')?.innerText || ''), null, { timeout: 8000 });
  t.has(await t.text(dlg()), '2 new, 1 replacing an earlier entry'); t.has(await t.text(dlg()), '₹');
  t.eq(n(`SELECT COUNT(*) FROM ad_daily_spend WHERE spend_day IN ('${d1}', '${d2}')`), 0, 'the check saved nothing');
  await dlg().locator('[data-act="yes"]').click(); await successToast('Imported');
  t.eq(rows(`SELECT spend_day, campaign, platform, amount_paise, gst_paise, source FROM ad_daily_spend WHERE spend_day IN ('${d1}', '${d2}') ORDER BY spend_day DESC`),
    [[d1, 'diwali-reels', 'meta', '10050', '1809', 'import'], [d2, 'yt-shorts-push', 'google', '4000', '0', 'import']]);
  t.eq(n(`SELECT amount_paise FROM ad_daily_spend WHERE spend_day = '${dq}'`), 1500, 'the line for an existing key replaced it');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.spend_imported'"), 1);
  // void with a reason
  await page.waitForFunction(() => document.querySelector('#ads-spend'));
  await page.click('[data-scamp="qa camp"]'); await settle();                      // the entry is 40 days old: it is not on the first page of the newest entries
  const row = page.locator('#ads-spend tbody tr', { hasText: 'qa camp' }).first();
  await clearToasts();
  await row.locator('[data-void-spend]').click();
  await submit(); t.has(await t.text(fieldErr('reason')), 'required');
  await dlg().locator('[name="reason"]').fill('Wrong platform'); await submit(); await successToast('voided');
  t.eq(rows(`SELECT voided_at IS NOT NULL FROM ad_daily_spend WHERE campaign = 'qa camp'`), [['1']], 'the row is kept');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.spend_voided'"), 1);
  await page.check('#as-voided'); await settle();
  t.eq(await page.locator('#ads-spend tbody tr.is-void').count(), 1, 'shown when asked for'); t.has(await t.text(page.locator('#ads-spend tbody tr.is-void')), 'voided');
  const estimate = n(`SELECT COALESCE(SUM(cpc_paise), 0) FROM visitor_days WHERE qualified = 1 AND day = '${dq}'`);
  const dashDay = (await (await page.request.get(BASE + `/admin/api/dashboard?range=custom&from=${dq}&to=${dq}`)).json()).dashboard.totals.ads_spend_paise;
  t.eq(dashDay, estimate, 'with its only entry voided, the day is on the CPC estimate again');
  await page.click('[data-scamp="diwali-reels"]'); await settle();
  t.ok(await page.locator('#ads-spend tbody tr').count() >= 1 && (await page.locator('#ads-spend tbody tr td:nth-child(2)').allInnerTexts()).every((x) => /diwali-reels/.test(x)), 'the campaign chip filters');
});

// ───────────────────────────── settings ─────────────────────────────
await t.step('Settings: the attribution window is validated in place, saved and audited; older orders are attributed after a dry run — from the same server-side data', async () => {
  await tab('settings');
  t.eq(await page.locator('#as-window').inputValue(), '7');
  for (const bad of ['0', '91', 'x', '7.5']) {
    await page.fill('#as-window', bad); await page.click('#as-save');
    await page.waitForSelector('#ads-settings [data-err="window_days"]');
    t.eq(sql("SELECT COUNT(*) FROM admin_settings WHERE k = 'ads.attribution_window_days'"), '0', `${bad} was refused and nothing stored`);
    await page.fill('#as-window', ''); await page.evaluate(() => document.querySelectorAll('[data-err]').forEach((x) => x.remove()));
  }
  await page.fill('#as-window', '14'); await clearToasts(); await page.click('#as-save'); await successToast('Settings saved');
  t.eq(sql("SELECT v FROM admin_settings WHERE k = 'ads.attribution_window_days'"), '14');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.attribution_changed'"), 1);
  t.has(await t.text(page.locator('#ads-root')), 'Every verified order has been credited');
  // three orders lose their attribution row (the process died after the payment), the page says so, and fixes them
  sql("DELETE FROM campaign_attribution WHERE order_id IN (SELECT id FROM (SELECT order_id AS id FROM campaign_attribution ORDER BY order_id DESC LIMIT 3) x)");
  await open('?tab=settings');
  t.eq(await page.locator('#ads-missing-count').innerText(), '3', 'the tab says how many');
  t.eq(await page.locator('[data-missing]').innerText(), '3');
  await clearToasts();
  await page.click('[data-backfill]'); await dlg().waitFor();
  t.has(await t.text(dlg()), 'Would attribute 3 verified orders');
  t.eq(n('SELECT COUNT(*) FROM campaign_attribution'), n('SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL') - 3, 'the dry run changed nothing');
  await dlg().locator('[data-act="yes"]').click(); await successToast('Attributed 3');
  t.eq(n('SELECT COUNT(*) FROM campaign_attribution'), n('SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL'));
  t.eq(n("SELECT COUNT(*) FROM campaign_attribution WHERE window_days = 14"), 3, 'each row says which window credited it');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'ads.attribution_backfilled'"), 1);
  await page.waitForSelector('[data-missing-none]');
  t.eq(await page.locator('#ads-missing-count').isVisible(), false);
  // the Ads page names an unattributed order instead of hiding it
  sql("DELETE FROM campaign_attribution WHERE order_id = (SELECT MAX(order_id) FROM (SELECT order_id FROM campaign_attribution) x)");
  await open('?range=lifetime');
  t.has(await t.text(page.locator('#ads-root .callout-info')), 'not credited to any campaign');
  t.ok(await page.locator('#ads-root .callout-info [data-goto="settings"]').isVisible(), 'and offers the way to fix it');
  await clearToasts();
  await page.click('#ads-root .callout-info [data-goto="settings"]'); await settle();
  await page.click('[data-backfill]'); await dlg().waitFor(); await dlg().locator('[data-act="yes"]').click(); await successToast('Attributed 1');
});

// ───────────────────────────── roles ─────────────────────────────
const QA = { user: 'qa-admin', pass: 'Qa-Admin#Test-2026x' };
async function asRole(role, fn) {
  sql(`UPDATE admins SET role = '${role}' WHERE username = '${QA.user}'`);
  sql(`DELETE FROM sessions WHERE admin_id = (SELECT id FROM admins WHERE username = '${QA.user}')`);
  resetLimits();
  const p = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [401, 403, 404, 409, 422, 429] });
  try {
    await p.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await p.fill('#username', QA.user); await p.fill('#password', QA.pass);
    await Promise.all([p.waitForURL('**/admin/dashboard', { timeout: 15000 }), p.click('#login-submit')]);
    await fn(p);
  } finally {
    await p.context().close();
    sql(`UPDATE admins SET role = 'owner' WHERE username = '${QA.user}'`);
  }
}
const send = async (p, method, path, body) => {
  const csrf = await p.locator('meta[name=csrf-token]').getAttribute('content');
  const r = await p.request.fetch(BASE + path, { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': csrf, Origin: BASE }, data: body });
  return { status: r.status(), body: await r.json().catch(() => ({})) };
};
const code = (r) => r.body.error?.code ?? r.body.code;
const navLinks = (p) => p.locator('.adm-side .adm-link').allInnerTexts();
const snapshot = () => [n('SELECT COUNT(*) FROM ad_daily_spend'), sql("SELECT COALESCE(MAX(v), '') FROM admin_settings WHERE k = 'ads.attribution_window_days'"), n('SELECT COUNT(*) FROM campaign_attribution'), n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'ads.%'")];
const writes = (day) => [
  ['POST', '/admin/api/ads/spend', { day, campaign: 'role-test', amount: '5' }],
  ['POST', '/admin/api/ads/spend/import', { csv: `${day},role-test,meta,5` }],
  ['POST', '/admin/api/ads/spend/1/void', { reason: 'role test' }],
  ['PUT', '/admin/api/ads/settings', { window_days: 30 }],
  ['POST', '/admin/api/ads/attribution/backfill', { dry_run: false }],
];

await t.step('role SUPPORT: no Ads page, no menu entry, no advertising data — every read and write is refused', async () => {
  await asRole('support', async (p) => {
    const before = snapshot();
    const res = await p.goto(BASE + '/admin/ads', { waitUntil: 'networkidle' });
    t.eq(res.status(), 403, 'the page is refused');
    await p.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
    t.ok(!(await navLinks(p)).some((x) => /Ads Intelligence/.test(x)), 'the menu does not offer it');
    for (const path of ['/admin/api/ads?range=30d', '/admin/api/ads/spend', '/admin/api/ads/settings']) { const r = await send(p, 'GET', path); t.eq([r.status, code(r)], [403, 'forbidden'], path); }
    for (const [method, path, body] of writes(TODAY)) { const r = await send(p, method, path, body); t.eq([r.status, code(r)], [403, 'forbidden'], `${method} ${path}`); }
    t.eq(snapshot(), before, 'nothing changed');
  });
});

await t.step('role ANALYST: reads every tab — read-only: no add / import / void / save / attribute control — and the server refuses every write', async () => {
  await asRole('analyst', async (p) => {
    const before = snapshot();
    await open('', p);
    t.ok((await navLinks(p)).some((x) => /Ads Intelligence/.test(x)), 'the menu offers it');
    t.ok(await p.locator('.kpi').count() >= 12, 'the figures are readable');
    await tab('campaigns', p);
    t.ok(await p.locator('#ads-campaigns tbody tr').count() >= 3);
    await tab('spend', p);
    t.eq([await p.locator('#ads-add').isVisible(), await p.locator('#ads-import').isVisible(), await p.locator('[data-void-spend]').count()], [false, false, 0]);
    await tab('settings', p);
    t.eq(await p.locator('#ads-settings input:not([disabled])').count(), 0, 'the window is read-only');
    t.has(await t.text(p.locator('#ads-root')), 'Only an owner or manager can change this');
    t.eq(await p.locator('[data-backfill]').count(), 0);
    for (const [method, path, body] of writes(TODAY)) { const r = await send(p, method, path, body); t.eq([r.status, code(r)], [403, 'forbidden'], `${method} ${path}`); }
    t.eq(snapshot(), before, 'nothing changed');
  });
});

await t.step('role MANAGER: enters, imports and voids spend, changes the window and attributes orders — as the owner does', async () => {
  await asRole('manager', async (p) => {
    await open('?tab=spend', p);
    t.eq([await p.locator('#ads-add').isVisible(), await p.locator('#ads-import').isVisible()], [true, true]);
    const a = await send(p, 'POST', '/admin/api/ads/spend', { day: addDays(TODAY, -50), campaign: 'manager-test', platform: 'meta', amount: '9' });
    t.eq([a.status, a.body.entry?.amount_paise], [200, 900]);
    const v = await send(p, 'POST', `/admin/api/ads/spend/${a.body.entry.id}/void`, { reason: 'manager test' });
    t.eq([v.status, v.body.entry?.voided], [200, true]);
    t.eq((await send(p, 'PUT', '/admin/api/ads/settings', { window_days: 7 })).status, 200);
    const b = await send(p, 'POST', '/admin/api/ads/attribution/backfill', { dry_run: true });
    t.eq([b.status, b.body.result?.dry_run], [200, true]);
    await tab('settings', p);
    t.eq(await p.locator('#ads-settings input:not([disabled])').count(), 1, 'the window is editable');
  });
});

// ───────────────────────────── a phone ─────────────────────────────
await t.step('on a phone: two columns of cards, tables become stacked cards, nothing overflows, every control can be reached', async () => {
  const phone = await newPage(browser, { width: 390, height: 844 }, { mobile: true, dpr: 2, allowStatus: [403, 409, 422, 429] });
  try {
    await login(phone);
    await open('', phone);
    const cols = await phone.evaluate(() => new Set([...document.querySelectorAll('.kpi-grid .kpi')].map((k) => Math.round(k.getBoundingClientRect().left))).size);
    t.eq(cols, 2, 'two cards per row');
    for (const name of ['overview', 'campaigns', 'spend', 'settings']) {
      await phone.click(`#ads-tab-${name}`); await settle(phone);
      const r = await layoutReport(phone);
      t.eq([name, r.overflowX, r.wide], [name, false, []], 'no horizontal overflow');
      t.eq(await coveredControls(phone), [], `${name}: nothing covers a control`);
    }
    await phone.click('#ads-tab-campaigns'); await settle(phone);
    t.ok(!(await phone.locator('#ads-campaigns thead').isVisible()), 'the table header gives way to labelled cells');
    t.ok(await phone.locator('#ads-campaigns tbody tr td[data-label="Attributed revenue"]').first().isVisible());
  } finally { await phone.context().close(); }
});

await t.step('no browser problems on the Ads pages (console errors, CSP violations, failed requests)', async () => {
  t.eq(page.problems.filter((p) => !/\b(403|409|422|429)\b/.test(p)), [], 'problems');
});

await browser.close();
process.exit(t.finish() ? 1 : 0);
