// Web installer on a pristine copy of the project (no .env, empty database) — like a fresh cPanel upload.
import { execFileSync } from 'node:child_process';
import { readFileSync, existsSync, statSync } from 'node:fs';
import { launch, newPage } from './admin.mjs';
import { suite } from './harness.mjs';

const B = 'http://127.0.0.1:8090';
const ROOT = '/tmp/qa/inst';
const t = suite('install-e2e');
execFileSync('bash', ['tools/qa/install-env.sh', 'start'], { cwd: '/home/user/claude1', stdio: 'ignore' });
const browser = await launch();
const page = await newPage(browser, { width: 1280, height: 900 }, { allowStatus: [403, 404, 422, 429] });
t.page = page;
const token = () => readFileSync(`${ROOT}/storage/install-token.txt`, 'utf8').trim();
const next = () => page.click('section:not([hidden]) button[type="submit"], section:not([hidden]) [data-next]');
const step = () => page.evaluate(() => [...document.querySelectorAll('[data-step]')].find((s) => !s.hidden)?.dataset.step);
const ADMIN = { user: 'shopowner', email: 'owner@shop.example', pass: 'Zebra-Cloud#Install-7741' };

await t.step('fresh upload: every URL redirects to /install, which shows ONLY the unlock step (no server details leak)', async () => {
  const r = await page.request.get(B + '/', { maxRedirects: 0 });
  t.eq(r.status(), 302);
  await page.goto(B + '/install', { waitUntil: 'networkidle' });
  t.ok(await page.locator('[data-step="unlock"]').isVisible(), 'unlock step');
  t.eq(await page.locator('[data-step="server"]').count(), 0, 'server step is not even in the HTML');
  const html = await page.content();
  t.ok(!html.includes('PHP 8') && !html.includes(ROOT), 'no PHP version / path leak before unlock');
  t.ok(existsSync(`${ROOT}/storage/install-token.txt`), 'token file created');
});

await t.step('install API is closed until unlocked (403) and the token cannot be guessed (throttled)', async () => {
  const h = { 'Content-Type': 'application/json', 'X-Requested-With': 'smb', Origin: B };
  t.eq((await page.request.post(B + '/install/run', { headers: h, data: {} })).status(), 403);
  t.eq((await page.request.post(B + '/install/test-db', { headers: h, data: {} })).status(), 403);
  let throttled = false;
  for (let i = 0; i < 12 && !throttled; i++) {
    const r = await page.request.post(B + '/install/unlock', { headers: h, data: { token: 'wrong-' + i } });
    throttled = r.status() === 429;
    if (!throttled) t.eq(r.status(), 403);
  }
  t.ok(throttled, 'rate limited after repeated wrong tokens');
});

await t.step('wrong token is refused with a clear message (after the throttle window is cleared)', async () => {
  execFileSync('rm', ['-f', `${ROOT}/storage/cache/install-attempts.json`]);
  await page.goto(B + '/install', { waitUntil: 'networkidle' });
  await page.fill('#token', 'not-the-token');
  await page.click('#unlock-btn');
  await page.waitForSelector('#unlock-form [data-err]');
  t.has(await t.text(page.locator('#unlock-form [data-err]')), 'not correct');
});

await t.step('correct token unlocks: server check lists requirements, all required ones green', async () => {
  await page.fill('#token', token());
  await Promise.all([page.waitForLoadState('networkidle'), page.click('#unlock-btn')]);
  await page.waitForSelector('[data-step="server"]');
  t.ok(await page.locator('.install-health .health-item').count() >= 10, 'requirements listed');
  t.eq(await page.locator('.install-health .health-item.bad').count(), 0, 'nothing red');
  t.eq(await page.locator('[data-next]').isEnabled(), true);
  const c = (await page.context().cookies()).find((x) => x.name === 'smb_install');
  t.ok(c && c.httpOnly && c.sameSite === 'Strict', 'install session cookie is HttpOnly + SameSite=Strict');
  await next();
  t.eq(await step(), 'database');
});

await t.step('database step: empty fields flagged; wrong password and unknown database give friendly errors; correct details pass', async () => {
  await page.click('[data-form="database"] [type="submit"]');
  await page.waitForSelector('[data-form="database"] [data-err]');
  t.ok(await page.locator('[data-form="database"] [data-err]').count() >= 2, 'required errors');
  await page.fill('#db_host', '127.0.0.1'); await page.fill('#db_name', 'smb_install_test'); await page.fill('#db_user', 'smb'); await page.fill('#db_pass', 'wrong-password');
  await page.click('#test-db');
  await page.waitForSelector('#db-result .callout-error');
  t.has(await t.text(page.locator('#db-result')), 'Access denied');
  t.ok(!(await page.content()).includes('wrong-password'), 'password never echoed back');
  await page.fill('#db_pass', 'smb_dev_pw_2026'); await page.fill('#db_name', 'no_such_db_xyz');
  await page.click('#test-db');
  await page.waitForSelector('#db-result .callout-error');
  t.ok(/Access denied|Unknown database/.test(await t.text(page.locator('#db-result'))), await t.text(page.locator('#db-result')));
  await page.fill('#db_name', 'smb_install_test');
  await page.click('#test-db');
  await page.waitForSelector('#db-result .callout-success');
  t.has(await t.text(page.locator('#db-result')), 'Connected');
  await page.click('[data-form="database"] [type="submit"]');
  await page.waitForFunction(() => !document.querySelector('[data-step="site"]').hidden);
});

await t.step('site step: invalid URL / name rejected; sensible defaults prefilled', async () => {
  t.eq(await page.locator('#url').inputValue(), B, 'URL detected from the request');
  await page.fill('#url', 'not a url');
  await page.fill('#site_name', 'X');
  await page.click('[data-form="site"] [type="submit"]');
  await page.waitForSelector('[data-form="site"] [data-err="url"]');
  t.ok(await page.locator('[data-form="site"] [data-err="site_name"]').count() === 1);
  await page.fill('#url', B + '/'); await page.fill('#site_name', 'Shop QA Boost');
  await page.click('[data-form="site"] [type="submit"]');
  await page.waitForFunction(() => !document.querySelector('[data-step="admin"]').hidden);
});

await t.step('admin step: username/email/password policy enforced live and on Continue; strength meter reacts', async () => {
  await page.fill('#admin_username', 'x'); await page.fill('#admin_email', 'nope'); await page.fill('#admin_password', 'weak');
  await page.click('[data-form="admin"] [type="submit"]');
  await page.waitForSelector('[data-form="admin"] [data-err="admin_username"]');
  t.ok(await page.locator('[data-form="admin"] [data-err="admin_email"]').count() === 1);
  t.ok(await page.locator('[data-form="admin"] [data-err="admin_password"]').count() === 1);
  await page.fill('#admin_username', ADMIN.user); await page.fill('#admin_email', ADMIN.email);
  await page.fill('#admin_password', ADMIN.pass); await page.fill('#admin_confirm', ADMIN.pass + 'x');
  t.eq(await page.locator('#pw-meter').getAttribute('data-level'), '4');
  await page.click('[data-form="admin"] [type="submit"]');
  await page.waitForSelector('[data-form="admin"] [data-err="admin_confirm"]');
  await page.fill('#admin_confirm', ADMIN.pass);
  await page.click('[data-form="admin"] [type="submit"]');
  await page.waitForFunction(() => !document.querySelector('[data-step="review"]').hidden);
  const sum = await t.text(page.locator('#summary'));
  t.has(sum, 'Shop QA Boost'); t.has(sum, ADMIN.email);
  t.ok(!sum.includes(ADMIN.pass) && !sum.includes('smb_dev_pw_2026'), 'summary shows no passwords');
});

await t.step('server-side policy has the last word: a password with a common word bounces back to the admin step with the reason', async () => {
  await page.click('#review-back');
  await page.fill('#admin_password', 'Install-Test#Password-77'); await page.fill('#admin_confirm', 'Install-Test#Password-77');
  await page.click('[data-form="admin"] [type="submit"]');
  await page.waitForFunction(() => !document.querySelector('[data-step="review"]').hidden);
  await page.click('#run-install');
  await page.waitForFunction(() => !document.querySelector('[data-step="admin"]').hidden);
  t.has(await t.text(page.locator('[data-form="admin"] [data-err="admin_password"]')), 'common');
  await page.fill('#admin_password', ADMIN.pass); await page.fill('#admin_confirm', ADMIN.pass);
  await page.click('[data-form="admin"] [type="submit"]');
  await page.waitForFunction(() => !document.querySelector('[data-step="review"]').hidden);
  t.ok(!existsSync(`${ROOT}/.env`), 'nothing was written yet');
});

await t.step('Back keeps what was typed; Install runs ONCE even on a double click and finishes with next steps', async () => {
  await page.click('#review-back');
  t.eq(await page.locator('#admin_username').inputValue(), ADMIN.user);
  await page.click('[data-form="admin"] [type="submit"]');
  await page.waitForFunction(() => !document.querySelector('[data-step="review"]').hidden);
  let runs = 0;
  page.on('request', (r) => { if (r.url().endsWith('/install/run') && r.method() === 'POST') runs++; });
  await page.dblclick('#run-install');
  await page.waitForFunction(() => !document.querySelector('[data-step="done"]').hidden, null, { timeout: 90000 });
  t.eq(runs, 1, 'exactly one install request');
  t.has(await t.text(page.locator('#done-list')), 'cron');
  t.has(await t.text(page.locator('#done-list')), 'Payment gateway');
  t.eq(await page.locator('#done-warn').isHidden(), false, 'plain-http warning shown');
});

await t.step('on disk: .env written (0640) with a fresh APP_KEY, lock file present, token file removed, tables + default catalogue created', async () => {
  const env = readFileSync(`${ROOT}/.env`, 'utf8');
  t.has(env, 'APP_ENV=production'); t.has(env, 'DB_NAME="smb_install_test"'); t.has(env, 'APP_URL=http://127.0.0.1:8090');
  t.ok(/APP_KEY=base64:[A-Za-z0-9+/=]{43,}/.test(env), 'APP_KEY generated');
  t.ok(/CRON_KEY=[a-f0-9]{40}/.test(env), 'CRON_KEY generated');
  t.eq((statSync(`${ROOT}/.env`).mode & 0o777).toString(8), '640');
  t.ok(existsSync(`${ROOT}/storage/installed.lock`), 'lock');
  t.ok(!existsSync(`${ROOT}/storage/install-token.txt`), 'token removed');
  const out = execFileSync('mariadb', ['-uroot', '-N', '-D', 'smb_install_test', '-e', "SELECT (SELECT COUNT(*) FROM services), (SELECT COUNT(*) FROM platforms), (SELECT COUNT(*) FROM admins), (SELECT COUNT(*) FROM payment_gateways), (SELECT v FROM admin_settings WHERE k='site.name')"], { encoding: 'utf8' }).toString().trim();
  t.eq(out.split('\t'), ['14', '2', '1', '1', '"Shop QA Boost"']);
  const hash = execFileSync('mariadb', ['-uroot', '-N', '-D', 'smb_install_test', '-e', "SELECT password_hash FROM admins LIMIT 1"], { encoding: 'utf8' }).trim();
  t.ok(/^\$(argon2id|2y)\$/.test(hash) && !hash.includes(ADMIN.pass), 'password stored hashed');
});

await t.step('the installer is gone afterwards: /install, /install/run and /install/unlock are 404', async () => {
  for (const [m, p] of [['GET', '/install'], ['POST', '/install/run'], ['POST', '/install/unlock'], ['POST', '/install/test-db']]) {
    const r = await page.request.fetch(B + p, { method: m, headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'smb', Origin: B }, data: '{}' });
    t.eq(r.status(), 404, `${m} ${p}`);
  }
});

await t.step('the new store works: seeded catalogue shown, custom name used, admin can sign in with the chosen credentials', async () => {
  await page.goto(B + '/', { waitUntil: 'networkidle' });
  t.has(await page.title(), 'Shop QA Boost');
  t.has(await page.content(), 'Shop QA Boost');
  t.ok((await page.locator('[data-service], .svc-card').count()) >= 1 || (await page.content()).includes('Followers'), 'services present');
  await page.goto(B + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('#username', ADMIN.user); await page.fill('#password', ADMIN.pass);
  await Promise.all([page.waitForURL('**/admin/dashboard'), page.click('#login-submit')]);
  await page.waitForSelector('.kpi');
  t.eq(await page.locator('.kpi').count(), 14);
  t.has((await t.text(page.locator('.adm-brand-text'))).toLowerCase(), 'shop qa boost');
});

await t.step('admin sees the first-run notice: payments are not set up (nothing is silently broken)', async () => {
  await page.waitForSelector('#banners .callout');
  t.has(await t.text(page.locator('#banners')), 'Payments are not set up');
});

await t.step('storefront works with no gateway: ordering is refused with a clear message, not a crash', async () => {
  const r = await page.request.post(B + '/api/checkout', { headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'smb', Origin: B, 'Idempotency-Key': 'inst-checkout-0001' }, data: { service_id: 1, target: '@someone' } });
  t.ok([409, 422, 503].includes(r.status()), 'status ' + r.status());
  t.ok((await r.json()).error.message.length > 10, 'friendly message');
});

console.log('console/network problems:', page.problems.filter((p) => !/status of (403|404|422|429)/.test(p) && !/\[http (403|404|422|429)\]/.test(p)).join('\n') || 'none');
await browser.close();
execFileSync('bash', ['tools/qa/install-env.sh', 'stop'], { cwd: '/home/user/claude1', stdio: 'ignore' });
process.exit(t.finish() ? 1 : 0);
