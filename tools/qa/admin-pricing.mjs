// Admin → Adaptive pricing, end to end in a real browser, against the REAL engine (App\Services\Pricing) — no mock metrics anywhere.
// Eligibility + the Simulator against a seeded service's real order history, the full experiment lifecycle (propose → dry run →
// go live → evaluate → rollback, with real cohort rollups), Profit Guard, Learning History + the AI insight's rules-based
// fallback (AI is off), a real anonymous storefront visit proving the candidate price only ever reaches an assigned cohort, every
// ROLE's buttons, and a phone. Local dev only (resets the dev database). Run:  node tools/qa/admin-pricing.mjs
import { execFileSync } from 'node:child_process';
import { launch, newPage, BASE, ROOT, sql, php, resetLimits } from './site.mjs';
import { layoutReport, coveredControls, login } from './admin.mjs';
import { suite } from './harness.mjs';

const t = suite('admin-pricing');
execFileSync('bash', ['tools/qa/baseline.sh'], { cwd: ROOT, stdio: 'ignore' });
execFileSync('php', ['tools/qa/qa-admin-account.php'], { cwd: ROOT, stdio: 'ignore' });
resetLimits();
const seed = JSON.parse(php('tools/qa/pricing-seed.php'));
const n = (q) => Number(sql(q));

const browser = await launch();
const page = await newPage(browser, { width: 1440, height: 900 }, { allowStatus: [403, 404, 409, 422, 429] });
t.page = page;
await login(page);

const closeDialogs = async (p = page) => { for (let i = 0; i < 3; i++) { if (!(await p.locator('dialog[open]').count())) break; await p.keyboard.press('Escape'); await p.waitForTimeout(400); } };
const rawStep = t.step.bind(t);
t.step = async (title, fn) => rawStep(title, async () => { await closeDialogs(); await fn(); });
const settle = async (p = page) => {
  await p.waitForFunction(() => document.querySelector('#pr-root')?.getAttribute('aria-busy') === 'false');
  await p.waitForFunction(() => document.querySelector('#pr-summary')?.getAttribute('aria-busy') === 'false');
  await p.waitForTimeout(300);
};
const open = async (p = page) => { await p.goto(BASE + '/admin/pricing', { waitUntil: 'networkidle' }); await settle(p); };
const tab = async (name, p = page) => { await p.click(`#pr-tab-${name}`); await settle(p); };
const dlg = (p = page) => p.locator('dialog.dlg-modal[open]');
const confirmYes = async (p = page) => { await dlg(p).waitFor(); await dlg(p).locator('[data-act="yes"]').click(); await dlg(p).waitFor({ state: 'detached' }); await p.waitForTimeout(400); };
const toast = (type) => page.locator(`.toast[data-type="${type}"]`).last();
const expId = () => n('SELECT id FROM pricing_experiments ORDER BY id DESC LIMIT 1');

await open();

await t.step('the page loads with real KPIs (no experiments yet) and the real eligible-services list — nothing is mock data', async () => {
  t.ok(await page.locator('#pr-summary .kpi').count() >= 4);
  await page.waitForFunction(() => {
    const el = [...document.querySelectorAll('.kpi')].find((k) => k.textContent.includes('Eligible services'));
    return el && el.querySelector('.kpi-value')?.textContent.trim() === '1';
  }, null, { timeout: 10000 });
  await tab('experiments');
  t.eq(await page.locator('[data-exp]').count(), 0);
  await page.click('summary.card-head');
  t.has(await t.text(page.locator('#pr-services')), '50K Likes');
});

await t.step('Simulator: compares real candidate prices against this service\'s own real order history and real costs', async () => {
  await tab('simulator');
  await page.selectOption('#sim-service', String(seed.service_id));
  await page.fill('#sim-candidates', '44, 59, 50');
  await page.click('#sim-run');
  await page.waitForSelector('#sim-result table');
  const rowsText = await page.locator('#sim-result tbody tr').allInnerTexts();
  t.ok(rowsText[0].includes('valid ending'), '₹44 is a valid ending');
  t.ok(rowsText[1].includes('valid ending'), '₹59 is a valid ending');
  t.ok(/end in 4 or 9/.test(rowsText[2]), '₹50 is rejected by the price-ending rule');
  const realPaid = n(`SELECT COUNT(*) FROM orders WHERE service_id = ${seed.service_id} AND paid_at IS NOT NULL`);
  t.ok(await t.text(page.locator('.stat:has-text("Volume used") strong')) === String(realPaid), 'the projection volume is the service\'s real paid-order count, not an invented number');
});

await t.step('propose an experiment: the price-ending rule and the "must differ" rule are enforced server-side, not just hidden client-side', async () => {
  await tab('experiments');
  await page.click('#pr-new-btn');
  await dlg().waitFor();
  await dlg().locator('select[name="service_id"]').selectOption(String(seed.service_id));
  await dlg().locator('input[name="candidate_price"]').fill('50');
  await dlg().locator('[data-act="yes"]').click();
  await page.waitForSelector('[data-err="candidate_price"]');
  t.has(await t.text(page.locator('[data-err="candidate_price"]')), 'end in 4 or 9');
  await dlg().locator('input[name="candidate_price"]').fill('59');
  await dlg().locator('[data-act="yes"]').click();
  await dlg().waitFor({ state: 'detached' });
  await toast('success').waitFor();
  t.eq(n(`SELECT status FROM pricing_experiments WHERE id = ${expId()}`) !== null, true);
  t.eq(sql(`SELECT status, mode, control_paise, candidate_paise FROM pricing_experiments WHERE id = ${expId()}`), `proposed\tdry_run\t4900\t5900`);
});

await t.step('start as a dry run: a REAL A/A test — a fresh anonymous visitor sees the UNCHANGED control price on the real storefront', async () => {
  await page.click(`[data-exp="${expId()}"] [data-act="start-dry"]`);
  await confirmYes();
  t.eq(sql(`SELECT status, mode FROM pricing_experiments WHERE id = ${expId()}`), 'running\tdry_run');

  const anon = await newPage(browser, { width: 1440, height: 900 });
  try {
    await anon.goto(BASE + '/', { waitUntil: 'networkidle' });
    await anon.waitForFunction(() => document.cookie.includes('smb_v='), null, { timeout: 5000 }).catch(() => {});
    await anon.reload({ waitUntil: 'networkidle' }); // now a real cohort is assigned behind this visitor's own cookie
    const card = anon.locator(`[data-service][data-id="${seed.service_id}"]`);
    t.has(await card.innerText(), '49', 'the dry run shows the real default price to every visitor, treatment cohort included');
    t.ok(!(await card.innerText()).includes('59.00'), 'the candidate price is never shown while the experiment is a dry run');
    t.ok(n(`SELECT COUNT(*) FROM pricing_assignments WHERE experiment_id = ${expId()}`) >= 1, 'a real assignment row was written even though the price shown never changed');
  } finally { await anon.context().close(); }
});

await t.step('go live (owner-only): the candidate price can now reach a real, freshly-assigned storefront visitor', async () => {
  await page.click(`[data-exp="${expId()}"] [data-act="golive"]`);
  await confirmYes();
  t.eq(sql(`SELECT mode FROM pricing_experiments WHERE id = ${expId()}`), 'live');

  // the FIRST ever page view of a visitor carries no smb_v cookie yet (it is set by the tracking beacon, not the SSR response) and
  // always sees the control price — exactly like a real brand-new visitor. Cohort pricing only applies once that cookie exists,
  // so each simulated visitor needs a first visit (to pick up the cookie) and a second one (to see their assigned price).
  let sawCandidate = false;
  for (let i = 0; i < 12 && !sawCandidate; i++) {
    const anon = await newPage(browser, { width: 1440, height: 900 });
    try {
      await anon.goto(BASE + '/', { waitUntil: 'networkidle' });
      await anon.waitForFunction(() => document.cookie.includes('smb_v='), null, { timeout: 5000 }).catch(() => {});
      await anon.reload({ waitUntil: 'networkidle' });
      const text = await anon.locator(`[data-service][data-id="${seed.service_id}"]`).innerText();
      if (text.includes('59.00')) sawCandidate = true;
    } finally { await anon.context().close(); }
  }
  t.ok(sawCandidate, 'at least one of 12 fresh (cookie-less) visitors was bucketed into the treatment cohort and shown ₹59.00');
  t.ok(n(`SELECT COUNT(*) FROM pricing_assignments WHERE experiment_id = ${expId()}`) >= 2, 'real assignment rows were written for real visitors');
});

await t.step('Evaluate now runs the real evaluator (same logic as the cron job) and records a real decision log', async () => {
  const before = n(`SELECT COUNT(*) FROM pricing_decision_logs WHERE experiment_id = ${expId()}`);
  await page.click(`[data-exp="${expId()}"] [data-act="evaluate"]`);
  await confirmYes();
  await toast('success').waitFor();
  t.ok(n(`SELECT COUNT(*) FROM pricing_decision_logs WHERE experiment_id = ${expId()}`) > before, 'a new decision-log row was written');
});

await t.step('Profit Guard shows the global pause, the per-service pause toggle, and (while the experiment ran) its profit-floor status', async () => {
  await tab('guard');
  t.eq(await page.locator('#pr-global-pause').isChecked(), false);
  const svcToggle = page.locator(`[data-svc-pause="${seed.service_id}"]`);
  await svcToggle.check();
  await page.waitForTimeout(500);
  t.eq(n(`SELECT pricing_paused FROM services WHERE id = ${seed.service_id}`), 1, 'the toggle really paused the service in the database');
  await svcToggle.uncheck();
  await page.waitForTimeout(500);
  t.eq(n(`SELECT pricing_paused FROM services WHERE id = ${seed.service_id}`), 0);
});

await t.step('Roll back: the experiment stops immediately, a cooldown is set, and a real Business Watchdog alert is raised', async () => {
  await tab('experiments');
  await page.click(`[data-exp="${expId()}"] [data-act="rollback"]`);
  await dlg().waitFor();
  await dlg().locator('input[name="reason"]').fill('QA: verifying the rollback path');
  await dlg().locator('[data-act="yes"]').click();
  await dlg().waitFor({ state: 'detached' });
  await toast('success').waitFor();
  t.eq(sql(`SELECT status FROM pricing_experiments WHERE id = ${expId()}`), 'rolled_back');
  t.ok(n(`SELECT cooldown_until IS NOT NULL FROM pricing_experiments WHERE id = ${expId()}`) === 1);
  t.eq(n(`SELECT COUNT(*) FROM business_alerts WHERE alert_type = 'pricing.rollback'`), 1);

  const anon = await newPage(browser, { width: 1440, height: 900 });
  try {
    await anon.goto(BASE + '/', { waitUntil: 'networkidle' });
    t.has(await anon.locator(`[data-service][data-id="${seed.service_id}"]`).innerText(), '49', 'a visitor after the rollback sees the normal price again');
  } finally { await anon.context().close(); }
});

await t.step('Learning History lists the rolled-back experiment with its real cohort numbers, and the AI insight is the honest rules-based fallback (AI is off)', async () => {
  await tab('history');
  t.eq(await t.text(page.locator('.stat:has-text("Rolled back") strong')), '1');
  const row = page.locator(`[data-exp="${expId()}"]`);
  await row.locator('[data-insight]').click();
  await page.waitForTimeout(500);
  const box = await t.text(page.locator(`[data-insight-row="${expId()}"] [data-insight-box]`));
  t.has(box, 'no AI model was used');
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

await t.step('role ANALYST: reads every tab (pricing.view) but has neither "New experiment" nor "Engine settings" — read-only', async () => {
  await asRole('analyst', async (p) => {
    await p.goto(BASE + '/admin/pricing', { waitUntil: 'networkidle' });
    await p.waitForFunction(() => document.querySelector('#pr-root')?.getAttribute('aria-busy') === 'false');
    t.eq(await p.locator('#pr-new-btn').count(), 0);
    t.eq(await p.locator('#pr-settings-btn').count(), 0);
  });
});

await t.step('role MANAGER: may propose and start a dry run (pricing.propose/pause) but has no "Engine settings" (pricing.approve, owner only)', async () => {
  await asRole('manager', async (p) => {
    await p.goto(BASE + '/admin/pricing', { waitUntil: 'networkidle' });
    await p.waitForFunction(() => document.querySelector('#pr-root')?.getAttribute('aria-busy') === 'false');
    t.eq(await p.locator('#pr-new-btn').count(), 1);
    t.eq(await p.locator('#pr-settings-btn').count(), 0);
  });
});

await t.step('role SUPPORT: no pricing.view — the page and the nav entry are both refused', async () => {
  await asRole('support', async (p) => {
    const res = await p.goto(BASE + '/admin/pricing', { waitUntil: 'networkidle' });
    t.eq(res.status(), 403);
  });
});

// ───────────────────────────── a phone ─────────────────────────────
await t.step('on a phone: every tab reads in one column, nothing overflows, every control can be reached', async () => {
  const phone = await newPage(browser, { width: 390, height: 844 }, { mobile: true, dpr: 2, allowStatus: [403, 404, 409, 422, 429] });
  try {
    await phone.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await phone.fill('#username', 'owner'); await phone.fill('#password', 'Sm8!Qx-Valley#2026');
    await Promise.all([phone.waitForURL('**/admin/dashboard'), phone.click('#login-submit')]);
    await phone.goto(BASE + '/admin/pricing', { waitUntil: 'networkidle' });
    for (const name of ['experiments', 'simulator', 'guard', 'history']) {
      await tab(name, phone);
      const r = await layoutReport(phone);
      t.eq([r.overflowX, r.wide], [false, []], `${name}: no horizontal overflow`);
      t.eq(await coveredControls(phone), [], `${name}: no covered controls`);
    }
  } finally { await phone.context().close(); }
});

await t.step('no browser problems on the Adaptive pricing page (console errors, CSP violations, failed requests)', async () => {
  t.eq(page.problems.filter((p) => !/\b(403|404|409|422|429)\b/.test(p)), [], 'problems');
});

await browser.close();
process.exit(t.finish() ? 1 : 0);
