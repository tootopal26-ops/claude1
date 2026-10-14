import './core/dialog.js';
import { $, $$, esc, html, icon, on } from './core/dom.js';
import { api, ApiError } from './core/api.js';
import { ok as toastOk, fail } from './core/toast.js';

const card = $('#install-card');
const alertBox = $('#install-alert');
const alertText = $('#install-alert-text');
const steps = $$('[data-step]');
const order = steps.map((s) => s.dataset.step);
let current = order[0];
const data = {};
let busy = false;

const showAlert = (msg) => { alertText.textContent = msg; alertBox.hidden = false; alertBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); };
const hideAlert = () => { alertBox.hidden = true; };

function go(step) {
  current = step;
  steps.forEach((s) => { s.hidden = s.dataset.step !== step; });
  const idx = order.indexOf(step);
  $$('#steps li').forEach((li, i) => {
    li.classList.toggle('is-done', i < idx || step === 'done');
    if (i === idx) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
  });
  hideAlert();
  $('#install-card').scrollIntoView({ block: 'start', behavior: 'smooth' });
  const h = $(`[data-step="${step}"] h2`);
  if (h) { h.tabIndex = -1; h.focus({ preventScroll: true }); }
  if (step === 'review') renderSummary();
}

// ── field helpers ──
function setErr(form, name, msg) {
  const el = form.elements[name];
  if (!el) return;
  el.setAttribute('aria-invalid', 'true');
  const wrap = el.closest('.field') || el.parentElement;
  wrap.querySelectorAll('[data-err]').forEach((n) => n.remove());
  const p = document.createElement('p');
  p.className = 'field-error'; p.dataset.err = name;
  p.innerHTML = icon('circle-alert', 'icon-sm').toString();
  const s = document.createElement('span'); s.textContent = msg; p.appendChild(s);
  wrap.appendChild(p);
}
function clearErrs(form) {
  $$('[data-err]', form).forEach((n) => n.remove());
  $$('[aria-invalid]', form).forEach((n) => n.removeAttribute('aria-invalid'));
}
function showFields(form, fields) {
  clearErrs(form);
  let first = null;
  for (const [k, v] of Object.entries(fields || {})) { setErr(form, k, v); first ??= form.elements[k]; }
  first?.focus();
  return !!first;
}
const val = (form, n) => (form.elements[n]?.value ?? '').trim();

// ── 1. unlock ──
$('#unlock-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (busy) return;
  const form = e.currentTarget;
  clearErrs(form);
  const token = val(form, 'token');
  if (!token) { setErr(form, 'token', 'Paste the token from storage/install-token.txt.'); return; }
  busy = true; $('#unlock-btn').classList.add('is-loading'); $('#unlock-btn').disabled = true;
  try {
    await api('/install/unlock', { method: 'POST', body: { token } });
    location.reload();
  } catch (err) {
    busy = false; $('#unlock-btn').classList.remove('is-loading'); $('#unlock-btn').disabled = false;
    setErr(form, 'token', err.message);
    form.elements.token.select();
  }
});

// ── navigation ──
on(document, 'click', '[data-next]', (e, b) => { if (!b.disabled) go(order[order.indexOf(current) + 1]); });
on(document, 'click', '[data-back]', () => go(order[Math.max(0, order.indexOf(current) - 1)]));
on(document, 'click', '[data-toggle]', (e, b) => {
  const inp = $('#' + b.dataset.toggle);
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  b.setAttribute('aria-pressed', String(show));
  b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  b.querySelector('[data-eye]').hidden = show; b.querySelector('[data-eye-off]').hidden = !show;
  inp.focus();
});

// ── 3. database ──
const dbForm = $('[data-form="database"]');
function dbBody() { return Object.fromEntries(['db_host', 'db_port', 'db_name', 'db_user'].map((k) => [k, val(dbForm, k)]).concat([['db_pass', dbForm.elements.db_pass.value]])); }
async function testDb(button) {
  clearErrs(dbForm);
  const b = dbBody();
  const local = {};
  if (!b.db_host) local.db_host = 'Enter the database host (usually "localhost").';
  if (!b.db_name) local.db_name = 'Enter the database name.';
  if (!b.db_user) local.db_user = 'Enter the database user.';
  if (Object.keys(local).length) { showFields(dbForm, local); return false; }
  const result = $('#db-result');
  result.innerHTML = '';
  if (button) { button.classList.add('is-loading'); button.disabled = true; }
  try {
    const r = await api('/install/test-db', { method: 'POST', body: b });
    result.innerHTML = String(html`<div class="callout callout-success" role="status">${icon('circle-check')}<div>${r.message}</div></div>`);
    return true;
  } catch (err) {
    showFields(dbForm, err.fields);
    result.innerHTML = String(html`<div class="callout callout-error" role="alert">${icon('circle-alert')}<div>${err.message}</div></div>`);
    return false;
  } finally { if (button) { button.classList.remove('is-loading'); button.disabled = false; } }
}
$('#test-db')?.addEventListener('click', (e) => testDb(e.currentTarget));
dbForm?.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (busy) return;
  busy = true;
  const submit = dbForm.querySelector('[type="submit"]');
  submit.classList.add('is-loading'); submit.disabled = true;
  const good = await testDb(null);
  busy = false; submit.classList.remove('is-loading'); submit.disabled = false;
  if (good) { Object.assign(data, dbBody()); go('site'); }
});

// ── 4. site ──
const siteForm = $('[data-form="site"]');
siteForm?.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrs(siteForm);
  const errs = {};
  const url = val(siteForm, 'url');
  try { const u = new URL(url); if (!/^https?:$/.test(u.protocol) || !u.hostname) throw new Error('x'); } catch { errs.url = 'Enter the full address of your site, for example https://example.com'; }
  const name = val(siteForm, 'site_name');
  if (name.length < 2 || name.length > 60) errs.site_name = 'Enter your website name (2–60 characters).';
  if (Object.keys(errs).length) { showFields(siteForm, errs); return; }
  Object.assign(data, { url: url.replace(/\/+$/, ''), site_name: name, timezone: siteForm.elements.timezone.value });
  go('admin');
});

// ── 5. admin ──
const adminForm = $('[data-form="admin"]');
const RULES = [['len', (p) => p.length >= 12], ['lower', (p) => /[a-z]/.test(p)], ['upper', (p) => /[A-Z]/.test(p)], ['num', (p) => /\d/.test(p)], ['sym', (p) => /[^A-Za-z0-9]/.test(p)]];
function paintRules() {
  const p = adminForm.elements.admin_password.value;
  let score = 0;
  RULES.forEach(([k, t]) => { const okk = t(p); score += okk ? 1 : 0; $(`[data-rule="${k}"]`).classList.toggle('is-ok', okk); });
  $('[data-rule="match"]').classList.toggle('is-ok', p !== '' && p === adminForm.elements.admin_confirm.value);
  const level = p.length === 0 ? 0 : score <= 2 ? 1 : score === 3 ? 2 : score === 4 ? 3 : p.length >= 16 ? 4 : 3;
  const m = $('#pw-meter'); m.dataset.level = String(level); m.firstElementChild.style.width = `${[0, 20, 45, 70, 100][level]}%`;
}
adminForm?.addEventListener('input', paintRules);
adminForm?.addEventListener('submit', (e) => {
  e.preventDefault();
  clearErrs(adminForm);
  const errs = {};
  const u = val(adminForm, 'admin_username'), em = val(adminForm, 'admin_email'), pw = adminForm.elements.admin_password.value;
  if (!/^[A-Za-z0-9._-]{3,64}$/.test(u)) errs.admin_username = 'Use 3–64 letters, numbers, dots, dashes or underscores.';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(em)) errs.admin_email = 'Enter a valid email address.';
  if (!RULES.every(([, t]) => t(pw))) errs.admin_password = 'The password does not meet all the requirements below.';
  else if (pw.toLowerCase().includes(u.toLowerCase()) && u.length >= 4) errs.admin_password = 'Do not include your username in the password.';
  if (pw !== adminForm.elements.admin_confirm.value) errs.admin_confirm = 'The two passwords do not match.';
  if (Object.keys(errs).length) { showFields(adminForm, errs); return; }
  Object.assign(data, { admin_username: u, admin_email: em, admin_password: pw, admin_confirm: adminForm.elements.admin_confirm.value });
  go('review');
});

// ── 6. review + run ──
function renderSummary() {
  $('#summary').innerHTML = String(html`<dl class="dl">
    <dt>Website</dt><dd>${data.site_name}</dd><dt>Address</dt><dd class="mono">${data.url}</dd><dt>Time zone</dt><dd>${data.timezone}</dd>
    <dt>Database</dt><dd class="mono">${data.db_name} @ ${data.db_host}:${data.db_port}</dd><dt>Database user</dt><dd class="mono">${data.db_user}</dd>
    <dt>Admin</dt><dd>${data.admin_username} · ${data.admin_email}</dd></dl>`);
  if (!data.url.startsWith('https://')) showAlert('Your site address is not https://. You can still install, but payment gateways and secure cookies need HTTPS on a live site.');
}

const STATUS = ['Saving your settings…', 'Creating database tables…', 'Adding the default services…', 'Creating your admin account…', 'Finishing up…'];
$('#run-install')?.addEventListener('click', async (e) => {
  if (busy) return;
  busy = true;
  const btn = e.currentTarget;
  btn.classList.add('is-loading'); btn.disabled = true; $('#review-back').disabled = true;
  $('#run-progress').hidden = false; $('#env-help').hidden = true; hideAlert();
  let i = 0;
  $('#run-status').textContent = STATUS[0];
  const timer = setInterval(() => { i = Math.min(i + 1, STATUS.length - 1); $('#run-status').textContent = STATUS[i]; }, 1600);
  try {
    const r = await api('/install/run', { method: 'POST', body: { ...data, must_change: false }, timeout: 180000 });
    clearInterval(timer);
    finish(r);
  } catch (err) {
    clearInterval(timer);
    busy = false; btn.classList.remove('is-loading'); btn.disabled = false; $('#review-back').disabled = false; $('#run-progress').hidden = true;
    if (err.code === 'env_not_writable') {
      showAlert(err.message);
      $('#env-text').value = err.detail?.env || '';
      $('#env-help').hidden = false;
    } else if (err.fields && Object.keys(err.fields).length) {
      showAlert(err.message);
      const first = Object.keys(err.fields)[0];
      const stepFor = first.startsWith('db_') ? 'database' : first.startsWith('admin_') ? 'admin' : 'site';
      go(stepFor);
      showFields($(`[data-form="${stepFor}"]`), err.fields);
    } else {
      showAlert(err.message || 'The installation failed. Please try again.');
    }
  }
});
$('#copy-env')?.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText($('#env-text').value); toastOk('Copied'); } catch { $('#env-text').select(); fail('Press Ctrl+C to copy'); }
});

function finish(r) {
  busy = false;
  $('#steps').hidden = true;
  go('done');
  $('#done-login').href = r.login;
  $('#done-warn').hidden = r.https;
  $('#done-list').innerHTML = String(html`
    <li><strong>Sign in</strong> with the admin account you just created.</li>
    <li><strong>Add the cron job</strong> (cPanel → Cron Jobs, every minute) so payments expire and orders sync:<br><code class="kbd break-all">* * * * * ${r.cron} &gt;/dev/null 2&gt;&amp;1</code></li>
    <li><strong>Payment gateway:</strong> Admin → Payment gateway → paste your Alpha UPI API key and webhook secret, then press Test connection.</li>
    <li><strong>SMM provider</strong> (optional, for automatic delivery): Admin → SMM API → Add provider.</li>
    <li><strong>Review services &amp; prices</strong> in Admin → Services; upload your logo in Admin → Website.</li>`);
}
