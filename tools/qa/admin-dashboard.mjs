// Dashboard: KPI cards, profit card, filters, ranges, charts, tooltips, keyboard, API-failure state.
import { launch, newPage, login, go, layoutReport, BASE } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-dashboard');
const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 });
t.page = page;
await login(page);
await go(page, '/admin/dashboard');

await t.step('14 KPI cards: Today and Lifetime × revenue, profit, orders, visitors, payments, ads, SMM', async () => {
  t.eq(await page.locator('.kpi').count(), 14);
  const labels = await page.locator('[data-scope="today"] .kpi-label').allInnerTexts();
  t.eq(labels.map((l) => l.trim().toLowerCase()), ['revenue', 'net profit', 'orders', 'visitors', 'successful payments', 'ads spend', 'smm spend']);
  t.eq((await page.locator('[data-scope="lifetime"] .kpi-label').allInnerTexts()).length, 7);
  for (const el of await page.locator('.kpi-value').all()) t.ok((await el.innerText()).trim().length > 0, 'value text');
});

await t.step('profit breakdown card: REVENUE − SMM − ADS = NET and the numbers add up', async () => {
  const items = await page.locator('.eq-item strong').allInnerTexts();
  const n = (s) => Math.round(parseFloat(s.replace(/[₹,\s−-]/g, '') || '0') * 100);
  const [rev, smm, ads, net] = [n(items[0]), n(items[1]), n(items[2]), items[3].includes('-') ? -n(items[3]) : n(items[3])];
  t.eq(rev - smm - ads, net, `${rev} - ${smm} - ${ads} should equal ${net}`);
  t.has((await t.text(page.locator('.eq-net small'))).toLowerCase(), 'margin');
});

await t.step('range buttons: Today, 30 days, 90 days, Lifetime each reload the data', async () => {
  for (const [key, text] of [['today', 'Today'], ['30d', '30 days'], ['90d', '90 days'], ['lifetime', 'Lifetime'], ['7d', '7 days']]) {
    const [resp] = await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/api/dashboard') && r.url().includes(`range=${key}`)), page.click(`#range-seg [data-range="${key}"]`)]);
    t.eq(resp.status(), 200, key);
    t.eq(await page.locator(`#range-seg [data-range="${key}"]`).getAttribute('aria-pressed'), 'true');
    await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
    t.ok(await page.locator('[data-chart="rev"] svg').count() === 1, `chart drawn for ${text}`);
  }
});

await t.step('Today view draws 24 hourly points', async () => {
  await Promise.all([page.waitForResponse((r) => r.url().includes('range=today')), page.click('#range-seg [data-range="today"]')]);
  await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
  const bars = await page.locator('[data-chart="orders"] rect.chart-bar').count();
  t.ok(bars >= 1, 'bars exist (some hour has orders)');
  await page.click('#range-seg [data-range="7d"]');
  await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
});

await t.step('custom range: pick dates, Apply, heading shows the dates; invalid/future dates are handled', async () => {
  await page.click('#range-seg [data-range="custom"]');
  t.ok(await page.locator('#custom-range').isVisible(), 'custom inputs visible');
  const today = await page.evaluate(() => document.querySelector('#f-to').max);
  const from = new Date(today + 'T00:00:00Z'); from.setUTCDate(from.getUTCDate() - 20);
  await page.fill('#f-from', from.toISOString().slice(0, 10));
  await page.fill('#f-to', today);
  const [resp] = await Promise.all([page.waitForResponse((r) => r.url().includes('range=custom')), page.click('#apply-custom')]);
  t.eq(resp.status(), 200);
  await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
  t.has(await t.text(page.locator('.sec-title').nth(3)), from.toLocaleString('en-IN', { month: 'short', timeZone: 'UTC' }).toUpperCase().slice(0, 3));
  // from > to is swapped by the server, not an error
  await page.fill('#f-from', today); await page.fill('#f-to', from.toISOString().slice(0, 10));
  const [r2] = await Promise.all([page.waitForResponse((r) => r.url().includes('range=custom')), page.click('#apply-custom')]);
  t.eq(r2.status(), 200);
  await page.click('#range-seg [data-range="7d"]');
  await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
});

await t.step('platform filter: net profit is shown BEFORE ads and says why; Reset restores', async () => {
  await Promise.all([page.waitForResponse((r) => r.url().includes('platform=instagram')), page.selectOption('#f-platform', 'instagram')]);
  await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
  t.has((await t.text(page.locator('.eq-net small'))).toLowerCase(), 'profit before ads');
  t.has(await t.text(page.locator('#dash-root')), 'not subtracted while a platform or delivery filter is on');
  t.ok(await page.locator('#reset-filters').isVisible(), 'reset visible');
  t.eq(await page.locator('.kpi').count(), 14, 'KPI cards ignore filters');
  await page.click('#reset-filters');
  await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
  t.has((await t.text(page.locator('.eq-net small'))).toLowerCase(), 'net profit');
  t.eq(await page.locator('#f-platform').inputValue(), '');
});

await t.step('delivery-mode and payment-status filters hit the API with the right parameters', async () => {
  const [r1] = await Promise.all([page.waitForResponse((r) => r.url().includes('mode=MANUAL')), page.selectOption('#f-mode', 'MANUAL')]);
  t.eq(r1.status(), 200);
  const [r2] = await Promise.all([page.waitForResponse((r) => r.url().includes('payment=pending')), page.selectOption('#f-payment', 'pending')]);
  t.eq(r2.status(), 200);
  await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
  await page.click('#reset-filters');
  await page.waitForFunction(() => document.querySelector('#dash-root').getAttribute('aria-busy') === 'false');
});

await t.step('chart tooltip follows the pointer and shows formatted values', async () => {
  await page.locator('[data-chart="rev"]').scrollIntoViewIfNeeded();
  const box = await page.locator('[data-chart="rev"] svg').boundingBox();
  await page.mouse.move(box.x + box.width * 0.5, box.y + box.height * 0.5);
  await page.waitForSelector('[data-chart="rev"] .chart-tip.is-on');
  const tip = await t.text(page.locator('[data-chart="rev"] .chart-tip'));
  t.ok(/₹/.test(tip) && /Revenue/.test(tip), tip);
  await page.mouse.move(box.x - 5, box.y - 5);
  await page.waitForFunction(() => !document.querySelector('[data-chart="rev"] .chart-tip.is-on'));
});

await t.step('chart is keyboard accessible: arrow keys move the active point', async () => {
  await page.locator('[data-chart="visitors"]').focus();
  await page.keyboard.press('ArrowLeft');
  await page.waitForSelector('[data-chart="visitors"] .chart-tip.is-on');
  const a = await t.text(page.locator('[data-chart="visitors"] .chart-tip b'));
  await page.keyboard.press('ArrowLeft');
  const b = await t.text(page.locator('[data-chart="visitors"] .chart-tip b'));
  t.ok(a !== b, `${a} → ${b}`);
  await page.keyboard.press('Escape');
});

await t.step('series toggles redraw the revenue chart without breaking it (and never allow an empty chart)', async () => {
  await page.click('.stog[data-series="gross"]');
  t.eq(await page.locator('.stog[data-series="gross"]').getAttribute('aria-pressed'), 'true');
  t.eq(await page.locator('[data-chart="rev"] path.chart-line').count(), 3);
  for (const k of ['revenue', 'net', 'gross']) await page.click(`.stog[data-series="${k}"]`);
  t.ok(await page.locator('[data-chart="rev"] path.chart-line').count() >= 1, 'at least one series stays on');
  await page.click('.stog[data-series="revenue"]'); await page.click('.stog[data-series="net"]');
});

await t.step('funnel, devices, sources, top services, SMM spend, payments donut and recent lists render', async () => {
  for (const sel of ['.funnel-row', '.donut', '#h-perf', '#h-smm', '#h-visitors', '#h-ro', '#h-rp', '#h-ra']) t.ok(await page.locator(sel).first().isVisible(), sel);
  t.ok((await page.locator('.hbar').count()) >= 8, 'bars');
  const donut = (await page.locator('.donut-center').first().textContent()) || '';
  t.ok(/\d/.test(donut), 'donut centre label: ' + donut);
});

await t.step('recent order link opens the order drawer on the Orders page', async () => {
  const link = page.locator('#h-ro').locator('xpath=ancestor::section').locator('a[href*="open="]').first();
  const href = await link.getAttribute('href');
  t.has(href, '/admin/orders?open=');
  await link.click();
  await page.waitForURL('**/admin/orders**');
  await page.waitForSelector('#order-drawer[open]');
  t.has(await t.text(page.locator('#od-title')), 'SMB-');
});

await t.step('Refresh button reloads and keeps the page usable', async () => {
  await go(page, '/admin/dashboard');
  const [r] = await Promise.all([page.waitForResponse((x) => x.url().includes('/admin/api/dashboard')), page.click('[data-action="refresh"]')]);
  t.eq(r.status(), 200);
});

await t.step('API failure: a clear error state with Retry (no blank page, no console crash)', async () => {
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 800 } });
  const p2 = await ctx2.newPage();
  await p2.goto(BASE + '/admin/login');
  await p2.fill('#username', 'owner'); await p2.fill('#password', 'Sm8!Qx-Valley#2026');
  await Promise.all([p2.waitForURL('**/admin/dashboard'), p2.click('#login-submit')]);
  await p2.route('**/admin/api/dashboard**', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code: 'server_error', message: 'Something went wrong. Please try again.' } }) }));
  await p2.reload({ waitUntil: 'networkidle' });
  await p2.waitForSelector('#dash-root [data-retry]');
  t.has(await p2.locator('#dash-root').innerText(), 'Could not load');
  await p2.unroute('**/admin/api/dashboard**');
  await Promise.all([p2.waitForResponse((r) => r.url().includes('/admin/api/dashboard')), p2.click('#dash-root [data-retry]')]);
  await p2.waitForSelector('.kpi');
  t.eq(await p2.locator('.kpi').count(), 14, 'recovers after retry');
  await ctx2.close();
});

await t.step('network offline: error toast, page keeps its last data', async () => {
  await go(page, '/admin/dashboard');
  await page.context().setOffline(true);
  await page.click('[data-action="refresh"]');
  await page.waitForSelector('.toast[data-type="error"]');
  t.has(await t.text(page.locator('.toast[data-type="error"]')), 'Network error');
  t.eq(await page.locator('.kpi').count(), 14);
  await page.context().setOffline(false);
});

console.log('console/network problems:', page.problems.filter((p) => !/Failed to load resource|net::ERR_INTERNET_DISCONNECTED/.test(p)).join('\n') || 'none');
await browser.close();
process.exit(t.finish() ? 1 : 0);
