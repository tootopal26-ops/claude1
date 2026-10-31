// Admin → Business alerts, end to end in a real browser. The alerts were raised by the REAL watchdog job from real rows (tools/qa/alerts-seed.php), and every
// row, card and verdict the page shows is checked against independent SQL. Acknowledge / resolve / recurrence, run now and a dry run, the Checks tab, the
// thresholds (validation, audit, a switched-off check, automatic resolution), the e-mail settings, what each ROLE sees, and a phone.
// Local dev only (resets the dev database). Run:  node tools/qa/admin-alerts.mjs
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, ROOT, sql, rows, php, resetLimits } from './site.mjs';
import { layoutReport, coveredControls, login } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-alerts');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
resetLimits();
const seed = JSON.parse(php('tools/qa/alerts-seed.php'));
const n = (q) => Number(sql(q));
const ownerId = () => n("SELECT id FROM admins WHERE username = 'owner'");
const live = "status IN ('open', 'acknowledged')";
const ascii = (x) => String(x).replace(/[^\x20-\x7E]/g, '');                 // the mariadb client prints “ ” as garbage; compare the plain-ASCII part
const alertOf = (type) => rows(`SELECT id, severity, status, occurrences, title, resolution FROM business_alerts WHERE alert_type = '${type}' ORDER BY id`).map(([id, severity, status, occ, title, resolution]) => ({ id: Number(id), severity, status, occurrences: Number(occ), title, resolution }));
const state = () => JSON.parse(sql("SELECT v FROM admin_settings WHERE k = 'watchdog.state'"));
// The demo data is randomly generated around "now", so a demo-origin payment can occasionally land inside the watchdog's own look-back window and
// change what the real check finds. Rather than hard-code the seed's own plan (10 failed of 14 finished), read what the check actually saw — the
// same arithmetic as Watchdog::paymentFailures() (60-minute window, half-up tenths of a percent) — so the test still fails if the real number drifts
// from what the page shows, but never from demo-data timing.
const payFail = () => {
  const [[paidS, badS]] = rows("SELECT SUM(CASE WHEN paid_at IS NOT NULL THEN 1 ELSE 0 END), SUM(CASE WHEN paid_at IS NULL AND status IN ('failed','mismatch') THEN 1 ELSE 0 END) FROM payments WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 60 MINUTE");
  const paid = Number(paidS) || 0, bad = Number(badS) || 0, finished = paid + bad;
  const tenths = finished === 0 ? 0 : Math.floor((bad * 1000) / finished + 0.5);
  return { paid, bad, finished, pct: (tenths / 10).toFixed(1) };
};

const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [403, 409, 422, 429] });
t.page = page;
await login(page);
const toastLast = () => page.locator('.toast').last();
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((x) => x.remove()));
const closeDialogs = async () => { for (let i = 0; i < 3; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeDialogs(); await clearToasts(); await fn(); });
const settle = async (p = page) => { await p.waitForFunction(() => document.querySelector('#al-root')?.getAttribute('aria-busy') === 'false' && document.querySelector('#al-summary')?.getAttribute('aria-busy') === 'false'); await p.waitForTimeout(300); };
const open = async (q = '', p = page) => { await p.goto(BASE + '/admin/alerts' + q, { waitUntil: 'networkidle' }); await settle(p); };
const tab = async (name, p = page) => { await p.click(`#al-tab-${name}`); await settle(p); };
const dlg = (p = page) => p.locator('dialog.dlg-modal[open]');
const successToast = async (part) => { await page.waitForSelector('.toast[data-type="success"]'); if (part) t.has(await t.text(toastLast()), part); };
const kpi = async (key, p = page) => (await p.locator(`.kpi[data-kpi="${key}"] .kpi-value`).innerText()).replace(/\s+/g, ' ').trim();
const alertRow = (id, p = page) => p.locator(`#al-root tr[data-alert="${id}"]`);
const rowText = async (id, p = page) => (await alertRow(id, p).innerText()).replace(/\s+/g, ' ').trim();

// ───────────────────────────── what the real watchdog found ─────────────────────────────
await t.step('the seed: the real watchdog job found the planted problems — and said "no verdict" where it could not judge', async () => {
  t.eq(seed.run.status, 'ok');
  for (const k of ['payment_failures', 'provider_errors', 'stuck_orders', 'cron_jobs']) t.eq(seed.run.verdicts[k], 'alert', k);
  t.eq(seed.run.verdicts.provider_balance, 'disabled', 'the optional provider-balance check is off by default');
  t.ok(['insufficient', 'ok'].includes(seed.run.verdicts.conversion), 'conversion: ' + seed.run.verdicts.conversion);
  t.eq(alertOf('payment_failures').map((a) => [a.severity, a.status]), [['warning', 'open']], payFail().bad + ' of ' + payFail().finished + ' finished payments failed: over the 40% limit, under twice that');
  t.eq(alertOf('cron_jobs')[0].severity, 'critical', 'the cleanup job failed three times in a row');
  t.ok(n("SELECT COUNT(*) FROM business_alerts WHERE active_key LIKE 'wd:%'") >= 4);
});

// ───────────────────────────── the page ─────────────────────────────
await t.step('the page: four cards, four tabs, the alerts — critical first — and the numbers equal the database', async () => {
  await open();
  t.eq(await page.locator('h1').innerText(), 'Business alerts');
  t.eq((await page.locator('.kpi .kpi-label').allTextContents()).map((x) => x.trim()), ['Critical', 'Warnings', 'Not acknowledged', 'Last check']);
  const c = (sev) => n(`SELECT COUNT(*) FROM business_alerts WHERE ${live} AND severity = '${sev}'`);
  t.eq([await kpi('critical'), await kpi('warning')], [String(c('critical')), String(c('warning'))]);
  t.eq(await kpi('unacknowledged'), String(n("SELECT COUNT(*) FROM business_alerts WHERE status = 'open'")));
  const tabs = (await page.locator('#al-tabs [data-tab]').allInnerTexts()).map((x) => x.replace(/\s+/g, ' ').trim());
  t.ok(['Alerts', 'Checks', 'Thresholds', 'E-mail'].every((l, i) => tabs[i].startsWith(l)), JSON.stringify(tabs));
  t.has(tabs[0], String(n(`SELECT COUNT(*) FROM business_alerts WHERE ${live}`)), 'the Alerts tab carries the live count');
  const total = n(`SELECT COUNT(*) FROM business_alerts WHERE ${live}`);
  t.eq(await page.locator('#al-root tbody tr').count(), Math.min(25, total));
  t.eq((await page.locator('#al-root tbody tr').first().locator('td').first().innerText()).trim(), 'Critical', 'the worst first');
  t.has(await t.text(page.locator('.kpi[data-kpi="last-check"]')), 'on schedule');
  t.has(await t.text(page.locator('.page-sub')), 'not an AI');
});

await t.step('every row is what the database says: severity, title, occurrences, status — and the payment alert quotes the real counts', async () => {
  const db = rows(`SELECT id, severity, title, occurrences, status, description FROM business_alerts WHERE ${live} ORDER BY FIELD(status, 'open', 'acknowledged'), FIELD(severity, 'critical', 'warning', 'info'), last_detected_at DESC LIMIT 25`);
  const ui = await page.locator('#al-root tbody tr').evaluateAll((trs) => trs.map((tr) => ({ id: tr.dataset.alert, text: tr.innerText.replace(/\s+/g, ' ').trim() })));
  t.eq(ui.map((x) => x.id), db.map((r) => r[0]), 'the same alerts in the same order');
  for (const [i, r] of db.entries()) {
    t.has(ascii(ui[i].text), ascii(r[2]), 'title'); t.has(ui[i].text, r[3] + '×', 'occurrences');
    t.has(ui[i].text, r[1][0].toUpperCase() + r[1].slice(1), 'severity');
  }
  const pay = alertOf('payment_failures')[0];
  const text = await rowText(pay.id);
  const pf = payFail();
  t.has(text, `${pf.bad} of ${pf.finished}`); t.has(text, `${pf.pct}%`); t.has(text, 'limit is 40%');
  t.ok(await alertRow(pay.id).locator('a', { hasText: 'Payments' }).isVisible(), 'and says where to look first');
});

// ───────────────────────────── filters ─────────────────────────────
await t.step('filters: status, severity and kind each narrow the list to exactly the database rows', async () => {
  await page.click('#al-toolbar [data-status="resolved"]'); await settle();
  t.eq(await page.locator('#al-root tbody tr').count(), 0);
  t.has(await t.text(page.locator('#al-root .empty')), 'No alerts match');
  await page.click('#al-toolbar [data-status="all"]'); await settle();
  t.eq(await page.locator('#al-root tbody tr').count(), Math.min(25, n('SELECT COUNT(*) FROM business_alerts')));
  await page.click('#al-toolbar [data-status="active"]'); await settle();
  await page.selectOption('#al-sev', 'critical'); await settle();
  t.eq(await page.locator('#al-root tbody tr').count(), n(`SELECT COUNT(*) FROM business_alerts WHERE ${live} AND severity = 'critical'`));
  await page.selectOption('#al-sev', ''); await page.selectOption('#al-type', 'stuck_orders'); await settle();
  t.eq(await page.locator('#al-root tbody tr').count(), 1);
  t.has(await t.text(page.locator('#al-root tbody')), 'Paid orders are stuck');
  t.ok((await page.url()).includes('type=stuck_orders'), 'the address bar keeps the filter');
  await page.selectOption('#al-type', ''); await settle();
});

// ───────────────────────────── acknowledge ─────────────────────────────
await t.step('acknowledge: recorded with who and when, the alert STAYS open, the cards and the menu badge follow — and a double click acts once', async () => {
  const stuck = alertOf('stuck_orders')[0];
  const before = n("SELECT COUNT(*) FROM business_alerts WHERE status = 'open'");
  t.eq(await page.locator('[data-alert-count]').first().isHidden(), false, 'the menu shows how many alerts nobody has seen');
  await alertRow(stuck.id).locator('[data-ack]').click();
  await successToast('Alert acknowledged');
  await settle();
  const [status, by, at] = rows(`SELECT status, acknowledged_by, acknowledged_at FROM business_alerts WHERE id = ${stuck.id}`)[0];
  t.eq([status, Number(by), at !== 'NULL'], ['acknowledged', ownerId(), true]);
  t.has(await rowText(stuck.id), 'Acknowledged'); t.has(await rowText(stuck.id), 'by owner');
  t.eq(await alertRow(stuck.id).locator('[data-ack]').count(), 0, 'nothing left to acknowledge on it');
  t.ok(await alertRow(stuck.id).locator('[data-resolve]').isVisible(), 'but it can still be resolved');
  t.eq(await kpi('unacknowledged'), String(before - 1));
  t.eq((await page.locator('[data-alert-count]').first().innerText()).trim(), String(before - 1), 'the menu badge');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'alert.acknowledged'"), 1);

  const prov = alertOf('provider_errors')[0];
  await alertRow(prov.id).locator('[data-ack]').dblclick();
  await page.waitForTimeout(900);
  t.eq(n(`SELECT COUNT(*) FROM audit_logs WHERE action = 'alert.acknowledged' AND entity_id = '${prov.id}'`), 1, 'a double click acknowledged once');
  await settle();
});

await t.step('the menu badge is red while a critical alert is unseen — and amber once every critical one is acknowledged, though others are still unseen', async () => {
  const badge = page.locator('[data-alert-count]').first();
  const unseenCritical = () => n("SELECT COUNT(*) FROM business_alerts WHERE status = 'open' AND severity = 'critical'");
  t.ok(unseenCritical() > 0, 'the seed has a critical alert nobody has seen');
  t.eq(await badge.getAttribute('data-critical') !== null, true, 'red while a critical alert is unseen');
  t.has(await badge.getAttribute('title'), `${unseenCritical()} critical`);
  for (const [id] of rows("SELECT id FROM business_alerts WHERE status = 'open' AND severity = 'critical' ORDER BY id")) {
    await clearToasts();
    await alertRow(Number(id)).locator('[data-ack]').click();
    await successToast('Alert acknowledged');
    await settle();
  }
  t.eq(unseenCritical(), 0, 'every critical alert is acknowledged now');
  t.ok(n("SELECT COUNT(*) FROM business_alerts WHERE status = 'open'") > 0, 'other alerts are still unseen');
  t.eq(await badge.isHidden(), false, 'so the badge is still shown');
  t.eq(await badge.getAttribute('data-critical'), null, 'but no longer red');
});

// ───────────────────────────── resolve and recurrence ─────────────────────────────
await t.step('resolve: asks first (Cancel changes nothing), then closes it for good — and when the problem is still there the next run opens a NEW alert', async () => {
  const pay = alertOf('payment_failures')[0];
  await alertRow(pay.id).locator('[data-resolve]').click();
  await dlg().waitFor();
  t.has(await t.text(dlg()), 'Resolve this alert?');
  await dlg().locator('[data-act="no"]').first().click(); await page.waitForTimeout(450);
  t.eq(alertOf('payment_failures')[0].status, 'open', 'Cancel changed nothing');
  await alertRow(pay.id).locator('[data-resolve]').click();
  await dlg().waitFor();
  await dlg().locator('[data-act="yes"]').click();
  await successToast('Alert resolved');
  await settle();
  const [status, resolution, by] = rows(`SELECT status, resolution, resolved_by FROM business_alerts WHERE id = ${pay.id}`)[0];
  t.eq([status, resolution, Number(by)], ['resolved', 'admin', ownerId()]);
  t.eq(await alertRow(pay.id).count(), 0, 'gone from the active list');
  await page.click('#al-toolbar [data-status="resolved"]'); await settle();
  t.has(await rowText(pay.id), 'by owner');
  await page.click('#al-toolbar [data-status="active"]'); await settle();

  // the failing payments are still in the last hour: running the checks opens a fresh alert; the resolved one stays as history
  await clearToasts();
  await page.click('#al-run'); await successToast('Checks finished'); await settle();
  const all = alertOf('payment_failures');
  t.eq(all.map((a) => a.status), ['resolved', 'open'], 'a recurrence is a new alert, the old one stays resolved');
  t.eq(all[1].occurrences, 1);
});

// ───────────────────────────── run now, dry run ─────────────────────────────
await t.step('Run checks now updates the open alerts (one more occurrence each, no duplicate rows); a Dry run shows the verdicts and changes NOTHING', async () => {
  const occ = n("SELECT SUM(occurrences) FROM business_alerts WHERE active_key LIKE 'wd:%'");
  const rowsBefore = n('SELECT COUNT(*) FROM business_alerts');
  const runsBefore = n("SELECT COUNT(*) FROM job_runs WHERE job = 'watchdog'");
  await clearToasts();
  await page.click('#al-run'); await successToast('Checks finished'); await settle();
  t.eq(n('SELECT COUNT(*) FROM business_alerts'), rowsBefore, 'no new rows: the same problems update the same alerts');
  t.ok(n("SELECT SUM(occurrences) FROM business_alerts WHERE active_key LIKE 'wd:%'") > occ, 'more occurrences');
  t.eq(n("SELECT COUNT(*) FROM job_runs WHERE job = 'watchdog'"), runsBefore + 1);
  t.eq(sql("SELECT trigger_source FROM job_runs WHERE job = 'watchdog' ORDER BY id DESC LIMIT 1"), 'admin');

  const snap = () => [n('SELECT COUNT(*) FROM business_alerts'), n('SELECT SUM(occurrences) FROM business_alerts'), sql("SELECT MD5(v) FROM admin_settings WHERE k = 'watchdog.state'"), n("SELECT COUNT(*) FROM audit_logs WHERE action <> 'job.run'")];
  const before = snap();
  await clearToasts();
  await page.click('#al-dry'); await page.waitForSelector('[data-dry-result]');
  const text = await t.text(page.locator('[data-dry-result]'));
  t.has(text, 'Dry run — nothing was changed'); t.has(text, 'No alert was raised or resolved, no e-mail was sent and no provider was contacted');
  t.ok(await page.locator('[data-dry-result] li').count() >= 12, 'a verdict for every check');
  t.eq(snap(), before, 'alerts, occurrences, stored state and audit log are untouched');
  const ran = JSON.parse(sql("SELECT meta_json FROM audit_logs WHERE action = 'job.run' ORDER BY id DESC LIMIT 1"));
  t.eq([ran.status, ran.dry_run], ['ok', true], 'only the request itself is audited, as a dry run');
  t.eq(n("SELECT COUNT(*) FROM job_runs WHERE job = 'watchdog' AND dry_run = 1"), 1, 'the dry run is in the job history, marked as one');
  await page.click('[data-dry-dismiss]'); await page.waitForTimeout(400);
  t.eq(await page.locator('[data-dry-result]').count(), 0);
});

// ───────────────────────────── checks ─────────────────────────────
await t.step('Checks tab: fourteen checks, each with its type and its last result straight from the stored state — "not enough data" is never shown as OK', async () => {
  await tab('checks');
  t.eq(await page.locator('#al-root tbody tr').count(), 14);
  const st = state();
  const LABEL = { ok: 'OK', alert: 'Alert', insufficient: 'Not enough data', unavailable: 'Unavailable', disabled: 'Switched off' };
  for (const [key, s] of Object.entries(st)) {
    const text = await t.text(page.locator(`#al-root tr[data-check="${key}"]`));
    t.has(text, LABEL[s.status] ?? s.status, key);
  }
  t.has(await t.text(page.locator('#al-root tr[data-check="provider_balance"]')), 'Switched off');
  for (const k of ['sales_drop', 'provider_cost', 'conversion']) t.has(await t.text(page.locator(`#al-root tr[data-check="${k}"]`)), 'Heuristic', k);
  for (const k of ['payment_failures', 'webhooks', 'stuck_orders']) t.has(await t.text(page.locator(`#al-root tr[data-check="${k}"]`)), 'Rule', k);
  const insufficient = Object.entries(st).filter(([, s]) => s.status === 'insufficient').map(([k]) => k);
  for (const k of insufficient) t.ok(!/\bOK\b/.test(await t.text(page.locator(`#al-root tr[data-check="${k}"] td[data-label="Result"]`))), `${k} is not shown as OK`);
  t.has(await t.text(page.locator('#al-root .callout-info[role="note"]')), 'could not');
  t.has(await t.text(page.locator('#al-root .callout-info[role="note"]')), 'none of them uses an AI model');
  t.has(await t.text(page.locator('#al-root tr[data-check="payment_failures"]')), '1 live alert');
});

// ───────────────────────────── thresholds ─────────────────────────────
await t.step('Thresholds: every setting loads with its unit and bounds; an out-of-range value is refused in place and nothing is saved', async () => {
  await tab('settings');
  t.eq(await page.locator('#al-settings input[type="number"]').count(), 32, 'one number field per threshold');
  t.eq(await page.locator('#al-settings input[type="checkbox"]').count(), 14, 'one switch per check');
  t.eq(await page.locator('#al-settings input[name="pay_fail_pct"]').inputValue(), '40');
  t.has(await t.text(page.locator('#al-settings .field', { has: page.locator('[name="pay_fail_pct"]') })), '% of finished payments');
  t.eq(await page.locator('#al-settings input[name="enabled.provider_balance"]').isChecked(), false, 'the provider-balance check starts off');
  await page.fill('#al-settings input[name="pay_fail_pct"]', '3');
  await page.click('#al-save');
  await page.waitForSelector('#al-settings [data-err="pay_fail_pct"]');
  t.has(await t.text(page.locator('#al-settings [data-err="pay_fail_pct"]')), 'Enter a number from 5 to 100');
  t.eq(sql("SELECT COUNT(*) FROM admin_settings WHERE k = 'watchdog.pay_fail_pct'"), '0', 'nothing saved');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'watchdog.settings_changed'"), 0);
});

await t.step('a saved threshold is audited with before and after, and changes the verdict: at 90% the 71% failure rate is fine — two clean runs resolve the alert BY THE WATCHDOG', async () => {
  await page.fill('#al-settings input[name="pay_fail_pct"]', '90');
  await page.click('#al-save'); await successToast('Thresholds saved');
  t.eq(sql("SELECT v FROM admin_settings WHERE k = 'watchdog.pay_fail_pct'"), '90');
  const audit = JSON.parse(sql("SELECT meta_json FROM audit_logs WHERE action = 'watchdog.settings_changed' ORDER BY id DESC LIMIT 1"));
  t.eq(audit.changed.pay_fail_pct, [40, 90]);
  const open = alertOf('payment_failures').find((a) => a.status === 'open');
  await clearToasts();
  await page.click('#al-run'); await successToast('Checks finished'); await settle();
  t.eq(alertOf('payment_failures').find((a) => a.id === open.id).status, 'open', 'one quiet run is not enough');
  await clearToasts();
  await page.click('#al-run'); await successToast('Checks finished'); await settle();
  const closed = alertOf('payment_failures').find((a) => a.id === open.id);
  t.eq([closed.status, closed.resolution], ['resolved', 'auto']);
  await tab('alerts');
  await page.click('#al-toolbar [data-status="resolved"]'); await settle();
  t.has(await rowText(open.id), 'by the watchdog (the check ran clean)');
  await page.click('#al-toolbar [data-status="active"]'); await settle();
});

await t.step('a switched-off check is not run — and its live alert is NOT quietly closed', async () => {
  await tab('settings');
  await page.uncheck('#al-settings input[name="enabled.stuck_orders"]');
  await page.click('#al-save'); await successToast('Thresholds saved');
  await clearToasts();
  await page.click('#al-run'); await successToast('Checks finished'); await settle();
  await tab('checks');
  t.has(await t.text(page.locator('#al-root tr[data-check="stuck_orders"]')), 'Switched off');
  t.ok(alertOf('stuck_orders').some((a) => a.status !== 'resolved'), 'the alert it raised earlier is still open for a person to deal with');
  await tab('settings');
  await page.check('#al-settings input[name="enabled.stuck_orders"]');
  await page.fill('#al-settings input[name="pay_fail_pct"]', '40');
  await page.click('#al-save'); await successToast('Thresholds saved');
});

// ───────────────────────────── e-mail ─────────────────────────────
await t.step('E-mail: off by default; switching it on needs valid recipients (refused in place); saved settings are audited without the addresses; a test message is rate-limited and reports the truth', async () => {
  await tab('mail');
  t.eq(await page.locator('#al-mail input[name="enabled"]').isChecked(), false);
  t.eq(await page.locator('#am-test').isDisabled(), true, 'no recipient saved yet: nothing to test');
  await page.check('#al-mail input[name="enabled"]');
  await page.click('#am-save'); await page.waitForSelector('#al-mail [data-err="to"]');
  t.has(await t.text(page.locator('#al-mail [data-err="to"]')), 'recipient');
  await page.fill('#am-to', 'owner@example.test, not-an-address');
  await page.click('#am-save'); await page.waitForTimeout(500);
  t.has(await t.text(page.locator('#al-mail [data-err="to"]')), 'not-an-address');
  t.eq(sql("SELECT COUNT(*) FROM admin_settings WHERE k = 'alerts.email_enabled'"), '0', 'nothing saved');
  await page.fill('#am-to', 'owner@example.test');
  await page.fill('#am-from', 'alerts@example.test');
  await page.selectOption('#am-sev', 'warning');
  await page.fill('#am-cool', '30');
  await page.click('#am-save'); await successToast('E-mail alerts are on');
  t.eq([sql("SELECT v FROM admin_settings WHERE k = 'alerts.email_enabled'"), sql("SELECT v FROM admin_settings WHERE k = 'alerts.email_min_severity'"), sql("SELECT v FROM admin_settings WHERE k = 'alerts.email_cooldown_min'")], ['true', '"warning"', '30']);
  const audit = sql("SELECT meta_json FROM audit_logs WHERE action = 'alerts.mail_settings_changed' ORDER BY id DESC LIMIT 1");
  t.ok(!audit.includes('example.test') && audit.includes('"recipients":1'), 'the audit log keeps the number of recipients, not the address');
  t.has(await t.text(page.locator('#al-mail')), 'no credentials, no customer data');
  await clearToasts();
  await page.click('#am-test');
  await page.waitForSelector('.toast');
  const toast = await t.text(toastLast());
  t.ok(/handed to the mail system|refused/i.test(toast), 'the test reports what the mail system said: ' + toast);
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'alerts.test_email'"), 1);
  await page.uncheck('#al-mail input[name="enabled"]');
  await page.click('#am-save'); await page.waitForTimeout(600);
  t.eq(sql("SELECT v FROM admin_settings WHERE k = 'alerts.email_enabled'"), 'false');
});

// ───────────────────────────── the dashboard ─────────────────────────────
await t.step('Dashboard: a Business alerts card shows the live counts and the worst alerts, and links to the page', async () => {
  await page.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
  await page.waitForSelector('#dash-alerts .card');
  const card = await t.text(page.locator('#dash-alerts'));
  t.ok(/business alerts/i.test(card), 'the card is titled');
  t.has(card, `${n(`SELECT COUNT(*) FROM business_alerts WHERE ${live}`)} active`);
  const worst = sql(`SELECT title FROM business_alerts WHERE ${live} ORDER BY FIELD(status, 'open', 'acknowledged'), FIELD(severity, 'critical', 'warning', 'info'), last_detected_at DESC LIMIT 1`);
  t.has(ascii(card), ascii(worst), 'the worst alert is on the card');
  // every row carries the start of its own description, so alerts that share a title (three payments that need review) can be told apart
  const shown = (await page.locator('#dash-alerts [data-alert-desc]').allInnerTexts()).map((x) => ascii(x).replace(/\s+/g, ' ').trim());
  t.eq(shown.length, Math.min(3, n(`SELECT COUNT(*) FROM business_alerts WHERE ${live}`)), 'a description line on each of the (at most three) rows');
  const known = rows(`SELECT description FROM business_alerts WHERE ${live}`).map(([d]) => ascii(d).replace(/\s+/g, ' ').trim());
  for (const d of shown) t.ok(d !== '' && known.some((k) => k.startsWith(d.slice(0, 40))), `the row's description is a live alert's own: "${d.slice(0, 50)}"`);
  await Promise.all([page.waitForURL('**/admin/alerts'), page.click('#dash-alerts a[href$="/admin/alerts"]')]);
});

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
const snapshot = () => [sql("SELECT GROUP_CONCAT(id, ':', status ORDER BY id) FROM business_alerts"), sql("SELECT COALESCE(MAX(v), '') FROM admin_settings WHERE k = 'watchdog.pay_fail_pct'"), n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'alert%' OR action LIKE 'watchdog%'"), n("SELECT COUNT(*) FROM job_runs WHERE job = 'watchdog'")];

for (const role of ['analyst', 'support']) {
  await t.step(`role ${role.toUpperCase()}: reads the alerts, the checks and the thresholds — and has no button that changes anything; the server refuses every write`, async () => {
    await asRole(role, async (p) => {
      const before = snapshot();
      await open('', p);
      t.ok(await p.locator('#al-root tbody tr').count() >= 4, 'the alerts are readable');
      t.eq([await p.locator('[data-ack]').count(), await p.locator('[data-resolve]').count(), await p.locator('#al-run').isVisible(), await p.locator('#al-dry').isVisible()], [0, 0, false, false]);
      await tab('checks', p);
      t.eq(await p.locator('#al-root tbody tr').count(), 14);
      await tab('settings', p);
      t.eq([await p.locator('#al-settings input:not([disabled])').count(), await p.locator('#al-save').count()], [0, 0], 'a read-only form without a Save button');
      t.has(await t.text(p.locator('#al-settings')), 'Only an owner or manager can change them');
      await tab('mail', p);
      const mail = await t.text(p.locator('#al-root'));
      t.has(mail, 'Only an owner or manager can change this');
      t.ok(!mail.includes('example.test') && (await p.locator('#al-mail').count()) === 0, 'no recipient addresses and no form');
      const [id] = rows(`SELECT id FROM business_alerts WHERE ${live} LIMIT 1`)[0];
      for (const [method, path, body] of [
        ['POST', `/admin/api/alerts/${id}/acknowledge`, {}], ['POST', `/admin/api/alerts/${id}/resolve`, {}], ['PUT', '/admin/api/alerts/settings', { thresholds: { pay_fail_pct: 70 } }],
        ['PUT', '/admin/api/alerts/mail', { enabled: false }], ['POST', '/admin/api/alerts/mail/test', {}], ['POST', '/admin/api/jobs/watchdog/run', {}],
      ]) { const r = await send(p, method, path, body); t.eq([r.status, code(r)], [403, 'forbidden'], `${method} ${path}`); }
      t.eq(snapshot(), before, 'nothing changed, nothing audited');
    });
  });
}

await t.step('role MANAGER: acknowledges, resolves, runs the checks and changes thresholds — as the owner does', async () => {
  await asRole('manager', async (p) => {
    await open('', p);
    t.eq([await p.locator('#al-run').isVisible(), await p.locator('#al-dry').isVisible(), await p.locator('[data-resolve]').count() > 0], [true, true, true]);
    const [id] = rows(`SELECT id FROM business_alerts WHERE status = 'open' LIMIT 1`)[0] ?? [null];
    if (id) {
      const a = await send(p, 'POST', `/admin/api/alerts/${id}/acknowledge`, {});
      t.eq(a.status, 200);
    }
    t.eq((await send(p, 'PUT', '/admin/api/alerts/settings', { thresholds: { pay_fail_pct: 40 } })).status, 200);
    const run = await send(p, 'POST', '/admin/api/jobs/watchdog/run', { dry_run: true });
    t.eq([run.status, run.body.run?.status], [200, 'ok']);
  });
});

// ───────────────────────────── a phone ─────────────────────────────
await t.step('on a phone: two columns of cards, the tables become labelled cards, nothing overflows, every control can be reached', async () => {
  const phone = await newPage(browser, { width: 390, height: 844 }, { mobile: true, dpr: 2, allowStatus: [403, 409, 422, 429] });
  try {
    await login(phone);
    await open('', phone);
    const cols = await phone.evaluate(() => new Set([...document.querySelectorAll('.kpi-grid .kpi')].map((k) => Math.round(k.getBoundingClientRect().left))).size);
    t.eq(cols, 2, 'two cards per row');
    for (const name of ['alerts', 'checks', 'settings', 'mail']) {
      await phone.click(`#al-tab-${name}`); await settle(phone);
      const r = await layoutReport(phone);
      t.eq([name, r.overflowX, r.wide], [name, false, []], 'no horizontal overflow');
      t.eq(await coveredControls(phone), [], `${name}: nothing covers a control`);
    }
    await phone.click('#al-tab-alerts'); await settle(phone);
    t.ok(!(await phone.locator('#al-root thead').isVisible()), 'the table header gives way to labelled cells');
    t.ok(await phone.locator('#al-root tbody tr td[data-label="Alert"]').first().isVisible());
  } finally { await phone.context().close(); }
});

await t.step('no browser problems on the Alerts page (console errors, CSP violations, failed requests)', async () => {
  t.eq(page.problems.filter((p) => !/\b(403|409|422|429)\b/.test(p)), [], 'problems');
});

await browser.close();
process.exit(t.finish() ? 1 : 0);
