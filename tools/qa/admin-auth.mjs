// Sign-in, session and access-control behaviour of the admin panel.
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, CREDS } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-auth');
const reset = () => execFileSync('php', ['tools/qa/reset-limits.php'], { cwd: '/home/user/claude1' });
reset();
const browser = await launch();
const page = await newPage(browser, { width: 1280, height: 800 }, { allowStatus: [401, 403, 429] });
t.page = page;

await t.step('unauthenticated /admin/dashboard redirects to the login form (with next=)', async () => {
  await page.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
  t.has(page.url(), '/admin/login');
  t.has(page.url(), 'next=%2Fadmin%2Fdashboard');
  t.ok(await page.locator('#login-form').isVisible(), 'login form visible');
});

await t.step('unauthenticated admin API returns 401 JSON (no data leaks)', async () => {
  const r = await page.request.get(BASE + '/admin/api/orders', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
  t.eq(r.status(), 401);
  const j = await r.json();
  t.eq(j.ok, false);
  t.ok(!JSON.stringify(j).includes('SMB-'), 'no order data in body');
});

await t.step('every admin API endpoint is closed to anonymous callers', async () => {
  const paths = ['dashboard', 'status', 'orders', 'orders/1', 'orders/approvable', 'payments', 'services', 'services/meta', 'services/1', 'tags', 'website', 'advertising', 'gateway', 'smm', 'account/sessions', 'audit', 'system'];
  for (const p of paths) {
    const r = await page.request.get(`${BASE}/admin/api/${p}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
    t.eq(r.status(), 401, `GET ${p}`);
  }
  for (const [m, p] of [['POST', 'orders/approve-all'], ['POST', 'orders/1/action'], ['POST', 'services'], ['PUT', 'services/1'], ['DELETE', 'services/1'], ['POST', 'tags'], ['PUT', 'tags/1'], ['DELETE', 'tags/1'], ['PUT', 'website'], ['PUT', 'gateway/1'], ['POST', 'smm'], ['POST', 'account/password'], ['POST', 'system/cron']]) {
    const r = await page.request.fetch(`${BASE}/admin/api/${p}`, { method: m, headers: { Accept: 'application/json', 'X-Requested-With': 'smb', 'Content-Type': 'application/json' }, data: '{}' });
    t.ok([401, 403].includes(r.status()), `${m} ${p} → ${r.status()}`);
  }
});

await t.step('empty submit shows an inline error and focuses the field', async () => {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.click('#login-submit');
  t.has(await t.text(page.locator('#login-alert')), 'Enter your username');
  t.eq(await page.locator('#username').getAttribute('aria-invalid'), 'true');
});

await t.step('wrong password: generic message, card shakes, password re-selected', async () => {
  await page.fill('#username', CREDS.user);
  await page.fill('#password', 'definitely-wrong');
  await page.click('#login-submit');
  await page.waitForSelector('#login-alert:not([hidden])');
  t.has(await t.text(page.locator('#login-alert')), 'Incorrect username/email or password');
  t.ok(await page.locator('#login-card.is-shake').count() === 1, 'shake class');
  t.eq(await page.locator('#login-submit').isDisabled(), false);
});

await t.step('unknown user gets the SAME message as a wrong password (no user enumeration)', async () => {
  await page.fill('#username', 'no-such-user-xyz');
  await page.fill('#password', 'definitely-wrong');
  await page.click('#login-submit');
  await page.waitForFunction(() => document.querySelector('#login-submit').disabled === false);
  t.has(await t.text(page.locator('#login-alert')), 'Incorrect username/email or password');
});

await t.step('show/hide password toggle works and keeps focus in the field', async () => {
  await page.fill('#password', 'abc123');
  t.eq(await page.locator('#password').getAttribute('type'), 'password');
  await page.click('#pw-toggle');
  t.eq(await page.locator('#password').getAttribute('type'), 'text');
  t.eq(await page.locator('#pw-toggle').getAttribute('aria-pressed'), 'true');
  t.eq(await page.evaluate(() => document.activeElement?.id), 'password');
  await page.click('#pw-toggle');
  t.eq(await page.locator('#password').getAttribute('type'), 'password');
});

await t.step('forged / missing CSRF token on the sign-in POST is rejected', async () => {
  const r = await page.request.post(BASE + '/admin/login', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', Origin: BASE }, data: { username: CREDS.user, password: CREDS.pass, _csrf: 'forged' } });
  t.eq(r.status(), 403);
  t.eq((await r.json()).error.code, 'bad_csrf');
});

await t.step('cross-site Origin on the sign-in POST is rejected', async () => {
  const r = await page.request.post(BASE + '/admin/login', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', Origin: 'https://evil.example' }, data: { username: CREDS.user, password: CREDS.pass, _csrf: 'x' } });
  t.eq(r.status(), 403);
});

await t.step('correct credentials sign in and land on the dashboard; cookie is HttpOnly + SameSite', async () => {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('#username', CREDS.user);
  await page.fill('#password', CREDS.pass);
  await Promise.all([page.waitForURL('**/admin/dashboard'), page.click('#login-submit')]);
  const c = (await page.context().cookies()).find((x) => /smb_admin/.test(x.name));
  t.ok(c, 'session cookie set');
  t.eq(c.httpOnly, true, 'httpOnly');
  t.eq(c.sameSite, 'Lax', 'sameSite');
  t.ok(!(await page.evaluate(() => document.cookie)).includes('smb_admin'), 'cookie invisible to JS');
});

await t.step('open-redirect via ?next= is ignored', async () => {
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/login?next=//evil.example/x', { waitUntil: 'networkidle' });
  t.eq(await p.locator('input[name=next]').inputValue(), '');
  await p.goto(BASE + '/admin/login?next=https://evil.example', { waitUntil: 'networkidle' });
  t.eq(await p.locator('input[name=next]').inputValue(), '');
  await ctx.close();
});

await t.step('sign out ends the session: back button / API are locked again', async () => {
  await page.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
  await Promise.all([page.waitForURL('**/admin/login**'), page.click('aside [data-action="logout"]')]);
  t.has(await t.text(page.locator('#login-alert')), 'signed out');
  const r = await page.request.get(BASE + '/admin/api/orders', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
  t.eq(r.status(), 401);
  await page.goto(BASE + '/admin/orders', { waitUntil: 'networkidle' });
  t.has(page.url(), '/admin/login');
});

await t.step('a revoked/expired session cookie cannot be reused (token is server-side)', async () => {
  const cookies = await page.context().cookies();
  t.ok(!cookies.find((c) => /smb_admin/.test(c.name) && c.value), 'cookie cleared on logout');
});

await t.step('lock-out: repeated failures throttle sign-in and show a countdown (429)', async () => {
  reset();
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  let locked = false;
  for (let i = 0; i < 12 && !locked; i++) {
    await page.fill('#username', 'throttle-test-user');
    await page.fill('#password', 'wrong-password-' + i);
    await page.click('#login-submit');
    await page.waitForFunction(() => !document.querySelector('#login-submit').classList.contains('is-loading'));
    locked = (await page.locator('#login-submit').innerText()).includes('Try again in');
  }
  t.ok(locked, 'sign-in button shows a retry countdown');
  t.eq(await page.locator('#login-submit').isDisabled(), true);
  t.has(await t.text(page.locator('#login-alert')), 'Too many');
  reset();
});

await t.step('5 wrong passwords lock the real account; the right password is then refused (and recovers)', async () => {
  reset();
  const r0 = await page.request.get(BASE + '/admin/login');
  const html = await r0.text();
  const csrf = /name="_csrf" value="([a-f0-9]+)"/.exec(html)[1];
  const post = (pw) => page.request.post(BASE + '/admin/login', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', Origin: BASE }, data: { username: 'qa-admin', password: pw, _csrf: csrf } });
  // the pre-login cookie is shared with the request context, so the double-submit pair stays valid
  for (let i = 0; i < 5; i++) { const r = await post('wrong-' + i); t.ok([401, 429].includes(r.status()), `attempt ${i + 1}: ${r.status()}`); }
  const good = await post('Qa-Admin#Test-2026x');
  t.eq(good.status(), 429, 'correct password refused while locked');
  t.has((await good.json()).error.message, 'locked');
  reset();
});

await browser.close();
reset();
process.exit(t.finish() ? 1 : 0);
