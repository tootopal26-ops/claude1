// Admin → Daily reports, end to end in a real browser. The reports were written by the REAL report code (tools/qa/reports-seed.php) from the demo history, and
// every list row, tile, comparison and note the page shows is checked against independent SQL over the raw orders and payments. Opening a report, the date
// filters, a missing day (created from the page), regenerating (in place, audited, figures compared), a deep link, what each ROLE sees, and a phone.
// Local dev only (resets the dev database). Run:  node tools/qa/admin-reports.mjs
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, ROOT, sql, rows, php, resetLimits } from './site.mjs';
import { layoutReport, coveredControls, login } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-reports');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
resetLimits();
const seed = JSON.parse(php('tools/qa/reports-seed.php'));

const n = (q) => Number(sql(q));
const nSigned = (q) => Number(sql("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_UNSIGNED_SUBTRACTION'); " + q));
const addDays = (d, k) => new Date(Date.parse(d + 'T00:00:00Z') + k * 86400e3).toISOString().slice(0, 10);
const YESTERDAY = seed.yesterday;
const money = (p) => '₹' + new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Math.abs(p) / 100);
const snapshotOf = (day) => JSON.parse(sql(`SELECT snapshot_json FROM daily_reports WHERE report_date = '${day}'`));
const longDay = (d) => new Intl.DateTimeFormat('en-IN', { timeZone: 'UTC', weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(d + 'T00:00:00Z'));
const BASIS = { measured: 'Measured', estimated: 'Estimated', incomplete: 'Incomplete', unavailable: 'Unavailable' };
/** What the raw rows say for one IST day, restated in SQL — independent of the PHP that built the report. */
const day = (d) => ({
  collected: n(`SELECT COALESCE(SUM(amount_paise), 0) FROM orders WHERE paid_at IS NOT NULL AND paid_day = '${d}'`),
  refunds: n(`SELECT COALESCE(SUM(refunded_paise), 0) FROM orders WHERE paid_at IS NOT NULL AND paid_day = '${d}'`),
  paid: n(`SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL AND paid_day = '${d}'`),
  provider: n(`SELECT COALESCE(SUM(provider_cost_paise), 0) FROM orders WHERE paid_at IS NOT NULL AND paid_day = '${d}' AND mode = 'AUTO'`),
  paymentsOk: n(`SELECT COUNT(*) FROM payments WHERE paid_at IS NOT NULL AND paid_day = '${d}'`),
  failed: n(`SELECT COUNT(*) FROM payments WHERE paid_at IS NULL AND status IN ('failed', 'mismatch') AND created_day = '${d}'`),
  expired: n(`SELECT COUNT(*) FROM payments WHERE paid_at IS NULL AND status = 'expired' AND created_day = '${d}'`),
});

const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [403, 404, 409, 422, 429] });
t.page = page;
await login(page);
const toastLast = () => page.locator('.toast').last();
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((x) => x.remove()));
const closeDialogs = async () => { for (let i = 0; i < 3; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeDialogs(); await clearToasts(); await fn(); });
const settle = async (p = page) => { await p.waitForFunction(() => document.querySelector('#rp-root')?.getAttribute('aria-busy') === 'false'); await p.waitForTimeout(300); };
const open = async (q = '', p = page) => { await p.goto(BASE + '/admin/reports' + q, { waitUntil: 'networkidle' }); await settle(p); };
const dlg = (p = page) => p.locator('dialog.dlg-modal[open]');
const successToast = async (part) => { await page.waitForSelector('.toast[data-type="success"]'); if (part) t.has(await t.text(toastLast()), part); };
const tile = (key, p = page) => p.locator(`#rp-root .stat[data-metric="${key}"]`);
const tileText = async (key, p = page) => (await tile(key, p).innerText()).replace(/\s+/g, ' ').trim();
const norm = (x) => String(x).toLowerCase().replace(/[,\s]+/g, ' ').trim();                 // case, commas and spacing differ between ICU versions
const hasI = (text, part) => t.ok(norm(text).includes(norm(part)), `expected “${text}” to include “${part}” (ignoring case and commas)`);
const fullDay = (d) => new Intl.DateTimeFormat('en-IN', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(d + 'T00:00:00Z'));
const cells = async (sel, p = page) => p.locator(sel).locator('td').evaluateAll((tds) => tds.map((td) => td.innerText.replace(/\s+/g, ' ').trim()));

await t.step('the seed: one report per finished day since the first day with data, written by the real report code', async () => {
  t.eq(seed.total, n("SELECT COUNT(*) FROM daily_reports"));
  t.eq(seed.total, Math.round((Date.parse(YESTERDAY) - Date.parse(seed.first)) / 86400e3) + 1, 'no gap, no duplicate');
  t.eq(n("SELECT COUNT(*) FROM (SELECT report_date FROM daily_reports GROUP BY report_date HAVING COUNT(*) > 1) x"), 0);
  t.eq(n("SELECT COUNT(*) FROM daily_reports WHERE ai_status <> 'none'"), 0, 'no AI is configured: none of them claims commentary');
});

await t.step('the history: newest first, twenty to a page, and every row equals the raw records of its day', async () => {
  await open();
  t.eq(await page.locator('h1').innerText(), 'Daily reports');
  t.eq(await page.locator('#rp-root tbody tr').count(), Math.min(20, seed.total));
  t.has(await t.text(page.locator('#rp-root .pager')), `of ${seed.total} reports`);
  const first = await page.locator('#rp-root tbody tr').evaluateAll((trs) => trs.map((tr) => tr.dataset.report));
  t.eq(first[0], YESTERDAY);
  t.eq(first, [...first].sort().reverse(), 'sorted newest first');
  for (const d of first.slice(0, 6)) {
    const e = day(d);
    const c = await cells(`#rp-root tr[data-report="${d}"]`);
    t.has(c[0], longDay(d));
    t.eq(c[1], money(e.collected), 'verified revenue ' + d);
    t.eq(Number(c[2]), e.paid, 'paid orders ' + d);
    const snap = snapshotOf(d);
    t.has(c[4], BASIS[snap.metrics.net_profit.basis], 'basis ' + d);
    t.has(c[3], (snap.metrics.net_profit.value < 0 ? '-' : '') + money(snap.metrics.net_profit.value).replace('₹', '₹'), 'net profit ' + d);
  }
  await page.click('#rp-root [data-page="2"]'); await settle();
  t.eq(await page.locator('#rp-root tbody tr').count(), Math.min(20, seed.total - 20));
  await page.click('#rp-root [data-page="1"]'); await settle();
});

await t.step('date filters: a range shows exactly its days, Reset brings everything back, a backwards range is refused', async () => {
  await page.fill('#rp-from', addDays(YESTERDAY, -2)); await page.fill('#rp-to', YESTERDAY);
  await page.click('#rp-apply'); await settle();
  t.eq(await page.locator('#rp-root tbody tr').count(), 3);
  t.eq(await page.locator('#rp-reset').isVisible(), true);
  t.ok((await page.url()).includes(`from=${addDays(YESTERDAY, -2)}`), 'the address bar keeps the range');
  await page.fill('#rp-from', YESTERDAY); await page.fill('#rp-to', addDays(YESTERDAY, -2));
  await page.click('#rp-apply');
  await page.waitForSelector('.toast[data-type="warning"]');
  t.has(await t.text(toastLast()), 'The first date must not be after the last');
  await page.click('#rp-reset'); await settle();
  t.eq(await page.locator('#rp-root tbody tr').count(), Math.min(20, seed.total));
});

await t.step('one report: every tile equals the raw records, every figure carries its basis, and the notes explain the ones that are not fully measured', async () => {
  await page.click(`#rp-root tr[data-report="${YESTERDAY}"] [data-open]`);
  await page.waitForSelector('[data-report-detail]'); await settle();
  hasI(await t.text(page.locator('#rp-root .report-date')), fullDay(YESTERDAY));                  // the day, written out in full, as the report's own heading
  t.ok((await page.url()).includes(`date=${YESTERDAY}`), 'a deep link');
  const e = day(YESTERDAY);
  const m = snapshotOf(YESTERDAY).metrics;
  t.has(await tileText('collected'), money(e.collected));
  t.has(await tileText('refunds'), money(e.refunds));
  t.has(await tileText('orders_paid'), String(e.paid));
  t.has(await tileText('provider_cost'), money(e.provider));
  t.has(await tileText('payments_success'), String(e.paymentsOk));
  t.has(await tileText('payments_failed'), String(e.failed));
  t.has(await tileText('payments_expired'), String(e.expired));
  t.eq(m.net_revenue.value, e.collected - e.refunds - m.chargebacks.value, 'net revenue in the stored snapshot');
  const sections = (await page.locator('#rp-root .card-title').allInnerTexts()).map((x) => x.trim());
  for (const s of ['Sales', 'Orders paid this day, by where they are now', 'Payments', 'Costs', 'Profit', 'Visitors', 'Compared with the day before', 'API and payment failures', 'Watchdog alerts', 'How to read this report', 'In short']) t.ok(sections.some((x) => x.toLowerCase().includes(s.toLowerCase())), 'section ' + s);
  for (const k of Object.keys(m)) {
    t.has(await t.text(tile(k).locator('.chip')), BASIS[m[k].basis], 'basis of ' + k);
  }
  const notes = Object.entries(m).filter(([, x]) => x.basis !== 'measured' && x.note).map(([k]) => k);
  const shown = await page.locator('#rp-root [data-note]').evaluateAll((li) => li.map((x) => x.dataset.note));
  t.eq(shown.flatMap((x) => x.split(' ')).sort(), [...notes].sort(), 'a note for every figure that is not fully measured (figures with the same note share one entry)');
  t.eq(new Set(shown).size, shown.length, 'and no note is repeated');
  t.eq(await page.locator('#rp-root .callout[data-quality]').getAttribute('data-quality'), snapshotOf(YESTERDAY).quality.status, 'the data-quality banner states the stored status');
  t.has(await t.text(page.locator('#rp-root')), 'No AI model took part');
  t.ok(await page.locator('[data-highlights] li').count() >= 1, 'the plain-language summary');
  t.has(await t.text(page.locator('#rp-root .card-sub').filter({ hasText: 'fixed rules' })), 'no AI');
  // services: only those that SOLD on the day with an unknown cost are listed; active services without a cost that did not sell are counted in one line
  const sv = snapshotOf(YESTERDAY).services;
  const missingCard = page.locator('#rp-root section[aria-label="Services with missing cost data"]');
  t.eq((await missingCard.locator('li b').allInnerTexts()).map((x) => x.trim()), sv.missing_cost.map((x) => x.title), 'the missing-cost card lists exactly the stored services');
  t.ok(sv.missing_cost.every((x) => x.orders > 0), 'and every one of them sold on the day');
  const idle = n(`SELECT COUNT(*) FROM services s WHERE s.deleted_at IS NULL AND s.status = 'active' AND ((s.mode = 'AUTO' AND COALESCE(s.smm_cost_paise, 0) <= 0) OR (s.mode <> 'AUTO' AND s.fulfil_cost_paise IS NULL))
    AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.service_id = s.id AND o.paid_at IS NOT NULL AND o.paid_day = '${YESTERDAY}')`);
  t.eq(sv.no_cost_unsold, idle, 'the idle count equals the active services without a cost that did not sell on the day');
  if (idle) t.has(await t.text(missingCard.locator('[data-svc-foot]')), `${idle} other active service`);
  else t.eq(await missingCard.locator('[data-svc-foot]').count(), 0, 'no footnote when there is nothing to count');
  // the alerts card says how many alerts were unresolved when the report was built — the figure stored with it
  t.has(await t.text(page.locator('#rp-root #h-rp-al + .card-sub')), `${snapshotOf(YESTERDAY).alerts.counts.total} unresolved when this report was built`);
});

await t.step('the comparison with the day before is the day before’s own figures, with the change calculated', async () => {
  const prev = day(addDays(YESTERDAY, -1));
  const e = day(YESTERDAY);
  const row = async (k) => cells(`#rp-root tr[data-compare="${k}"]`);
  const c = await row('collected');
  t.eq([c[1], c[2]], [money(e.collected), money(prev.collected)]);
  const delta = e.collected - prev.collected;
  t.has(c[3], money(Math.abs(delta)));
  if (prev.collected > 0 && delta !== 0) t.has(c[3], `${(Math.floor((Math.abs(delta) * 1000) / prev.collected + 0.5) / 10).toFixed(1)}%`, 'percent change, rounded half up to a tenth');
  const o = await row('orders_paid');
  t.eq([Number(o[1]), Number(o[2])], [e.paid, prev.paid]);
  t.eq(await page.locator('#rp-root tr[data-compare]').count(), 8);
});

await t.step('Back returns to the history; a deep link opens a report; a day that has no report falls back to the history with a notice', async () => {
  await page.click('#rp-back'); await settle();
  t.eq(await page.locator('#rp-root tbody tr').count(), Math.min(20, seed.total));
  await open(`?date=${addDays(YESTERDAY, -3)}`);
  hasI(await t.text(page.locator('#rp-root .report-date')), fullDay(addDays(YESTERDAY, -3)));
  await open('?date=2020-01-01');
  await page.waitForSelector('.toast[data-type="warning"]');
  t.has(await t.text(toastLast()), 'There is no report for that day yet');
  t.eq(await page.locator('#rp-root tbody tr').count(), Math.min(20, seed.total), 'the history is shown instead');
  await open('?date=not-a-date');
  t.eq(await page.locator('#rp-root tbody tr').count(), Math.min(20, seed.total), 'a malformed date is ignored');
});

await t.step('a missing day is announced and created from the page — once, audited, and the callout disappears', async () => {
  sql(`DELETE FROM daily_reports WHERE report_date = '${YESTERDAY}'`);
  await open();
  const callout = await t.text(page.locator('#rp-status [data-missing]'));
  t.has(callout, '1 finished day has no report yet'); t.has(callout, longDay(YESTERDAY));
  await clearToasts();
  await page.click(`#rp-status [data-gen="${YESTERDAY}"]`);
  await successToast('Report created');
  await page.waitForSelector('[data-report-detail]');
  t.eq(n(`SELECT COUNT(*) FROM daily_reports WHERE report_date = '${YESTERDAY}'`), 1);
  t.eq(sql(`SELECT generated_by FROM daily_reports WHERE report_date = '${YESTERDAY}'`), 'admin');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.generated'"), 1);
  await page.click('#rp-back'); await settle();
  t.eq(await page.locator('#rp-status [data-missing]').count(), 0, 'nothing is missing any more');
});

await t.step('Regenerate: confirms first, rebuilds IN PLACE (never a second report), shows what changed, audits before and after — and with nothing changed it says so', async () => {
  await open(`?date=${YESTERDAY}`);
  const before = day(YESTERDAY);
  const count = n('SELECT COUNT(*) FROM daily_reports');
  await page.click('#rp-regen'); await dlg().waitFor();
  t.has(await t.text(dlg()), 'never creates a second report');
  await dlg().locator('[data-act="no"]').first().click(); await page.waitForTimeout(450);
  t.eq(n(`SELECT regenerated_count FROM daily_reports WHERE report_date = '${YESTERDAY}'`), 0, 'Cancel rebuilt nothing');

  // a late correction to a paid order of that day
  sql(`UPDATE orders SET amount_paise = amount_paise + 1000 WHERE paid_at IS NOT NULL AND paid_day = '${YESTERDAY}' ORDER BY id LIMIT 1`);
  await clearToasts();
  await page.click('#rp-regen'); await dlg().waitFor(); await dlg().locator('[data-act="yes"]').click();
  await successToast('Report rebuilt');
  await settle();
  const after = day(YESTERDAY);
  t.eq(after.collected, before.collected + 1000);
  t.has(await tileText('collected'), money(after.collected), 'the page shows the new figure');
  t.eq([n('SELECT COUNT(*) FROM daily_reports'), n(`SELECT regenerated_count FROM daily_reports WHERE report_date = '${YESTERDAY}'`)], [count, 1], 'one row, rebuilt once');
  const audit = JSON.parse(sql("SELECT meta_json FROM audit_logs WHERE action = 'report.regenerated' ORDER BY id DESC LIMIT 1"));
  const c = audit.changed.find((x) => x.key === 'collected');
  t.eq([c.before, c.after], [before.collected, after.collected], 'the audit log keeps the figure before and after');

  await clearToasts();
  await page.click('#rp-regen'); await dlg().waitFor(); await dlg().locator('[data-act="yes"]').click();
  await successToast('no figure changed');
  t.eq(n(`SELECT regenerated_count FROM daily_reports WHERE report_date = '${YESTERDAY}'`), 2);
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
const navLinks = (p) => p.locator('.adm-side .adm-link').allInnerTexts();
const snapshot = () => [n('SELECT COUNT(*) FROM daily_reports'), sql('SELECT COALESCE(SUM(regenerated_count), 0) FROM daily_reports'), n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'report.%'")];

await t.step('role SUPPORT: no Daily reports page or menu entry, and no report data — every read and write is refused', async () => {
  await asRole('support', async (p) => {
    const before = snapshot();
    const res = await p.goto(BASE + '/admin/reports', { waitUntil: 'networkidle' });
    t.eq(res.status(), 403, 'the page is refused');
    await p.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
    t.ok(!(await navLinks(p)).some((x) => /Daily reports/.test(x)), 'the menu does not offer it');
    for (const path of ['/admin/api/reports', `/admin/api/reports/${YESTERDAY}`]) { const r = await send(p, 'GET', path); t.eq([r.status, code(r)], [403, 'forbidden'], path); }
    const r = await send(p, 'POST', `/admin/api/reports/${YESTERDAY}/generate`, { force: true });
    t.eq([r.status, code(r)], [403, 'forbidden']);
    t.eq(snapshot(), before, 'nothing changed');
  });
});

await t.step('role ANALYST: reads the history and every report — read-only: no Regenerate, no Create — and the server refuses the write', async () => {
  await asRole('analyst', async (p) => {
    const before = snapshot();
    await open('', p);
    t.ok((await navLinks(p)).some((x) => /Daily reports/.test(x)), 'the menu offers it');
    t.ok(await p.locator('#rp-root tbody tr').count() > 0);
    await p.click(`#rp-root tr[data-report="${YESTERDAY}"] [data-open]`); await p.waitForSelector('[data-report-detail]');
    t.eq([await p.locator('#rp-regen').count(), await p.locator('[data-gen]').count()], [0, 0]);
    t.ok(/Verified revenue/.test(await t.text(p.locator('#rp-root'))), 'the figures are readable');
    const r = await send(p, 'POST', `/admin/api/reports/${YESTERDAY}/generate`, { force: true });
    t.eq([r.status, code(r)], [403, 'forbidden']);
    t.eq(snapshot(), before, 'nothing changed');
  });
});

await t.step('role MANAGER: creates and regenerates reports, as the owner does', async () => {
  await asRole('manager', async (p) => {
    await open(`?date=${YESTERDAY}`, p);
    t.eq(await p.locator('#rp-regen').isVisible(), true);
    const r = await send(p, 'POST', `/admin/api/reports/${YESTERDAY}/generate`, { force: true });
    t.eq([r.status, r.body.result?.regenerated], [200, true]);
  });
});

// ───────────────────────────── a phone ─────────────────────────────
await t.step('on a phone: the history becomes labelled cards, a report reads in one column, nothing overflows, every control can be reached', async () => {
  const phone = await newPage(browser, { width: 390, height: 844 }, { mobile: true, dpr: 2, allowStatus: [403, 404, 409, 422, 429] });
  try {
    await login(phone);
    await open('', phone);
    let r = await layoutReport(phone);
    t.eq([r.overflowX, r.wide], [false, []], 'the history: no horizontal overflow');
    t.eq(await coveredControls(phone), []);
    t.ok(!(await phone.locator('#rp-root thead').isVisible()), 'the table header gives way to labelled cells');
    t.ok(await phone.locator('#rp-root tbody tr td[data-label="Verified revenue"]').first().isVisible());
    await phone.click(`#rp-root tr[data-report="${YESTERDAY}"] [data-open]`); await phone.waitForSelector('[data-report-detail]'); await settle(phone);
    r = await layoutReport(phone);
    t.eq([r.overflowX, r.wide], [false, []], 'a report: no horizontal overflow');
    t.eq(await coveredControls(phone), []);
    const cols = await phone.evaluate(() => new Set([...document.querySelectorAll('#rp-root .stat')].map((s) => Math.round(s.getBoundingClientRect().left))).size);
    t.ok(cols <= 2, 'the tiles use at most two columns: ' + cols);
  } finally { await phone.context().close(); }
});

await t.step('no browser problems on the Reports page (console errors, CSP violations, failed requests)', async () => {
  t.eq(page.problems.filter((p) => !/\b(403|404|409|422|429)\b/.test(p)), [], 'problems');
});

await browser.close();
process.exit(t.finish() ? 1 : 0);
