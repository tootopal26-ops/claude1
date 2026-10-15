// Admin → Payment checks, end to end in a real browser: real checkouts at the mock gateway, signed webhooks (right, wrong, unknown, lost, not
// confirmed by the gateway), the monitor, the flags, re-check, review / dismiss, the thresholds, and what each ROLE sees and may do.
// Local dev only (resets the dev database). Run:  node tools/qa/admin-checks.mjs
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { launch, newPage, BASE, ROOT, sql, rows, resetLimits, mocksReset, alpha, useMockProviderIds, php } from './site.mjs';
import { go, login, coveredControls, layoutReport } from './admin.mjs';
import { suite } from './harness.mjs';

const require = createRequire(import.meta.url);
const AXE = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const t = suite('admin-checks');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
useMockProviderIds(); resetLimits(); await mocksReset();

// ── a small world made through the real code paths ──────────────────────────────────────────────────────────────────────────────────────
const seeded = JSON.parse(php('tools/qa/checks-seed.php', 'ok_paid', 'wrong_amt', 'open_one', 'lost_hook', 'gw_unpaid', 'review_me', 'dismiss_me').trim());
const by = Object.fromEntries(seeded.map((s) => [s.name, s]));
const hook = (name, extra = {}) => alpha('/__webhook', { body: { event_id: `evt_${name}`, order_id: by[name].gateway_order_id, status: 'SUCCESS', amount: '5.00', utr: 'UTR' + name.toUpperCase(), provider: 'bharatpe', ...extra } });
await alpha(`/__pay/${by.ok_paid.gateway_order_id}/success`, { noWebhook: true, utr: 'UTROK_PAID' });
t.eq((await hook('ok_paid', { utr: 'UTROK_PAID' })).status, 200);
t.eq((await hook('wrong_amt', { amount: '4.00' })).status, 200);
await alpha(`/__pay/${by.lost_hook.gateway_order_id}/success`, { noWebhook: true, utr: 'UTRLOST_HOOK' });        // paid at the gateway, our site never heard
t.eq((await hook('gw_unpaid')).status, 200);                                                                      // webhook says paid, the gateway shows nothing
t.eq((await hook('review_me', { amount: '3.00' })).status, 200);
t.eq((await hook('dismiss_me', { currency: 'USD' })).status, 200);
t.eq((await alpha('/__webhook', { body: { event_id: 'evt_unknown_ref', order_id: 'SMB-NOT-OURS', status: 'SUCCESS', amount: '5.00', utr: 'UTRX' } })).status, 200);
const state = (name) => sql(`SELECT status FROM payments WHERE gateway_order_id = '${by[name].gateway_order_id}'`);
t.eq([state('ok_paid'), state('wrong_amt'), state('open_one'), state('lost_hook'), state('gw_unpaid')], ['success', 'mismatch', 'pending', 'pending', 'success'], 'the world is as designed');

const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [403, 409, 422, 429] });
t.page = page;
await login(page);
const toastLast = () => page.locator('.toast').last();
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((n) => n.remove()));
const closeDialogs = async () => { for (let i = 0; i < 3; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeDialogs(); await clearToasts(); await fn(); });
const root = () => page.locator('#pc-root');
const typed = async () => { await page.waitForTimeout(550); await settle(); };
const settle = async () => { await page.waitForFunction(() => document.querySelector('#pc-root')?.getAttribute('aria-busy') === 'false'); await page.waitForTimeout(250); };
const rowOf = (publicId) => root().locator('tbody tr', { hasText: publicId });
const rowText = async (publicId) => (await rowOf(publicId).first().innerText()).replace(/\s+/g, ' ');
const kpi = async (label) => Number((await page.locator('#pc-summary .kpi', { hasText: label }).first().locator('.kpi-value').innerText()).replace(/[^\d]/g, ''));
const flagCount = (extra = '') => Number(sql(`SELECT COUNT(*) FROM payment_flags WHERE active_key IS NOT NULL ${extra}`));

// ───────────────────────────── the monitor ─────────────────────────────
await go(page, '/admin/payment-checks');

await t.step('payments: every payment shows expected vs verified amount, currency, the requested state, source, timestamps, flags and webhook status', async () => {
  const ok = await rowText(by.ok_paid.public_id);
  t.has(ok, '₹5.00'); t.has(ok, 'verified ₹5.00 · INR'); t.has(ok, 'PAID'); t.has(ok, 'Signed webhook'); t.has(ok, 'UTR UTROK_PAID'); t.has(ok, by.ok_paid.gateway_order_id);
  t.ok(/verified \d+ \w+, /.test(ok) && /created \d+ \w+, /.test(ok) && /expires \d+ \w+, /.test(ok), 'verified / created / expires timestamps: ' + ok);
  t.has(ok, 'webhook processed'); t.has(ok, '1 delivery');
  const wrong = await rowText(by.wrong_amt.public_id);
  t.has(wrong, 'REVIEW_REQUIRED'); t.has(wrong, 'Needs review'); t.has(wrong, '₹5.00'); t.has(wrong, 'verified ₹4.00 · INR'); t.has(wrong, 'Amount mismatch'); t.has(wrong, 'webhook mismatch');
  const open = await rowText(by.open_one.public_id);
  t.has(open, 'PENDING'); t.has(open, 'not verified'); t.has(open, 'Not verified');
  t.eq(await page.locator('#pc-root tbody tr.is-flagged').count() >= 2, true, 'rows with a flag carry the amber marker');
});

await t.step('summary cards are the database, not decoration', async () => {
  t.eq(await kpi('Held for review'), Number(sql("SELECT COUNT(*) FROM payments WHERE status = 'mismatch'")));
  t.eq(await kpi('Open flags'), flagCount("AND severity IN ('high','medium')"));
  t.eq(await kpi('Paid · 30 days'), Number(sql("SELECT COUNT(*) FROM payments WHERE status = 'success' AND created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY")));
  t.eq(await kpi('Webhooks · 24 h'), Number(sql('SELECT COUNT(*) FROM webhook_events WHERE received_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY')));
});

await t.step('the sidebar badge counts the flags that need a person (medium + high), in the same colour language as a warning', async () => {
  const badge = page.locator('.adm-side [data-flag-count]');
  await badge.waitFor({ state: 'visible' });
  t.eq(await badge.innerText(), String(flagCount("AND severity IN ('high','medium')")));
  t.ok((await badge.evaluate((e) => getComputedStyle(e).backgroundColor)) !== (await page.locator('.adm-side [data-approve-count]').evaluate((e) => getComputedStyle(e).backgroundColor)), 'not the green "to approve" colour');
});

await t.step('filters: Flagged, Needs review, Paid and a search by bank reference narrow the list; the address bar remembers them', async () => {
  await page.click('[data-state="FLAGGED"]'); await settle();
  const flaggedIds = sql("SELECT COUNT(DISTINCT payment_id) FROM payment_flags WHERE active_key IS NOT NULL AND payment_id IS NOT NULL");
  t.eq(String(await root().locator('tbody tr').count()), flaggedIds);
  t.has(page.url(), 'state=FLAGGED');
  await page.click('[data-state="REVIEW_REQUIRED"]'); await settle();
  t.eq(String(await root().locator('tbody tr').count()), sql("SELECT COUNT(*) FROM payments WHERE status = 'mismatch'"));
  t.has(await rowText(by.wrong_amt.public_id), 'REVIEW_REQUIRED');
  await page.click('[data-state="PAID"]'); await settle();
  t.ok((await root().locator('tbody tr').allInnerTexts()).every((x) => x.includes('PAID')), 'only paid rows');
  await page.click('[data-state=""]'); await settle();
  await page.fill('#pc-search', 'UTROK_PAID'); await typed();
  t.eq(await root().locator('tbody tr').count(), 1);
  t.has(await rowText(by.ok_paid.public_id), 'UTROK_PAID');
  await page.reload({ waitUntil: 'networkidle' }); await settle();
  t.eq(await page.locator('#pc-search').inputValue(), 'UTROK_PAID', 'the search survives a reload');
  await page.fill('#pc-search', 'no-such-payment-anywhere'); await typed();
  t.has(await t.text(root()), 'No payments match');
  await page.fill('#pc-search', ''); await typed();
  t.ok(await root().locator('tbody tr').count() >= 7);
});

// ───────────────────────────── re-check ─────────────────────────────
await t.step('re-check: a payment whose webhook was lost is confirmed by the gateway lookup, credited once, and says so', async () => {
  await page.goto(BASE + '/admin/payment-checks?q=' + by.lost_hook.public_id, { waitUntil: 'networkidle' }); await settle();
  t.has(await rowText(by.lost_hook.public_id), 'PENDING');
  await rowOf(by.lost_hook.public_id).locator('[data-recheck]').click();
  await page.waitForSelector('.toast[data-type="success"]');
  t.has(await t.text(toastLast()), 'Payment confirmed');
  t.has(await t.text(toastLast()), 'Gateway says success');
  await settle();
  const r = await rowText(by.lost_hook.public_id);
  t.has(r, 'PAID'); t.has(r, 'Gateway lookup'); t.has(r, 'UTR UTRLOST_HOOK');
  t.eq(rows(`SELECT p.status, p.verified_via, o.status FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.gateway_order_id = '${by.lost_hook.gateway_order_id}'`)[0], ['success', 'poll', 'SUBMITTED']);   // ig-followers-50 is an AUTO service here: the re-check settles it and the order goes on to the (mock) provider
  t.eq(Number(sql(`SELECT COUNT(*) FROM order_status_history WHERE order_id = ${by.lost_hook.order_id} AND to_status = 'PAID'`)), 1, 'credited exactly once');
  t.eq(Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.rechecked' AND entity_id = '${by.lost_hook.payment_id}'`)), 1, 'audited');
  await clearToasts();
  await page.locator('[data-recheck]').first().click();      // asking again changes nothing
  await page.waitForSelector('.toast');
  t.has(await t.text(toastLast()), 'confirms this payment');
  t.eq(Number(sql(`SELECT COUNT(*) FROM order_status_history WHERE order_id = ${by.lost_hook.order_id} AND to_status = 'PAID'`)), 1, 'still once');
});

await t.step('re-check: a flagged payment is never released by it — and a pending one is just reported as unpaid', async () => {
  await page.goto(BASE + '/admin/payment-checks?q=' + by.wrong_amt.public_id, { waitUntil: 'networkidle' }); await settle();
  await rowOf(by.wrong_amt.public_id).locator('[data-recheck]').click();
  await page.waitForSelector('.toast');
  t.has(await t.text(toastLast()), 'Checked with the gateway');
  t.has(await t.text(toastLast()), 'stays flagged');
  t.eq(state('wrong_amt'), 'mismatch');
  await clearToasts();
  await page.goto(BASE + '/admin/payment-checks?q=' + by.open_one.public_id, { waitUntil: 'networkidle' }); await settle();
  await rowOf(by.open_one.public_id).locator('[data-recheck]').click();
  await page.waitForSelector('.toast');
  t.has(await t.text(toastLast()), 'unpaid');
  t.eq(state('open_one'), 'pending');
});

// ───────────────────────────── the scan (cron) ─────────────────────────────
await t.step('cron: a webhook the gateway does not confirm is flagged once the grace period has passed — and the payment stays paid', async () => {
  t.eq(flagCount("AND flag_type = 'conflicting_status'"), 0, 'not flagged straight after the webhook');
  const ours = seeded.map((x) => `'${x.gateway_order_id}'`).join(',');
  sql(`UPDATE payments SET last_polled_at = paid_at + INTERVAL 1 MINUTE WHERE gateway_order_id NOT IN (${ours}) AND verified_via = 'webhook'`);   // the demo history never existed at the mock gateway
  sql(`UPDATE payments SET paid_at = paid_at - INTERVAL 20 MINUTE WHERE gateway_order_id = '${by.gw_unpaid.gateway_order_id}'`);
  const out = JSON.parse(execFileSync('php', ['bin/cron.php', 'payments', '-v'], { cwd: ROOT, encoding: 'utf8' }));
  t.eq([out.status, out.result.risk.rechecked], ['ok', 1], 'only gw_unpaid is due: ok_paid was confirmed right after its webhook, lost_hook was settled by a lookup, the rest are held');
  t.eq(flagCount("AND flag_type = 'conflicting_status'"), 1);
  t.eq(state('gw_unpaid'), 'success', 'a flag never undoes a payment');
  const run = rows("SELECT status, trigger_source FROM job_runs WHERE job = 'payments' ORDER BY id DESC LIMIT 1")[0];
  t.eq(run, ['ok', 'cron']);
});

await t.step('flags tab: the flag explains itself — what it means, what to do, and the facts behind it', async () => {
  await page.goto(BASE + '/admin/payment-checks?tab=flags', { waitUntil: 'networkidle' }); await settle();
  const text = (await root().innerText()).replace(/\s+/g, ' ');
  for (const part of ['Amount mismatch', 'Gateway answers disagree', 'Unknown order reference', 'Currency is not INR', 'Expected: ₹5.00', 'Gateway reported: ₹4.00', 'Gateway status: PENDING', 'Gateway order id: SMB-NOT-OURS', 'Gateway currency: USD', 'Check what actually reached the bank account']) t.has(text, part);
  t.eq(await root().locator('tbody tr').count(), flagCount(), 'one row per open flag');
  await page.selectOption('#pc-sev', 'high'); await settle();
  t.eq(await root().locator('tbody tr').count(), flagCount("AND severity = 'high'"));
  t.has(page.url(), 'severity=high');
  await page.selectOption('#pc-sev', ''); await page.selectOption('#pc-type', 'unknown_reference'); await settle();
  t.eq(await root().locator('tbody tr').count(), 1);
  await page.selectOption('#pc-type', ''); await settle();
});

await t.step('flags: "Reviewed" asks for an optional note, closes the flag and keeps who / when / why; the badge and the numbers follow', async () => {
  const before = flagCount("AND severity IN ('high','medium')");
  const target = by.review_me.public_id;
  const flagId = sql(`SELECT f.id FROM payment_flags f JOIN orders o ON o.id = f.order_id WHERE o.public_id = '${target}' AND f.active_key IS NOT NULL`);
  const tr = root().locator('tbody tr', { hasText: target });
  await tr.locator('[data-review][data-status="reviewed"]').click();
  await page.waitForSelector('dialog.dlg[open] textarea[name=note]');
  await page.fill('dialog.dlg[open] textarea[name=note]', 'Checked the bank statement: Rs 3.00 arrived, customer will be refunded');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await page.waitForSelector('.toast[data-type="success"]');
  await settle();
  t.eq(await root().locator('tbody tr', { hasText: target }).count(), 0, 'gone from the open list');
  t.eq(rows(`SELECT status, reviewed_by IS NOT NULL, review_note, active_key IS NULL FROM payment_flags WHERE id = ${flagId}`)[0], ['reviewed', '1', 'Checked the bank statement: Rs 3.00 arrived, customer will be refunded', '1']);
  t.eq(Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action = 'payment_flag.reviewed' AND entity_id = '${flagId}'`)), 1);
  t.eq(await page.locator('.adm-side [data-flag-count]').innerText(), String(before - 1), 'the sidebar badge went down');
  t.has(await kpi('Open flags'), String(before - 1));
  await page.click('[data-fstatus="closed"]'); await settle();
  const closed = (await root().locator('tbody tr', { hasText: target }).innerText()).replace(/\s+/g, ' ');
  t.has(closed, 'Reviewed'); t.has(closed, 'by owner'); t.has(closed, 'Checked the bank statement');
  await page.click('[data-fstatus="open"]'); await settle();
});

await t.step('flags: "False alarm" cannot be sent without a reason; with one it closes the flag as dismissed', async () => {
  const target = by.dismiss_me.public_id;
  const flagId = sql(`SELECT f.id FROM payment_flags f JOIN orders o ON o.id = f.order_id WHERE o.public_id = '${target}' AND f.active_key IS NOT NULL`);
  const tr = root().locator('tbody tr', { hasText: target });
  await tr.locator('[data-review][data-status="dismissed"]').click();
  await page.waitForSelector('dialog.dlg[open] textarea[name=note]');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  t.has(await t.text(page.locator('dialog.dlg[open]')), 'required');
  t.eq(sql(`SELECT status FROM payment_flags WHERE id = ${flagId}`), 'open', 'nothing was sent');
  await page.fill('dialog.dlg[open] textarea[name=note]', 'Test payment from the gateway dashboard');
  await page.click('dialog.dlg[open] [data-act="yes"]');
  await page.waitForSelector('.toast[data-type="success"]');
  await settle();
  t.eq(sql(`SELECT status FROM payment_flags WHERE id = ${flagId}`), 'dismissed');
  t.eq(Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE action = 'payment_flag.dismissed' AND entity_id = '${flagId}'`)), 1);
});

// ───────────────────────────── thresholds ─────────────────────────────
await t.step('thresholds: validated on the server, saved, audited, and still there after a reload', async () => {
  await page.goto(BASE + '/admin/payment-checks?tab=settings', { waitUntil: 'networkidle' }); await settle();
  await page.fill('#rs-failed_attempts', '1');
  await page.click('#pc-save');
  await page.waitForSelector('#pc-settings .field-error');
  t.has(await t.text(page.locator('#pc-settings .field-error')), 'from 2 to 50');
  t.eq(Number(sql("SELECT COUNT(*) FROM admin_settings WHERE k = 'risk.failed_attempts'")), 0, 'nothing was saved');
  await page.fill('#rs-failed_attempts', '6');
  await page.fill('#rs-confirm_grace_seconds', '300');
  await page.selectOption('#rs-webhook_double_check', 'require');
  await page.uncheck('#pc-settings input[name=flag_late_payments]');
  await page.click('#pc-save');
  await page.waitForSelector('.toast[data-type="success"]');
  t.eq(rows("SELECT k, v FROM admin_settings WHERE k LIKE 'risk.%' ORDER BY k"), [['risk.confirm_grace_seconds', '300'], ['risk.failed_attempts', '6'], ['risk.failed_window_hours', '24'], ['risk.flag_late_payments', 'false'], ['risk.invalid_signatures_per_hour', '5'], ['risk.webhook_double_check', '"require"']]);
  t.eq(Number(sql("SELECT COUNT(*) FROM audit_logs WHERE action = 'risk.settings_changed'")), 1);
  await page.reload({ waitUntil: 'networkidle' }); await settle();
  t.eq([await page.inputValue('#rs-failed_attempts'), await page.inputValue('#rs-webhook_double_check'), await page.isChecked('#pc-settings input[name=flag_late_payments]')], ['6', 'require', false]);
  await page.fill('#rs-failed_attempts', '4'); await page.fill('#rs-confirm_grace_seconds', '120'); await page.selectOption('#rs-webhook_double_check', 'flag'); await page.check('#pc-settings input[name=flag_late_payments]');
  await page.click('#pc-save'); await page.waitForSelector('.toast[data-type="success"]');
});

// ───────────────────────────── layout, keyboard, accessibility ─────────────────────────────
const axe = async (name) => {
  await page.evaluate(AXE);
  const v = await page.evaluate(async () => (await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] }, resultTypes: ['violations'] })).violations.map((x) => ({ id: x.id, impact: x.impact, help: x.help, nodes: x.nodes.slice(0, 3).map((n) => n.target.join(' ') + ' — ' + n.html.slice(0, 120)) })));
  t.eq(v, [], `${name}: axe violations`);
};

await t.step('accessibility (axe, WCAG 2.2 AA + best practice): payments, flags and thresholds tabs, and the review dialog', async () => {
  for (const [name, path] of [['payments', '/admin/payment-checks'], ['flags', '/admin/payment-checks?tab=flags'], ['thresholds', '/admin/payment-checks?tab=settings']]) {
    await page.goto(BASE + path, { waitUntil: 'networkidle' }); await settle(); await axe(name);
  }
  await page.goto(BASE + '/admin/payment-checks?tab=flags', { waitUntil: 'networkidle' }); await settle();
  await page.locator('[data-review]').first().click();
  await page.waitForSelector('dialog.dlg[open] textarea'); await page.waitForTimeout(500);
  await axe('review dialog');
});

await t.step('keyboard: the tabs are a real tablist (arrow keys move and select), the table scroller and every button are reachable', async () => {
  await page.goto(BASE + '/admin/payment-checks', { waitUntil: 'networkidle' }); await settle();
  await page.focus('#pc-tab-payments');
  await page.keyboard.press('ArrowRight'); await settle();
  t.eq(await page.getAttribute('#pc-tab-flags', 'aria-selected'), 'true');
  t.eq(await page.evaluate(() => document.activeElement.id), 'pc-tab-flags');
  await page.keyboard.press('End'); await settle();
  t.eq(await page.getAttribute('#pc-tab-settings', 'aria-selected'), 'true');
  await page.keyboard.press('Home'); await settle();
  t.eq(await page.getAttribute('#pc-tab-payments', 'aria-selected'), 'true');
  t.ok((await page.locator('#pc-root .tbl-scroll').getAttribute('tabindex')) === '0', 'the scrollable table is focusable');
  t.eq(await coveredControls(page), [], 'no control is covered by another element');
});

await t.step('desktop 1440 and tablet 820: no horizontal overflow on any tab', async () => {
  for (const vp of [{ width: 1440, height: 900 }, { width: 1024, height: 768 }, { width: 820, height: 1180 }]) {
    await page.setViewportSize(vp);
    for (const path of ['/admin/payment-checks', '/admin/payment-checks?tab=flags', '/admin/payment-checks?tab=settings']) {
      await page.goto(BASE + path, { waitUntil: 'networkidle' }); await settle();
      const r = await layoutReport(page);
      t.eq([r.overflowX, r.wide], [false, []], `${vp.width}px ${path}`);
    }
  }
  await page.setViewportSize({ width: 1440, height: 900 });
});

// ───────────────────────────── phone ─────────────────────────────
const phone = await newPage(browser, { width: 390, height: 844 }, { mobile: true, allowStatus: [403, 409, 422, 429] });
t.page = phone;
await login(phone);
const pgo = async (path) => { await phone.goto(BASE + path, { waitUntil: 'networkidle' }); await phone.waitForFunction(() => document.querySelector('#pc-root')?.getAttribute('aria-busy') === 'false'); await phone.waitForTimeout(300); };

await t.step('phone 390: tables become labelled cards, nothing overflows sideways, every control can be tapped', async () => {
  for (const path of ['/admin/payment-checks', '/admin/payment-checks?state=FLAGGED', '/admin/payment-checks?tab=flags', '/admin/payment-checks?tab=settings']) {
    await pgo(path);
    const r = await layoutReport(phone);
    t.eq([r.overflowX, r.wide, r.small], [false, [], []], path);
    t.eq(await coveredControls(phone), [], path + ' covered controls');
  }
  await pgo('/admin/payment-checks?state=FLAGGED');
  const labels = await phone.locator('#pc-root tbody tr').first().evaluate((tr) => [...tr.querySelectorAll('td[data-label]')].map((td) => getComputedStyle(td, '::before').content.replace(/"/g, '')));
  t.eq(labels, ['Order', 'Amount', 'State', 'Verification', 'Flags and webhook'], 'every cell is labelled');
  t.eq(await phone.locator('#pc-root thead').first().isVisible(), false, 'the header row is replaced by labels');
});

await t.step('phone 390: Re-check and the review dialog work by touch', async () => {
  await pgo('/admin/payment-checks?q=' + by.open_one.public_id);
  await phone.locator('#pc-root [data-recheck]').first().tap();
  await phone.waitForSelector('.toast');
  t.has(await t.text(phone.locator('.toast').last()), 'unpaid');
  await pgo('/admin/payment-checks?tab=flags');
  await phone.locator('[data-review]').first().tap();
  await phone.waitForSelector('dialog.dlg[open] textarea');
  const box = await phone.locator('dialog.dlg[open] .dlg-panel').boundingBox();
  t.ok(box.width <= 390 && box.x >= 0, 'the dialog fits the screen: ' + JSON.stringify(box));
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
const navLabels = (p) => p.locator('.adm-side .adm-link').allInnerTexts().then((a) => a.map((x) => x.replace(/\s+/g, ' ').replace(/\d+$/, '').trim()));
const apiPost = async (p, path, body) => {
  const csrf = await p.locator('meta[name=csrf-token]').getAttribute('content');
  const r = await p.request.post(BASE + path, { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': csrf, Origin: BASE }, data: body });
  return { status: r.status(), body: await r.json().catch(() => ({})) };
};

await t.step('role SUPPORT: sees payments and flags read-only — no re-check, review or threshold control — and the navigation hides what it may not open', async () => {
  await asRole('support', async (p) => {
    const nav = await navLabels(p);
    for (const yes of ['Dashboard', 'Orders', 'Payments', 'Payment checks', 'Security', 'System']) t.ok(nav.includes(yes), `support sees ${yes}: ${nav}`);
    for (const no of ['Payment gateway', 'SMM API', 'Services', 'Website', 'Advertising', 'Audit log', 'Tags & Badges']) t.ok(!nav.includes(no), `support must not see ${no}: ${nav}`);
    await p.goto(BASE + '/admin/payment-checks', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    t.ok(await p.locator('#pc-root tbody tr').count() >= 7, 'the payments are readable');
    t.eq(await p.locator('[data-recheck]').count(), 0, 'no re-check button');
    await p.goto(BASE + '/admin/payment-checks?tab=flags', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    t.ok(await p.locator('#pc-root tbody tr').count() >= 1, 'flags are readable');
    t.eq(await p.locator('[data-review]').count(), 0, 'no review buttons');
    await p.goto(BASE + '/admin/payment-checks?tab=settings', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    t.eq(await p.locator('#pc-settings input:not([disabled]), #pc-settings select:not([disabled])').count(), 0, 'every threshold is read-only');
    t.eq(await p.locator('#pc-save').count(), 0, 'no save button');
    t.has(await t.text(p.locator('#pc-settings')), 'Only an owner or manager can change them');
    // the server, not the browser, is the protection
    const flagId = sql('SELECT id FROM payment_flags WHERE active_key IS NOT NULL ORDER BY id LIMIT 1');
    const payId = sql('SELECT id FROM payments ORDER BY id DESC LIMIT 1');
    for (const [path, body] of [[`/admin/api/payment-flags/${flagId}/review`, { status: 'reviewed' }], [`/admin/api/payments/${payId}/recheck`, {}]]) {
      const r = await apiPost(p, path, body);
      t.eq([r.status, r.body.error?.code ?? r.body.code], [403, 'forbidden'], path);
    }
    const put = await p.request.put(BASE + '/admin/api/payment-checks/settings', { headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': await p.locator('meta[name=csrf-token]').getAttribute('content'), Origin: BASE }, data: { failed_attempts: 9 } });
    t.eq(put.status(), 403);
    t.eq(sql(`SELECT status FROM payment_flags WHERE id = ${flagId}`), 'open', 'nothing changed');
    t.eq(sql("SELECT v FROM admin_settings WHERE k = 'risk.failed_attempts'"), '4', 'the refused change was not stored');
    // pages the role may not open are refused, not merely hidden
    for (const path of ['/admin/gateway', '/admin/smm', '/admin/services', '/admin/website', '/admin/audit', '/admin/advertising']) {
      t.eq((await p.request.get(BASE + path)).status(), 403, path);
    }
    // orders: readable, but no bulk button and no action buttons in the drawer
    await p.goto(BASE + '/admin/orders', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    t.eq(await p.locator('#approve-all').isVisible(), false, 'no Approve All');
    await p.goto(BASE + `/admin/orders?open=${by.open_one.order_id}`, { waitUntil: 'networkidle' }); await p.waitForTimeout(1000);
    t.has(await t.text(p.locator('#order-drawer')), by.open_one.public_id);
    t.eq(await p.locator('.action-bar button').count(), 0, 'no action buttons');
  });
});

await t.step('role MANAGER: reviews flags, re-checks and sets thresholds — but cannot confirm a payment by hand or open the gateway settings', async () => {
  await asRole('manager', async (p) => {
    const nav = await navLabels(p);
    t.ok(nav.includes('Payment checks') && nav.includes('Services') && nav.includes('Audit log'), `manager sees the shop pages: ${nav}`);
    t.ok(!nav.includes('Payment gateway') && !nav.includes('SMM API'), `…but not the integrations: ${nav}`);
    await p.goto(BASE + '/admin/payment-checks', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    t.ok(await p.locator('[data-recheck]').count() > 0, 're-check is offered');
    await p.goto(BASE + '/admin/payment-checks?tab=settings', { waitUntil: 'networkidle' }); await p.waitForTimeout(800);
    t.eq(await p.locator('#pc-save').count(), 1, 'managers can change thresholds');
    t.eq((await p.request.get(BASE + '/admin/gateway')).status(), 403);
    // the order drawer of an unpaid order: cancel yes, "Confirm payment manually" no (owner only)
    await p.goto(BASE + `/admin/orders?open=${by.open_one.order_id}`, { waitUntil: 'networkidle' }); await p.waitForTimeout(1000);
    const buttons = await p.locator('.action-bar button').allInnerTexts();
    t.ok(buttons.some((b) => /Cancel order/.test(b)), 'manager can cancel: ' + buttons);
    t.ok(!buttons.some((b) => /Confirm payment manually/.test(b)), 'manager cannot confirm a payment by hand: ' + buttons);
    const r = await apiPost(p, `/admin/api/orders/${by.open_one.order_id}/action`, { action: 'confirm_payment', reference: 'BANK123' });
    t.eq([r.status, r.body.error?.code ?? r.body.code], [403, 'forbidden']);
    t.eq(state('open_one'), 'pending');
  });
});

await t.step('role OWNER (control): the same drawer offers "Confirm payment manually", and nothing was changed by the refused attempts', async () => {
  await page.goto(BASE + `/admin/orders?open=${by.open_one.order_id}`, { waitUntil: 'networkidle' }); await page.waitForTimeout(1000);
  t.ok((await page.locator('.action-bar button').allInnerTexts()).some((b) => /Confirm payment manually/.test(b)));
  t.eq(state('open_one'), 'pending');
});

process.exitCode = t.finish() ? 1 : 0;
await browser.close();
