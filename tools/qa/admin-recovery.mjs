// Admin → Order recovery, end to end in a real browser: paid AUTO orders put into every recovery state through the real code paths (mock SMM panel,
// mock gateway), the queue and its numbers, every action and its safety rules (an unknown outcome is never resent without "CHECKED", a provider order
// is linked only when the provider confirms it, one click sends once), the retry policy, the job buttons, the order drawer, and what each ROLE sees.
// Local dev only (resets the dev database). Run:  node tools/qa/admin-recovery.mjs
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { launch, newPage, BASE, ROOT, SMM, sql, rows, resetLimits, mocksReset, useMockProviderIds, php } from './site.mjs';
import { go, login, coveredControls, layoutReport } from './admin.mjs';
import { suite } from './harness.mjs';

const require = createRequire(import.meta.url);
const AXE = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const t = suite('admin-recovery');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
useMockProviderIds(); resetLimits(); await mocksReset();

// ── a small world made through the real code paths ──────────────────────────────────────────────────────────────────────────────────────
const w = JSON.parse(php('tools/qa/recovery-seed.php').trim());
const panelOrders = async () => Object.values((await (await fetch(SMM + '/__state')).json()).orders);
const atPanel = async (link) => (await panelOrders()).filter((o) => o.link === link);
const orderRow = (key) => rows(`SELECT status, mode, provider_order_id, recovery_status, failure_kind, provider_attempts FROM orders WHERE id = ${w[key].id}`)[0];
const link = (key) => `https://www.instagram.com/${key}/`;

// a demo order that failed for a plain reason, and one that is at the provider but not followed (the baseline history), for the actions the seed does not cover
const demoFailed = rows("SELECT id, public_id FROM orders WHERE status = 'FAILED' AND paid_at IS NOT NULL AND COALESCE(failure_kind, '') <> 'ambiguous' AND public_id NOT IN ('" + w.r_failed.public_id + "') ORDER BY id LIMIT 2");
const demoStale = rows("SELECT id, public_id FROM orders WHERE status IN ('SUBMITTED', 'IN_PROGRESS') AND provider_order_id IS NOT NULL AND COALESCE(last_sync_at, submitted_at) < UTC_TIMESTAMP() - INTERVAL 30 MINUTE AND public_id <> '" + w.r_stale.public_id + "' ORDER BY id LIMIT 1");
t.ok(demoFailed.length === 2 && demoStale.length === 1, `the baseline history has orders to work with: ${JSON.stringify([demoFailed, demoStale])}`);

// the categories, restated independently of the PHP that derives them
const BASE_W = "o.paid_at IS NOT NULL AND o.status IN ('PAID','PROCESSING','SUBMITTED','IN_PROGRESS','FAILED')";
const LIVE = "o.status IN ('SUBMITTED','IN_PROGRESS') AND o.provider_order_id IS NOT NULL";
const COND = {
  unknown: "o.status = 'FAILED' AND o.failure_kind = 'ambiguous' AND COALESCE(o.recovery_status,'') <> 'resolved'",
  review: "o.status IN ('PAID','PROCESSING','FAILED','SUBMITTED','IN_PROGRESS') AND o.recovery_status IN ('needs_review','escalated')",
  failed: "o.status = 'FAILED' AND COALESCE(o.failure_kind,'') <> 'ambiguous' AND COALESCE(o.recovery_status,'') <> 'resolved'",
  sending: "o.status = 'PROCESSING' AND o.updated_at < UTC_TIMESTAMP() - INTERVAL 300 SECOND",
  errors: `${LIVE} AND (SELECT COUNT(*) FROM smm_transactions x WHERE x.order_id = o.id AND x.action = 'status' AND x.ok = 0 AND x.created_at >= UTC_TIMESTAMP() - INTERVAL 7200 SECOND) >= 3`,
  stale: `${LIVE} AND COALESCE(o.last_sync_at, o.submitted_at) < UTC_TIMESTAMP() - INTERVAL 1800 SECOND`,
  waiting: "o.mode = 'AUTO' AND o.status = 'PAID' AND o.provider_order_id IS NULL AND (o.next_attempt_at IS NULL OR o.next_attempt_at <= UTC_TIMESTAMP())",
  retry: "o.mode = 'AUTO' AND o.status = 'PAID' AND o.provider_order_id IS NULL AND o.next_attempt_at > UTC_TIMESTAMP()",
};
const count = (cond) => Number(sql(`SELECT COUNT(*) FROM orders o WHERE ${BASE_W} AND (${cond})`));
const inCat = (k) => count(COND[k]);
const needsPerson = () => count(`(${COND.unknown}) OR (${COND.review}) OR (${COND.failed})`);
const inQueue = () => count(Object.values(COND).map((c) => `(${c})`).join(' OR '));

const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [403, 409, 422, 429, 502] });
t.page = page;
await login(page);
const toastLast = () => page.locator('.toast').last();
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((n) => n.remove()));
const closeDialogs = async () => { for (let i = 0; i < 3; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeDialogs(); await clearToasts(); await fn(); });
const root = () => page.locator('#rc-root');
const settle = async () => { await page.waitForFunction(() => document.querySelector('#rc-root')?.getAttribute('aria-busy') === 'false'); await page.waitForTimeout(250); };
const typed = async () => { await page.waitForTimeout(550); await settle(); };
const open = async (q = '') => { await page.goto(BASE + '/admin/recovery' + q, { waitUntil: 'networkidle' }); await settle(); };
const rowOf = (key) => root().locator('tbody tr', { hasText: w[key]?.public_id ?? key });
const rowText = async (key) => (await rowOf(key).first().innerText()).replace(/\s+/g, ' ');
const rowHas = async (key, part) => {                                   // the table reloads right after the toast: wait for the row to say it
  const id = w[key]?.public_id ?? key;
  await page.waitForFunction(([id_, part_]) => [...document.querySelectorAll('#rc-root tbody tr')].some((r) => r.innerText.includes(id_) && r.innerText.replace(/\s+/g, ' ').includes(part_)), [id, part], { timeout: 8000 });
};
const kpi = async (label) => (await page.locator('#rc-summary .kpi', { hasText: label }).first().locator('.kpi-value').innerText()).trim();
const dlg = () => page.locator('dialog.dlg-modal[open]');          // the form / confirm dialogs (the order drawer is a dialog too, and is not one of these)
const gone = async (publicId) => { await root().locator('tbody tr', { hasText: publicId }).waitFor({ state: 'detached', timeout: 8000 }); };   // the table reloads right after the toast
const submitDialog = () => dlg().locator('[data-act="yes"]').click();
const successToast = async (part) => { await page.waitForSelector('.toast[data-type="success"]'); if (part) t.has(await t.text(toastLast()), part); };
const search = async (key) => { await page.fill('#rc-search', w[key]?.public_id ?? key); await typed(); };
const clearSearch = async () => { await page.fill('#rc-search', ''); await typed(); };
const history = (id) => rows(`SELECT to_status, note FROM order_status_history WHERE order_id = ${id} ORDER BY id DESC LIMIT 3`);
const auditCount = (action, id) => Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action = '${action}' AND entity_id = '${id}'`));

await open();

// ───────────────────────────── the queue ─────────────────────────────
await t.step('the world is as designed: every scenario is in the state the recovery rules say', async () => {
  const s = (k) => orderRow(k);                                     // [status, mode, provider_order_id, recovery_status, failure_kind, attempts]
  t.eq([s('r_live')[0], s('r_live')[1]], ['SUBMITTED', 'AUTO']);
  t.ok(s('r_live')[2] !== 'NULL', 'a delivered order has a provider order id');
  t.eq([s('r_waiting')[0], s('r_waiting')[2]], ['PAID', 'NULL'], 'a paid order not yet sent');
  t.eq([s('r_unknown')[0], s('r_unknown')[4]], ['FAILED', 'ambiguous'], 'a garbled answer is an unknown outcome');
  t.eq([s('r_link')[0], s('r_link')[4], s('r_resend')[4]], ['FAILED', 'ambiguous', 'ambiguous']);
  t.eq(s('r_failed')[0], 'FAILED');
  t.eq(s('r_review')[3], 'needs_review');
  t.ok(Number(w.r_link.panel_order_id) > 0, 'the provider panel holds r_link\'s order');
  t.eq((await atPanel(link('r_link'))).length, 1);
  t.eq((await atPanel(link('r_resend'))).length, 0, 'the provider has nothing for r_resend');
  t.eq((await atPanel(link('r_unknown'))).length, 0);
});

await t.step('summary cards are the database, not decoration; the sidebar badge counts what needs a person', async () => {
  t.eq(await kpi('Needs a person'), String(needsPerson()));
  const sub = (label) => page.locator('#rc-summary .kpi', { hasText: label }).first().locator('.kpi-sub').innerText();
  const s1 = await sub('Needs a person');
  t.has(s1, `${inCat('unknown')} unknown outcome`); t.has(s1, `${inCat('review')} review`); t.has(s1, `${inCat('failed')} failed`);
  t.eq(await kpi('Waiting or retrying'), String(inCat('waiting') + inCat('retry')));
  t.eq(await kpi('Provider follow-up'), String(inCat('sending') + inCat('errors') + inCat('stale')));
  t.eq(await kpi('Recovery job'), 'Never run');
  t.has(await sub('Recovery job'), 'add the cron line');
  const badge = page.locator('.adm-side [data-recovery-count]');
  await badge.waitFor({ state: 'visible' });
  t.eq(await badge.innerText(), String(needsPerson()));
  t.has(await t.text(page.locator('#rc-tab-queue')), `Queue (${inQueue()})`);
});

await t.step('every row says what is wrong, what was tried, where the provider stands and offers the right first action', async () => {
  const r = {
    unknown: await rowText('r_unknown'), link: await rowText('r_link'), failed: await rowText('r_failed'), waiting: await rowText('r_waiting'), stale: await rowText('r_stale'), review: await rowText('r_review'),
  };
  t.has(r.unknown, 'Provider outcome unknown'); t.has(r.unknown, 'Unclear provider response'); t.has(r.unknown, '1 of 5'); t.has(r.unknown, 'no provider order'); t.has(r.unknown, 'Failed'); t.has(r.unknown, 'Link order…');
  t.has(r.unknown, link('r_unknown'), 'the account link is readable');
  t.has(r.failed, 'Failed'); t.has(r.failed, 'Incorrect service ID'); t.has(r.failed, 'Send now');
  t.ok(!r.failed.includes('Provider outcome unknown'), 'a plain failure is not an unknown outcome');
  t.has(r.waiting, 'Waiting to be sent'); t.has(r.waiting, '0 of 5'); t.has(r.waiting, 'Send now');
  t.has(r.stale, 'Provider not followed'); t.has(r.stale, 'Mock SMM Panel'); t.has(r.stale, 'Sync');
  t.has(r.review, 'Needs review'); t.has(r.review, 'Resolve…');
  t.eq(await root().locator('tbody tr', { hasText: w.r_live.public_id }).count(), 0, 'a delivered, followed order is not in the queue');
  t.eq(await root().locator('tbody tr').count(), Math.min(25, inQueue()), 'one row per order in the queue');
  t.ok(await page.locator('#rc-root tbody tr.is-flagged').count() >= 3, 'orders that need a person carry the amber marker');
  // unknown outcomes come first: the most urgent is on top
  const first = (await root().locator('tbody tr').first().innerText()).replace(/\s+/g, ' ');
  t.has(first, 'Provider outcome unknown');
});

await t.step('the problems are explained once, in a legend, not repeated on every row', async () => {
  const summary = page.locator('.rc-legend > summary');
  t.has(await t.text(summary), 'What the problems mean');
  t.eq(await page.locator('.rc-legend').evaluate((e) => e.open), false, 'closed by default');
  await summary.click();
  const text = await t.text(page.locator('.rc-legend'));
  for (const part of ['Provider outcome unknown', 'never resent by itself', 'Needs review', 'Failed', 'Sending is stuck', 'Repeated provider errors', 'Provider not followed', 'Waiting to be sent', 'Retry scheduled']) t.has(text, part);
  const rowsText = (await root().locator('tbody').innerText()).replace(/\s+/g, ' ');
  t.ok(!rowsText.includes('It is never resent by itself: check the provider panel'), 'rows do not repeat the explanation');
  await summary.click();
});

await t.step('filters: each problem narrows the queue to exactly its orders; the address bar and a reload remember it', async () => {
  for (const [key, label] of [['unknown', 'Unknown outcome'], ['review', 'Needs review'], ['failed', 'Failed'], ['stale', 'Not followed'], ['waiting', 'Waiting']]) {
    await page.click(`[data-category="${key}"]`); await settle();
    t.eq(await root().locator('tbody tr').count(), Math.min(25, inCat(key)), label);
    t.has(page.url(), 'category=' + key);
    t.eq(await page.locator(`[data-category="${key}"] b`).innerText(), String(inCat(key)), `${label} chip count`);
  }
  await page.click('[data-category="unknown"]'); await settle();
  const names = (await root().locator('tbody tr b.mono').allInnerTexts()).sort();
  t.eq(names, [w.r_link.public_id, w.r_resend.public_id, w.r_unknown.public_id].sort(), 'exactly the three unknown outcomes');
  await page.reload({ waitUntil: 'networkidle' }); await settle();
  t.eq(await page.getAttribute('[data-category="unknown"]', 'aria-pressed'), 'true', 'the filter survives a reload');
  t.eq(await root().locator('tbody tr').count(), 3);
  await page.click('[data-category=""]'); await settle();
  await search('r_review');
  t.eq(await root().locator('tbody tr').count(), 1);
  await page.fill('#rc-search', 'https://www.instagram.com/r_stale/'); await typed();
  t.eq(await root().locator('tbody tr').count(), 1, 'search by the account link');
  await page.fill('#rc-search', 'no-such-order-anywhere'); await typed();
  t.has(await t.text(root()), 'No orders match');
  await clearSearch();
  t.eq(await root().locator('tbody tr').count(), Math.min(25, inQueue()));
});

await t.step('"More" lists every action the order allows, each with what it does — and the primary button is the safest useful one', async () => {
  await search('r_resend');
  await rowOf('r_resend').locator('[data-rc-more]').click();
  await page.waitForSelector('dialog.dlg[open] [data-pick]');
  const text = await t.text(dlg());
  for (const part of ['Resend after checking…', 'Link provider order…', 'Move to manual queue…', 'Only after you have looked in the provider’s panel', 'Enter its order id', 'You deliver it yourself from the manual queue']) t.has(text, part);
  t.eq(await dlg().locator('[data-pick="retry_submit"]').count(), 1);
  await page.keyboard.press('Escape'); await page.waitForTimeout(450);
  // the primary button of an unknown outcome is "link", never "resend"
  t.eq(await rowOf('r_resend').locator('[data-rc-act]').getAttribute('data-rc-act'), 'link_provider_order');
  await clearSearch();
});

// ───────────────────────────── actions ─────────────────────────────
await t.step('link a provider order: a wrong id is refused by the provider\'s own answer and changes nothing; the right id links it', async () => {
  const before = orderRow('r_link');
  await search('r_link');
  await rowOf('r_link').locator('[data-rc-act]').click();
  await page.waitForSelector('dialog.dlg[open] input[name=provider_order_id]');
  await submitDialog();
  t.has(await t.text(dlg()), 'required');
  await dlg().locator('input[name=provider_order_id]').fill('99999');
  await submitDialog();
  await dlg().locator('.field-error').first().waitFor();
  t.has(await t.text(dlg()), 'The provider does not know this id');
  t.eq(orderRow('r_link'), before, 'nothing changed');
  // an id that belongs to a different order of ours is refused too
  await dlg().locator('input[name=provider_order_id]').fill(w.r_live.provider_order_id);
  await submitDialog();
  await page.waitForFunction(() => /Already linked/.test(document.querySelector('dialog.dlg[open]')?.innerText || ''));
  t.eq(orderRow('r_link'), before, 'still nothing changed');
  // the id the person found in the provider panel
  await dlg().locator('input[name=provider_order_id]').fill(w.r_link.panel_order_id);
  await dlg().locator('textarea[name=note]').fill('Found it in the panel');
  await submitDialog();
  await successToast('linked');
  await settle();
  const after = orderRow('r_link');
  t.eq([after[0], after[2]], ['SUBMITTED', w.r_link.panel_order_id], 'the order now follows the provider order');
  t.eq(auditCount('order.recovery.linked', w.r_link.id), 1, 'audited');
  t.eq((await atPanel(link('r_link'))).length, 1, 'the provider still has exactly one order for this link');
  await gone(w.r_link.public_id);
  t.ok(history(w.r_link.id).some(([to, note]) => /linked/i.test(note) || to === 'SUBMITTED'), 'the order timeline says so: ' + JSON.stringify(history(w.r_link.id)));
  await clearSearch();
});

await t.step('resend after checking: nothing is sent until the person types CHECKED and says what they looked at; then it is sent exactly once', async () => {
  const before = orderRow('r_resend');
  await search('r_resend');
  await rowOf('r_resend').locator('[data-rc-more]').click();
  await page.waitForSelector('dialog.dlg[open] [data-pick]');
  await dlg().locator('[data-pick="retry_submit"]').click();
  await page.waitForSelector('dialog.dlg[open] input[name=confirm]');
  await submitDialog();
  t.has(await t.text(dlg()), 'required');
  await dlg().locator('input[name=confirm]').fill('yes');
  await dlg().locator('textarea[name=note]').fill('Searched the panel');
  await submitDialog();
  await dlg().locator('.field-error').first().waitFor();
  t.has(await t.text(dlg()), 'Type CHECKED to confirm');
  t.eq(orderRow('r_resend'), before, 'nothing was sent');
  t.eq((await atPanel(link('r_resend'))).length, 0);
  await dlg().locator('input[name=confirm]').fill('checked');
  await submitDialog();
  await successToast('Submitted to the provider');
  await settle();
  t.eq(orderRow('r_resend')[0], 'SUBMITTED');
  t.ok(orderRow('r_resend')[2], 'a provider order id was recorded');
  t.eq((await atPanel(link('r_resend'))).length, 1, 'the provider holds exactly one order for it');
  t.eq(auditCount('order.recovery.retry', w.r_resend.id), 1, 'audited');
  t.ok(history(w.r_resend.id).some(([, note]) => /no order exists for this link/.test(note)), 'the timeline records what the person confirmed: ' + JSON.stringify(history(w.r_resend.id)));
  await clearSearch();
});

await t.step('Send now: one click sends once — a double click does not create a second provider order', async () => {
  await search('r_waiting');
  const btn = rowOf('r_waiting').locator('[data-rc-act="retry_submit"]');
  t.has(await t.text(btn), 'Send now');
  await btn.dblclick();
  await successToast('Submitted to the provider');
  await settle();
  t.eq(orderRow('r_waiting')[0], 'SUBMITTED');
  t.eq((await atPanel(link('r_waiting'))).length, 1, 'one order at the provider');
  t.eq(auditCount('order.recovery.retry', w.r_waiting.id), 1, 'audited once');
  await gone(w.r_waiting.public_id);
  await clearSearch();
});

await t.step('Send now on a failure the provider refuses again: the order stays failed, the new reason is shown, and the attempt is counted', async () => {
  // a retry re-reads the service, so a fixed SMM service id applies; what the provider still refuses is a bad link
  sql(`UPDATE orders SET target_url = 'https://www.instagram.com/badlink_user/' WHERE id = ${w.r_failed.id}`);
  await search('r_failed');
  const attempts = Number(sql(`SELECT provider_attempts FROM orders WHERE id = ${w.r_failed.id}`));
  await rowOf('r_failed').locator('[data-rc-act="retry_submit"]').click();
  await page.waitForSelector('.toast');
  await page.waitForFunction((id) => /Incorrect link/.test([...document.querySelectorAll('#rc-root tbody tr')].find((r) => r.innerText.includes(id))?.innerText || ''), w.r_failed.public_id);
  await settle();
  t.eq(orderRow('r_failed')[0], 'FAILED', 'still failed');
  t.has(await rowText('r_failed'), 'Provider rejected the order: Incorrect link');
  t.eq(Number(sql(`SELECT provider_attempts FROM orders WHERE id = ${w.r_failed.id}`)), attempts + 1, 'the attempt was counted');
  t.eq((await atPanel('https://www.instagram.com/badlink_user/')).length, 0, 'nothing was created at the provider');
  await clearSearch();
});

await t.step('Sync: asks the provider now; the order is followed again and leaves "Not followed"', async () => {
  await search('r_stale');
  const before = sql(`SELECT last_sync_at FROM orders WHERE id = ${w.r_stale.id}`);
  await rowOf('r_stale').locator('[data-rc-act="sync"]').click();
  await successToast();
  await settle();
  t.ok(sql(`SELECT last_sync_at FROM orders WHERE id = ${w.r_stale.id}`) > before, 'last_sync_at moved forward');
  await gone(w.r_stale.public_id);
  t.eq(auditCount('order.recovery.sync', w.r_stale.id), 1);
  await clearSearch();
});

await t.step('Escalate and Mark for review need a person\'s words (escalate) or take them optionally (review); who and when are kept', async () => {
  await search('r_failed');
  await rowOf('r_failed').locator('[data-rc-more]').click();
  await page.waitForSelector('dialog.dlg[open] [data-pick]');
  await dlg().locator('[data-pick="escalate"]').click();
  await page.waitForSelector('dialog.dlg[open] textarea[name=note]');
  await submitDialog();
  t.has(await t.text(dlg()), 'required');
  t.ok(orderRow('r_failed')[3] !== 'escalated', 'not escalated yet');
  await dlg().locator('textarea[name=note]').fill('Customer is a reseller, please look today');
  await submitDialog();
  await successToast('Escalated');
  await settle();
  t.eq(rows(`SELECT recovery_status, escalated_by, escalated_at IS NOT NULL FROM orders WHERE id = ${w.r_failed.id}`)[0], ['escalated', '1', '1']);
  await rowHas('r_failed', 'Escalated'); t.has(await rowText('r_failed'), 'by owner'); t.has(await rowText('r_failed'), 'Needs review');
  t.eq(auditCount('order.recovery.escalated', w.r_failed.id), 1);
  await clearSearch();
  // a stale demo order marked for review (the note is optional)
  await clearToasts();
  const [dsId, dsPublic] = demoStale[0];
  await search(dsPublic);
  await rowOf(dsPublic).locator('[data-rc-more]').click();
  await page.waitForSelector('dialog.dlg[open] [data-pick]');
  await dlg().locator('[data-pick="mark_review"]').click();
  await page.waitForSelector('dialog.dlg[open] textarea[name=note]');
  await submitDialog();
  await successToast('Marked for review');
  await settle();
  t.eq(sql(`SELECT recovery_status FROM orders WHERE id = ${dsId}`), 'needs_review');
  await rowHas(dsPublic, 'Needs review');
  await clearSearch();
});

await t.step('Resolve: says what was done, closes the review and takes the order out of the queue', async () => {
  await search('r_review');
  const btn = rowOf('r_review').locator('[data-rc-act]');
  t.eq(await btn.getAttribute('data-rc-act'), 'resolve_review');
  await btn.click();
  await page.waitForSelector('dialog.dlg[open] textarea[name=note]');
  await submitDialog();
  t.has(await t.text(dlg()), 'required');
  await dlg().locator('textarea[name=note]').fill('Customer informed, delivery is on track');
  await submitDialog();
  await successToast('Resolved');
  await settle();
  t.eq(orderRow('r_review')[3], 'resolved');
  await gone(w.r_review.public_id);
  t.eq(auditCount('order.recovery.resolved', w.r_review.id), 1);
  t.ok(history(w.r_review.id).some(([, note]) => /Customer informed/.test(note)), 'the note is on the timeline');
  await clearSearch();
});

await t.step('Move to the manual queue: asks first, then stops sending to the provider and hands the order to the manual queue', async () => {
  const [id, publicId] = demoFailed[0];
  await search(publicId);
  await rowOf(publicId).locator('[data-rc-more]').click();
  await page.waitForSelector('dialog.dlg[open] [data-pick]');
  await dlg().locator('[data-pick="move_to_manual"]').click();
  await page.waitForSelector('dialog.dlg[open] [data-act="yes"]');
  t.has(await t.text(dlg()), 'stop going to the SMM provider');
  await dlg().locator('[data-act="no"]').last().click(); await page.waitForTimeout(450);
  t.eq(sql(`SELECT mode FROM orders WHERE id = ${id}`), 'AUTO', 'cancelling changes nothing');
  await rowOf(publicId).locator('[data-rc-more]').click();
  await page.waitForSelector('dialog.dlg[open] [data-pick]');
  await dlg().locator('[data-pick="move_to_manual"]').click();
  await page.waitForSelector('dialog.dlg[open] [data-act="yes"]');
  await dlg().locator('[data-act="yes"]').click();
  await successToast('Moved to the manual queue');
  await settle();
  t.eq(rows(`SELECT mode, status FROM orders WHERE id = ${id}`)[0], ['MANUAL', 'MANUAL_PENDING']);
  await gone(publicId);
  t.eq(Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE entity_id = '${id}' AND action = 'order.action' AND meta_json LIKE '%move_to_manual%'`)), 1, 'audited');
  await clearSearch();
});

await t.step('the numbers followed every action: cards, chip counts and the sidebar badge are the database again', async () => {
  await open();
  t.eq(await kpi('Needs a person'), String(needsPerson()));
  t.eq(await page.locator('.adm-side [data-recovery-count]').innerText(), String(needsPerson()));
  t.has(await t.text(page.locator('#rc-tab-queue')), `Queue (${inQueue()})`);
  for (const k of ['unknown', 'review', 'failed', 'stale', 'waiting', 'retry']) t.eq(await page.locator(`[data-category="${k}"] b`).innerText(), String(inCat(k)), k);
});

// ───────────────────────────── retry policy ─────────────────────────────
await t.step('retry policy: validated by the server, saved, audited, shown on every row, and the schedule is spelled out', async () => {
  await open('?tab=policy');
  const text = await t.text(root());
  for (const part of ['wait 60 s', 'wait 5 min', 'wait 20 min', 'wait 1 h', 'never retried by itself'.replace('never retried by itself', 'Never retried automatically'), 'not supported by the SMM API v2']) t.has(text, part);
  for (const bad of ['0', '11']) {
    await page.fill('#rp-max', bad);
    await page.click('#rp-save');
    await page.waitForSelector('#rc-policy .field-error');
    t.has(await t.text(page.locator('#rc-policy .field-error')), 'from 1 to 10');
  }
  t.eq(Number(sql("SELECT COUNT(*) FROM admin_settings WHERE k = 'recovery.max_attempts'")), 0, 'nothing was saved');
  await page.fill('#rp-max', '3');
  await page.click('#rp-save');
  await successToast('Retry policy saved');
  t.eq(sql("SELECT v FROM admin_settings WHERE k = 'recovery.max_attempts'"), '3');
  t.eq(auditCount('recovery.settings_changed', 'recovery'), 1);
  t.has(await t.text(root()), 'attempt 3 fails'); t.ok(!(await t.text(root())).includes('attempt 4 fails'), 'the schedule has three attempts');
  await open();
  t.has(await rowText(w.r_unknown.public_id), '1 of 3');
  await open('?tab=policy');
  await page.fill('#rp-max', '5'); await page.click('#rp-save'); await successToast();
  t.eq(sql("SELECT v FROM admin_settings WHERE k = 'recovery.max_attempts'"), '5');
});

// ───────────────────────────── the job ─────────────────────────────
await t.step('Dry run changes nothing and says so; Run recovery now records a run and the card follows it', async () => {
  await open();
  const fingerprint = () => sql("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, status, mode, IFNULL(provider_order_id, ''), IFNULL(recovery_status, ''), provider_attempts) ORDER BY id)) FROM orders");
  const before = fingerprint();
  await page.click('#rc-dry');
  await successToast('Dry run finished');
  t.has(await t.text(toastLast()), 'nothing was changed');
  t.eq(fingerprint(), before, 'no order was touched');
  t.eq(rows("SELECT dry_run, status, trigger_source FROM job_runs WHERE job = 'recovery' ORDER BY id DESC LIMIT 1")[0].slice(0, 2), ['1', 'ok']);
  await clearToasts();
  await page.click('#rc-run');
  await page.waitForSelector('.toast');
  t.has(await t.text(toastLast()), 'Recovery run finished');
  await settle();
  t.eq(rows("SELECT dry_run, status FROM job_runs WHERE job = 'recovery' ORDER BY id DESC LIMIT 1")[0], ['0', 'ok']);
  t.eq(await kpi('Recovery job'), 'Running');
  t.has(await page.locator('#rc-summary .kpi', { hasText: 'Recovery job' }).first().locator('.kpi-sub').innerText(), 'last run');
});

// ───────────────────────────── the orders drawer ─────────────────────────────
await t.step('the order drawer shows why the order is in recovery and offers the same actions (the order is the same, the page is not)', async () => {
  await page.goto(BASE + `/admin/orders?open=${w.r_unknown.id}`, { waitUntil: 'networkidle' });
  await page.waitForSelector('#order-drawer [data-act]');
  await page.waitForTimeout(700);
  const text = await t.text(page.locator('#order-drawer'));
  for (const part of ['Recovery', 'Provider outcome unknown', 'Attempts', '1 of 5', 'Latest error', 'Unclear provider response']) t.has(text, part);
  const actions = await page.locator('#order-drawer .action-bar button').allInnerTexts();
  t.ok(actions.some((a) => /Resend after checking/.test(a)) && actions.some((a) => /Link provider order/.test(a)) && actions.some((a) => /Move to manual/.test(a)), 'recovery actions: ' + actions);
  await page.locator('#order-drawer [data-act="link_provider_order"]').click();
  await page.waitForSelector('dialog.dlg[open] input[name=provider_order_id]');
  await dlg().locator('input[name=provider_order_id]').fill('88888');
  await submitDialog();
  await dlg().locator('.field-error').first().waitFor();
  t.has(await t.text(dlg()), 'The provider does not know this id');
  t.eq(orderRow('r_unknown')[2], 'NULL', 'refused: nothing linked');
  await page.keyboard.press('Escape'); await page.waitForTimeout(450);
});

// ───────────────────────────── layout, keyboard, accessibility ─────────────────────────────
const axe = async (name) => {
  await page.evaluate(AXE);
  const v = await page.evaluate(async () => (await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] }, resultTypes: ['violations'] })).violations.map((x) => ({ id: x.id, impact: x.impact, help: x.help, nodes: x.nodes.slice(0, 3).map((n) => n.target.join(' ') + ' — ' + n.html.slice(0, 120)) })));
  t.eq(v, [], `${name}: axe violations`);
};

await t.step('accessibility (axe, WCAG 2.2 AA + best practice): the queue with its legend, the retry policy, the More dialog and a form dialog', async () => {
  await open(); await page.locator('.rc-legend > summary').click(); await axe('queue');
  await page.locator('.rc-legend > summary').click();
  await open('?tab=policy'); await axe('retry policy');
  await open();
  await page.locator('[data-rc-more]').first().click();
  await page.waitForSelector('dialog.dlg[open] [data-pick]'); await page.waitForTimeout(500); await axe('More dialog');
  await page.keyboard.press('Escape'); await page.waitForTimeout(450);
  await page.locator('[data-rc-act="retry_submit"], [data-rc-act="resolve_review"], [data-rc-act="link_provider_order"]').first().click();
  await page.waitForSelector('dialog.dlg[open]'); await page.waitForTimeout(500); await axe('action dialog');
});

await t.step('keyboard: the tabs are a real tablist (arrows, Home, End); the table scroller, buttons and filters are reachable; nothing is covered', async () => {
  await open();
  await page.focus('#rc-tab-queue');
  await page.keyboard.press('ArrowRight'); await settle();
  t.eq(await page.getAttribute('#rc-tab-policy', 'aria-selected'), 'true');
  t.eq(await page.evaluate(() => document.activeElement.id), 'rc-tab-policy');
  await page.keyboard.press('Home'); await settle();
  t.eq(await page.getAttribute('#rc-tab-queue', 'aria-selected'), 'true');
  t.eq(await page.locator('#rc-root .tbl-scroll').getAttribute('tabindex'), '0', 'the scrollable table is focusable');
  await page.focus('[data-category="failed"]'); await page.keyboard.press('Enter'); await settle();
  t.eq(await page.getAttribute('[data-category="failed"]', 'aria-pressed'), 'true', 'a filter works from the keyboard');
  await page.click('[data-category=""]'); await settle();
  t.eq(await coveredControls(page), [], 'no control is covered by another element');
});

await t.step('desktop 1440 / 1366 / 1100 / tablet 820: no horizontal overflow on either tab; at 1366 and wider the table fits without scrolling', async () => {
  for (const vp of [{ width: 1440, height: 900 }, { width: 1366, height: 768 }, { width: 1100, height: 800 }, { width: 820, height: 1180 }]) {
    await page.setViewportSize(vp);
    for (const q of ['', '?tab=policy']) {
      await open(q);
      const r = await layoutReport(page);
      t.eq([r.overflowX, r.wide], [false, []], `${vp.width}px ${q || 'queue'}`);
    }
    if (vp.width >= 1366) {
      await open();
      const m = await page.locator('#rc-root .tbl-scroll').evaluate((e) => ({ sw: e.scrollWidth, cw: e.clientWidth, cols: [...e.querySelectorAll('thead th')].map((th) => Math.round(th.getBoundingClientRect().width)) }));
      t.ok(m.sw <= m.cw + 1, `${vp.width}px: the queue table fits its card (no hidden columns): ${JSON.stringify(m)}`);
      t.ok(await rowOf('r_unknown').locator('a[aria-label^="Open order"]').isVisible(), 'the last action is visible');
    }
  }
  await page.setViewportSize({ width: 1440, height: 900 });
});

// ───────────────────────────── phone ─────────────────────────────
const phone = await newPage(browser, { width: 390, height: 844 }, { mobile: true, allowStatus: [403, 409, 422, 429, 502] });
t.page = phone;
await login(phone);
const pgo = async (path) => { await phone.goto(BASE + path, { waitUntil: 'networkidle' }); await phone.waitForFunction(() => document.querySelector('#rc-root')?.getAttribute('aria-busy') === 'false'); await phone.waitForTimeout(300); };

await t.step('phone 390: the table becomes labelled cards, nothing overflows sideways, every control can be tapped, a row is not a wall of text', async () => {
  for (const path of ['/admin/recovery', '/admin/recovery?category=unknown', '/admin/recovery?tab=policy']) {
    await pgo(path);
    const r = await layoutReport(phone);
    t.eq([r.overflowX, r.wide, r.small], [false, [], []], path);
    t.eq(await coveredControls(phone), [], path + ' covered controls');
  }
  await pgo('/admin/recovery?category=unknown');
  const labels = await phone.locator('#rc-root tbody tr').first().evaluate((tr) => [...tr.querySelectorAll('td[data-label]')].map((td) => getComputedStyle(td, '::before').content.replace(/"/g, '')));
  t.eq(labels, ['Order', 'Problem', 'Attempts', 'Provider'], 'every cell is labelled');
  t.eq(await phone.locator('#rc-root thead').first().isVisible(), false, 'the header row is replaced by labels');
  const h = await phone.locator('#rc-root tbody tr').first().evaluate((e) => Math.round(e.getBoundingClientRect().height));
  t.ok(h < 460, `a recovery card fits in about one screen: ${h}px`);
});

await t.step('phone 390: the primary action, More and the form dialog work by touch and fit the screen', async () => {
  await pgo('/admin/recovery?category=unknown');
  await phone.locator('#rc-root [data-rc-more]').first().tap();
  await phone.waitForSelector('dialog.dlg[open] [data-pick]');
  let box = await phone.locator('dialog.dlg[open] .dlg-panel').boundingBox();
  t.ok(box.width <= 390 && box.x >= 0, 'the More dialog fits the screen: ' + JSON.stringify(box));
  await phone.keyboard.press('Escape'); await phone.waitForTimeout(500);
  await phone.locator('#rc-root [data-rc-act="link_provider_order"]').first().tap();
  await phone.waitForSelector('dialog.dlg[open] input[name=provider_order_id]');
  box = await phone.locator('dialog.dlg[open] .dlg-panel').boundingBox();
  t.ok(box.width <= 390 && box.x >= 0, 'the link dialog fits the screen: ' + JSON.stringify(box));
  await phone.keyboard.press('Escape'); await phone.waitForTimeout(500);
});
t.page = page;

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
const waitQueue = async (p) => { await p.goto(BASE + '/admin/recovery', { waitUntil: 'networkidle' }); await p.waitForFunction(() => document.querySelector('#rc-root')?.getAttribute('aria-busy') === 'false'); await p.waitForTimeout(400); };
// an order that is still in the queue for the role checks (the seed's r_unknown outcome)
const target = w.r_unknown;

await t.step('role SUPPORT: reads the queue — no action, no job button, no policy control; the server refuses every write anyway', async () => {
  await asRole('support', async (p) => {
    await waitQueue(p);
    t.ok(await p.locator('#rc-root tbody tr').count() >= 1, 'the queue is readable');
    t.eq(await p.locator('[data-rc-act], [data-rc-more]').count(), 0, 'no action buttons');
    t.eq([await p.locator('#rc-run').isVisible(), await p.locator('#rc-dry').isVisible()], [false, false], 'no job buttons');
    t.ok((await p.locator('.adm-side .adm-link').allInnerTexts()).some((x) => /Order recovery/.test(x)), 'the nav offers the page');
    await p.goto(BASE + '/admin/recovery?tab=policy', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    t.eq(await p.locator('#rc-policy input:not([disabled])').count(), 0, 'the policy is read-only');
    t.eq(await p.locator('#rp-save').count(), 0, 'no save button');
    t.has(await t.text(p.locator('#rc-policy')), 'Only an owner or manager can change it');
    const before = orderRow('r_unknown');
    const act = await send(p, 'POST', `/admin/api/recovery/${target.id}/action`, { action: 'move_to_manual' });
    t.eq([act.status, code(act)], [403, 'forbidden'], 'action refused');
    const put = await send(p, 'PUT', '/admin/api/recovery/settings', { max_attempts: 9 });
    t.eq([put.status, code(put)], [403, 'forbidden'], 'policy refused');
    const run = await send(p, 'POST', '/admin/api/jobs/recovery/run', { dry_run: true });
    t.eq([run.status, code(run)], [403, 'forbidden'], 'job run refused');
    const ord = await send(p, 'POST', `/admin/api/orders/${target.id}/action`, { action: 'move_to_manual' });
    t.eq(ord.status, 403, 'the order drawer endpoint refuses it too');
    t.eq(orderRow('r_unknown'), before, 'nothing changed');
    t.eq(sql("SELECT v FROM admin_settings WHERE k = 'recovery.max_attempts'"), '5', 'the refused change was not stored');
    await p.goto(BASE + `/admin/orders?open=${target.id}`, { waitUntil: 'networkidle' }); await p.waitForTimeout(1000);
    t.has(await t.text(p.locator('#order-drawer')), 'Provider outcome unknown');
    t.eq(await p.locator('#order-drawer .action-bar button').count(), 0, 'the drawer offers no actions');
  });
});

await t.step('role ANALYST: the same read-only view of the queue', async () => {
  await asRole('analyst', async (p) => {
    await waitQueue(p);
    t.ok(await p.locator('#rc-root tbody tr').count() >= 1);
    t.eq(await p.locator('[data-rc-act], [data-rc-more]').count(), 0);
    const act = await send(p, 'POST', `/admin/api/recovery/${target.id}/action`, { action: 'escalate', note: 'nope' });
    t.eq([act.status, code(act)], [403, 'forbidden']);
  });
});

await t.step('role MANAGER: acts on the queue, edits the policy and runs the job (a dry run), as the owner does', async () => {
  await asRole('manager', async (p) => {
    await waitQueue(p);
    t.ok(await p.locator('[data-rc-act]').count() >= 1, 'action buttons are offered');
    t.eq([await p.locator('#rc-run').isVisible(), await p.locator('#rc-dry').isVisible()], [true, true], 'job buttons are offered');
    const run = await send(p, 'POST', '/admin/api/jobs/recovery/run', { dry_run: true });
    t.eq(run.status, 200);
    t.eq(rows("SELECT dry_run, status, trigger_source FROM job_runs WHERE job = 'recovery' ORDER BY id DESC LIMIT 1")[0].slice(0, 2), ['1', 'ok']);
    const put = await send(p, 'PUT', '/admin/api/recovery/settings', { max_attempts: 4 });
    t.eq(put.status, 200);
    t.eq(sql("SELECT v FROM admin_settings WHERE k = 'recovery.max_attempts'"), '4');
    const bad = await send(p, 'POST', `/admin/api/recovery/${target.id}/action`, { action: 'retry_submit' });
    t.eq([bad.status, code(bad)], [422, 'verification_required'], 'even a manager cannot resend an unknown outcome without the CHECKED statement');
    t.eq(orderRow('r_unknown')[0], 'FAILED');
    t.eq((await atPanel(link('r_unknown'))).length, 0, 'nothing reached the provider');
    await send(p, 'PUT', '/admin/api/recovery/settings', { max_attempts: 5 });
  });
});

await t.step('no browser problems on the recovery pages (console errors, CSP violations, failed requests)', async () => {
  // the refusals this suite provokes on purpose (a wrong provider id → 409, a missing CHECKED → 422 …) show up as console lines: anything else is a problem
  const expected = /\[http (403|409|422|429|502)\]|Failed to load resource: the server responded with a status of (403|409|422|429|502)/;
  t.eq(page.problems.filter((x) => !expected.test(x)), [], 'console / page / request problems');
  t.eq(phone.problems.filter((x) => !expected.test(x)), [], 'phone problems');
});

process.exitCode = t.finish() ? 1 : 0;
await browser.close();
