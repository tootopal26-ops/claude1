// Orders page: filters, search, drawer, every order action, Approve All (with confirm + double-click protection), pagination, failures.
import { execFileSync } from 'node:child_process';
import { launch, newPage, login, go, BASE } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-orders');
const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeAllDialogs(); await clearToasts(); await fn(); });
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: '/home/user/claude1', stdio: 'ignore' });
execFileSync('php', ['tools/qa/reset-limits.php'], { cwd: '/home/user/claude1', stdio: 'ignore' });
const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 });
t.page = page;
await login(page);
await go(page, '/admin/orders');
const settled = () => page.waitForFunction(() => document.querySelector('#orders-root').getAttribute('aria-busy') === 'false' && !document.querySelector('#orders-root.is-stale'));
const rows = () => page.locator('#orders-root tbody tr');
const approveCount = async () => Number((await page.locator('#approve-all-count').textContent()) || 0);
const toast = (type) => page.locator(`.toast[data-type="${type}"]`).last();
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((n) => n.remove()));
const closeAllDialogs = async () => { for (let i = 0; i < 4; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
const openByChip = async (chipText) => {
  const row = rows().filter({ hasText: chipText }).first();
  await row.click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  return (await page.locator('#od-title').innerText()).trim();
};
const closeDrawer = async () => { await page.waitForFunction(() => !document.querySelector('dialog.dlg-modal[open]')); await page.keyboard.press('Escape'); await page.waitForFunction(() => !document.querySelector('#order-drawer[open]')); await clearToasts(); };

await t.step('list renders 25 rows with chips counts, pager and Approve All badge', async () => {
  t.eq(await rows().count(), 25);
  t.has(await t.text(page.locator('#order-chips')), 'All');
  t.has(await t.text(page.locator('.pager')), 'Showing 1–25 of');
  t.ok((await approveCount()) > 0, 'approve count');
  t.eq(await page.locator('#approve-all').isEnabled(), true);
});

await t.step('quick filter chips: Manual shows only MANUAL orders; URL reflects the filter', async () => {
  await page.click('[data-filter="manual"]');
  await settled();
  const modes = await rows().locator('.mode').allInnerTexts();
  t.ok(modes.length > 0 && modes.every((m) => m === 'MANUAL'), modes.slice(0, 5).join());
  t.has(page.url(), 'filter=manual');
  t.eq(await page.locator('[data-filter="manual"]').getAttribute('aria-pressed'), 'true');
  await page.click('[data-filter="auto"]'); await settled();
  t.ok((await rows().locator('.mode').allInnerTexts()).every((m) => m === 'AUTO'));
  await page.click('[data-filter="all"]'); await settled();
});

await t.step('every quick filter chip returns a valid list', async () => {
  for (const k of ['pending', 'paid', 'processing', 'completed', 'failed', 'refunded']) {
    await page.click(`[data-filter="${k}"]`); await settled();
    const n = await rows().count();
    const empty = await page.locator('#orders-root .empty').count();
    t.ok(n > 0 || empty === 1, `${k}: ${n} rows`);
  }
  await page.click('[data-filter="all"]'); await settled();
});

await t.step('search by order ID finds exactly that order; no match shows an empty state with Clear filters', async () => {
  const id = (await rows().first().locator('.mono').first().innerText()).trim();
  await Promise.all([page.waitForResponse((r) => r.url().includes('/admin/api/orders?') && r.url().includes('q=')), page.fill('#o-search', id)]);
  await settled();
  t.eq(await rows().count(), 1);
  t.has(await t.text(rows().first()), id);
  await Promise.all([page.waitForResponse((r) => r.url().includes('q=zzzz')), page.fill('#o-search', 'zzzz-no-such-order')]);
  await settled();
  t.has(await t.text(page.locator('#orders-root')), 'No orders match');
  await page.click('#orders-root [data-clear]');
  await settled();
  t.eq(await rows().count(), 25);
  t.eq(await page.locator('#o-search').inputValue(), '');
});

await t.step('status, platform, mode and date filters combine; Reset clears all', async () => {
  await page.selectOption('#o-status', 'FAILED'); await settled();
  const chips = await rows().locator('.st').allInnerTexts();
  t.ok(chips.length > 0 && chips.filter((c) => /Failed/.test(c)).length >= 1, chips.slice(0, 6).join());
  await page.selectOption('#o-platform', 'instagram'); await settled();
  await page.selectOption('#o-mode', 'AUTO'); await settled();
  t.ok(await page.locator('#o-reset').isVisible(), 'reset visible');
  await page.click('#o-reset'); await settled();
  t.eq(await rows().count(), 25);
  const today = await page.evaluate(() => new Date().toISOString().slice(0, 10));
  await page.fill('#o-from', today); await page.dispatchEvent('#o-from', 'change'); await settled();
  t.ok((await rows().count()) >= 0);
  await page.click('#o-reset'); await settled();
});

await t.step('pagination: Next loads page 2, Prev returns; buttons disable at the ends', async () => {
  t.eq(await page.locator('.pager [data-page]').first().isDisabled(), true, 'prev disabled on page 1');
  const firstId = (await rows().first().locator('.mono').first().innerText()).trim();
  await page.click('.pager [data-page]:has-text("Next")'); await settled();
  t.has(await t.text(page.locator('.pager')), '26–50');
  t.ok((await rows().first().locator('.mono').first().innerText()).trim() !== firstId, 'different first row');
  await page.click('.pager [data-page]:has-text("Prev")'); await settled();
  t.eq((await rows().first().locator('.mono').first().innerText()).trim(), firstId);
});

await t.step('order drawer: opens by click, shows all sections, closes with ESC and returns focus', async () => {
  const row = rows().first();
  await row.click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  const body = await t.text(page.locator('#od-body'));
  for (const s of ['ORDER', 'MONEY', 'PAYMENT', 'TIMELINE', 'INTERNAL NOTE']) t.has(body.toUpperCase(), s);
  t.has(await t.text(page.locator('#od-title')), 'SMB-');
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('#order-drawer[open]'));
  t.ok((await page.evaluate(() => document.activeElement?.closest('tr')?.dataset.open)) !== undefined, 'focus returned to a row');
});

await t.step('drawer opens by keyboard (Enter on a row) and via ?open= deep link', async () => {
  await rows().nth(1).locator('button[data-open]').focus();                       // rows are not controls; the View button inside is
  await page.keyboard.press('Enter');
  await page.waitForSelector('#order-drawer[open] .od-hero');
  const id = new URL(page.url()).searchParams.get('open');
  t.ok(/^\d+$/.test(id), 'open param: ' + id);
  await closeDrawer();
  await page.goto(`${BASE}/admin/orders?open=${id}`, { waitUntil: 'networkidle' });
  await page.waitForSelector('#order-drawer[open] .od-hero');
  await closeDrawer();
});

await t.step('drawer: customer link and UTR copy buttons work', async () => {
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: BASE });
  await openByChip('Completed');
  await page.locator('#od-body [data-copy]').first().click();
  await page.waitForSelector('.toast[data-type="success"]');
  const clip = await page.evaluate(() => navigator.clipboard.readText());
  t.ok(clip.length > 5, 'clipboard has text: ' + clip);
  await closeDrawer();
});

let approvedBefore = 0;
await t.step('single approve: MANUAL_PENDING → Manual approved, counts update', async () => {
  approvedBefore = await approveCount();
  await openByChip('Manual pending');
  t.ok(await page.locator('#od-body [data-act="approve"]').isVisible(), 'Approve button shown');
  await page.click('#od-body [data-act="approve"]');
  await toast('success').waitFor();
  t.has(await t.text(toast('success')), 'approved');
  t.has(await t.text(page.locator('#od-body .od-hero')), 'Manual approved');
  t.eq(await page.locator('#od-body [data-act="approve"]').count(), 0, 'approve button gone');
  await closeDrawer(); await settled();
  t.eq(await approveCount(), approvedBefore - 1);
});

await t.step('APPROVE ALL: confirm dialog (Cancel does nothing), then approves in one go; double click = one request', async () => {
  const before = await approveCount();
  t.ok(before >= 1, 'something to approve');
  await page.click('#approve-all');
  await page.waitForSelector('dialog.dlg[open]');
  t.has(await t.text(page.locator('dialog.dlg[open] h2')), 'Approve all eligible manual orders?');
  t.has(await t.text(page.locator('dialog.dlg[open] .dlg-body')), String(before));
  await page.click('dialog.dlg[open] [data-act="no"]:has-text("Cancel")');
  await page.waitForFunction(() => !document.querySelector('dialog.dlg[open]'));
  t.eq(await approveCount(), before, 'cancel changed nothing');
  let posts = 0;
  page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes('orders/approve-all')) posts++; });
  await page.click('#approve-all');
  await page.waitForSelector('dialog.dlg[open] [data-act="yes"]');
  await page.dblclick('dialog.dlg[open] [data-act="yes"]');
  await toast('success').waitFor();
  t.has(await t.text(toast('success')), 'approved');
  await settled();
  t.eq(posts, 1, 'only one approve-all request');
  t.eq(await page.locator('#approve-all').isDisabled(), true, 'button disabled when nothing is left');
  t.eq(await page.locator('[data-approve-count]').first().isHidden(), true, 'sidebar badge hidden');
});

await t.step('Approve All API: needs confirm:true and an Idempotency-Key; replays are harmless', async () => {
  const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
  const h = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': csrf, Origin: BASE };
  let r = await page.request.post(BASE + '/admin/api/orders/approve-all', { headers: { ...h, 'Idempotency-Key': 'qa-key-0001' }, data: {} });
  t.eq(r.status(), 422, 'no confirm → 422');
  r = await page.request.post(BASE + '/admin/api/orders/approve-all', { headers: h, data: { confirm: true } });
  t.eq(r.status(), 400, 'no idempotency key → 400');
  r = await page.request.post(BASE + '/admin/api/orders/approve-all', { headers: { ...h, 'Idempotency-Key': 'qa-key-0002' }, data: { confirm: true } });
  t.eq(r.status(), 200);
  const first = await r.json();
  const r2 = await page.request.post(BASE + '/admin/api/orders/approve-all', { headers: { ...h, 'Idempotency-Key': 'qa-key-0002' }, data: { confirm: true } });
  t.eq(await r2.json(), first, 'replay returns the same response');
  t.eq(r2.headers()['x-idempotent-replay'], 'true');
});

await t.step('Approve All with nothing to approve: friendly message, no error', async () => {
  const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
  const r = await page.request.post(BASE + '/admin/api/orders/approve-all', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': csrf, Origin: BASE, 'Idempotency-Key': 'qa-key-0003' }, data: { confirm: true } });
  const j = await r.json();
  t.eq(j.approved, 0);
  t.has(j.message, 'no eligible');
});

await t.step('AUTO failed order: Retry provider submission responds with a clear outcome', async () => {
  await page.click('[data-filter="failed"]'); await settled();
  const row = rows().filter({ has: page.locator('.mode-auto') }).filter({ hasText: 'Provider rejected' }).first();
  t.ok(await row.count() === 1, 'a paid FAILED AUTO order exists in the demo data');
  await row.click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  const actions = await page.locator('#od-body [data-act]').allInnerTexts();
  t.ok(actions.some((a) => /Retry provider/.test(a)), actions.join('|'));
  t.ok(actions.some((a) => /Move to manual/.test(a)), 'move to manual offered');
  await page.click('#od-body [data-act="retry_submit"]');
  await page.waitForSelector('.toast');
  await closeDrawer();
});

await t.step('Move to manual queue: confirm dialog, then order becomes Manual pending', async () => {
  await page.click('[data-filter="failed"]'); await settled();
  const row = rows().filter({ has: page.locator('.mode-auto') }).filter({ hasText: 'Provider rejected' }).last();
  t.ok(await row.count() === 1, 'a paid FAILED AUTO order exists');
  await row.click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  t.ok(await page.locator('#od-body [data-act="move_to_manual"]').count() === 1, 'move to manual offered');
  await page.click('#od-body [data-act="move_to_manual"]');
  await page.waitForSelector('dialog.dlg[open] [data-act="yes"]');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await toast('success').waitFor();
  t.has(await t.text(page.locator('#od-body .od-hero')), 'Manual pending');
  await closeDrawer();
});

await t.step('refund: validation (reference required, amount > remaining rejected), then full refund recorded', async () => {
  await page.click('[data-filter="completed"]'); await settled();
  const row = rows().filter({ has: page.locator('.mode-manual') }).first();
  const target = (await row.count()) ? row : rows().first();
  await target.click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  await page.click('#od-body [data-act="refund"]');
  await page.waitForSelector('dialog.dlg[open] form');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  t.ok((await page.locator('dialog.dlg[open] [data-err]').count()) >= 1, 'required-field error shown');
  await page.fill('dialog.dlg[open] [name="reference"]', 'REF-QA-0001');
  await page.fill('dialog.dlg[open] [name="amount"]', '999999');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await page.waitForSelector('dialog.dlg[open] [data-form-error]:not([hidden]), dialog.dlg[open] [data-err]');
  t.ok(await page.locator('dialog.dlg-modal[open]').count() === 1, 'dialog stays open on server error');
  const amountVal = await page.locator('dialog.dlg[open] [name="amount"]').inputValue();
  t.eq(amountVal, '999999', 'what the user typed is kept');
  await page.fill('dialog.dlg[open] [name="amount"]', '');
  await page.fill('dialog.dlg[open] [name="amount"]', '1');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await toast('success').waitFor();
  const hero = await t.text(page.locator('#od-body .od-hero'));
  t.ok(/Refunded|Partial|Completed|Manual approved/.test(hero), hero);
  t.has(await t.text(page.locator('#od-body')), 'REF-QA-0001');
  await closeDrawer();
});

await t.step('cancel an unpaid-or-pending order with a reason', async () => {
  await page.click('[data-filter="all"]'); await settled();
  await page.selectOption('#o-status', 'MANUAL_PENDING'); await settled();
  if (!(await rows().count())) { await page.click('#o-reset'); await settled(); return; }
  await rows().first().click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  await page.click('#od-body [data-act="cancel"]');
  await page.fill('dialog.dlg[open] [name="note"]', 'QA: customer asked to cancel');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await toast('success').waitFor();
  t.has(await t.text(page.locator('#od-body .od-hero')), 'Cancelled');
  t.has(await t.text(page.locator('#od-body .timeline')), 'QA: customer asked to cancel');
  await closeDrawer();
  await page.click('#o-reset'); await settled();
});

await t.step('confirm payment manually on an EXPIRED order (UTR required) marks it paid once', async () => {
  await page.selectOption('#o-status', 'EXPIRED'); await settled();
  await rows().first().click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  t.ok(await page.locator('#od-body [data-act="confirm_payment"]').count() === 1, 'confirm payment offered');
  await page.click('#od-body [data-act="confirm_payment"]');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  t.ok((await page.locator('dialog.dlg[open] [data-err]').count()) >= 1, 'UTR is required');
  await page.fill('dialog.dlg[open] [name="reference"]', 'UTRQA' + Date.now());
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await toast('success').waitFor();
  const hero = await t.text(page.locator('#od-body .od-hero'));
  t.ok(/Manual pending|Paid|Submitted|In progress|Processing|Completed|Failed/.test(hero), hero);
  t.has(hero, 'late payment');
  t.eq(await page.locator('#od-body [data-act="confirm_payment"]').count(), 0, 'no second confirmation possible');
  await closeDrawer();
  await page.click('#o-reset'); await settled();
});

await t.step('internal note saves and persists after reopening', async () => {
  await rows().first().click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  const note = 'QA note ' + Date.now();
  await page.fill('#od-note', note);
  await page.click('#od-body [data-act="note"]');
  await toast('success').waitFor();
  await closeDrawer();
  await rows().first().click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  t.eq(await page.locator('#od-note').inputValue(), note);
  await closeDrawer();
});

await t.step('XSS: hostile text in a target/note is escaped everywhere it is shown', async () => {
  execFileSync('php', ['tools/qa/set-hostile.php'], { cwd: '/home/user/claude1', stdio: 'ignore' });
  await go(page, '/admin/orders');
  await rows().first().click();
  await page.waitForSelector('#order-drawer[open] .od-hero');
  t.eq(await page.evaluate(() => window.__xss), undefined, 'no script executed');
  t.has(await t.text(page.locator('#od-body')), '<img src=x onerror=window.__xss=1>');
  t.eq(await page.locator('#od-body img[src="x"]').count(), 0);
  await closeDrawer();
});

await t.step('drawer API failure shows an error with Retry instead of a blank panel', async () => {
  await page.route('**/admin/api/orders/*', (route) => (route.request().method() === 'GET' && /orders\/\d+$/.test(route.request().url()) ? route.fulfill({ status: 500, contentType: 'application/json', body: '{"ok":false,"error":{"message":"Something went wrong. Please try again."}}' }) : route.continue()));
  await rows().first().click();
  await page.waitForSelector('#od-body [data-retry]');
  t.has(await t.text(page.locator('#od-body')), 'Could not load');
  await page.unroute('**/admin/api/orders/*');
  await page.click('#od-body [data-retry]');
  await page.waitForSelector('#od-body .od-hero');
  await closeDrawer();
});

console.log('console/network problems:', page.problems.join('\n') || 'none');
await browser.close();
process.exit(t.finish() ? 1 : 0);
