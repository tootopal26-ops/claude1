// Admin → Finance, end to end in a real browser: the profit statement (every line, three columns) against independent SQL, the filters, the services table,
// expenses and chargebacks (add / validate / void), the cost lines of an order in its drawer, the manual fulfilment cost in the service editor, the fee model,
// the reconciliation fixes (dry run first, idempotent), the dashboard profit card, what each ROLE sees, and a phone.
// Local dev only (resets the dev database). Run:  node tools/qa/admin-finance.mjs
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, ROOT, sql, rows, resetLimits, php } from './site.mjs';
import { layoutReport, coveredControls } from './admin.mjs';
import { login } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-finance');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
resetLimits();
const w = JSON.parse(php('tools/qa/finance-seed.php').trim());          // three paid orders of today made through the real checkout + settlement code

// ── helpers ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const istToday = () => new Date(Date.now() + 5.5 * 3600e3).toISOString().slice(0, 10);
const addDays = (d, n) => new Date(Date.parse(d + 'T00:00:00Z') + n * 86400e3).toISOString().slice(0, 10);
const cents = (s) => (/\d/.test(String(s)) ? Math.round(parseFloat(String(s).replace(/[^0-9.\-]/g, '')) * 100) : null);
const ROWS = ['collected', 'refunds', 'after', 'provider', 'fulfil', 'fees', 'other', 'contribution', 'chargebacks', 'expenses', 'ads', 'net', 'margin', 'orders', 'aov'];
const LABELS = ['Verified revenue', 'Refunds', 'Revenue after refunds', 'API (provider) cost', 'Manual fulfilment', 'Payment fees', 'Other order costs', 'Contribution', 'Chargebacks', 'Business expenses', 'Advertising', 'Net profit', 'Margin', 'Paid orders', 'Average order value'];
const COL = { today: 0, period: 1, life: 2 };
const n = (q) => Number(sql(q));
const nSigned = (q) => Number(sql("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_UNSIGNED_SUBTRACTION'); " + q));   // the app connects the same way: a refund larger than a payment must give a negative number, not an error
const ints = (s) => Number(String(s).replace(/[^0-9]/g, ''));              // a count on the page: "1,234" → 1234

const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [403, 409, 422, 429] });
t.page = page;
await login(page);
const toastLast = () => page.locator('.toast').last();
const clearToasts = () => page.evaluate(() => document.querySelectorAll('.toast').forEach((x) => x.remove()));
const closeDialogs = async () => { for (let i = 0; i < 3; i++) { if (!(await page.locator('dialog[open]').count())) break; await page.keyboard.press('Escape'); await page.waitForTimeout(450); } };
const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeDialogs(); await clearToasts(); await fn(); });
const settle = async (p = page) => { await p.waitForFunction(() => document.querySelector('#fin-root')?.getAttribute('aria-busy') === 'false'); await p.waitForTimeout(300); };
const open = async (q = '', p = page) => { await p.goto(BASE + '/admin/finance' + q, { waitUntil: 'networkidle' }); await settle(p); };
const tab = async (name, p = page) => { await p.click(`#fin-tab-${name}`); await settle(p); };
const dlg = (p = page) => p.locator('dialog.dlg-modal[open]');
const submit = (p = page) => dlg(p).locator('[data-act="yes"]').click();
const fieldErr = (name, p = page) => dlg(p).locator(`[data-err="${name}"]`);
const successToast = async (part) => { await page.waitForSelector('.toast[data-type="success"]'); if (part) t.has(await t.text(toastLast()), part); };
async function statement(p = page) {
  return p.evaluate((keys) => {
    const out = {};
    [...document.querySelectorAll('.stmt tbody tr')].forEach((tr, i) => {
      out[keys[i]] = { label: tr.querySelector('th').innerText.replace(/\s+/g, ' ').trim(), cells: [...tr.querySelectorAll('td')].map((td) => td.innerText.replace(/\s+/g, ' ').trim()) };
    });
    return out;
  }, ROWS);
}
const cell = (st, key, col) => cents(st[key].cells[COL[col]]);
const lifetime = async () => { await open('?range=lifetime'); return statement(); };

// ───────────────────────────── the profit statement ─────────────────────────────
await t.step('the page: five tabs, the filters, the status banner and the statement lines in their documented order', async () => {
  await open();
  const tabs = (await page.locator('#fin-tabs [data-tab]').allInnerTexts()).map((x) => x.replace(/\s+/g, ' ').trim());
  t.eq(tabs.length, 5);
  t.ok(['Profit', 'Services', 'Expenses', 'Reconciliation', 'Settings'].every((l, i) => tabs[i].startsWith(l)), 'tab labels: ' + JSON.stringify(tabs));
  t.eq(await page.locator('#fin-tab-overview').getAttribute('aria-selected'), 'true');
  t.eq(await page.locator('#fin-range [aria-pressed="true"]').innerText(), '30 days', 'the default range');
  const banner = await t.text(page.locator('#fin-status'));
  t.eq(await page.locator('#fin-status [data-profit-status]').getAttribute('data-profit-status'), 'incomplete', 'nothing is complete while the fee model is not set up');
  t.has(banner, 'paid orders have a cost that is not known yet');
  t.has(banner, 'CPC estimate');
  const st = await statement();
  ROWS.forEach((k, i) => t.has(st[k]?.label ?? '', LABELS[i], `row ${i}`));
  t.eq(Object.keys(st).length, 15);
  t.eq((await page.locator('.kpi .kpi-label').allTextContents()).map((x) => x.trim()), ['Net revenue', 'Net profit', 'Margin', 'Average order']);
  t.ok(await page.locator('[data-chart="trend"] svg').count() >= 1, 'the trend chart is drawn');
  t.eq(await page.locator('.legend i[data-c]').count(), 4);
});

await t.step('every figure is what the database says — independently computed — and the statement foots on screen', async () => {
  const st = await lifetime();
  const today = istToday();
  const P = 'o.paid_at IS NOT NULL';
  t.eq(cell(st, 'collected', 'life'), n(`SELECT COALESCE(SUM(amount_paise),0) FROM orders o WHERE ${P}`), 'lifetime revenue');
  t.eq(cell(st, 'refunds', 'life'), n(`SELECT COALESCE(SUM(refunded_paise),0) FROM orders o WHERE ${P}`), 'lifetime refunds');
  t.eq(cell(st, 'provider', 'life'), n(`SELECT COALESCE(SUM(provider_cost_paise),0) FROM orders o WHERE ${P} AND mode = 'AUTO'`), 'lifetime provider cost');
  t.eq(ints(st.orders.cells[2]), n(`SELECT COUNT(*) FROM orders o WHERE ${P}`), 'lifetime paid orders');
  t.eq(cell(st, 'collected', 'today'), n(`SELECT COALESCE(SUM(amount_paise),0) FROM orders o WHERE ${P} AND paid_day = '${today}'`), 'today revenue');
  t.eq(ints(st.orders.cells[0]), n(`SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL AND paid_day = '${today}'`), 'today paid orders');
  // the statement adds up — from the numbers that are on the screen
  for (const c of ['today', 'period', 'life']) {
    const v = (k) => cell(st, k, c) ?? 0;
    t.eq(v('after'), v('collected') - v('refunds'), `${c}: revenue after refunds`);
    t.eq(v('contribution'), v('after') - v('provider') - v('fulfil') - v('fees') - v('other'), `${c}: contribution`);
    t.eq(v('net'), v('contribution') - v('chargebacks') - v('expenses') - v('ads'), `${c}: net profit`);
  }
  t.ok((cell(st, 'ads', 'life') ?? 0) > 0, 'advertising is the CPC estimate, and the page says so');
  t.has(st.ads.label, 'estimated');
});

await t.step('the range chips: Today, 7 days, Custom — the period column follows, and so does the URL', async () => {
  const today = istToday();
  await open('?range=lifetime');
  await page.click('#fin-range [data-range="today"]'); await settle();
  t.has(page.url(), 'range=today');
  const st = await statement();
  ROWS.slice(0, 12).forEach((k) => t.eq(st[k].cells[1], st[k].cells[0], `Today = Period for ${k}`));
  await page.click('#fin-range [data-range="7d"]'); await settle();
  const st7 = await statement();
  t.eq(ints(st7.orders.cells[1]), n(`SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL AND paid_day BETWEEN '${addDays(today, -6)}' AND '${today}'`), '7 days of paid orders');
  t.eq(cell(st7, 'collected', 'period'), n(`SELECT COALESCE(SUM(amount_paise),0) FROM orders WHERE paid_at IS NOT NULL AND paid_day BETWEEN '${addDays(today, -6)}' AND '${today}'`), '7 days of revenue');
  await page.click('#fin-range [data-range="custom"]');
  t.ok(await page.locator('#fin-custom').isVisible(), 'the date pickers appear');
  await page.fill('#fin-from', addDays(today, -2)); await page.fill('#fin-to', today);
  await page.click('#fin-apply'); await settle();
  const stc = await statement();
  t.eq(ints(stc.orders.cells[1]), n(`SELECT COUNT(*) FROM orders WHERE paid_at IS NOT NULL AND paid_day BETWEEN '${addDays(today, -2)}' AND '${today}'`), 'a custom range');
  t.has(page.url(), `from=${addDays(today, -2)}`);
  // a reload keeps the view
  await page.reload({ waitUntil: 'networkidle' }); await settle();
  t.eq(await page.locator('#fin-range [aria-pressed="true"]').innerText(), 'Custom');
  t.eq(await page.locator('#fin-from').inputValue(), addDays(today, -2));
});

await t.step('filters: a platform / order-type view leaves out what belongs to the whole business, and says so', async () => {
  await open('?range=lifetime');
  await page.selectOption('#fin-platform', 'instagram'); await settle();
  let st = await statement();
  for (const k of ['chargebacks', 'expenses', 'ads']) t.eq(st[k].cells, ['—', '—', '—'], `${k} is not part of a filtered view`);
  t.eq(st.net.cells, st.contribution.cells, 'net profit equals contribution');
  t.has(await t.text(page.locator('#fin-root')), 'belong to the whole business');
  t.ok(await page.locator('#fin-reset').isVisible());
  t.has(page.url(), 'platform=instagram');
  await page.selectOption('#fin-mode', 'MANUAL'); await settle();
  st = await statement();
  t.eq(ints(st.orders.cells[2]), n("SELECT COUNT(*) FROM orders o JOIN services s ON s.id = o.service_id JOIN platforms p ON p.id = s.platform_id WHERE o.paid_at IS NOT NULL AND o.mode = 'MANUAL' AND p.slug = 'instagram'"), 'Instagram manual orders');
  t.eq(cell(st, 'provider', 'life'), 0, 'a manual order has no provider cost');
  await page.click('#fin-reset'); await settle();
  st = await statement();
  t.ok(st.ads.cells[2] !== '—', 'reset brings the whole business back');
  t.eq(await page.locator('#fin-reset').isVisible(), false);
});

// ───────────────────────────── services ─────────────────────────────
await t.step('Services: contribution per service, the loss-making and the services whose costs are missing — with their reasons', async () => {
  await open('?tab=services&range=lifetime');
  const lists = await page.locator('#fin-root section.card .card-title').allTextContents();
  t.ok(['Most profitable', 'Loss-making', 'Missing cost data'].every((x) => lists.some((l) => l.includes(x))), JSON.stringify(lists));
  const missing = await t.text(page.locator('#fin-root section.card', { hasText: 'Missing cost data' }));
  t.has(missing, '100K Followers', 'the manual service nobody stated a cost for');
  const table = await page.locator('table.tbl-resp tbody tr').evaluateAll((trs) => trs.map((tr) => [...tr.querySelectorAll('td')].map((td) => td.innerText.replace(/\s+/g, ' ').trim())));
  t.ok(table.length >= 5, 'every service that sold');
  const contrib = table.map((r) => cents(r[5]));
  t.eq([...contrib].sort((a, b) => b - a), contrib, 'sorted by contribution, best first');
  const row = table.find((r) => r[0].startsWith('100K Followers'));
  t.ok(row && row[7].includes('incomplete'), 'its costs are labelled incomplete: ' + JSON.stringify(row));
  // a service's contribution in the table is the database's
  const auto = table.find((r) => r[0].startsWith('50 Followers'));
  const sqlAuto = nSigned("SELECT COALESCE(SUM(o.amount_paise - o.refunded_paise - o.provider_cost_paise),0) FROM orders o JOIN services s ON s.id = o.service_id WHERE o.paid_at IS NOT NULL AND s.code = 'ig-followers-50'");
  t.eq(cents(auto[5]), sqlAuto, 'the contribution of an AUTO service');
});

// ───────────────────────────── expenses and chargebacks ─────────────────────────────
let netBefore;
await t.step('Expenses: validation in place, an expense and a chargeback recorded, counted once in the statement, and audited', async () => {
  const st0 = await lifetime();
  netBefore = cell(st0, 'net', 'life');
  await open('?tab=costs');
  t.has(await t.text(page.locator('#fin-root')), 'No expenses recorded');
  t.eq(await page.locator('#fin-add').isVisible(), true, 'the owner may record');
  await page.click('#fin-add');
  t.ok(await dlg().count() === 1);
  await submit();                                                              // nothing entered
  t.has(await t.text(fieldErr('amount')), 'required');
  t.eq(await dlg().count(), 1, 'the dialog stays open');
  await dlg().locator('[name="amount"]').fill('1.999');
  await submit();
  await fieldErr('amount').waitFor();
  t.has(await t.text(fieldErr('amount')), 'two decimals');
  await dlg().locator('[name="category"]').selectOption('chargeback');
  await dlg().locator('[name="amount"]').fill('5');
  await submit();
  await fieldErr('order').waitFor();
  t.eq(n('SELECT COUNT(*) FROM expenses'), 0, 'nothing was saved by the refused attempts');
  await dlg().locator('[name="order"]').fill(w.f_manual_a.public_id);
  await submit();
  await successToast('Chargeback');
  await dlg().waitFor({ state: 'detached' }).catch(() => {});
  await page.waitForFunction(() => document.querySelectorAll('#fin-root tbody tr').length === 1);
  t.has(await t.text(page.locator('#fin-root tbody tr')), `Order ${w.f_manual_a.public_id}`);
  await clearToasts();
  await page.click('#fin-add');
  await dlg().locator('[name="amount"]').fill('499');
  await dlg().locator('[name="description"]').fill('SMS panel, October');
  await dlg().locator('[name="reference"]').fill('INV-77');
  await submit();
  await successToast('Tools');
  await page.waitForFunction(() => document.querySelectorAll('#fin-root tbody tr').length === 2);
  t.has(await t.text(page.locator('#fin-root')), 'Total of what is shown (voided excluded): ₹504');
  t.eq(rows("SELECT category, amount_paise, reference FROM expenses ORDER BY id"), [['chargeback', '500', 'NULL'], ['tools', '49900', 'INV-77']]);
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.expense_added'"), 2);
  await page.click('[data-category="chargeback"]'); await settle();
  t.eq(await page.locator('#fin-root tbody tr').count(), 1, 'the category chip filters');
  await page.click('[data-category=""]'); await settle();
  const st = await lifetime();
  t.eq([cell(st, 'chargebacks', 'life'), cell(st, 'expenses', 'life')], [500, 49900], 'each counted once');
  t.eq(cell(st, 'net', 'life'), netBefore - 500 - 49900, 'net profit fell by exactly what was recorded');
});

await t.step('Expenses: a wrong entry is voided with a reason — it stays visible, stops counting, and the audit log has it', async () => {
  await open('?tab=costs');
  const toolsRow = page.locator('#fin-root tbody tr', { hasText: 'SMS panel' });
  await toolsRow.locator('[data-void-expense]').click();
  await submit();
  t.has(await t.text(fieldErr('reason')), 'required');
  await dlg().locator('[name="reason"]').fill('Entered twice');
  await submit();
  await successToast('voided');
  await page.waitForFunction(() => document.querySelectorAll('#fin-root tbody tr').length === 1);
  await page.check('#fe-voided'); await settle();
  const voided = page.locator('#fin-root tbody tr.is-void');
  t.eq(await voided.count(), 1);
  t.has(await t.text(voided), 'Voided: Entered twice');
  t.eq(rows("SELECT category, voided_at IS NOT NULL, void_reason FROM expenses ORDER BY id"), [['chargeback', '0', 'NULL'], ['tools', '1', 'Entered twice']], 'the row is kept');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.expense_voided'"), 1);
  const st = await lifetime();
  t.eq([cell(st, 'chargebacks', 'life'), cell(st, 'expenses', 'life')], [500, 0], 'a voided expense is not counted');
  t.eq(cell(st, 'net', 'life'), netBefore - 500);
});

// ───────────────────────────── the order drawer ─────────────────────────────
const drawer = () => page.locator('#order-drawer');
const openOrder = async (id, p = page) => {
  await p.goto(BASE + `/admin/orders?open=${id}`, { waitUntil: 'networkidle' });
  await p.waitForSelector('#order-drawer[open] .od-section', { timeout: 15000 });
  await p.waitForTimeout(500);
};
const lines = () => drawer().locator('[data-cost-lines] > li');

await t.step('Order drawer: money, the cost lines with their source, record → correct → add → remove — append-only, validated and audited', async () => {
  await openOrder(w.f_manual_a.id);
  const money = await t.text(drawer().locator('.od-section', { hasText: 'Contribution' }));
  t.has(money, '₹99'); t.has(money, 'manual orders have no provider cost');
  t.eq(await drawer().locator('[data-cost-status]').getAttribute('data-cost-status'), 'incomplete');
  t.has(await t.text(drawer()), 'No cost line is recorded for this order');
  await drawer().locator('[data-record-cost]').click();
  await submit();
  t.has(await t.text(fieldErr('amount')), 'required');
  t.has(await t.text(fieldErr('note')), 'required');
  await dlg().locator('[name="amount"]').fill('15.50');
  await dlg().locator('[name="note"]').fill('Supplier invoice 1042');
  await submit();
  await successToast('Cost recorded');
  await lines().first().waitFor();
  t.eq(await lines().count(), 1);
  const l1 = await t.text(lines().first());
  t.has(l1, 'Fulfilment'); t.has(l1, 'actual'); t.has(l1, '₹15.50'); t.has(l1, 'Supplier invoice 1042');
  t.has(await t.text(drawer().locator('[data-contribution]')), '₹83.50');
  // a correction replaces the amount and keeps the old one as history
  await clearToasts();
  await drawer().locator('[data-record-cost]').click();
  await dlg().locator('[name="amount"]').fill('12');
  await dlg().locator('[name="note"]').fill('The supplier refunded ₹3.50');
  await submit();
  await successToast();
  await page.waitForFunction(() => document.querySelector('#order-drawer [data-contribution]')?.innerText.includes('87'));
  t.eq(await lines().count(), 1);
  t.has(await t.text(lines().first()), '₹12');
  t.has(await t.text(drawer().locator('details.fold summary')), 'Earlier amounts (1)');
  t.eq(rows(`SELECT amount_paise, superseded_at IS NOT NULL FROM order_costs WHERE order_id = ${w.f_manual_a.id} AND cost_type = 'fulfilment' ORDER BY id`), [['1550', '1'], ['1200', '0']], 'append-only');
  // "other" adds a further line, and only an "other" line can be removed
  await clearToasts();
  await drawer().locator('[data-record-cost]').click();
  await dlg().locator('[name="type"]').selectOption('other');
  await dlg().locator('[name="amount"]').fill('2');
  await dlg().locator('[name="note"]').fill('Packing');
  await submit();
  await successToast();
  await page.waitForFunction(() => document.querySelectorAll('#order-drawer [data-cost-lines] > li').length === 2);
  t.eq(await drawer().locator('[data-void-line]').count(), 1, 'a fulfilment cost can only be corrected, an other cost can be removed');
  await clearToasts();
  await drawer().locator('[data-void-line]').click();
  await submit();
  t.has(await t.text(fieldErr('reason')), 'required');
  await dlg().locator('[name="reason"]').fill('Wrong order');
  await submit();
  await successToast('removed');
  await page.waitForFunction(() => document.querySelectorAll('#order-drawer [data-cost-lines] > li').length === 1);
  t.has(await t.text(drawer().locator('[data-contribution]')), '₹87');
  t.eq(n(`SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.cost_recorded' AND entity_id = '${w.f_manual_a.id}'`), 3);
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.cost_removed'"), 1);
  t.eq(n(`SELECT COUNT(*) FROM order_status_history WHERE order_id = ${w.f_manual_a.id} AND note LIKE 'Cost recorded:%'`), 3, 'on the order\'s timeline');
  // the statement shows it
  const st = await lifetime();
  t.eq(cell(st, 'fulfil', 'life'), 1200, 'only the live line counts');
  t.eq(cell(st, 'other', 'life'), 0, 'the removed line does not');
});

// ───────────────────────────── the service editor ─────────────────────────────
await t.step('Service editor: a MANUAL service takes a fulfilment cost — empty means not stated, 0 is a statement, the preview and the list say so', async () => {
  await page.goto(BASE + '/admin/services', { waitUntil: 'networkidle' }); await page.waitForTimeout(900);
  const rowFor = (title) => page.locator('#services-root tbody tr', { hasText: title });
  t.has(await t.text(rowFor('100K Followers').first()), 'cost not stated');
  await rowFor('100K Followers').first().locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  t.eq([await page.locator('#manual-fields').isVisible(), await page.locator('#auto-fields').isVisible()], [true, false], 'a MANUAL service shows the fulfilment field, not the provider ones');
  t.eq(await page.locator('#f-fulfil_cost').inputValue(), '', 'not stated');
  let preview = await t.text(page.locator('#profit-preview'));
  t.has(preview, 'not stated');
  await page.fill('#f-fulfil_cost', 'abc');
  t.has(await t.text(page.locator('#profit-preview')), 'valid price and fulfilment cost');
  await page.click('#svc-save');
  await page.waitForSelector('#svc-form [data-err="fulfil_cost"]');
  t.eq(sql("SELECT COALESCE(fulfil_cost_paise,'NULL') FROM services WHERE code = 'ig-followers-100k'"), 'NULL', 'a refused save changes nothing');
  await page.fill('#f-fulfil_cost', '3.5');
  preview = await t.text(page.locator('#profit-preview'));
  t.has(preview, 'Manual fulfilment cost'); t.has(preview, '− ₹3.50'); t.has(preview, '₹95.50');
  await clearToasts();
  await page.click('#svc-save');
  await page.waitForSelector('.toast[data-type="success"]');
  t.eq(sql("SELECT fulfil_cost_paise FROM services WHERE code = 'ig-followers-100k'"), '350');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'service.fulfil_cost_changed'"), 1);
  t.eq(n("SELECT COUNT(*) FROM order_costs WHERE cost_type = 'fulfilment' AND source = 'service_default'"), 0, 'saving the service rewrites nothing that was already paid');
  await page.waitForTimeout(700);
  t.has(await t.text(rowFor('100K Followers').first()), '₹95.50');
  // an AUTO service does not show it; flipping the toggle does
  await rowFor('100 Likes').first().locator('[data-edit]').click();
  await page.waitForSelector('#service-drawer[open] #svc-form');
  t.eq([await page.locator('#manual-fields').isVisible(), await page.locator('#auto-fields').isVisible()], [false, true]);
  await page.click('[data-mode="MANUAL"]');
  t.eq(await page.locator('#manual-fields').isVisible(), true);
  await page.keyboard.press('Escape');
});

// ───────────────────────────── the fee model ─────────────────────────────
await t.step('Settings: the fee model is validated in place, saved, audited, and recomputes the statement', async () => {
  await open('?tab=settings');
  t.eq(await page.locator('#fs-mode').inputValue(), 'unset');
  t.has(await t.text(page.locator('[data-fee-now]')), 'not set up');
  await page.selectOption('#fs-mode', 'percent');
  await page.fill('#fs-pct', '25');
  await page.click('#fs-save');
  await page.waitForSelector('#fin-settings [data-err="fee_percent"]');
  t.eq(sql("SELECT COUNT(*) FROM admin_settings WHERE k = 'finance.fee_mode'"), '0', 'nothing stored by the refused save');
  await page.fill('#fs-pct', '2');
  await clearToasts();
  await page.click('#fs-save');
  await successToast('Settings saved');
  t.has(await t.text(page.locator('[data-fee-now]')), '2%');
  t.eq(sql("SELECT v FROM admin_settings WHERE k = 'finance.fee_mode'"), '"percent"');
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.settings_changed'"), 1);
  // a payment made from now on carries an estimated fee at the moment it is verified
  const more = JSON.parse(php('tools/qa/finance-seed.php').trim());
  const fee = rows(`SELECT cost_type, amount_paise, source, is_estimate FROM order_costs WHERE order_id = ${more.f_auto.id}`);
  t.eq(fee, [['payment_fee', '10', 'rate', '1']], '2 % of ₹5.00 = 10 paise, an estimate from the model');
  t.eq(rows(`SELECT cost_type, amount_paise FROM order_costs WHERE order_id = ${w.f_auto.id}`), [], 'the order paid before the model existed is not rewritten');
});

await t.step('Reconciliation: what is missing, with a dry run before anything is added — and a second run adds nothing', async () => {
  await open('?tab=reconcile');
  t.ok(await page.locator('#fin-recon-count').isVisible(), 'the tab says how many things need a look');
  const keys = await page.locator('#fin-root [data-issue]').evaluateAll((els) => els.map((e) => e.dataset.issue));
  t.ok(keys.includes('fee_missing') && keys.includes('fulfilment_missing'), JSON.stringify(keys));
  const missingFees = n("SELECT COUNT(*) FROM orders o WHERE o.paid_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM order_costs c WHERE c.order_id = o.id AND c.cost_type = 'payment_fee' AND c.superseded_at IS NULL)");
  t.eq(Number(await page.locator('[data-issue="fee_missing"] [data-count]').innerText().then((x) => x.replace(/,/g, ''))), missingFees, 'the count is the database\'s');
  // fees
  await clearToasts();
  const feeFix = page.locator('[data-issue="fee_missing"] [data-fix="backfill_fees"]');
  t.has(await t.text(feeFix), '2% of the payment');
  await feeFix.click();
  await dlg().waitFor();
  t.has(await t.text(dlg()), `${missingFees} estimated payment-fee line`);
  t.eq(n("SELECT COUNT(*) FROM order_costs WHERE source = 'rate' AND note LIKE 'Added afterwards%'"), 0, 'the dry run changed nothing');
  await dlg().locator('[data-act="yes"]').click();
  await successToast('Added');
  await page.waitForFunction(() => !document.querySelector('[data-issue="fee_missing"]'));
  t.eq(n("SELECT COUNT(*) FROM order_costs WHERE source = 'rate' AND note LIKE 'Added afterwards%'"), missingFees);
  t.eq(n("SELECT COUNT(*) FROM audit_logs WHERE action = 'finance.reconcile_fix'"), 1);
  // fulfilment: the service now has a standard cost (set in the previous steps)
  const withDefault = n("SELECT COUNT(*) FROM orders o JOIN services s ON s.id = o.service_id WHERE o.paid_at IS NOT NULL AND o.mode = 'MANUAL' AND s.fulfil_cost_paise IS NOT NULL AND NOT EXISTS (SELECT 1 FROM order_costs c WHERE c.order_id = o.id AND c.cost_type = 'fulfilment' AND c.superseded_at IS NULL)");
  t.ok(withDefault >= 2, 'there are manual orders to apply it to');
  const fulBefore = n("SELECT COUNT(*) FROM order_costs WHERE cost_type = 'fulfilment' AND source = 'service_default'");
  await clearToasts();
  const fulFix = page.locator('[data-issue="fulfilment_missing"] [data-fix="backfill_fulfilment"]');
  t.has(await t.text(fulFix), `${withDefault} order`);
  await fulFix.click();
  await dlg().waitFor();
  await dlg().locator('[data-act="yes"]').click();
  await successToast('Added');
  await page.waitForTimeout(800);
  t.eq(n("SELECT COUNT(*) FROM order_costs WHERE cost_type = 'fulfilment' AND source = 'service_default'") - fulBefore, withDefault, 'one standard-cost line per order that lacked one');
  t.eq(n("SELECT COUNT(*) FROM (SELECT order_id FROM order_costs WHERE cost_type = 'fulfilment' AND superseded_at IS NULL GROUP BY order_id HAVING COUNT(*) > 1) x"), 0, 'never two live lines for one order');
  t.eq(await page.locator('[data-fix="backfill_fulfilment"]').count(), 0, 'nothing left to apply');
  // a repeat is harmless
  const r = await page.request.fetch(BASE + '/admin/api/finance/reconciliation/fix', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'smb', 'X-CSRF-Token': await page.locator('meta[name=csrf-token]').getAttribute('content'), Origin: BASE }, data: { action: 'backfill_fees', dry_run: false } });
  t.eq([r.status(), (await r.json()).result.orders], [200, 0], 'a second run adds nothing');
});

await t.step('the statement now carries the fees and the standard fulfilment costs, and still foots', async () => {
  const st = await lifetime();
  t.eq(cell(st, 'fees', 'life'), n("SELECT COALESCE(SUM(amount_paise),0) FROM order_costs WHERE cost_type = 'payment_fee' AND superseded_at IS NULL"));
  t.eq(cell(st, 'fulfil', 'life'), n("SELECT COALESCE(SUM(amount_paise),0) FROM order_costs WHERE cost_type = 'fulfilment' AND superseded_at IS NULL"));
  t.ok(cell(st, 'fees', 'life') > 0 && cell(st, 'fulfil', 'life') > 1200);
  for (const c of ['today', 'period', 'life']) {
    const v = (k) => cell(st, k, c) ?? 0;
    t.eq(v('contribution'), v('after') - v('provider') - v('fulfil') - v('fees') - v('other'), `${c}: contribution`);
    t.eq(v('net'), v('contribution') - v('chargebacks') - v('expenses') - v('ads'), `${c}: net profit`);
  }
  const banner = await t.text(page.locator('#fin-status'));
  t.eq(await page.locator('#fin-status [data-profit-status]').getAttribute('data-profit-status'), 'incomplete', 'the other manual services still have no stated cost');
  t.ok(!/payment fee/i.test(banner), 'but the payment fee is no longer what is missing: ' + banner);
  // a view where every cost is known (this service has a standard fulfilment cost and every order has a fee line) is honest about estimates instead
  await open('?range=lifetime&service=ig-followers-100k');
  t.eq(await page.locator('#fin-status [data-profit-status]').getAttribute('data-profit-status'), 'estimated', 'every cost known — some of them estimates, and the page says so');
});

// ───────────────────────────── the dashboard ─────────────────────────────
await t.step('Dashboard: the profit card uses the same costs and adds up on screen', async () => {
  await page.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' }); await page.waitForTimeout(1500);
  const vals = (await page.locator('.eq-item strong').allInnerTexts()).map((x) => cents(x));
  t.eq(vals.length, 4, 'revenue, costs, ads, net');
  t.eq(vals[3], vals[0] - vals[1] - vals[2], 'revenue − costs − ads = net profit');
  const card = await page.locator('.card', { hasText: 'Profit breakdown' }).first().textContent();
  t.ok(/Costs = SMM provider/.test(card), 'the card explains what the costs are');
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
const snapshot = () => [n('SELECT COUNT(*) FROM order_costs'), n('SELECT COUNT(*) FROM expenses'), sql("SELECT v FROM admin_settings WHERE k = 'finance.fee_mode'"), n("SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'finance.%'"), sql("SELECT fulfil_cost_paise FROM services WHERE code = 'ig-followers-100k'")];

await t.step('role SUPPORT: no Finance page or menu entry, no profit anywhere in the data, an order shows only what was paid — and every write is refused', async () => {
  await asRole('support', async (p) => {
    const before = snapshot();
    const res = await p.goto(BASE + '/admin/finance', { waitUntil: 'networkidle' });
    t.eq(res.status(), 403, 'the page is refused');
    await p.goto(BASE + '/admin/dashboard', { waitUntil: 'networkidle' });
    t.ok(!(await navLinks(p)).some((x) => /Finance/.test(x)), 'the menu does not offer it');
    for (const path of ['/admin/api/finance?range=30d', '/admin/api/finance/reconciliation', '/admin/api/finance/expenses', '/admin/api/finance/settings', `/admin/api/finance/orders/${w.f_manual_a.id}`]) {
      const r = await send(p, 'GET', path);
      t.eq([r.status, code(r)], [403, 'forbidden'], path);
    }
    const dash = await send(p, 'GET', '/admin/api/dashboard?range=30d');
    t.eq(dash.status, 200, 'the dashboard itself is theirs');
    const d = dash.body.dashboard;
    t.eq([d.breakdown, d.labels, d.smm, d.meta.finance], [null, null, null, false]);
    t.eq([d.kpis.today.profit, d.kpis.today.ads, d.kpis.lifetime.profit, d.totals.net_profit_paise, d.totals.smm_spend_paise], [undefined, undefined, undefined, undefined, undefined], 'no profit, advertising or provider-cost figure in the response');
    t.ok(!JSON.stringify(d).includes('cpc_paise'), 'not even the cost per click');
    const list = await send(p, 'GET', '/admin/api/orders?per=25');
    t.ok(list.body.rows.length > 0 && list.body.rows.every((r) => !('profit_paise' in r) && !('cost_paise' in r) && !('line_costs' in r)), 'the order list carries no cost or profit');
    const one = await send(p, 'GET', `/admin/api/orders/${w.f_manual_a.id}`);
    t.eq(one.body.money ?? one.body.detail?.money, { amount: 9900, refunded: 0, mode: 'MANUAL', restricted: true }, 'only what was paid and refunded');
    await openOrder(w.f_manual_a.id, p);
    const text = await t.text(p.locator('#order-drawer'));
    t.ok(!/Contribution|Cost lines|Record a cost/i.test(text), 'the drawer shows no cost, contribution or cost-line control');
    t.has(text, 'Paid');
    for (const [method, path, body] of [
      ['POST', `/admin/api/finance/orders/${w.f_manual_a.id}/costs`, { type: 'other', amount: '1', note: 'support cannot' }],
      ['POST', '/admin/api/finance/expenses', { day: istToday(), category: 'tools', amount: '5', description: 'x' }],
      ['PUT', '/admin/api/finance/settings', { fee_mode: 'none' }],
      ['POST', '/admin/api/finance/reconciliation/fix', { action: 'backfill_fees', dry_run: false }],
    ]) { const r = await send(p, method, path, body); t.eq([r.status, code(r)], [403, 'forbidden'], `${method} ${path}`); }
    t.eq(snapshot(), before, 'nothing changed');
  });
});

await t.step('role ANALYST: reads the whole Finance page — read-only: no record button, no fix buttons, a disabled fee form — and the server refuses every write', async () => {
  await asRole('analyst', async (p) => {
    const before = snapshot();
    await open('', p);
    t.ok((await navLinks(p)).some((x) => /Finance/.test(x)), 'the menu offers it');
    t.eq(await p.locator('.stmt').count(), 1, 'the statement is readable');
    t.eq(await p.locator('#fin-add').isVisible(), false);
    await tab('costs', p);
    t.eq(await p.locator('#fin-add').isVisible(), false);
    t.eq(await p.locator('[data-void-expense]').count(), 0, 'no void buttons');
    await tab('reconcile', p);
    t.ok(await p.locator('[data-issue]').count() >= 1);
    t.eq(await p.locator('[data-fix]').count(), 0, 'no fix buttons');
    await tab('settings', p);
    t.eq(await p.locator('#fin-settings input:not([disabled]), #fin-settings select:not([disabled])').count(), 0, 'the fee form is read-only');
    t.has(await t.text(p.locator('#fin-root')), 'Only an owner or manager can change these settings');
    await openOrder(w.f_manual_a.id, p);
    t.ok(/contribution/i.test(await t.text(p.locator('#order-drawer'))), 'the analyst sees the contribution');
    t.eq(await p.locator('#order-drawer [data-record-cost], #order-drawer [data-void-line]').count(), 0, 'the cost lines are read-only');
    for (const [method, path, body] of [
      ['POST', `/admin/api/finance/orders/${w.f_manual_a.id}/costs`, { type: 'other', amount: '1', note: 'analyst cannot' }],
      ['POST', '/admin/api/finance/expenses', { day: istToday(), category: 'tools', amount: '5', description: 'x' }],
      ['POST', '/admin/api/finance/expenses/1/void', { reason: 'no' }],
      ['PUT', '/admin/api/finance/settings', { fee_mode: 'none' }],
      ['POST', '/admin/api/finance/reconciliation/fix', { action: 'backfill_fees', dry_run: false }],
    ]) { const r = await send(p, method, path, body); t.eq([r.status, code(r)], [403, 'forbidden'], `${method} ${path}`); }
    t.eq(snapshot(), before, 'nothing changed');
  });
});

await t.step('role MANAGER: records, voids, fixes and saves — as the owner does', async () => {
  await asRole('manager', async (p) => {
    await open('?tab=costs', p);
    t.eq(await p.locator('#fin-add').isVisible(), true);
    const r1 = await send(p, 'POST', '/admin/api/finance/expenses', { day: istToday(), category: 'hosting', amount: '120', description: 'cPanel renewal' });
    t.eq(r1.status, 200);
    const r2 = await send(p, 'POST', `/admin/api/finance/expenses/${r1.body.expense.id}/void`, { reason: 'Test entry' });
    t.eq(r2.status, 200);
    const r3 = await send(p, 'POST', '/admin/api/finance/reconciliation/fix', { action: 'backfill_fees', dry_run: true });
    t.eq([r3.status, r3.body.result.dry_run], [200, true]);
    const r4 = await send(p, 'PUT', '/admin/api/finance/settings', { fee_mode: 'percent', fee_percent: '2', fee_fixed: '0', ad_gst: 'cost' });
    t.eq(r4.status, 200);
    await tab('reconcile', p);
    await tab('settings', p);
    t.eq(await p.locator('#fin-settings select:not([disabled])').count(), 2, 'the fee form is editable');
  });
});

// ───────────────────────────── a phone ─────────────────────────────
await t.step('on a phone: the statement shows one column at a time, nothing overflows, every control can be reached', async () => {
  const phone = await newPage(browser, { width: 390, height: 844 }, { mobile: true, dpr: 2, allowStatus: [403, 409, 422, 429] });
  try {
    await login(phone);
    await open('?range=lifetime', phone);
    const vis = () => phone.evaluate(() => ['today', 'period', 'life'].map((c) => !!document.querySelector(`.stmt td.c-${c}`)?.offsetParent));
    t.eq(await vis(), [false, true, false], 'the period column is shown first');
    await phone.click('#stmt-cols [data-col="life"]');
    t.eq(await vis(), [false, false, true]);
    await phone.click('#stmt-cols [data-col="today"]');
    t.eq(await vis(), [true, false, false]);
    for (const tabName of ['overview', 'services', 'costs', 'reconcile', 'settings']) {
      await phone.click(`#fin-tab-${tabName}`); await settle(phone);
      const r = await layoutReport(phone);
      t.eq([tabName, r.overflowX, r.wide], [tabName, false, []], 'no horizontal overflow');
      t.eq(await coveredControls(phone), [], `${tabName}: nothing covers a control`);
    }
  } finally { await phone.context().close(); }
});

await t.step('no browser problems on the Finance pages (console errors, CSP violations, failed requests)', async () => {
  t.eq(page.problems.filter((p) => !/\b(403|409|422|429)\b/.test(p)), [], 'problems');
});

await browser.close();
process.exit(t.finish() ? 1 : 0);
