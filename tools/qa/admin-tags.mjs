// Admin → Tags & Badges, driven through the real UI in Chromium, with what customers then see on the storefront:
//   the 10 predefined tags; create / edit / disable / enable / delete a custom tag (colour picker + HEX box, live previews, validation); assign, change
//   and remove tags on Instagram AND YouTube services from the service editor (dropdown, "No tag", live preview on a real card); the automatic ₹5
//   TRIAL PACK (priority over the assigned tag, the assigned tag restored when the price changes, no duplicates); disabled / deleted tags;
//   persistence after a refresh; authentication, CSRF and hostile input; phone and tablet layouts.
// Screenshots for visual review land in $QA_OUT (default /tmp/qa/admin-tags).
//   NO_BASELINE=1 skips the dev-database reset (use it with BASE=… DB_NAME=… against another copy of the site).
import { execFileSync } from 'node:child_process';
import { launch, newPage, login, go, layoutReport, BASE } from './admin.mjs';
import { sql, rows, ROOT, resetLimits, contrast } from './site.mjs';
import { suite } from './harness.mjs';

const OUT = process.env.QA_OUT || '/tmp/qa/admin-tags';
const t = suite('admin-tags', OUT);
if (!process.env.NO_BASELINE) execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
resetLimits();
const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 1000 }, { allowStatus: [403, 404, 422] });
t.page = page;
await login(page);

const rgb = (hex) => `rgb(${[1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(', ')})`;
const PREDEFINED = [
  ['trial-pack', 'TRIAL PACK', '#DC2626'], ['best-seller', 'BEST SELLER', '#7C3AED'], ['popular', 'POPULAR', '#D97706'], ['best-value', 'BEST VALUE', '#15803D'],
  ['premium', 'PREMIUM', '#0369A1'], ['hot-deal', 'HOT DEAL', '#BE185D'], ['non-drop', 'NON DROP', '#0F766E'], ['instant', 'INSTANT', '#4338CA'],
  ['limited-offer', 'LIMITED OFFER', '#475569'], ['vip', 'VIP', '#A16207'],
];
const MANUAL = PREDEFINED.filter(([c]) => c !== 'trial-pack');

// ── helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((n) => n.remove()));
const closeAll = async () => { for (let i = 0; i < 4; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
const toast = (type) => page.locator(`.toast[data-type="${type}"]`).last();
const idle = (root) => page.waitForFunction((r) => document.querySelector(r)?.getAttribute('aria-busy') === 'false', root);
const tagRow = (name) => page.locator('#tags-root tbody tr').filter({ hasText: name });
const svcId = (code) => Number(sql(`SELECT id FROM services WHERE code = '${code}'`));
const tagId = (match) => Number(sql(`SELECT id FROM service_tags WHERE ${/^[a-z-]+$/.test(match) ? `code = '${match}'` : `name = '${match.replace(/'/g, "''")}'`}`));
const csrf = () => page.evaluate(() => document.querySelector('meta[name="csrf-token"]').content);
const api = async (method, path, body, { token = true, headers = {} } = {}) => {
  const r = await page.request.fetch(`${BASE}/admin/api/${path}`, {
    method, data: body === undefined ? undefined : JSON.stringify(body),
    headers: { Accept: 'application/json', 'X-Requested-With': 'smb', 'Content-Type': 'application/json', Origin: BASE, ...(token ? { 'X-CSRF-Token': await csrf() } : {}), ...headers },
  });
  let json = null; try { json = await r.json(); } catch { /* not JSON */ }
  return { status: r.status(), json };
};
const unesc = (s) => s.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

/** What the storefront shows on a service card: the badge element (kind auto|tag, text, tag colours from /tags.css) or null; plus how many badges there are. */
async function store(code) {
  const html = await (await page.request.get(BASE + '/', { headers: { 'Cache-Control': 'no-cache' } })).text();
  const i = html.indexOf(`data-id="${svcId(code)}"`);
  if (i < 0) return { missing: true };
  const j = html.indexOf('<article', i + 10);
  const block = html.slice(i, j < 0 ? undefined : j);
  const all = [...block.matchAll(/<span class="badge svc-badge tag-(\d+)" data-badge="(auto|tag)" data-tag-id="\d+">([^<]*)<\/span>/g)];
  const v = html.match(/tags\.css\?v=([0-9a-f]+)/)?.[1];
  const css = v ? await (await page.request.get(`${BASE}/tags.css?v=${v}`)).text() : '';
  const map = Object.fromEntries([...css.matchAll(/\.tag-(\d+)\{--tag-bg:(#[0-9A-F]{6});--tag-fg:(#[0-9A-F]{6})/g)].map((m) => [m[1], { bg: m[2], fg: m[3] }]));
  if (!all.length) return { count: 0, badge: null };
  const [, id, kind, text] = all[0];
  return { count: all.length, badge: { id: Number(id), kind, text: unesc(text), ...(map[id] ?? { bg: null, fg: null }) } };
}
const storeText = async (code) => { const s = await store(code); return s.badge ? `${s.badge.text}${s.badge.kind === 'auto' ? ' (auto)' : ''}` : null; };

async function openService(code) {
  await go(page, '/admin/services');
  await page.locator('#services-root tbody tr').filter({ hasText: code }).first().locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  await page.waitForFunction(() => document.querySelector('#tag-preview .svc-badge, #tag-preview .svc'));
  await page.waitForTimeout(300);
}
const previewBadge = () => page.evaluate(() => { const b = document.querySelector('#tag-preview .svc-badge'); if (!b) return null; const cs = getComputedStyle(b); return { text: b.textContent.trim(), bg: cs.backgroundColor, color: cs.color }; });
const saveService = async () => { await page.click('#svc-save'); await toast('success').waitFor(); await idle('#services-root'); await page.waitForFunction(() => !document.querySelector('#service-drawer[open]')); };
const optionTexts = () => page.$$eval('#f-tag_id option', (os) => os.map((o) => ({ label: o.textContent.trim(), value: o.value, disabled: o.disabled, selected: o.selected })));

async function openTagEditor(name) {
  await go(page, '/admin/tags');
  if (name) await tagRow(name).first().locator('[data-edit]').click(); else await page.click('#add-tag');
  await page.waitForSelector('#tag-dialog[open] #tag-form');
  await page.waitForTimeout(250);
}
const fillTag = async ({ name, bg, fg }) => {
  if (name !== undefined) await page.fill('#t-name', name);
  if (bg !== undefined) { await page.fill('#t-bg_color', bg); await page.locator('#t-bg_color').blur(); }
  if (fg !== undefined) { await page.fill('#t-text_color', fg); await page.locator('#t-text_color').blur(); }
};
const saveTag = async () => { await page.click('#tag-save'); await toast('success').waitFor(); await idle('#tags-root'); await page.waitForFunction(() => !document.querySelector('#tag-dialog[open]')); };
const pillOf = (sel) => page.$eval(sel, (e) => { const cs = getComputedStyle(e); return { text: e.textContent.trim(), bg: cs.backgroundColor, color: cs.color }; });

const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeAll(); await clearToasts(); await fn(); });

// ═══════════════════════════════ 1 · the Tags & Badges section ═══════════════════════════════
await go(page, '/admin/tags');

await t.step('the section sits under Service management: sidebar sub-item, tab bar on both pages, page title', async () => {
  t.eq(await page.locator('h1#page-title').innerText(), 'Tags & Badges');
  const nav = page.locator('.adm-side a.adm-link.adm-sub[href$="/admin/tags"]');
  t.eq([await nav.count(), await nav.getAttribute('aria-current')], [1, 'page'], 'sidebar sub-item (current page)');
  t.eq(await page.locator('nav.seg a[aria-current="page"]').innerText(), 'Tags & Badges');
  await go(page, '/admin/services');
  t.eq(await page.locator('nav.seg a[aria-current="page"]').innerText(), 'Services');
  await page.locator('nav.seg a', { hasText: 'Tags & Badges' }).click();
  await page.waitForURL(/\/admin\/tags$/);
  await go(page, '/admin/tags');
  await page.screenshot({ path: `${OUT}/tags-list.png` });
});

await t.step('the 10 predefined tags are listed with the specified names, background colours and white text; predefined tags have no Delete button', async () => {
  const r = tagRow('');
  t.eq(await page.locator('#tags-root tbody tr').count(), 10);
  for (const [code, name, bg] of PREDEFINED) {
    const row = page.locator('#tags-root tbody tr').nth(PREDEFINED.findIndex(([c]) => c === code));
    t.eq(await row.locator('td[data-label="Badge"] .cell-main b').innerText(), name);
    const p = await row.locator('.tagpill').evaluate((e) => { const cs = getComputedStyle(e); return { bg: cs.backgroundColor, color: cs.color, text: e.textContent.trim() }; });
    t.eq([p.text, p.bg, p.color], [name, rgb(bg), 'rgb(255, 255, 255)'], `${name}: the pill`);
    t.eq((await row.locator('td[data-label="Background"]').innerText()).trim(), bg, `${name}: background hex`);
    t.eq((await row.locator('td[data-label="Text"]').innerText()).split('\n')[0].trim(), '#FFFFFF', `${name}: text hex`);
    t.eq(await row.locator('[data-toggle]').isChecked(), true, `${name}: enabled`);
    t.eq(await row.locator('[data-delete]').count(), 0, `${name}: predefined tags cannot be deleted`);
    t.eq(await row.locator('[data-edit]').count(), 1);
  }
  t.has(await t.text(page.locator('#tags-root tbody tr').first()), 'automatic at ₹5');
  t.has(await t.text(tagRow('PREMIUM')), '1 service', 'PREMIUM is used by the watch-time card');
  t.has(await t.text(tagRow('POPULAR')), 'contrast 3.2:1', 'the amber/white combination carries a contrast note');
  t.eq(r === undefined, false);
});

await t.step('the editor previews the badge and a real service card live; the colour picker and the HEX box stay in sync; contrast is reported', async () => {
  await openTagEditor(null);
  await fillTag({ name: 'Summer sale', bg: '#0EA5E9' });
  t.eq(await pillOf('#t-pill .tagpill'), { text: 'Summer sale', bg: 'rgb(14, 165, 233)', color: 'rgb(255, 255, 255)' }, 'pill follows name + colour');
  t.eq(await pillOf('#t-card .svc-badge'), { text: 'Summer sale', bg: 'rgb(14, 165, 233)', color: 'rgb(255, 255, 255)' }, 'the real card preview follows too');
  t.has(await page.locator('#t-card .svc-title').innerText(), '100K Followers');
  t.has(await t.text(page.locator('#t-contrast')), 'below the 4.5:1');
  await page.click('[data-fg="#000000"]');
  t.eq(await page.locator('#t-text_color').inputValue(), '#000000');
  t.eq((await pillOf('#t-pill .tagpill')).color, 'rgb(0, 0, 0)');
  t.has(await t.text(page.locator('#t-contrast')), 'easy to read');
  await page.fill('[data-color="bg_color"]', '#ff0000');                       // the native colour picker
  t.eq(await page.locator('#t-bg_color').inputValue(), '#FF0000', 'picker → HEX box (upper case)');
  t.eq((await pillOf('#t-pill .tagpill')).bg, 'rgb(255, 0, 0)');
  await page.fill('#t-bg_color', '#abc'); await page.locator('#t-bg_color').blur();
  t.eq(await page.locator('#t-bg_color').inputValue(), '#AABBCC', '#RGB is expanded');
  t.eq(await page.locator('[data-color="bg_color"]').inputValue(), '#aabbcc', 'HEX box → picker');
  await page.fill('#t-bg_color', 'banana');
  t.has(await t.text(page.locator('#t-contrast')), 'Enter both colours as HEX');
  await page.fill('#t-bg_color', '#FFFFFF'); await page.fill('#t-text_color', '#FFFFFF');
  t.has(await t.text(page.locator('#t-contrast')), 'nearly invisible');
  await page.uncheck('#tag-form [name="is_active"]');
  t.eq(await page.locator('#t-off').isVisible(), true, 'a disabled tag says so in the preview');
  await page.screenshot({ path: `${OUT}/tag-editor.png` });
});

await t.step('validation: duplicate / empty / markup names, bad HEX, unreadable colours — inline errors, the dialog stays open, nothing is saved', async () => {
  const before = Number(sql('SELECT COUNT(*) FROM service_tags'));
  const expectError = async (field, text, set) => {
    await openTagEditor(null);
    await fillTag({ name: 'Valid name', bg: '#1D4ED8', fg: '#FFFFFF', ...set });
    await page.click('#tag-save');
    await page.waitForSelector(`#tag-form [data-err="${field}"]`);
    t.has(await page.locator(`#tag-form [data-err="${field}"]`).innerText(), text, `${field} — ${JSON.stringify(set)}`);
    t.eq(await page.locator('#tag-dialog[open]').count(), 1, 'the dialog stays open');
    t.eq(await page.locator(`#tag-form [name="${field}"]`).getAttribute('aria-invalid'), 'true');
    await closeAll(); await clearToasts();
  };
  await expectError('name', 'already exists', { name: 'vip' });
  await expectError('name', 'already exists', { name: 'trial pack' });
  await expectError('name', 'at least 2', { name: 'A' });
  await expectError('name', 'letters, numbers', { name: '<b>x</b>' });
  await expectError('bg_color', 'HEX', { bg: 'red' });
  await expectError('bg_color', 'HEX', { bg: 'rgb(1,2,3)' });
  await expectError('text_color', 'HEX', { fg: '#12345' });
  await expectError('text_color', 'too close', { bg: '#FFFFFF', fg: '#FFFFFF' });
  await expectError('text_color', 'too close', { bg: '#FFFF00', fg: '#FFFFFF' });
  t.eq(Number(sql('SELECT COUNT(*) FROM service_tags')), before, 'nothing was saved');
  // the limits the form cannot enforce on its own: straight to the API
  for (const [body, field] of [[{ name: 'x'.repeat(25), bg_color: '#1D4ED8' }, 'name'], [{ name: '"><img src=x onerror=alert(1)>', bg_color: '#1D4ED8' }, 'name'], [{ name: 'ok', bg_color: '#fff;}body{display:none}' }, 'bg_color'], [{ name: 'ok', bg_color: '#1D4ED8', text_color: 'url(javascript:1)' }, 'text_color']]) {
    const r = await api('POST', 'tags', body);
    t.eq([r.status, Object.keys(r.json?.error?.fields ?? {}).includes(field)], [422, true], JSON.stringify(body).slice(0, 70));
  }
});

await t.step('create a custom tag (double-clicking Save creates exactly one); it is listed, persists after a refresh and is stored in MySQL', async () => {
  await openTagEditor(null);
  await fillTag({ name: 'Flash sale', bg: '#E11D48', fg: '#FFFFFF' });
  await page.dblclick('#tag-save');
  await toast('success').waitFor(); await idle('#tags-root');
  t.eq(await page.locator('#tags-root tbody tr').count(), 11);
  t.eq(Number(sql("SELECT COUNT(*) FROM service_tags WHERE name = 'Flash sale'")), 1, 'one row, not two');
  t.eq(rows("SELECT name, bg_color, text_color, is_active, is_system, code FROM service_tags WHERE name = 'Flash sale'")[0], ['Flash sale', '#E11D48', '#FFFFFF', '1', '0', 'NULL']);
  const row = tagRow('Flash sale');
  t.eq((await pillOf('#tags-root tbody tr:last-child .tagpill')).bg, 'rgb(225, 29, 72)');
  t.has(await t.text(row), 'Custom');
  t.eq(await row.locator('[data-delete]').count(), 1, 'custom tags can be deleted');
  await page.reload({ waitUntil: 'networkidle' }); await idle('#tags-root');
  t.eq(await page.locator('#tags-root tbody tr').count(), 11, 'still there after a refresh');
  t.eq((await pillOf('#tags-root tbody tr:last-child .tagpill')).text, 'Flash sale');
  t.eq(Number(sql("SELECT COUNT(*) FROM audit_logs WHERE action = 'tag.created'")), 1, 'audit log');
});

await t.step('edit a custom tag: name, HEX background and text colour; the pill, the list and MySQL follow; a refresh keeps it', async () => {
  await openTagEditor('Flash sale');
  t.eq(await page.locator('#t-name').inputValue(), 'Flash sale');
  await fillTag({ name: 'Flash deal', bg: '#16A34A' });
  await page.click('[data-fg="#000000"]');
  await saveTag();
  const p = await pillOf('#tags-root tbody tr:last-child .tagpill');
  t.eq(p, { text: 'Flash deal', bg: 'rgb(22, 163, 74)', color: 'rgb(0, 0, 0)' });
  t.eq(rows("SELECT name, bg_color, text_color FROM service_tags WHERE id = " + tagId('Flash deal'))[0], ['Flash deal', '#16A34A', '#000000']);
  await page.reload({ waitUntil: 'networkidle' }); await idle('#tags-root');
  t.eq(await pillOf('#tags-root tbody tr:last-child .tagpill'), p, 'same after a refresh');
});

await t.step('predefined tags can be edited (and reset to their defaults) but not deleted — not even through the API', async () => {
  await openTagEditor('VIP');
  t.eq(await page.locator('[data-delete-current]').count(), 0, 'no Delete button in the editor');
  await fillTag({ name: 'V.I.P', bg: '#B45309' });
  await saveTag();
  t.eq(rows("SELECT name, bg_color FROM service_tags WHERE code = 'vip'")[0], ['V.I.P', '#B45309']);
  await openTagEditor('V.I.P');
  await page.click('[data-reset-default]');
  t.eq([await page.locator('#t-name').inputValue(), await page.locator('#t-bg_color').inputValue(), await page.locator('#t-text_color').inputValue()], ['VIP', '#A16207', '#FFFFFF']);
  await saveTag();
  t.eq(rows("SELECT name, bg_color, text_color FROM service_tags WHERE code = 'vip'")[0], ['VIP', '#A16207', '#FFFFFF']);
  for (const [code] of PREDEFINED) {
    const r = await api('DELETE', `tags/${tagId(code)}`);
    t.eq([r.status, r.json?.error?.code], [422, 'protected'], `DELETE ${code}`);
  }
  t.eq(Number(sql('SELECT COUNT(*) FROM service_tags WHERE is_system = 1')), 10);
});

await t.step('enable / disable a tag from the list: dims the row, persists after a refresh, and is reversible', async () => {
  await go(page, '/admin/tags');
  const sw = tagRow('Flash deal').locator('[data-toggle]');
  await sw.uncheck({ force: true }); await toast('success').waitFor(); await idle('#tags-root');
  t.eq(Number(sql("SELECT is_active FROM service_tags WHERE name = 'Flash deal'")), 0);
  t.has(await t.text(tagRow('Flash deal')), 'hidden on the store');
  await page.reload({ waitUntil: 'networkidle' }); await idle('#tags-root');
  t.eq(await tagRow('Flash deal').locator('[data-toggle]').isChecked(), false, 'still disabled after a refresh');
  await clearToasts();
  await tagRow('Flash deal').locator('[data-toggle]').check({ force: true }); await toast('success').waitFor(); await idle('#tags-root');
  t.eq(Number(sql("SELECT is_active FROM service_tags WHERE name = 'Flash deal'")), 1);
  t.eq(await tagRow('Flash deal').locator('[data-toggle]').isChecked(), true);
});

// ═══════════════════════════════ 2 · assigning tags to services ═══════════════════════════════
await t.step('the service editor offers "No tag", every enabled tag and TRIAL PACK only as a disabled explanation; the preview is the real card', async () => {
  await openService('ig-followers-100k');
  const o = await optionTexts();
  t.eq(o[0], { label: 'No tag', value: '', disabled: false, selected: true });
  t.eq(o.slice(1, 10).map((x) => x.label), MANUAL.map(([, n]) => n), 'the 9 manual predefined tags, in order');
  t.has(o.find((x) => x.label === 'Flash deal')?.label ?? '', 'Flash deal');
  const auto = o.at(-1);
  t.eq([auto.label, auto.disabled], ['TRIAL PACK — automatic at ₹5', true], 'TRIAL PACK is an explanation, not a choice');
  t.eq(o.filter((x) => x.label === 'TRIAL PACK').length, 0);
  t.eq(await previewBadge(), null, 'no tag → no badge on the preview card');
  t.has(await t.text(page.locator('#tag-hint')), 'No badge');
  t.eq(await page.locator('#tag-preview .svc-title').innerText(), '100K Followers');
  await page.selectOption('#f-tag_id', { label: 'BEST SELLER' });
  t.eq(await previewBadge(), { text: 'BEST SELLER', bg: 'rgb(124, 58, 237)', color: 'rgb(255, 255, 255)' });
  t.has(await t.text(page.locator('#tag-hint')), 'BEST SELLER is shown at the top of the card');
  await page.locator('#f-tag_id').scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${OUT}/service-editor-tag.png` });
});

await t.step('assign tags to an Instagram and a YouTube service, change them and choose "No tag": the store follows each time, one badge, exact colours', async () => {
  const cases = [['ig-followers-100k', 'BEST SELLER', '#7C3AED'], ['yt-subs-100k', 'HOT DEAL', '#BE185D']];
  for (const [code, label, bg] of cases) {
    await openService(code);
    await page.selectOption('#f-tag_id', { label });
    await saveService();
    const s = await store(code);
    t.eq([s.count, s.badge.kind, s.badge.text, s.badge.bg, s.badge.fg], [1, 'tag', label, bg, '#FFFFFF'], `${code} → ${label}`);
    t.has(await t.text(page.locator('#services-root tbody tr').filter({ hasText: code })), label, 'the list row shows the badge');
    await page.reload({ waitUntil: 'networkidle' });
    await openService(code);
    t.eq((await optionTexts()).find((x) => x.selected).label, label, 'the editor remembers it after a refresh');
    await closeAll();
  }
  // change
  await openService('ig-followers-100k'); await page.selectOption('#f-tag_id', { label: 'NON DROP' }); await saveService();
  t.eq(await storeText('ig-followers-100k'), 'NON DROP');
  // remove
  for (const code of ['ig-followers-100k', 'yt-subs-100k']) {
    await openService(code); await page.selectOption('#f-tag_id', { label: 'No tag' });
    t.eq(await previewBadge(), null);
    await saveService();
    t.eq(await store(code), { count: 0, badge: null }, `${code} → No tag`);
    t.eq(sql(`SELECT COALESCE(tag_id, 'none') FROM services WHERE code = '${code}'`), 'none');
  }
});

await t.step('each of the 9 manual predefined tags can be chosen in the UI and shows on the store with exactly its colour (YouTube and Instagram)', async () => {
  for (const [i, [, name, bg]] of MANUAL.entries()) {
    const code = i % 2 ? 'yt-likes-50k' : 'ig-likes-50k';
    await openService(code);
    await page.selectOption('#f-tag_id', { label: name });
    t.eq((await previewBadge()).bg, rgb(bg), `${name}: preview colour`);
    await saveService();
    const s = await store(code);
    t.eq([s.count, s.badge.text, s.badge.bg, s.badge.fg, s.badge.kind], [1, name, bg, '#FFFFFF', 'tag'], `${name} on ${code}`);
  }
  for (const code of ['ig-likes-50k', 'yt-likes-50k']) sql(`UPDATE services SET tag_id = NULL WHERE code = '${code}'`);
});

await t.step('the automatic ₹5 rule in the editor: ₹5 shows TRIAL PACK ahead of the chosen tag, ₹10 restores it, ₹4.99 / ₹5.01 are not ₹5, never two badges', async () => {
  await openService('ig-likes-100');                                  // a ₹5 service
  await page.selectOption('#f-tag_id', { label: 'POPULAR' });
  t.eq(await previewBadge(), { text: 'TRIAL PACK', bg: 'rgb(220, 38, 38)', color: 'rgb(255, 255, 255)' }, 'at ₹5 the preview shows TRIAL PACK');
  t.has(await t.text(page.locator('#tag-hint')), 'TRIAL PACK is shown automatically while the price is ₹5, ahead of POPULAR');
  t.eq(await page.locator('#tag-preview .svc-badge').count(), 1, 'one badge only');
  await saveService();
  t.eq(await store('ig-likes-100'), { count: 1, badge: { id: tagId('trial-pack'), kind: 'auto', text: 'TRIAL PACK', bg: '#DC2626', fg: '#FFFFFF' } }, 'the store: TRIAL PACK, one badge');
  t.eq(sql("SELECT t.name FROM services s JOIN service_tags t ON t.id = s.tag_id WHERE s.code = 'ig-likes-100'"), 'POPULAR', 'the manual assignment is kept underneath');
  for (const [price, expected] of [['10', 'POPULAR'], ['5.00', 'TRIAL PACK (auto)'], ['4.99', 'POPULAR'], ['5', 'TRIAL PACK (auto)'], ['5.01', 'POPULAR'], ['5.0', 'TRIAL PACK (auto)'], ['25', 'POPULAR']]) {
    await openService('ig-likes-100');
    await page.fill('#f-price', price);
    t.eq((await previewBadge()).text + (expected.includes('auto') ? ' (auto)' : ''), expected, `preview at ₹${price}`);
    await saveService();
    t.eq([await storeText('ig-likes-100'), (await store('ig-likes-100')).count], [expected, 1], `store at ₹${price}`);
  }
  // the same on a YouTube service, starting from a dearer one
  await openService('yt-views-10k');
  await page.selectOption('#f-tag_id', { label: 'INSTANT' }); await saveService();
  t.eq(await storeText('yt-views-10k'), 'INSTANT');
  await openService('yt-views-10k'); await page.fill('#f-price', '5'); await saveService();
  t.eq(await storeText('yt-views-10k'), 'TRIAL PACK (auto)');
  await openService('yt-views-10k'); await page.fill('#f-price', '99'); await saveService();
  t.eq(await storeText('yt-views-10k'), 'INSTANT', 'back to ₹99: the assigned tag returns');
  for (const code of ['ig-likes-100', 'yt-views-10k']) sql(`UPDATE services SET tag_id = NULL WHERE code = '${code}'`);
  sql("UPDATE services SET price_paise = 500 WHERE code = 'ig-likes-100'"); sql("UPDATE services SET price_paise = 9900 WHERE code = 'yt-views-10k'");
});

await t.step('TRIAL PACK cannot be assigned by hand (API refuses it with 422); unknown ids and junk are refused too', async () => {
  const id = svcId('ig-followers-100k');
  for (const [tag, text] of [[tagId('trial-pack'), 'automatic'], [999999, 'no longer exists'], ['abc', 'Choose a tag'], [-3, 'Choose a tag']]) {
    const r = await api('PUT', `services/${id}`, { tag_id: tag });
    t.eq(r.status, 422, `tag_id ${JSON.stringify(tag)}`);
    t.has(r.json?.error?.fields?.tag_id ?? '', text);
  }
  t.eq(sql("SELECT COALESCE(tag_id, 'none') FROM services WHERE code = 'ig-followers-100k'"), 'none');
});

await t.step('a disabled tag is hidden on the store but stays assigned (and shown as "disabled" in the editor); it cannot be newly assigned; enabling brings it back', async () => {
  await openService('ig-followers-100k'); await page.selectOption('#f-tag_id', { label: 'Flash deal' }); await saveService();
  await openService('yt-subs-100k'); await page.selectOption('#f-tag_id', { label: 'Flash deal' }); await saveService();
  t.eq([await storeText('ig-followers-100k'), await storeText('yt-subs-100k')], ['Flash deal', 'Flash deal']);
  await go(page, '/admin/tags');
  await tagRow('Flash deal').locator('[data-toggle]').uncheck({ force: true }); await toast('success').waitFor(); await idle('#tags-root'); await clearToasts();
  t.eq([await storeText('ig-followers-100k'), await storeText('yt-subs-100k')], [null, null], 'disabled → hidden on the store');
  t.eq(Number(sql(`SELECT COUNT(*) FROM services WHERE tag_id = ${tagId('Flash deal')}`)), 2, 'both assignments are kept');
  await openService('ig-followers-100k');
  const sel = (await optionTexts()).find((x) => x.selected);
  t.eq(sel.label, 'Flash deal (disabled)', 'the editor still shows the assignment');
  t.has(await t.text(page.locator('#tag-hint')), 'disabled, so no badge is shown');
  t.eq(await previewBadge(), null, 'and the preview shows nothing');
  await closeAll();
  await openService('yt-likes-50k');
  t.eq((await optionTexts()).some((x) => x.label.startsWith('Flash deal')), false, 'a disabled tag is not offered for other services');
  const r = await api('PUT', `services/${svcId('yt-likes-50k')}`, { tag_id: tagId('Flash deal') });
  t.eq(r.status, 422); t.has(r.json.error.fields.tag_id, 'disabled');
  await closeAll();
  await go(page, '/admin/tags');
  await tagRow('Flash deal').locator('[data-toggle]').check({ force: true }); await toast('success').waitFor(); await idle('#tags-root');
  t.eq([await storeText('ig-followers-100k'), await storeText('yt-subs-100k')], ['Flash deal', 'Flash deal'], 'enabled → back on both');
});

await t.step('deleting a custom tag asks first (naming how many services use it), then clears it from every service: the store shows no badge, the editor "No tag"', async () => {
  await go(page, '/admin/tags');
  await tagRow('Flash deal').locator('[data-delete]').click();
  await page.waitForSelector('dialog[open] [data-act="yes"]');
  t.has(await page.locator('dialog[open] .dlg-body').innerText(), '2 services use this tag');
  await page.locator('dialog[open] [data-act="no"]').last().click(); await page.waitForTimeout(400);
  t.eq(Number(sql("SELECT COUNT(*) FROM service_tags WHERE name = 'Flash deal'")), 1, 'cancel keeps the tag');
  await tagRow('Flash deal').locator('[data-delete]').click();
  await page.waitForSelector('dialog[open] [data-act="yes"]');
  await page.click('dialog[open] [data-act="yes"]');
  await toast('success').waitFor(); await idle('#tags-root');
  t.eq(await tagRow('Flash deal').count(), 0, 'gone from the list');
  t.eq(await page.locator('#tags-root tbody tr').count(), 10);
  t.eq(Number(sql("SELECT COUNT(*) FROM service_tags WHERE name = 'Flash deal'")), 0);
  t.eq(sql("SELECT COUNT(*) FROM services WHERE tag_id IS NOT NULL"), '1', 'only the watch-time card is still tagged');
  t.eq([await storeText('ig-followers-100k'), await storeText('yt-subs-100k')], [null, null], 'the store: no badge on either service');
  await openService('ig-followers-100k');
  t.eq((await optionTexts()).find((x) => x.selected).label, 'No tag');
  t.eq(await page.locator('#f-tag_id option', { hasText: 'Flash deal' }).count(), 0);
  t.eq(Number(sql("SELECT COUNT(*) FROM audit_logs WHERE action = 'tag.deleted'")), 1, 'audit log');
});

await t.step('persistence: after a refresh the Tags page, the service editor and the storefront show exactly what was saved', async () => {
  await openTagEditor(null); await fillTag({ name: 'Keep me', bg: '#7C2D12', fg: '#FFFFFF' }); await saveTag();
  await openService('ig-views-1m'); await page.selectOption('#f-tag_id', { label: 'Keep me' }); await saveService();
  for (let i = 0; i < 2; i++) {
    await page.reload({ waitUntil: 'networkidle' });
    t.eq(await storeText('ig-views-1m'), 'Keep me');
    await go(page, '/admin/tags');
    t.eq((await pillOf('#tags-root tbody tr:last-child .tagpill')), { text: 'Keep me', bg: 'rgb(124, 45, 18)', color: 'rgb(255, 255, 255)' });
    await openService('ig-views-1m');
    t.eq((await optionTexts()).find((x) => x.selected).label, 'Keep me');
    await closeAll();
  }
  const real = await newPage(browser, { width: 1366, height: 800 }, {});
  await real.goto(BASE + '/', { waitUntil: 'networkidle' });
  const computed = await real.$eval(`[data-id="${svcId('ig-views-1m')}"] .svc-badge`, (e) => { const cs = getComputedStyle(e); return { text: e.textContent, bg: cs.backgroundColor, color: cs.color }; });
  t.eq(computed, { text: 'Keep me', bg: 'rgb(124, 45, 18)', color: 'rgb(255, 255, 255)' }, 'what a customer sees in a real browser');
  await real.close();
  sql("UPDATE services SET tag_id = NULL WHERE code = 'ig-views-1m'");
});

// ═══════════════════════════════ 3 · security ═══════════════════════════════
await t.step('security: tag endpoints need a signed-in admin, a same-origin request and the CSRF token; hostile names are refused or rendered as text', async () => {
  await go(page, '/admin/tags');
  const anon = await browser.newContext();
  const a = await anon.request.fetch(`${BASE}/admin/api/tags`, { headers: { Accept: 'application/json', 'X-Requested-With': 'smb' } });
  t.eq(a.status(), 401, 'GET without a session');
  for (const [m, p] of [['POST', 'tags'], ['PUT', `tags/${tagId('vip')}`], ['DELETE', `tags/${tagId('Keep me')}`]]) {
    const r = await anon.request.fetch(`${BASE}/admin/api/${p}`, { method: m, headers: { Accept: 'application/json', 'X-Requested-With': 'smb', 'Content-Type': 'application/json', Origin: BASE }, data: '{}' });
    t.ok([401, 403].includes(r.status()), `${m} ${p} without a session → ${r.status()}`);
  }
  await anon.close();
  for (const [m, p, body] of [['POST', 'tags', { name: 'Nope', bg_color: '#111111' }], ['PUT', `tags/${tagId('vip')}`, { name: 'Nope' }], ['DELETE', `tags/${tagId('Keep me')}`, undefined]]) {
    const noCsrf = await api(m, p, body, { token: false });
    t.eq([noCsrf.status, noCsrf.json?.error?.code], [403, 'bad_csrf'], `${m} ${p} without the CSRF token`);
    const evil = await api(m, p, body, { headers: { Origin: 'https://evil.example' } });
    t.eq([evil.status, evil.json?.error?.code], [403, 'bad_origin'], `${m} ${p} from another origin`);
  }
  t.eq(Number(sql("SELECT COUNT(*) FROM service_tags WHERE name = 'Nope'")), 0);
  t.eq(rows("SELECT name FROM service_tags WHERE code = 'vip'")[0][0], 'VIP', 'nothing changed');
  // quotes and ampersands are legal and must stay text everywhere
  const r = await api('POST', 'tags', { name: 'R&D "Pro" \'25', bg_color: '#1D4ED8', text_color: '#FFFFFF' });
  t.eq(r.status, 200);
  await openService('ig-views-1m'); await page.selectOption('#f-tag_id', { label: 'R&D "Pro" \'25' }); await saveService();
  t.eq(await storeText('ig-views-1m'), 'R&D "Pro" \'25');
  const html = await (await page.request.get(BASE + '/', { headers: { 'Cache-Control': 'no-cache' } })).text();
  t.has(html, 'R&amp;D &quot;Pro&quot; &#039;25');
  t.eq(html.includes('R&D "Pro"'), false, 'never raw in the HTML');
  await go(page, '/admin/tags');
  t.eq((await pillOf('#tags-root tbody tr:last-child .tagpill')).text, 'R&D "Pro" \'25', 'admin list shows it as text');
  await tagRow('R&D').first().locator('[data-edit]').click(); await page.waitForSelector('#tag-dialog[open] #tag-form');
  t.eq(await page.locator('#t-name').inputValue(), 'R&D "Pro" \'25', 'and the editor field too');
  await closeAll();
  sql("UPDATE services SET tag_id = NULL WHERE code = 'ig-views-1m'");
  const css = await (await page.request.get(`${BASE}/tags.css`)).text();
  t.ok(css.split('\n').filter(Boolean).every((l) => /^\.tag-\d+\{--tag-bg:#[0-9A-F]{6};--tag-fg:#[0-9A-F]{6};--tag-ts:rgb\([0-9 \/.]+\)\}$/.test(l)), '/tags.css contains nothing but validated colour rules');
});

// ═══════════════════════════════ 4 · layouts ═══════════════════════════════
await t.step('phones and tablet: Tags page, tag editor and service editor have no horizontal overflow, nothing off-screen, and the previews fit', async () => {
  const problems = [];
  for (const vp of [{ width: 320, height: 700, mobile: true }, { width: 360, height: 800, mobile: true }, { width: 390, height: 844, mobile: true }, { width: 768, height: 1024, mobile: true }]) {
    const p = await newPage(browser, { width: vp.width, height: vp.height }, { mobile: true, allowStatus: [422] });
    await login(p);
    const check = async (what) => {
      const r = await layoutReport(p);
      if (r.overflowX) problems.push(`${vp.width}px ${what}: horizontal overflow ${r.scrollW} > ${r.vw}`);
      if (r.wide.length) problems.push(`${vp.width}px ${what}: wider than the viewport: ${r.wide.slice(0, 3)}`);
      if (r.small.length) problems.push(`${vp.width}px ${what}: tiny controls ${r.small.slice(0, 3)}`);
    };
    await go(p, '/admin/tags'); await check('tags list');
    await p.screenshot({ path: `${OUT}/phone-tags-${vp.width}.png` });
    await p.click('#add-tag'); await p.waitForSelector('#tag-dialog[open] #tag-form'); await p.waitForTimeout(400);
    await p.fill('#t-name', 'Phone check'); await check('tag editor');
    const fit = await p.evaluate(() => { const d = document.querySelector('#tag-dialog .dlg-panel').getBoundingClientRect(); const c = document.querySelector('#t-card .svc').getBoundingClientRect(); const b = document.querySelector('#t-card .svc-badge').getBoundingClientRect(); return { dlgR: d.right, vw: innerWidth, cardR: c.right, badge: [b.left, b.right], dlgL: d.left }; });
    if (fit.dlgR > fit.vw + 1 || fit.cardR > fit.dlgR + 1 || fit.badge[0] < fit.dlgL || fit.badge[1] > fit.dlgR) problems.push(`${vp.width}px: editor dialog / preview card do not fit ${JSON.stringify(fit)}`);
    await p.screenshot({ path: `${OUT}/phone-tag-editor-${vp.width}.png` });
    await p.keyboard.press('Escape'); await p.waitForTimeout(400);
    await go(p, '/admin/services');
    await p.locator('#services-root tbody tr').filter({ hasText: 'ig-likes-100' }).first().locator('[data-edit]').click();
    await p.waitForSelector('#service-drawer[open] #svc-form'); await p.waitForTimeout(500);
    await p.locator('#f-tag_id').scrollIntoViewIfNeeded(); await check('service editor');
    const fit2 = await p.evaluate(() => { const c = document.querySelector('#tag-preview .svc').getBoundingClientRect(); const dr = document.querySelector('#service-drawer .dlg-panel').getBoundingClientRect(); const b = document.querySelector('#tag-preview .svc-badge').getBoundingClientRect(); return { cardL: c.left, cardR: c.right, dlgL: dr.left, dlgR: dr.right, badge: [b.left, b.right] }; });
    if (fit2.cardR > fit2.dlgR + 1 || fit2.cardL < fit2.dlgL - 1 || fit2.badge[1] > fit2.dlgR) problems.push(`${vp.width}px: preview card outside the editor ${JSON.stringify(fit2)}`);
    await p.screenshot({ path: `${OUT}/phone-service-editor-${vp.width}.png` });
    await p.close();
  }
  t.eq(problems, [], problems.slice(0, 6).join('\n   '));
});

await t.step('no console errors, CSP violations or failed requests (the expected 4xx answers of the negative tests aside)', async () => {
  const bad = page.problems.filter((l) => !/status of (403|404|422)|\[http (403|404|422)\]/.test(l));
  t.eq(bad, [], bad.slice(0, 4).join('\n   '));
});

console.log(`\nscreenshots: ${OUT}`);
const failed = t.finish();
await browser.close();
process.exit(failed ? 1 : 0);
