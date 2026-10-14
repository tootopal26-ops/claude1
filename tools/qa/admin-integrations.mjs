// Gateway, SMM provider, advertising, security (password/sessions/forced change), audit log and system pages.
import { execFileSync } from 'node:child_process';
import { writeFileSync, unlinkSync } from 'node:fs';
import { launch, newPage, login, go, BASE } from './admin.mjs';
import { sql } from './site.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-integrations');
const rawStep = t.step.bind(t);
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: '/home/user/claude1', stdio: 'ignore' });
const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [401, 403, 404, 409, 422, 429, 503] });
t.page = page;
await login(page);
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((n) => n.remove()));
const closeAll = async () => { for (let i = 0; i < 4; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
t.step = async (title, fn) => rawStep(title, async () => { await closeAll(); await clearToasts(); await fn(); });
const toast = (type) => page.locator(`.toast[data-type="${type}"]`).last();
const csrf = async () => page.locator('meta[name=csrf-token]').getAttribute('content');
const apiH = async () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': await csrf(), Origin: BASE });
const php = (code) => execFileSync('php', ['-r', `require "app/bootstrap.php"; ${code}`], { cwd: '/home/user/claude1' }).toString();

// ───────────────────────────── GATEWAY ─────────────────────────────
await go(page, '/admin/gateway');

await t.step('gateway: secrets are masked everywhere (page, JSON, HTML source) and the webhook URL is shown', async () => {
  const html = await page.content();
  const json = JSON.stringify(await (await page.request.get(BASE + '/admin/api/gateway', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } })).json());
  for (const s of ['test-api-key-123', 'test-webhook-secret-456']) { t.ok(!html.includes(s), 'HTML leaks ' + s); t.ok(!json.includes(s), 'JSON leaks ' + s); }
  t.has(await t.text(page.locator('[data-secret="api_key"]')), 'Saved and encrypted');
  t.eq(await page.locator('#wh-url').inputValue(), `${BASE}/webhooks/payments/1`);
});

await t.step('gateway: Test connection against the (mock) gateway reports success with timing', async () => {
  await page.click('#gw-test');
  await page.waitForSelector('#gw-result .callout');
  const r = await t.text(page.locator('#gw-result'));
  t.ok(/ms/.test(r), r);
  t.ok(await page.locator('#gw-result .callout-success, #gw-result .callout-warning').count() === 1, r);
});

await t.step('gateway: invalid base URL and too-short key are refused with field errors', async () => {
  await page.fill('#gw-url', 'ftp://nope.example');
  await page.click('[data-secret-replace="api_key"]');
  await page.fill('#s-api_key', 'short');
  await page.click('#gw-save');
  await page.waitForSelector('#gw-form [data-err="base_url"]');
  t.ok(await page.locator('#gw-form [data-err="api_key"]').count() === 1, 'api_key error');
  await page.reload({ waitUntil: 'networkidle' });
});

await t.step('gateway: Copy button copies the webhook URL; environment switch persists; key can be replaced (hint changes)', async () => {
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  await page.click('[data-copy-url]');
  t.eq(await page.evaluate(() => navigator.clipboard.readText()), `${BASE}/webhooks/payments/1`);
  await page.click('[data-env="production"]');
  await page.click('[data-secret-replace="api_key"]');
  await page.fill('#s-api_key', 'rotated-key-ABCDEFGH-789');
  await page.click('#gw-save');
  await toast('success').waitFor();
  t.has(await t.text(page.locator('.chip:has-text("Production")')), 'Production');
  t.has(await t.text(page.locator('[data-secret="api_key"]')), '789');
  // restore for the other suites
  await clearToasts();
  await page.click('[data-env="test"]');
  await page.click('[data-secret-replace="api_key"]');
  await page.fill('#s-api_key', 'test-api-key-123');
  await page.click('#gw-save');
  await toast('success').waitFor();
});

await t.step('gateway: "Keep the saved value" cancels a secret replacement', async () => {
  await page.click('[data-secret-replace="webhook_secret"]');
  await page.fill('#s-webhook_secret', 'typed-but-cancelled-123');
  await page.click('[data-secret-cancel="webhook_secret"]');
  t.ok(await page.locator('[data-secret="webhook_secret"] [data-secret-set]').isVisible(), 'saved row back');
  t.eq(await page.locator('#s-webhook_secret').inputValue(), '');
});

await t.step('gateway: deactivating it shows a "payments not set up" banner and the checkout API refuses; reactivating restores', async () => {
  await page.locator('[name="is_active"]').locator('xpath=..').click();
  await page.click('#gw-save');
  await toast('success').waitFor();
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForSelector('#banners .callout-error');
  t.has(await t.text(page.locator('#banners')), 'Payments are not set up');
  const r = await page.request.post(BASE + '/api/checkout', { headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'smb', Origin: BASE, 'Idempotency-Key': 'qa-checkout-0001' }, data: { service_id: 1, target: '@someone' } });
  t.ok([409, 422, 503].includes(r.status()), 'checkout refused: ' + r.status());
  await page.locator('[name="is_active"]').locator('xpath=..').click();
  await clearToasts();
  await page.click('#gw-save');
  await toast('success').waitFor();
});

// ───────────────────────────── SMM ─────────────────────────────
await go(page, '/admin/smm');

await t.step('smm: API key is never present in the page or JSON; TEST CONNECTION succeeds and shows the balance', async () => {
  const html = await page.content();
  const json = JSON.stringify(await (await page.request.get(BASE + '/admin/api/smm', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } })).json());
  t.ok(!html.includes('smm-key-ok') && !json.includes('smm-key-ok'), 'key leaked');
  await page.click('[data-test]');
  await page.waitForSelector('[data-test-result] .callout');
  const r = await t.text(page.locator('[data-test-result]'));
  t.has(r, 'Connected');
  t.has(await t.text(page.locator('.dl')), 'Passed');
});

await t.step('smm: add provider — empty/invalid input is refused; wrong key saves but its test fails clearly', async () => {
  await page.click('#smm-root ~ * [data-add], [data-add]');
  await page.waitForSelector('#prov-dialog[open]');
  await page.click('#pv-save');
  await page.waitForSelector('#prov-form [data-err]');
  for (const f of ['name', 'api_url', 'api_key']) t.ok(await page.locator(`#prov-form [data-err="${f}"]`).count() === 1, f);
  await page.fill('#pv-name', 'QA Bad Key Panel');
  await page.fill('#pv-url', 'ftp://panel.example/api');
  await page.fill('#s-api_key', 'wrong-key-123');
  await page.click('#pv-save');
  await page.waitForSelector('#prov-form [data-err="api_url"]');
  await page.fill('#pv-url', 'http://127.0.0.1:4020/api/v2');
  await page.fill('#pv-cur', 'USD');
  await page.fill('#pv-rate', '-3');
  await page.click('#pv-save');
  await page.waitForSelector('#prov-form [data-err="rate_to_inr"]');
  await page.fill('#pv-rate', '83.25');
  await clearToasts();
  await page.click('#pv-save');
  await toast('success').waitFor();
  t.eq(await page.locator('[data-provider]').count(), 2);
  await clearToasts();
  await page.locator('[data-provider]').nth(1).locator('[data-test]').click();
  await page.waitForSelector('[data-provider]:nth-child(2) [data-test-result] .callout-error, [data-test-result] .callout-error');
  t.ok((await t.text(page.locator('[data-test-result] .callout-error').first())).length > 10, 'failure explained');
});

await t.step('smm: edit keeps the stored key when the field is left blank; Replace stores a new one', async () => {
  await page.locator('[data-provider]').nth(1).locator('[data-edit]').click();
  await page.waitForSelector('#prov-dialog[open]');
  t.has(await t.text(page.locator('#prov-form [data-secret="api_key"]')), 'Saved and encrypted');
  await page.fill('#pv-name', 'QA Panel Renamed');
  await page.click('#pv-save');
  await toast('success').waitFor();
  t.has(await t.text(page.locator('#smm-root')), 'QA Panel Renamed');
  t.has(await t.text(page.locator('[data-provider]').nth(1)), '-123');   // key hint unchanged (…wrong-key-123)
  await clearToasts();
  await page.locator('[data-provider]').nth(1).locator('[data-edit]').click();
  await page.click('[data-secret-replace="api_key"]');
  await page.fill('#s-api_key', 'smm-key-ok');
  await page.click('#pv-save');
  await toast('success').waitFor();
  await clearToasts();
  await page.locator('[data-provider]').nth(1).locator('[data-test]').click();
  await page.waitForSelector('[data-provider]:nth-child(2) [data-test-result] .callout-success');
});

await t.step('smm: a provider used by services cannot be removed; an unused one can (with confirmation)', async () => {
  await page.locator('[data-provider]').first().locator('[data-remove]').click();
  await page.waitForSelector('dialog.dlg[open] [data-act="yes"]');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await toast('error').waitFor();
  t.has(await t.text(toast('error')), 'still use');
  await clearToasts();
  await page.locator('[data-provider]').nth(1).locator('[data-remove]').click();
  await page.waitForSelector('dialog.dlg[open] [data-act="yes"]');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await toast('success').waitFor();
  t.eq(await page.locator('[data-provider]').count(), 1);
});

// ───────────────────────────── ADVERTISING ─────────────────────────────
await go(page, '/admin/advertising');

await t.step('advertising: CPC validation (letters, 3 decimals, negative, huge), valid value saves and lands in history', async () => {
  t.eq(await page.locator('#ad-cpc').inputValue(), '1.08');
  for (const bad of ['abc', '1.234', '-1', '1001', '']) {
    await page.fill('#ad-cpc', bad);
    await page.click('#ad-save');
    await page.waitForSelector('#ads-form [data-err="cpc"]');
    await clearToasts();
  }
  await page.fill('#ad-cpc', '1.25');
  await page.fill('#ad-secs', '0');
  await page.click('#ad-save');
  await page.waitForSelector('#ads-form [data-err="qualify_seconds"]');
  await page.fill('#ad-secs', '3');
  await page.fill('#ad-conv', '250');
  await page.click('#ad-save');
  await page.waitForSelector('#ads-form [data-err="assumed_conversion"]');
  await page.fill('#ad-conv', '4');
  await clearToasts();
  await page.click('#ad-save');
  await toast('success').waitFor();
  t.has(await t.text(page.locator('#advertising-root .tbl')), '₹1.25');
  const d = await (await page.request.get(BASE + '/admin/api/dashboard?range=today', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } })).json();
  t.eq(d.dashboard.meta.cpc_paise, 125);
});

await t.step('advertising: changing the CPC does NOT rewrite past ad spend (history keeps its own CPC)', async () => {
  const q = async (c) => (await (await page.request.get(BASE + '/admin/api/dashboard?range=lifetime', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } })).json()).dashboard.totals.ads_spend_paise;
  const before = await q();
  await page.fill('#ad-cpc', '1.08');
  await page.click('#ad-save');
  await toast('success').waitFor();
  t.eq(await q(), before, 'ads spend for past days unchanged');
});

// ───────────────────────────── AUDIT & SYSTEM ─────────────────────────────
await go(page, '/admin/audit');

await t.step('audit: category chips and search filter the entries; pager works; no secrets in any row', async () => {
  const total = Number(/of (\d+)/.exec(await t.text(page.locator('.pager')))?.[1] || 0);
  t.ok(total > 5, 'entries: ' + total);
  await Promise.all([page.waitForResponse((r) => r.url().includes('group=settings')), page.click('[data-group="settings"]')]);
  await page.waitForFunction(() => document.querySelector('#audit-root').getAttribute('aria-busy') === 'false');
  const labels = await page.locator('#audit-root tbody tr td:nth-child(3)').allInnerTexts();
  t.ok(labels.length > 0 && labels.every((l) => /(settings|smm|gateway|upload)/i.test(l)), labels.slice(0, 4).join('|'));
  await Promise.all([page.waitForResponse((r) => r.url().includes('q=gateway')), page.fill('#a-search', 'gateway')]);
  await page.waitForFunction(() => document.querySelector('#audit-root').getAttribute('aria-busy') === 'false');
  t.ok(await page.locator('#audit-root tbody tr').count() >= 1, 'search hit');
  await page.fill('#a-search', 'zzzzzz-none');
  await page.waitForSelector('#audit-root .empty');
  const all = JSON.stringify(await (await page.request.get(BASE + '/admin/api/audit?page=1', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } })).json());
  for (const s of ['smm-key-ok', 'wrong-key-123', 'rotated-key', 'test-api-key-123', 'Sm8!Qx', 'wrong-password']) t.ok(!all.includes(s), 'leak: ' + s);
});

await go(page, '/admin/system');

await t.step('system: health shows PHP/DB/extensions; "Run background tasks now" runs cron and the banner clears', async () => {
  t.has(await t.text(page.locator('.health')), 'PHP 8');
  await page.click('#run-cron');
  await toast('success').waitFor();
  t.has(await t.text(page.locator('.chip:has-text("Last run")')), 'just now');
  await page.reload({ waitUntil: 'networkidle' });
  t.ok(await page.locator('#banners:has-text("Background tasks are not running")').count() === 0, 'cron banner gone');
});

await t.step('system: "Database updates" — up to date; a pending migration raises a banner and "Apply database updates" applies it exactly once', async () => {
  const card = page.locator('[aria-labelledby="h-db"]');
  t.has((await t.text(card)).toLowerCase(), 'database updates'); t.has(await t.text(card.locator('.chip')), 'Up to date');
  t.eq(await page.locator('#run-migrate').isDisabled(), true, 'nothing to apply → button disabled');
  const file = '/home/user/claude1/database/migrations/998_qa_tmp.sql';
  writeFileSync(file, 'CREATE TABLE IF NOT EXISTS qa_tmp_migration2 (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB;\n');
  try {
    await page.reload({ waitUntil: 'networkidle' });
    t.has(await t.text(page.locator('#banners')), 'database update is waiting');
    t.has(await t.text(card.locator('.chip')), '1 pending'); t.has(await t.text(card), '998_qa_tmp');
    await page.click('#run-migrate'); await toast('success').waitFor();
    t.has(await t.text(card.locator('.chip')), 'Up to date');
    t.eq(sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'smb_dev' AND TABLE_NAME = 'qa_tmp_migration2'"), '1', 'the migration ran');
    t.eq(sql("SELECT COUNT(*) FROM audit_logs WHERE action = 'system.migrated'"), '1', 'and was audited');
    await page.reload({ waitUntil: 'networkidle' });
    t.eq(await page.locator('#banners:has-text("database update is waiting")').count(), 0, 'banner gone');
  } finally {
    try { unlinkSync(file); } catch { /* already gone */ }
    sql('DROP TABLE IF EXISTS qa_tmp_migration2'); sql("DELETE FROM schema_migrations WHERE version = '998_qa_tmp'");
  }
});

await t.step('system: the cron HTTP endpoint needs the key (403 without / with a wrong key)', async () => {
  t.eq((await page.request.get(BASE + '/cron/run')).status(), 403);
  t.eq((await page.request.get(BASE + '/cron/run?key=wrong')).status(), 403);
});

// ───────────────────────────── SECURITY: sessions & password ─────────────────────────────
const QA = { user: 'qa-admin', pass: 'Qa-Admin#Test-2026x', next: 'Fresh-Passphrase#9981-QA' };
const signIn = async (ctx, pw = QA.pass) => {
  const p = await ctx.newPage();
  await p.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await p.fill('#username', QA.user); await p.fill('#password', pw);
  await Promise.all([p.waitForURL(/admin\/(dashboard|security)/, { timeout: 15000 }), p.click('#login-submit')]);
  return p;
};
const ctxA = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const ctxB = await browser.newContext({ viewport: { width: 1280, height: 800 } });
let A, B;

await t.step('sessions: a second sign-in appears in Active sessions; revoking it signs that browser out on its next request', async () => {
  A = await signIn(ctxA); B = await signIn(ctxB);
  await A.goto(BASE + '/admin/security', { waitUntil: 'networkidle' });
  await A.waitForSelector('#sessions-box li');
  t.eq(await A.locator('#sessions-box li').count(), 2);
  t.ok(await A.locator('#sessions-box li:has-text("This device")').count() === 1, 'current marked');
  await A.locator('#sessions-box [data-revoke]').first().click();
  await A.waitForFunction(() => document.querySelectorAll('#sessions-box li').length === 1);
  const r = await B.request.get(BASE + '/admin/api/orders', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
  t.eq(r.status(), 401);
  await B.reload({ waitUntil: 'networkidle' });
  t.has(B.url(), '/admin/login');
});

await t.step('password change: wrong current / weak / mismatch are refused; success signs out other devices and rotates the credential', async () => {
  B = await signIn(ctxB);
  await A.goto(BASE + '/admin/security', { waitUntil: 'networkidle' });
  await A.fill('#pw-current', 'not-my-password'); await A.fill('#pw-new', QA.next); await A.fill('#pw-confirm', QA.next);
  await A.click('#pw-save');
  await A.waitForSelector('#pw-form [data-err]');
  // weak password blocked client-side with the requirement list lit up
  await A.fill('#pw-current', QA.pass); await A.fill('#pw-new', 'short'); await A.fill('#pw-confirm', 'short');
  await A.click('#pw-save');
  await A.waitForSelector('#pw-form [data-err="new"]');
  t.eq(await A.locator('#pw-rules li.is-ok').count(), 2, 'only "lowercase" and "both match" are satisfied');
  await A.fill('#pw-new', QA.next); await A.fill('#pw-confirm', QA.next + 'x');
  await A.click('#pw-save');
  await A.waitForSelector('#pw-form [data-err="confirm"]');
  await A.fill('#pw-confirm', QA.next);
  t.eq(await A.locator('#pw-meter').getAttribute('data-level'), '4', 'strong meter');
  await A.click('#pw-save');
  await A.locator('.toast[data-type="success"]').first().waitFor();
  const r = await B.request.get(BASE + '/admin/api/orders', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
  t.eq(r.status(), 401, 'other device signed out');
  const ok = await A.request.get(BASE + '/admin/api/status', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
  t.eq(ok.status(), 200, 'this device stays signed in');
  // old password no longer works, new one does
  const ctxC = await browser.newContext();
  const c = await ctxC.newPage();
  await c.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await c.fill('#username', QA.user); await c.fill('#password', QA.pass); await c.click('#login-submit');
  await c.waitForSelector('#login-alert:not([hidden])');
  t.has(await c.locator('#login-alert').innerText(), 'Incorrect');
  await c.fill('#password', QA.next);
  await Promise.all([c.waitForURL('**/admin/dashboard'), c.click('#login-submit')]);
  await ctxC.close();
});

await t.step('password change API: needs CSRF (403 without it) and enforces the policy server-side', async () => {
  const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', Origin: BASE };
  let r = await A.request.post(BASE + '/admin/api/account/password', { headers: h, data: { current: QA.next, new: 'Another-Good#Pass-7712', confirm: 'Another-Good#Pass-7712' } });
  t.eq(r.status(), 403, 'no CSRF token');
  const token = await A.locator('meta[name=csrf-token]').getAttribute('content');
  r = await A.request.post(BASE + '/admin/api/account/password', { headers: { ...h, 'X-CSRF-Token': token }, data: { current: QA.next, new: 'password1234', confirm: 'password1234' } });
  t.eq(r.status(), 422, 'weak password refused by the server');
});

await t.step('forced password change: a temporary password locks every page/API except Security until it is changed', async () => {
  php('App\\Core\\Db::exec("UPDATE admins SET must_change_password = 1 WHERE username = \'qa-admin\'");');
  await ctxA.clearCookies();
  const p = await signIn(ctxA, QA.next);
  t.has(p.url(), '/admin/security');
  t.has(await p.locator('.callout-error').first().innerText(), 'Choose a new password');
  await p.goto(BASE + '/admin/orders', { waitUntil: 'networkidle' });
  t.has(p.url(), '/admin/security?force=1');
  const r = await p.request.get(BASE + '/admin/api/orders', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
  t.eq(r.status(), 403);
  t.eq((await r.json()).error.code, 'password_change_required');
  await p.fill('#pw-current', QA.next); await p.fill('#pw-new', 'Third-Strong#Pass-5531'); await p.fill('#pw-confirm', 'Third-Strong#Pass-5531');
  await p.click('#pw-save');
  await p.waitForURL('**/admin/dashboard', { timeout: 15000 });
});

await ctxA.close(); await ctxB.close();
console.log('console/network problems:', page.problems.filter((p) => !/status of (401|403|404|409|422|429|503)/.test(p) && !/\[http (401|403|404|409|422|429|503)\]/.test(p)).join('\n') || 'none');
await browser.close();
execFileSync('php', ['tools/qa/qa-admin-account.php'], { cwd: '/home/user/claude1', stdio: 'ignore' });
process.exit(t.finish() ? 1 : 0);
