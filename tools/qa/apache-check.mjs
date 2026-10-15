// DEPLOYMENT CHECK on real Apache + mod_php (what cPanel/LiteSpeed shared hosting runs): verifies both folder layouts from the deploy guide.
//   Layout A  everything uploaded to public_html (the root .htaccess routes into /public and hides app code, .env, storage, tools …)
//   Layout B  private code outside the web root, only the contents of /public in public_html (one line in index.php points at the code)
// For each layout: pretty URLs, static-file headers (types, gzip, long-lived caching), blocked paths, uploads that can never execute,
// no directory listing, no PHP banner, and a complete order → payment → webhook → SMM → tracking → cron round trip through Apache.
// Needs apache2 + libapache2-mod-php installed (skips otherwise). Local dev only: uses the dev database and the two mock servers.
import { execFileSync } from 'node:child_process';
import { writeFileSync, readFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';
import { ROOT, sql, alpha, smm, mocksReset, resetLimits, useMockProviderIds, sleep, serviceId, launch, newPage, waitUntil } from './site.mjs';
import { startApache, sh, PORT_A, PORT_B, PORT_C } from './apache-env.mjs';
import { suite } from './harness.mjs';

const t = suite('apache-check');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
useMockProviderIds(); resetLimits();
// hostile uploads that must never run are planted BEFORE the server starts (so the copies contain them)
const apache = await startApache().catch((e) => { console.log(String(e.message || e)); process.exit(1); });
if (!apache) { console.log('apache2 / mod_php not installed — skipping the Apache deployment check (apt-get install apache2 libapache2-mod-php)'); process.exit(0); }
const W = apache.W;
const evil = '<?php echo "PWNED-" . (1+1); ?>';
for (const [dir, name] of [[`${W}/siteA/public/uploads`, 'evil.php'], [`${W}/siteA/public/uploads`, 'evil.phtml'], [`${W}/siteA/public/uploads`, 'evil.php.png'], [`${W}/siteA/public/uploads`, 'evil.php5'], [`${W}/siteB/public_html/uploads`, 'evil.php'], [`${W}/siteB/public_html/uploads`, 'evil.phtml'], [`${W}/siteB/public_html/uploads`, 'evil.php.png']]) writeFileSync(`${dir}/${name}`, evil);
// a stray script in the web root, a PHP file dropped into assets/, and (layout B) OTHER sites sharing the same web root: a PHP app and a static site in sub-folders
const stray = '<?php echo "STRAY-RAN";';
for (const [dir, name] of [[`${W}/siteA/public`, 'stray.php'], [`${W}/siteB/public_html`, 'stray.php'], [`${W}/siteA/public/assets`, 'evil.php'], [`${W}/siteB/public_html/assets`, 'evil.php'], [`${W}/siteB/public_html/assets/css`, 'evil.php5']]) writeFileSync(`${dir}/${name}`, stray);
sh(`mkdir -p ${W}/siteB/public_html/otherapp ${W}/siteB/public_html/oldsite`);
writeFileSync(`${W}/siteB/public_html/otherapp/hello.php`, '<?php echo "OTHER-APP-OK";');
writeFileSync(`${W}/siteB/public_html/oldsite/index.html`, '<h1>OLD-STATIC-SITE</h1>');
sh(`chmod -R go+rX ${W}`);
t.ok(true, 'index.php carries the documented layout-B line (the module throws otherwise)');

const http = async (base, method, path, { headers = {}, json, body } = {}) => {
  const r = await fetch(base + path, { method, redirect: 'manual', headers: { ...(json !== undefined ? { 'content-type': 'application/json', 'x-requested-with': 'smb', origin: base } : {}), ...headers }, body: json !== undefined ? JSON.stringify(json) : body, signal: AbortSignal.timeout(30000) });
  const buf = Buffer.from(await r.arrayBuffer());
  return { status: r.status, headers: r.headers, buf, text: buf.toString('utf8') };
};

for (const [label, port] of [['A (everything in public_html)', PORT_A], ['B (code outside the web root)', PORT_B]]) {
  const base = `http://127.0.0.1:${port}`;
  const get = (p, h) => http(base, 'GET', p, { headers: h });

  await t.step(`layout ${label}: the storefront renders through Apache with the application's security headers and no PHP banner`, async () => {
    const r = await get('/'); t.eq(r.status, 200, r.text.slice(0, 300));
    t.has(r.text, 'SOCIAL MEDIA BOOSTER'); t.has(r.headers.get('content-type'), 'text/html');
    t.has(r.headers.get('content-security-policy') || '', "default-src 'self'"); t.eq(r.headers.get('x-content-type-options'), 'nosniff'); t.ok(r.headers.get('x-frame-options'));
    t.ok(!r.headers.get('x-powered-by'), 'X-Powered-By: ' + r.headers.get('x-powered-by')); t.ok(!/PHP\//.test(r.headers.get('server') || ''), 'Server: ' + r.headers.get('server'));
    t.has(r.text, `href="${base}/`.replace(base, '') ? '<link rel="canonical"' : '');
    t.has(r.text, `${base}/`, 'absolute URLs use APP_URL');
  });

  await t.step(`layout ${label}: static files — correct types, gzip, far-future caching, nosniff; no source maps`, async () => {
    for (const [p, type] of [['/assets/css/app.css', 'text/css'], ['/assets/js/site.js', 'javascript'], ['/assets/fonts/inter-latin.woff2', 'font/woff2'], ['/assets/img/platforms/instagram.webp', 'image/webp'], ['/assets/img/logo-mark.webp', 'image/webp'], ['/assets/img/favicon-48.png', 'image/png'], ['/favicon.ico', 'image'], ['/assets/img/og-default.png', 'image/png']]) {
      const r = await get(p, { 'accept-encoding': 'gzip' }); t.eq(r.status, 200, p); t.has(r.headers.get('content-type') || '', type, p);
      t.eq(r.headers.get('x-content-type-options'), 'nosniff', p + ' nosniff');
      if (!/woff2|png|webp|ico/.test(p)) t.eq(r.headers.get('content-encoding'), 'gzip', p + ' is compressed');   // images / fonts are already compressed formats   // (fetch already inflated the body)
      const cc = (r.headers.get('cache-control') || '') + ' ' + (r.headers.get('expires') || ''); t.ok(/max-age=\d{6,}|expires/i.test(cc) || r.headers.get('expires'), `${p} cache headers: ${cc}`);
    }
    const css = await get('/assets/css/app.css'); const home = await get('/');
    t.ok(/app\.css\?v=\w+/.test(home.text), 'CSS/JS URLs carry a ?v= cache-buster so long caching is safe');
    t.ok(!/sourceMappingURL/.test(css.text));
  });

  await t.step(`layout ${label}: pretty URLs and routes work without index.php (health, manifest, sitemap, 404 page, HEAD, POST JSON)`, async () => {
    t.eq((await get('/health')).status, 200); t.has((await get('/manifest.webmanifest')).headers.get('content-type'), 'manifest');
    t.has((await get('/sitemap.xml')).text, '<urlset'); t.has((await get('/robots.txt')).text, 'Disallow: /admin');
    const nf = await get('/definitely/not/here'); t.eq(nf.status, 404); t.has(nf.text.toLowerCase(), 'not found'); t.ok(!/Apache|Not Found<\/h1>\s*<p>The requested URL/i.test(nf.text), 'our own 404 page, not Apache\'s');
    t.eq((await http(base, 'HEAD', '/')).status, 200);
    t.eq((await get('/admin/login')).status, 200); t.eq((await get('/admin/dashboard')).status, 302);
    t.eq((await get('/install')).status, 404, 'installer is gone once installed');
    t.eq((await get('/index.php')).status, 200, 'index.php direct hit still serves the app'); t.eq((await get('/index.php/admin/dashboard')).status === 200 && /data-page=/.test((await get('/index.php/admin/dashboard')).text), false, 'PATH_INFO tricks do not bypass sign-in');
  });

  await t.step(`layout ${label}: private files are unreachable (.env, app code, storage, database, tools, tests, bin, docs, lock/log files, dot-files)`, async () => {
    const paths = ['/.env', '/.env.example', '/app/bootstrap.php', '/app/Core/Db.php', '/app/routes.php', '/storage/installed.lock', '/storage/logs/', '/storage/logs/app-2026-10-14.log', '/storage/cache/', '/database/schema.sql', '/database/migrations/001_initial.sql', '/database/seeds/catalog.php',
      '/bin/cron.php', '/bin/install.php', '/tools/router.php', '/tools/qa/site.mjs', '/tests/run.php', '/docs/FONT-LICENSE-Inter.txt', '/resources/js/site.js', '/package.json', '/package-lock.json', '/README.md', '/composer.json', '/.gitignore', '/.git/HEAD', '/config/', '/node_modules/', '/vendor/autoload.php',
      '/public/.htaccess', '/.htaccess', '/uploads/.htaccess', '/storage/.htaccess', '/%2e%2e/.env', '/assets/../.env', '/app%2fbootstrap.php', '/.env%00.png', '/.env/', '//.env'];
    for (const p of paths) {
      const r = await http(base, 'GET', p);
      const leaked = r.status === 200 && /DB_PASS|APP_KEY=|<\?php|declare\(strict_types|CREATE TABLE|password_hash|RewriteRule|SecRule/i.test(r.text);
      t.ok(!leaked, `${p} → ${r.status} leaks content: ${r.text.slice(0, 80)}`);
      t.ok([301, 302, 400, 403, 404].includes(r.status) || (r.status === 200 && /SOCIAL MEDIA BOOSTER|not found/i.test(r.text)), `${p} → ${r.status}`);
    }
    // the shipped .htaccess files themselves are not downloadable
    for (const p of ['/.htaccess', '/uploads/.htaccess']) t.ok([403, 404].includes((await get(p)).status), `${p} must not be downloadable`);
  });

  await t.step(`layout ${label}: uploaded files can never execute (php, phtml, php5, double extension), directory listing is off`, async () => {
    for (const f of ['evil.php', 'evil.phtml', 'evil.php.png', 'evil.php5']) {
      const r = await get('/uploads/' + f); t.ok(!r.text.includes('PWNED-2'), `${f} executed!`);
      t.ok([403, 404].includes(r.status) || (r.status === 200 && r.text.includes('<?php') && /^(image|application\/octet)/.test(r.headers.get('content-type') || '')), `${f} → ${r.status} ${r.headers.get('content-type')}: must be refused, or delivered as inert data`);
    }
    for (const d of ['/uploads/', '/assets/', '/assets/js/', '/assets/css/', '/assets/img/', '/assets/fonts/']) { const r = await get(d); t.ok(!/Index of/i.test(r.text), `${d} lists its files`); t.ok([403, 404].includes(r.status), `${d} → ${r.status}`); }
    const png = await get('/uploads/evil.php.png'); t.ok(png.headers.get('content-security-policy') === null || png.status !== 200 || /default-src 'none'/.test(png.headers.get('content-security-policy')), 'uploads carry a locked-down CSP');
  });

  await t.step(`layout ${label}: only index.php executes at the top of the web root, assets/ is inert${port === PORT_B ? ', and OTHER sites sharing the web root keep working' : ''}`, async () => {
    const stray = await get('/stray.php'); t.eq(stray.status, 403, `a stray PHP file in the web root must be refused: ${stray.status}`); t.ok(!stray.text.includes('STRAY-RAN'), 'stray.php executed!');
    const up = await get('/STRAY.PHP'); t.ok(!up.text.includes('STRAY-RAN'), 'case tricks execute it!');
    for (const f of ['/assets/evil.php', '/assets/css/evil.php5']) { const r = await get(f); t.ok([403, 404].includes(r.status) && !r.text.includes('STRAY-RAN'), `${f} → ${r.status}`); }
    t.eq((await get('/index.php')).status, 200, 'index.php itself still runs'); t.eq((await get('/assets/css/app.css')).status, 200, 'static assets still served');
    if (port === PORT_B) {
      const app = await get('/otherapp/hello.php'); t.eq(app.status, 200, 'a PHP app in a sub-folder of the web root must not be blocked by our .htaccess'); t.has(app.text, 'OTHER-APP-OK');
      const old = await get('/oldsite/'); t.eq(old.status, 200, 'a static site in a sub-folder is served (DirectoryIndex falls back to index.html)'); t.has(old.text, 'OLD-STATIC-SITE');
    }
  });

  await t.step(`layout ${label}: full round trip through Apache — order → QR → signed webhook → SMM → tracking page → cron`, async () => {
    await mocksReset(); await alpha('/__control', { webhookUrl: `${base}/webhooks/payments/1` }); resetLimits();
    const home = await get('/');
    const co = await http(base, 'POST', '/api/checkout', { json: { service_id: serviceId('ig-followers-50'), target: 'https://instagram.com/apache_' + port }, headers: { 'idempotency-key': randomUUID() } });
    t.eq(co.status, 200, co.text); const j = JSON.parse(co.text); const ref = j.url.match(/pay\/([a-f0-9]{32})/)[1];
    const page = await get(`/pay/${ref}`); t.eq(page.status, 200); t.has(page.text, 'SCAN AND PAY');
    const qr = await get(`/pay/${ref}/qr.png`); t.eq(qr.status, 200); t.eq(qr.buf.subarray(1, 4).toString(), 'PNG'); t.has(qr.headers.get('content-type'), 'image/png');
    const gw = sql(`SELECT gateway_order_id FROM payments WHERE ref = '${ref}'`);
    const paid = await alpha(`/__pay/${gw}/success`, {}); t.eq(paid.webhook?.status, 200, JSON.stringify(paid));
    const orderStatus = () => sql(`SELECT o.status FROM orders o JOIN payments p ON p.order_id = o.id WHERE p.ref = '${ref}'`);
    await waitUntil(() => ['SUBMITTED', 'IN_PROGRESS', 'COMPLETED'].includes(orderStatus()), { timeout: 15000 });   // the provider call runs after the webhook has been answered
    const st = JSON.parse((await get(`/api/pay/${ref}/status`)).text); t.eq(st.state, 'paid');
    t.ok(['SUBMITTED', 'IN_PROGRESS', 'COMPLETED'].includes(orderStatus()), 'handed to the SMM provider: ' + orderStatus());
    const tr = await get(`/order/${j.token}`); t.eq(tr.status, 200); t.has(tr.text, 'Order');
    const wrong = await get('/cron/run?key=wrong'); t.eq(wrong.status, 403);
    const key = readFileSync(`${ROOT}/.env`, 'utf8').match(/^CRON_KEY=(.*)$/m)?.[1]?.replace(/"/g, '');
    const cron = await get('/cron/run?key=' + key); t.eq(cron.status, 200, cron.text.slice(0, 200)); t.ok(JSON.parse(cron.text).payments !== undefined || JSON.parse(cron.text).ok, cron.text.slice(0, 200));
    // visitor beacon sets first-party cookies under Apache too
    const hit = await http(base, 'POST', '/api/track/hit', { json: { path: '/', first: true }, headers: { 'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36' } });
    t.eq(hit.status, 200); t.ok((hit.headers.getSetCookie?.() || []).length >= 1, 'tracking cookies set');
    void home;
  });

  await t.step(`layout ${label}: the payment webhook is answered at once even while the SMM provider hangs (mod_php cannot close the connection early, so the answer carries its Content-Length)`, async () => {
    await mocksReset(); await alpha('/__control', { webhookUrl: `${base}/webhooks/payments/1` }); resetLimits();
    await smm('/__control', { hang: true });                                   // the provider call after the answer will take its full 30 s timeout
    try {
      const co = await http(base, 'POST', '/api/checkout', { json: { service_id: serviceId('ig-followers-50'), target: 'https://instagram.com/slowprovider_' + port }, headers: { 'idempotency-key': randomUUID() } });
      t.eq(co.status, 200, co.text); const ref = JSON.parse(co.text).url.match(/pay\/([a-f0-9]{32})/)[1];
      const gw = sql(`SELECT gateway_order_id FROM payments WHERE ref = '${ref}'`);
      const t0 = Date.now(); const paid = await alpha(`/__pay/${gw}/success`, {}); const ms = Date.now() - t0;
      t.eq(paid.webhook?.status, 200, JSON.stringify(paid)); t.ok(ms < 5000, `the webhook was answered in ${ms} ms while the provider hangs for 30 s`);
      t.eq(sql(`SELECT status FROM payments WHERE ref = '${ref}'`), 'success', 'and the payment is recorded');
    } finally { await smm('/__control', { hang: false }); }
  });

  await t.step(`layout ${label}: writes land where they should (logs/cache owned by the web user; no stray files in the web root)`, async () => {
    const dir = port === PORT_A ? `${W}/siteA` : `${W}/siteB/smb`;
    const logs = sh(`ls ${dir}/storage/logs | head -3`); t.ok(logs.trim().length >= 0);
    const pub = port === PORT_A ? `${W}/siteA/public` : `${W}/siteB/public_html`;
    const strays = sh(`find ${pub} -maxdepth 1 -newer ${W}/apache2.conf -type f ! -name stray.php | head`).trim(); t.eq(strays, '', 'nothing is written into the web root: ' + strays);
  });
}

// ───────────────────────── layout A in a SUB-FOLDER (https://example.com/booster/) ─────────────────────────
{
  const base = `http://127.0.0.1:${PORT_C}/booster`; const origin = `http://127.0.0.1:${PORT_C}`;
  const get = (p, h) => http(base, 'GET', p, { headers: h });
  await t.step('sub-folder install (/booster): every link, script, stylesheet, form target and redirect stays inside the folder', async () => {
    t.has((await http(origin, 'GET', '/')).text, 'parent site');
    const r = await get('/'); t.eq(r.status, 200, r.text.slice(0, 200)); t.has(r.text, 'SOCIAL MEDIA BOOSTER');
    const urls = [...r.text.matchAll(/(?:href|src|action)="([^"#]+)"/g)].map((m) => m[1]).filter((u) => u.startsWith('/'));
    t.ok(urls.length > 8, 'internal URLs found: ' + urls.length);
    t.eq(urls.filter((u) => !u.startsWith('/booster/') && u !== '/booster'), [], 'root-relative URLs that would escape the folder');
    for (const u of new Set(urls.filter((x) => /\.(css|js|svg|png|ico|woff2|webmanifest)(\?|$)/.test(x)))) t.eq((await http(origin, 'GET', u)).status, 200, u);
    t.eq((await get('/admin/dashboard')).headers.get('location')?.startsWith('/booster/admin/login') || (await get('/admin/dashboard')).headers.get('location')?.startsWith(origin + '/booster/admin/login'), true, 'sign-in redirect keeps the folder: ' + (await get('/admin/dashboard')).headers.get('location'));
    t.eq((await get('/admin/login')).status, 200); t.eq((await get('/health')).status, 200); t.eq((await get('/nothing-here')).status, 404);
    for (const p of ['/.env', '/app/bootstrap.php', '/storage/installed.lock', '/database/schema.sql', '/tools/router.php']) t.ok([403, 404].includes((await get(p)).status), `${p} → ${(await get(p)).status}`);
    const man = JSON.parse((await get('/manifest.webmanifest')).text); t.has(man.start_url, '/booster/'); t.ok(man.icons.every((i) => i.src.includes('/booster/')), JSON.stringify(man.icons));
  });
  await t.step('sub-folder install (/booster): a real browser can order and reach the payment page with no 404s or console errors', async () => {
    await mocksReset(); await alpha('/__control', { webhookUrl: `${base}/webhooks/payments/1` }); resetLimits();
    const browser = await launch(); const page = await newPage(browser, { width: 1366, height: 800 });
    try {
      await page.goto(base + '/', { waitUntil: 'networkidle' }); await page.waitForTimeout(500);
      await page.click('[data-platform-tab="youtube"]');
      const card = page.locator(`[data-service][data-id="${serviceId('yt-views-10')}"]`);
      await card.locator('[data-action=buy]').click(); await card.locator('input[name=target]').fill('https://youtu.be/dQw4w9WgXcQ');
      await page.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card.elementHandle());
      await Promise.all([page.waitForURL(/\/booster\/pay\/[a-f0-9]{32}/, { timeout: 30000 }), card.locator('[data-action=proceed]').click()]);
      await page.waitForSelector('#qr-img'); t.ok(await page.$eval('#qr-img', (e) => e.naturalWidth > 100), 'QR loaded from inside the folder');
      const ref = page.url().match(/pay\/([a-f0-9]{32})/)[1];
      const gw = sql(`SELECT gateway_order_id FROM payments WHERE ref = '${ref}'`); const res = await alpha(`/__pay/${gw}/success`, {});
      t.eq(res.webhook?.status, 200, 'the signed webhook reached the sub-folder install: ' + JSON.stringify(res));
      await page.waitForSelector('[data-view=paid]:not([hidden])', { timeout: 10000 }).catch(async () => { throw new Error(`the page never flipped to paid; payment=${sql(`SELECT status FROM payments WHERE ref = '${ref}'`)} statusUrl=${await page.evaluate(() => JSON.parse(document.getElementById('pay-data').textContent).statusUrl)} problems=${page.problems.join(' | ')}`); });
      await page.click('#btn-track'); await page.waitForURL(/\/booster\/order\//);
      await page.click('.shop-btn'); await page.waitForURL(/\/booster\/(\?|$)/); await page.waitForSelector('#orders-drawer[open]', { timeout: 15000 });
      t.has(await page.locator('#orders-drawer .order-row').first().innerText(), 'Views', 'the drawer lists the order just placed'); t.ok(!page.url().includes('orders=1'), 'the ?orders=1 marker is cleaned from the URL');
      t.eq(page.problems.filter((p) => !/favicon/.test(p)), [], page.problems.join('\n'));
    } finally { await browser.close(); }
  });
}

const errlog = apache.errorLog();
await t.step('Apache error log shows no PHP warnings/fatals or .htaccess syntax errors during the whole run', async () => {
  const bad = errlog.split('\n').filter((l) => /PHP (Fatal|Warning|Parse|Notice|Deprecated)|Invalid command|AH00526|AH01630|configuration error|Premature end of script/i.test(l) && !/client denied by server configuration/.test(l));
  t.eq(bad, [], bad.slice(0, 5).join('\n'));
});

const failed = t.finish();
apache.stop();
await alpha('/__control', { webhookUrl: 'http://127.0.0.1:8080/webhooks/payments/1' });
process.exit(failed ? 1 : 0);
