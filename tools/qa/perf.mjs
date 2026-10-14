// FINAL PERFORMANCE CHECK (brief §60): real numbers, not opinions.
//   • page weight, request count, compression and caching on REAL Apache (layout B) — cold and warm cache
//   • Core Web Vitals-style metrics (FCP, LCP, CLS, long tasks) on a throttled phone profile (Slow-4G network, 4× CPU slow-down) and on desktop
//   • images: explicit sizes (no layout shift), lazy-loaded below the fold, modern formats
//   • database statements per request (dev Server-Timing header) and API chatter (beacons, polling)
// Budgets are asserted so a regression fails the run. Needs apache2 + mod_php (skips the Apache part otherwise).
import { launch, newPage, BASE, sql, resetLimits, mocksReset, useMockProviderIds, placeOrder, serviceId, sleep, MOBILE_UA, DESKTOP_UA } from './site.mjs';
import { startApache } from './apache-env.mjs';
import { suite } from './harness.mjs';

const t = suite('perf');
useMockProviderIds(); resetLimits(); await mocksReset();
const browser = await launch();
const apache = await startApache().catch(() => null);
const kb = (n) => (n / 1024).toFixed(1) + ' KB';

const INIT = () => {
  window.__perf = { lcp: 0, cls: 0, fcp: 0, long: [] };
  try {
    new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__perf.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__perf.cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) if (e.name === 'first-contentful-paint') window.__perf.fcp = e.startTime; }).observe({ type: 'paint', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__perf.long.push([e.startTime, e.duration]); }).observe({ type: 'longtask', buffered: true });
  } catch { /* unsupported */ }
};

/** Load a URL with a profile and return what the network and the page measured. */
async function measure(url, { mobile = false, throttle = false, warm = false, wait = 1500, context = null } = {}) {
  const ctx = context || await browser.newContext({ userAgent: mobile ? MOBILE_UA : DESKTOP_UA, viewport: mobile ? { width: 390, height: 844 } : { width: 1366, height: 768 }, deviceScaleFactor: mobile ? 2 : 1, isMobile: mobile, hasTouch: mobile });
  const page = await ctx.newPage();
  await page.addInitScript(INIT);
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  if (throttle) {
    await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
  }
  const reqs = new Map();
  cdp.on('Network.responseReceived', (e) => reqs.set(e.requestId, { url: e.response.url, status: e.response.status, type: e.type, enc: e.response.headers['content-encoding'] || e.response.headers['Content-Encoding'] || '', cache: e.response.fromDiskCache || e.response.fromPrefetchCache || false, mime: e.response.mimeType }));
  cdp.on('Network.loadingFinished', (e) => { const r = reqs.get(e.requestId); if (r) r.bytes = e.encodedDataLength; });
  const nav = async () => { await page.goto(url, { waitUntil: 'load', timeout: 60000 }); await page.waitForTimeout(wait); };
  await nav();
  if (warm) { reqs.clear(); await page.reload({ waitUntil: 'load' }); await page.waitForTimeout(wait); }
  const p = await page.evaluate(() => {
    const n = performance.getEntriesByType('navigation')[0];
    const imgs = [...document.images].map((i) => ({ src: i.currentSrc.split('/').slice(-2).join('/'), w: i.getAttribute('width'), h: i.getAttribute('height'), lazy: i.loading === 'lazy', top: i.getBoundingClientRect().top, nat: i.naturalWidth }));
    return { ...window.__perf, ttfb: n.responseStart, dcl: n.domContentLoadedEventEnd, load: n.loadEventEnd, imgs, dom: document.getElementsByTagName('*').length };
  });
  const list = [...reqs.values()].filter((r) => r.bytes !== undefined || r.status);
  const total = list.reduce((a, r) => a + (r.bytes || 0), 0);
  const tbt = p.long.filter(([s]) => s >= p.fcp).reduce((a, [, d]) => a + Math.max(0, d - 50), 0);
  const result = { ...p, tbt, requests: list.length, total, list, byType: Object.fromEntries(['Document', 'Stylesheet', 'Script', 'Font', 'Image', 'Fetch', 'XHR', 'Other'].map((k) => [k, list.filter((r) => r.type === k).reduce((a, r) => a + (r.bytes || 0), 0)])) };
  if (!context) await ctx.close(); else await page.close();
  return result;
}

const show = (name, m) => console.log(`      ${name}: ${m.requests} requests, ${kb(m.total)} on the wire (doc ${kb(m.byType.Document)}, css ${kb(m.byType.Stylesheet)}, js ${kb(m.byType.Script)}, fonts ${kb(m.byType.Font)}, img ${kb(m.byType.Image)}) · TTFB ${m.ttfb.toFixed(0)} ms · FCP ${m.fcp.toFixed(0)} ms · LCP ${m.lcp.toFixed(0)} ms · CLS ${m.cls.toFixed(3)} · TBT ${m.tbt.toFixed(0)} ms · DOM ${m.dom} nodes`);

if (apache) {
  const B = `http://127.0.0.1:${apache.ports.B}`;
  await t.step('desktop, cold cache (Apache, gzip, cable): light page, few requests, fast paint, no layout shift', async () => {
    const m = await measure(B + '/'); show('desktop cold', m);
    t.ok(m.requests <= 22, 'requests ' + m.requests);
    t.ok(m.total < 230 * 1024, 'transfer ' + kb(m.total));
    t.ok(m.byType.Document < 12 * 1024, 'HTML on the wire (gzip) ' + kb(m.byType.Document));
    t.ok(m.byType.Script < 14 * 1024, 'JavaScript on the wire ' + kb(m.byType.Script));
    t.ok(m.byType.Stylesheet < 30 * 1024, 'CSS on the wire ' + kb(m.byType.Stylesheet));
    const plain = m.list.filter((r) => ['Document', 'Stylesheet', 'Script'].includes(r.type) && r.bytes > 1024 && !/gzip|br/.test(r.enc)); t.eq(plain.map((r) => r.url), [], 'text resources over 1 KB are compressed');
    t.ok(m.ttfb < 250, 'TTFB ' + m.ttfb.toFixed(0) + ' ms'); t.ok(m.fcp < 800, 'FCP ' + m.fcp.toFixed(0)); t.ok(m.lcp < 1200, 'LCP ' + m.lcp.toFixed(0)); t.ok(m.cls < 0.02, 'CLS ' + m.cls); t.ok(m.tbt < 50, 'TBT ' + m.tbt);
    t.ok(m.dom < 1800, 'DOM size ' + m.dom);
  });

  await t.step('PHONE on Slow-4G + 4× slower CPU, cold cache: usable in under 2.5 s, stable layout, no long tasks', async () => {
    const m = await measure(B + '/', { mobile: true, throttle: true, wait: 2500 }); show('phone slow-4G cold', m);
    t.ok(m.fcp < 2000, 'FCP ' + m.fcp.toFixed(0) + ' ms'); t.ok(m.lcp < 2500, 'LCP ' + m.lcp.toFixed(0) + ' ms'); t.ok(m.cls < 0.05, 'CLS ' + m.cls.toFixed(3)); t.ok(m.tbt < 200, 'TBT ' + m.tbt.toFixed(0) + ' ms');
    t.ok(m.long.length <= 3, 'long tasks: ' + JSON.stringify(m.long));
  });

  await t.step('warm cache (second visit): static files come from the browser cache — only the HTML (and the tracking beacon) hit the network', async () => {
    const ctx = await browser.newContext({ userAgent: DESKTOP_UA, viewport: { width: 1366, height: 768 } });
    await measure(B + '/', { context: ctx });
    const m = await measure(B + '/', { context: ctx, warm: true }); show('desktop warm', m);
    const net = m.list.filter((r) => !r.cache && r.bytes > 300);
    t.ok(m.total < 25 * 1024, 'warm transfer ' + kb(m.total) + ' — ' + net.map((r) => r.url.split('/').pop() + ':' + r.bytes).join(', '));
    const staticOnNet = m.list.filter((r) => /\.(css|js|woff2|svg|png|webp)(\?|$)/.test(r.url) && r.bytes > 500 && !r.cache);
    t.eq(staticOnNet.map((r) => r.url), [], 'versioned static files are served from cache');
    await ctx.close();
  });

  await t.step('images: every <img> has width/height (no shift), below-the-fold images are lazy, nothing oversized', async () => {
    const m = await measure(B + '/'); const bad = m.imgs.filter((i) => !i.w || !i.h);
    t.eq(bad, [], 'images without explicit size'); const eager = m.imgs.filter((i) => !i.lazy && i.top > 900); t.eq(eager, [], 'below-the-fold images that are not lazy');
    const heavy = m.list.filter((r) => r.type === 'Image' && r.bytes > 40 * 1024); t.eq(heavy.map((r) => r.url), [], 'images over 40 KB on the first screen');
    const types = new Set(m.list.filter((r) => r.type === 'Image').map((r) => r.mime)); console.log('      image types on the home page:', [...types].join(', '));
  });

  await t.step('payment page on the phone profile: QR visible fast, tiny JS, no layout shift', async () => {
    resetLimits();
    const ctx = await browser.newContext({ userAgent: MOBILE_UA, viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    const page = await ctx.newPage(); await page.goto(B + '/', { waitUntil: 'networkidle' });
    const co = await page.evaluate(async (id) => (await fetch('/api/checkout', { method: 'POST', headers: { 'content-type': 'application/json', 'x-requested-with': 'smb', 'idempotency-key': crypto.randomUUID() }, body: JSON.stringify({ service_id: id, target: 'https://www.instagram.com/p/PerfPay00001/' }) })).json(), serviceId('ig-likes-100'));
    await ctx.close();
    t.ok(co.url, JSON.stringify(co));
    const m = await measure(B + co.url, { mobile: true, throttle: true, wait: 2500 }); show('pay page, phone slow-4G', m);
    t.ok(m.lcp < 2800, 'LCP ' + m.lcp.toFixed(0)); t.ok(m.cls < 0.05, 'CLS ' + m.cls.toFixed(3)); t.ok(m.byType.Script < 8 * 1024, 'JS ' + kb(m.byType.Script));
  });
} else console.log('      (Apache not installed — skipping the compression / caching / Web Vitals part)');

// ───────────────────────── server side ─────────────────────────
await t.step('database statements and time per request (dev Server-Timing): the storefront needs a handful of indexed queries', async () => {
  resetLimits();
  const timing = async (method, path, init = {}) => { const r = await fetch(BASE + path, { method, ...init }); await r.arrayBuffer(); const h = r.headers.get('server-timing') || ''; return { q: Number(h.match(/"(\d+) queries"/)?.[1]), app: Number(h.match(/app;dur=([\d.]+)/)?.[1]), db: Number(h.match(/db;dur=([\d.]+)/)?.[1]), status: r.status }; };
  const co = await (await fetch(BASE + '/api/checkout', { method: 'POST', headers: { 'content-type': 'application/json', 'x-requested-with': 'smb', origin: BASE, 'idempotency-key': crypto.randomUUID() }, body: JSON.stringify({ service_id: serviceId('ig-likes-100'), target: 'https://www.instagram.com/p/PerfDb00001/' }) })).json();
  const ref = co.url.match(/pay\/([a-f0-9]{32})/)[1];
  const rows = {
    'GET /': await timing('GET', '/'), 'GET /pay/{ref}': await timing('GET', `/pay/${ref}`), 'GET /pay/{ref}/qr.png': await timing('GET', `/pay/${ref}/qr.png`), 'GET /api/pay/{ref}/status (poll)': await timing('GET', `/api/pay/${ref}/status`),
    'GET /order/{token}': await timing('GET', `/order/${co.token}`), 'GET /api/order/{token}': await timing('GET', `/api/order/${co.token}`), 'GET /health': await timing('GET', '/health'),
    'POST /api/track/hit': await timing('POST', '/api/track/hit', { headers: { 'content-type': 'application/json', origin: BASE, 'user-agent': DESKTOP_UA }, body: JSON.stringify({ path: '/', first: true }) }),
  };
  for (const [k, v] of Object.entries(rows)) console.log(`      ${k.padEnd(36)} ${String(v.q).padStart(2)} queries · app ${v.app.toFixed(1)} ms · db ${v.db.toFixed(1)} ms`);
  t.ok(rows['GET /'].q <= 5, 'storefront queries ' + rows['GET /'].q); t.ok(rows['GET /pay/{ref}'].q <= 8); t.ok(rows['GET /api/pay/{ref}/status (poll)'].q <= 8, 'poll queries'); t.ok(rows['GET /order/{token}'].q <= 8); t.ok(rows['POST /api/track/hit'].q <= 14, 'beacon queries ' + rows['POST /api/track/hit'].q);
  for (const [k, v] of Object.entries(rows)) t.ok(v.app < 120, `${k} server time ${v.app} ms`);
  const slow = await timing('GET', '/'); t.ok(slow.app < 60, 'storefront server time ' + slow.app);
  // the heaviest read: the admin dashboard with ~75 days of data
  console.log('      (admin dashboard is timed in the admin suites)');
});

await t.step('API chatter: a fresh visitor makes ONE beacon (+ one "qualify" after 3 s); the payment page polls about every 3 s, not in a flood', async () => {
  const { ctx, page } = await (async () => { const ctx = await browser.newContext({ userAgent: DESKTOP_UA }); return { ctx, page: await ctx.newPage() }; })();
  const calls = []; page.on('request', (r) => { if (/\/api\//.test(r.url())) calls.push(`${r.method()} ${new URL(r.url()).pathname.replace(/[a-f0-9]{32}/, '{id}')}`); });
  await page.goto(BASE + '/', { waitUntil: 'load' }); await sleep(4500);
  t.eq(calls, ['POST /api/track/hit', 'POST /api/track/qualify'], 'API calls on a fresh visit');
  resetLimits(); calls.length = 0;
  const { ref } = await placeOrder(page, 'ig-likes-100', 'https://www.instagram.com/p/PerfPoll0001/'); calls.length = 0;
  await sleep(12000);
  const polls = calls.filter((c) => /status/.test(c)).length; t.ok(polls >= 3 && polls <= 6, `status polls in 12 s: ${polls}`);
  await ctx.close(); void ref;
});

await t.step('server-side rendering: the storefront HTML already contains every service (no client-side data fetch needed to show prices)', async () => {
  const html = await (await fetch(BASE + '/')).text();
  for (const needle of ['50 Followers', '100K Followers', '1,000 Views', 'Permanent Blue Tick', '50 Subscribers', '4,000 Hours Watch Time', '₹199', '₹99', 'TRIAL PACK']) t.has(html, needle);
});

if (apache) apache.stop();
const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
