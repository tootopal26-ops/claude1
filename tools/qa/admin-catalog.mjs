// Services editor + Website settings (+ what the storefront shows afterwards).
import { execFileSync } from 'node:child_process';
import { launch, newPage, login, go, BASE } from './admin.mjs';
import { suite } from './harness.mjs';

const A = '/tmp/qa/assets';
const t = suite('admin-catalog');
const rawStep = t.step.bind(t);
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: '/home/user/claude1', stdio: 'ignore' });
const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [409, 422] });
t.page = page;
await login(page);
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((n) => n.remove()));
const closeAll = async () => { for (let i = 0; i < 4; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
t.step = async (title, fn) => rawStep(title, async () => { await closeAll(); await clearToasts(); await fn(); });
const toast = (type) => page.locator(`.toast[data-type="${type}"]`).last();
const store = async () => (await page.request.get(BASE + '/', { headers: { 'Cache-Control': 'no-cache' } })).text();
const idle = () => page.waitForFunction(() => document.querySelector('#services-root')?.getAttribute('aria-busy') === 'false');
const rowFor = (title) => page.locator('#services-root tbody tr').filter({ hasText: title });

// ───────────────────────────── SERVICES ─────────────────────────────
await go(page, '/admin/services');

await t.step('services list: 14 seeded services in two platform groups, each with Edit / Delete / switch', async () => {
  t.eq(await page.locator('#services-root tbody tr').count(), 14);
  t.eq(await page.locator('#services-root section').count(), 2);
  t.eq(await page.locator('[data-edit]').count(), 14);
  t.eq(await page.locator('[data-toggle]').count(), 14);
  t.has(await t.text(page.locator('#services-root')), 'TRIAL PACK');
});

await t.step('active switch hides a service from the storefront immediately, and shows it again', async () => {
  t.has(await store(), 'Permanent Blue Tick');
  const row = rowFor('Permanent Blue Tick');
  await row.locator('.switch').click();
  await toast('success').waitFor();
  await idle();
  t.ok(!(await store()).includes('Permanent Blue Tick'), 'hidden on the store');
  await rowFor('Permanent Blue Tick').locator('.switch').click();
  await toast('success').waitFor();
  await idle();
  t.has(await store(), 'Permanent Blue Tick');
});

await t.step('editor opens with the service data; ESC closes it', async () => {
  await rowFor('100 Likes').first().locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  t.eq(await page.locator('#f-code').inputValue(), 'ig-likes-100');
  t.eq(await page.locator('#f-price').inputValue(), '5');
  t.ok(await page.locator('#profit-preview').isVisible(), 'profit preview');
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('#service-drawer[open]'));
});

await t.step('price validation: letters / 3 decimals / zero are rejected with field errors; valid price saves and shows on the storefront', async () => {
  await rowFor('100 Likes').first().locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  for (const bad of ['abc', '5.999', '0', '-4', '100001']) {
    await page.fill('#f-price', bad);
    await page.click('#svc-save');
    await page.waitForSelector('#svc-form [data-err="price"]');
    t.eq(await page.locator('#f-price').getAttribute('aria-invalid'), 'true', bad);
    await clearToasts();
  }
  await page.fill('#f-price', '7.5');
  t.eq(await page.locator('#f-price').getAttribute('aria-invalid'), null, 'error clears while typing');
  await page.click('#svc-save');
  await toast('success').waitFor();
  await idle();
  t.has(await t.text(rowFor('100 Likes').first()), '₹7.50');
  t.has(await store(), '₹7.50');
  // history + audit
  await rowFor('100 Likes').first().locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  t.has(await t.text(page.locator('#sd-body')), 'Price history');
  await clearToasts();
  await page.fill('#f-price', '5');
  await page.click('#svc-save');
  await toast('success').waitFor();
  await idle();
  t.has(await t.text(rowFor('100 Likes').first()), '₹5 ');
});

await t.step('profit preview updates live: AUTO shows provider cost; MANUAL ignores it', async () => {
  await rowFor('100 Likes').first().locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  await page.click('[data-mode="MANUAL"]');
  await page.fill('#f-price', '100');
  const manual = await t.text(page.locator('#profit-preview'));
  t.has(manual, 'not counted');
  t.has(manual, '₹100');
  await page.click('[data-mode="AUTO"]');
  await page.fill('#f-smm_cost', '40');
  const auto = await t.text(page.locator('#profit-preview'));
  t.has(auto, '₹60');
  await page.fill('#f-smm_cost', '150');
  t.ok(await page.locator('#profit-preview.is-neg').count() === 1, 'negative profit is flagged');
  await page.keyboard.press('Escape');
});

await t.step('create service: empty form lists errors; AUTO needs an SMM ID; duplicate code is refused; valid MANUAL service appears on the store', async () => {
  await page.click('#add-service');
  await page.waitForSelector('#service-drawer[open] #svc-form');
  await page.click('#svc-save');
  await page.waitForSelector('#svc-form [data-err]');
  for (const f of ['title', 'price', 'quantity', 'code']) t.ok(await page.locator(`#svc-form [data-err="${f}"]`).count() === 1, f + ' error');
  await clearToasts();
  await page.fill('#f-title', 'QA 777 Followers');
  await page.fill('#f-price', '12');
  await page.fill('#f-quantity', '777');
  t.eq(await page.locator('#f-code').inputValue(), 'ig-followers-777', 'code suggested from platform/type/quantity');
  await page.click('[data-mode="AUTO"]');
  await page.click('#svc-save');
  await page.waitForSelector('#svc-form [data-err="smm_service_id"]');
  await clearToasts();
  await page.fill('#f-code', 'ig-likes-100');
  await page.click('[data-mode="MANUAL"]');
  await page.click('#svc-save');
  await page.waitForSelector('#svc-form [data-err="code"]');
  t.has(await t.text(page.locator('#svc-form [data-err="code"]')), 'already used');
  await clearToasts();
  await page.fill('#f-code', 'qa-followers-777');
  await page.click('#svc-save');
  await toast('success').waitFor();
  await idle();
  t.ok(await rowFor('QA 777 Followers').count() === 1, 'listed');
  t.has(await store(), 'QA 777 Followers');
});

await t.step('provider service lookup fills the SMM ID and an estimated cost (AUTO)', async () => {
  await rowFor('QA 777 Followers').locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  await page.click('[data-mode="AUTO"]');
  await page.fill('#svc-search', 'follow');
  await page.waitForSelector('#svc-results .combo-item[data-pick]');
  const first = page.locator('#svc-results [data-pick]').first();
  const text = await t.text(first);
  t.ok(/#\d+/.test(text), text);
  await first.click();
  t.ok((await page.locator('#f-smm_service_id').inputValue()).length > 0, 'ID filled');
  t.ok((await page.locator('#f-smm_cost').inputValue()).length > 0, 'cost filled');
  await page.click('#svc-save');
  await toast('success').waitFor();
  await idle();
  t.has(await t.text(rowFor('QA 777 Followers')), 'AUTO');
});

await t.step('icon upload: PNG accepted (re-encoded to WebP, random name); SVG / fake PNG / polyglot / oversize / bomb refused', async () => {
  await rowFor('QA 777 Followers').locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  await page.setInputFiles('#icon-file', `${A}/logo.png`);
  await page.waitForSelector('#icon-field img[src*="/uploads/services/"]');
  const src = await page.locator('#icon-field img').getAttribute('src');
  t.ok(/\/uploads\/services\/[a-f0-9]{20}\.(webp|png)/.test(src), src);
  const res = await page.request.get(BASE + src.split('?')[0]);
  t.eq(res.status(), 200);
  t.ok(/image\/(webp|png)/.test(res.headers()['content-type']), res.headers()['content-type']);
  const body = await res.body();
  t.ok(!body.includes(Buffer.from('<?php')), 'no php payload');
  for (const [file, why] of [['evil.svg', 'svg'], ['fake.png', 'fake image'], ['poly.png', 'polyglot (valid image + php) is re-encoded'], ['bomb.png', 'bomb']]) {
    await clearToasts();
    await page.setInputFiles('#icon-file', `${A}/${file}`);
    if (file === 'poly.png') { await toast('success').waitFor(); const s2 = await page.locator('#icon-field img').getAttribute('src'); const b2 = await (await page.request.get(BASE + s2.split('?')[0])).body(); t.ok(!b2.includes(Buffer.from('<?php')), 'polyglot payload stripped'); }
    else { await toast('error').waitFor(); }
  }
  await clearToasts();
  await page.setInputFiles('#icon-file', `${A}/big.png`);
  await toast('error').waitFor();
  t.has(await t.text(toast('error')), 'too large');
  await page.click('#svc-save');
  await toast('success').waitFor();
  await idle();
  t.has(await store(), '/uploads/services/');
  // remove the icon again
  await rowFor('QA 777 Followers').locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  await page.click('[data-icon-remove]');
  await page.click('#svc-save');
  await toast('success').waitFor();
  await idle();
  t.ok(!(await store()).includes('/uploads/services/'), 'icon removed');
});

await t.step('uploaded files cannot execute: PHP in /uploads is not served as code', async () => {
  const r = await page.request.get(BASE + '/uploads/services/does-not-exist.php');
  t.ok([403, 404].includes(r.status()), String(r.status()));
});

await t.step('editing conflict: saving a stale form is refused with a clear message (no silent overwrite)', async () => {
  await rowFor('QA 777 Followers').locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  execFileSync('php', ['-r', 'require "app/bootstrap.php"; App\\Core\\Db::exec("UPDATE services SET updated_at = ? WHERE code = ?", ["2030-01-01 00:00:00", "qa-followers-777"]);'], { cwd: '/home/user/claude1' });
  await page.fill('#f-title', 'QA 777 Followers (edited)');
  await page.click('#svc-save');
  await toast('error').waitFor();
  t.has(await t.text(toast('error')), 'someone else');
  t.ok(await page.locator('#service-drawer[open]').count() === 1, 'drawer stays open');
});

await t.step('double-clicking Save sends ONE request (button locks while saving)', async () => {
  await closeAll();
  await go(page, '/admin/services');
  await rowFor('QA 777 Followers').locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  let puts = 0;
  page.on('request', (r) => { if (r.method() === 'PUT' && /services\/\d+$/.test(r.url())) puts++; });
  await page.fill('#f-title', 'QA 777 Followers x');
  await page.dblclick('#svc-save');
  await toast('success').waitFor();
  t.eq(puts, 1);
  await idle();
});

await t.step('save failure (server 500) keeps the form and shows an error', async () => {
  await rowFor('QA 777 Followers').locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  await page.route('**/admin/api/services/*', (route) => (route.request().method() === 'PUT' ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"ok":false,"error":{"message":"Something went wrong. Please try again."}}' }) : route.continue()));
  await page.fill('#f-title', 'Will not save');
  await page.click('#svc-save');
  await toast('error').waitFor();
  t.eq(await page.locator('#f-title').inputValue(), 'Will not save');
  t.eq(await page.locator('#svc-save').isEnabled(), true, 'button usable again');
  await page.unroute('**/admin/api/services/*');
});

await t.step('delete: confirm dialog, removes from list and store; the code can be reused', async () => {
  await closeAll();
  await go(page, '/admin/services');
  await rowFor('QA 777 Followers').locator('[data-delete]').click();
  await page.waitForSelector('dialog.dlg[open] [data-act="yes"]');
  t.has(await t.text(page.locator('dialog.dlg[open] h2')), 'Delete');
  await page.click('dialog.dlg[open] [data-act="no"]:has-text("Cancel")');
  await page.waitForFunction(() => !document.querySelector('dialog.dlg[open]'));
  t.ok(await rowFor('QA 777 Followers').count() === 1, 'cancel keeps it');
  await rowFor('QA 777 Followers').locator('[data-delete]').click();
  await page.waitForSelector('dialog.dlg[open] [data-act="yes"]');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await toast('success').waitFor();
  await idle();
  t.ok(await rowFor('QA 777 Followers').count() === 0, 'gone');
  t.ok(!(await store()).includes('QA 777 Followers'), 'gone from the store');
  // code freed → can create again
  await page.click('#add-service');
  await page.waitForSelector('#service-drawer[open] #svc-form');
  await page.fill('#f-title', 'QA Again');
  await page.fill('#f-price', '9'); await page.fill('#f-quantity', '5');
  await page.fill('#f-code', 'qa-followers-777');
  await page.click('#svc-save');
  await toast('success').waitFor();
  await idle();
  await rowFor('QA Again').locator('[data-delete]').click();
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await toast('success').waitFor();
});

// ───────────────────────────── WEBSITE ─────────────────────────────
await go(page, '/admin/website');
const save = async () => { await page.click('#save'); await toast('success').waitFor(); await page.waitForSelector('#savebar', { state: 'hidden' }); };

await t.step('website: pristine form has no save bar; editing shows it; Discard restores', async () => {
  t.ok(await page.locator('#savebar').isHidden(), 'hidden when clean');
  await page.fill('#w-name', 'Changed Name');
  t.ok(await page.locator('#savebar').isVisible(), 'visible when dirty');
  await page.click('#discard');
  t.eq(await page.locator('#w-name').inputValue(), 'SOCIAL MEDIA BOOSTER');
  t.ok(await page.locator('#savebar').isHidden());
});

await t.step('rename the site: saved, shown in the store header; revert', async () => {
  await page.fill('#w-name', 'QA BOOST CO');
  await save();
  t.has(await store(), 'QA BOOST CO');
  await page.fill('#w-name', 'SOCIAL MEDIA BOOSTER');
  await save();
  t.has(await store(), 'SOCIAL MEDIA BOOSTER');
});

await t.step('name validation: too short / too long are refused', async () => {
  await page.fill('#w-name', 'A');
  await page.click('#save');
  await page.waitForSelector('#website-form [data-err="name"]');
  await page.fill('#w-name', 'X'.repeat(70));
  t.eq((await page.locator('#w-name').inputValue()).length, 60, 'maxlength stops overlong input');
  await page.click('#discard');
});

await t.step('accent colours: bad hex and too-light colours are refused; a valid deep colour applies to the store; reset restores', async () => {
  await page.fill('[name="accent_from"]', 'red');
  await page.click('#save');
  await page.waitForSelector('#website-form [data-err="accent_from"]');
  await clearToasts();
  await page.fill('[name="accent_from"]', '#ffff00');
  t.has(await t.text(page.locator('[data-contrast="from"]')), 'too light');
  await page.click('#save');
  await page.waitForSelector('#website-form [data-err="accent_from"]');
  t.has(await t.text(page.locator('#website-form [data-err="accent_from"]')), 'too light');
  await clearToasts();
  await page.fill('[name="accent_from"]', '#c4005f');
  await page.fill('[name="accent_to"]', '#d92d20');
  await save();
  const css = await (await page.request.get(BASE + '/theme.css')).text();
  t.has(css, '#c4005f');
  await page.click('[data-default-colors]');
  await save();
  t.has(await (await page.request.get(BASE + '/theme.css')).text(), '#ff2d87');
});

await t.step('hostile CSS-injection attempts in the accent colour are refused (theme.css stays safe)', async () => {
  const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
  for (const bad of ['#fff;}body{display:none', 'red;background:url(//evil)', '#12345', 'url(javascript:1)', '#ff2d87}</style><script>']) {
    const r = await page.request.put(BASE + '/admin/api/website', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': csrf, Origin: BASE }, data: { accent_from: bad } });
    t.eq(r.status(), 422, bad);
  }
  const css = await (await page.request.get(BASE + '/theme.css')).text();
  t.ok(/^:root\{--accent-from:#[0-9a-f]{6};--accent-to:#[0-9a-f]{6}\}\s*$/.test(css), css);
});

await t.step('support link: javascript:/data: URLs refused; https link accepted and rendered in the store footer', async () => {
  for (const bad of ['javascript:alert(1)', 'data:text/html,<script>1</script>', 'http://insecure.example', 'ftp://x.example', 'https://user:pw@x.example/']) {
    await page.fill('#w-support', bad);
    await page.click('#save');
    await page.waitForSelector('#website-form [data-err="support_link"]');
    await clearToasts();
  }
  await page.fill('#w-support', 'https://wa.me/911234567890');
  await save();
  t.has(await store(), 'https://wa.me/911234567890');
  await page.fill('#w-support', '');
  await save();
});

await t.step('logo upload: preview + admin chrome update, store header uses it; invalid files refused; Reset restores the default', async () => {
  await page.setInputFiles('#file-site_logo', `${A}/logo.png`);
  await toast('success').waitFor();
  await page.waitForFunction(() => /\/uploads\/branding\//.test(document.querySelector('.adm-brand-logo img').src));
  t.ok(/\/uploads\/branding\/[a-f0-9]{20}\./.test(await page.locator('[data-image="site_logo"] [data-preview]').getAttribute('src')));
  t.ok(/\/uploads\/branding\//.test(await store()), 'store header');
  await clearToasts();
  await page.setInputFiles('#file-site_logo', `${A}/evil.svg`);
  await toast('error').waitFor();
  await page.click('[data-reset="site_logo"]');
  await toast('success').waitFor();
  t.ok(!(await store()).includes('/uploads/branding/'), 'default logo back');
});

await t.step('favicon upload and reset', async () => {
  await page.setInputFiles('#file-favicon', `${A}/icon.jpg`);
  await toast('success').waitFor();
  t.ok(/rel="icon" href="[^"]*\/uploads\/branding\//.test(await store()), 'favicon link updated');
  await page.click('[data-reset="favicon"]');
  await toast('success').waitFor();
});

await t.step('maintenance mode: store shows a 503 maintenance page + admin banner; turn off restores', async () => {
  await page.locator('[name="maintenance"]').locator('xpath=..').click();
  await save();
  const r = await page.request.get(BASE + '/');
  t.eq(r.status(), 503);
  t.has(await r.text(), 'back shortly');
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForSelector('#banners .callout');
  t.has(await t.text(page.locator('#banners')), 'Maintenance mode is ON');
  await page.locator('[name="maintenance"]').locator('xpath=..').click();
  await save();
  t.eq((await page.request.get(BASE + '/')).status(), 200);
});

await t.step('platform card: rename shows on the store; cannot hide every platform; logo upload + reset', async () => {
  const card = page.locator('[data-platform]').first();
  await card.locator('[name="p_name"]').fill('Insta QA');
  await card.locator('[data-plat-save]').click();
  await toast('success').waitFor();
  t.has(await store(), 'Insta QA');
  await clearToasts();
  for (const c of await page.locator('[data-platform]').all()) await c.locator('[name="p_active"]').locator('xpath=..').click();
  await page.locator('[data-platform]').first().locator('[data-plat-save]').click();
  await toast('success').waitFor();                       // first platform hidden (second still active) is allowed
  await clearToasts();
  await page.locator('[data-platform]').nth(1).locator('[name="p_active"]').locator('xpath=..').click();
  await page.locator('[data-platform]').nth(1).locator('[data-plat-save]').click();
  await toast('error').waitFor();
  t.has(await t.text(toast('error')), 'At least one platform');
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForSelector('[data-platform]');
  const c0 = page.locator('[data-platform]').first();
  await c0.locator('[name="p_name"]').fill('Instagram');
  if (!(await c0.locator('[name="p_active"]').isChecked())) await c0.locator('[name="p_active"]').locator('xpath=..').click();
  await clearToasts();
  await c0.locator('[data-plat-save]').click();
  await toast('success').waitFor();
  await clearToasts();
  await c0.locator('input[type=file]').setInputFiles(`${A}/logo.png`);
  await toast('success').waitFor();
  t.ok(/\/uploads\/platforms\//.test(await store()), 'platform logo on the store');
  await page.click('[data-plat-reset]');
  await toast('success').waitFor();
  t.ok(!(await store()).includes('/uploads/platforms/'));
});

await t.step('time zone is locked once orders exist (cannot be changed through the API either)', async () => {
  t.eq(await page.locator('#w-tz').isDisabled(), true);
  const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
  const r = await page.request.put(BASE + '/admin/api/website', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': csrf, Origin: BASE }, data: { timezone: 'UTC' } });
  t.eq(r.status(), 422);
});

await t.step('audit trail recorded the changes and contains no secrets', async () => {
  const r = await page.request.get(BASE + '/admin/api/audit?q=settings', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
  const j = await r.json();
  t.ok(j.total > 0, 'settings entries exist');
  const all = JSON.stringify((await (await page.request.get(BASE + '/admin/api/audit', { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } })).json()));
  for (const secret of ['smm-key-ok', 'test-api-key-123', 'test-webhook-secret-456', 'Sm8!Qx']) t.ok(!all.includes(secret), 'audit leaks ' + secret);
});

console.log('console/network problems:', page.problems.filter((p) => !/status of (409|422|500)/.test(p) && !/\[http (409|422|500)\]/.test(p)).join('\n') || 'none');
await browser.close();
process.exit(t.finish() ? 1 : 0);
