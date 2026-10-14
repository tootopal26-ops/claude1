import { $, $$, html, icon, on } from '../core/dom.js';
import { confirmDialog } from '../core/dialog.js';
import { fmtDateTime, timeAgo } from '../core/format.js';
import { get, post, del } from './net.js';
import { ok, fail, errorState, withBusy, showFieldErrors, clearFieldErrors, autoClearErrors } from './ui.js';
import { reloadStatus } from './shell.js';

let account = null;
let sessions = [];
const root = () => $('#security-root');
const forced = new URLSearchParams(location.search).get('force') === '1';

const RULES = [
  ['len', 'At least 12 characters', (p) => p.length >= 12],
  ['lower', 'A lowercase letter', (p) => /[a-z]/.test(p)],
  ['upper', 'An uppercase letter', (p) => /[A-Z]/.test(p)],
  ['num', 'A number', (p) => /\d/.test(p)],
  ['sym', 'A symbol (! @ # $ %)', (p) => /[^A-Za-z0-9]/.test(p)],
];

function sessionRow(s) {
  return html`<li class="flex flex-wrap items-center gap-3 py-3" data-session="${s.id}">
    <span class="grid h-10 w-10 place-items-center rounded-full bg-brand-50 text-brand-700">${icon(s.kind === 'mobile' ? 'smartphone' : s.kind === 'tablet' ? 'tablet' : 'monitor')}</span>
    <span class="min-w-0 flex-1"><b class="block text-sm">${s.device}${s.current ? html` <span class="chip chip-ok ml-1">This device</span>` : ''}${s.remember ? html` <span class="chip chip-gray ml-1">remembered</span>` : ''}</b>
      <span class="block text-xs text-muted">${s.ip || 'unknown IP'} · active ${timeAgo(s.last_seen_at)} · signed in ${fmtDateTime(s.created_at)}</span></span>
    ${s.current ? '' : html`<button type="button" class="btn btn-sm" data-revoke="${s.id}">Sign out</button>`}</li>`;
}

function renderSessions() {
  const box = $('#sessions-box');
  if (!box) return;
  box.innerHTML = String(html`<ul class="divide-y divide-line">${sessions.map(sessionRow)}</ul>
    ${sessions.length > 1 ? html`<div class="mt-3"><button type="button" class="btn btn-sm" id="revoke-others">${icon('log-out', 'icon-sm')}<span>Sign out all other devices</span></button></div>` : ''}`);
}

function render() {
  root().innerHTML = String(html`
  ${forced || account.must_change_password ? html`<div class="callout callout-error mb-4" role="alert">${icon('shield-alert')}<div><strong>Choose a new password to continue.</strong> You are using a temporary password. The rest of the admin panel unlocks once it is changed.</div></div>` : ''}
  <div class="grid-2">
    <form id="pw-form" class="card" novalidate autocomplete="off" aria-labelledby="h-pw">
      <div class="card-head"><h2 class="card-title" id="h-pw">${icon('key-round')}Change password</h2></div>
      <input type="text" name="username" value="${account.username}" autocomplete="username" hidden aria-hidden="true" tabindex="-1">
      ${['current', 'new', 'confirm'].map((n) => html`<div class="field"><label class="label req" for="pw-${n}">${{ current: 'Current password', new: 'New password', confirm: 'Confirm new password' }[n]}</label>
        <div class="input-icon">${icon('lock')}<input class="input pw-input" id="pw-${n}" name="${n}" type="password" autocomplete="${n === 'current' ? 'current-password' : 'new-password'}" maxlength="128" ${n === 'new' ? 'aria-describedby="pw-rules"' : ''}>
        <button type="button" class="pw-toggle" data-toggle="pw-${n}" aria-label="Show password" aria-pressed="false"><span data-eye>${icon('eye')}</span><span data-eye-off hidden>${icon('eye-off')}</span></button></div></div>`)}
      <div class="meter mt-3" id="pw-meter" data-level="0" role="img" aria-label="Password strength"><span></span></div>
      <ul class="reqs" id="pw-rules">${RULES.map(([k, l]) => html`<li data-rule="${k}">${icon('circle-check')}<span>${l}</span></li>`)}<li data-rule="match">${icon('circle-check')}<span>Both new passwords match</span></li></ul>
      <div class="form-actions"><button type="submit" class="btn btn-primary btn-shine" id="pw-save">${icon('check')}<span>Change password</span></button></div>
      <p class="field-hint mt-2">Changing your password signs out every other device.</p>
    </form>

    <div class="stack">
      <section class="card" aria-labelledby="h-sess"><div class="card-head"><h2 class="card-title" id="h-sess">${icon('monitor')}Active sessions</h2></div><div id="sessions-box"></div></section>
      <section class="card" aria-labelledby="h-acc"><div class="card-head"><h2 class="card-title" id="h-acc">${icon('user')}Account</h2></div>
        <div class="kv"><span>Username</span><b>${account.username}</b></div><div class="kv"><span>Email</span><b>${account.email}</b></div>
        <div class="kv"><span>Last sign-in</span><b>${account.last_login_at ? fmtDateTime(account.last_login_at) : '—'}${account.last_login_ip ? ' · ' + account.last_login_ip : ''}</b></div>
        <div class="kv"><span>Password changed</span><b>${account.password_changed_at ? fmtDateTime(account.password_changed_at) : 'never'}</b></div>
        <p class="field-hint mt-2">Sign-in attempts are rate limited: 5 wrong passwords lock the account for 15 minutes. Every sign-in and change is in the <a class="underline" href="${location.pathname.replace('security', 'audit')}">audit log</a>.</p></section>
    </div>
  </div>`);
  autoClearErrors(root());
  renderSessions();
  paintRules();
  root().setAttribute('aria-busy', 'false');
}

function paintRules() {
  const f = $('#pw-form');
  if (!f) return;
  const p = f.elements.new.value;
  let score = 0;
  RULES.forEach(([k, , test]) => { const okk = test(p); score += okk ? 1 : 0; $(`[data-rule="${k}"]`).classList.toggle('is-ok', okk); });
  const match = p !== '' && p === f.elements.confirm.value;
  $('[data-rule="match"]').classList.toggle('is-ok', match);
  const level = p.length === 0 ? 0 : score <= 2 ? 1 : score === 3 ? 2 : score === 4 ? 3 : p.length >= 16 ? 4 : 3;
  const m = $('#pw-meter');
  m.dataset.level = String(level);
  m.firstElementChild.style.width = `${[0, 20, 45, 70, 100][level]}%`;
  m.setAttribute('aria-label', `Password strength: ${['empty', 'weak', 'fair', 'good', 'strong'][level]}`);
}

export default async function init() {
  try {
    const d = await get('account/sessions');
    account = d.account; sessions = d.sessions;
  } catch (e) { root().innerHTML = errorState(e.message).toString(); root().setAttribute('aria-busy', 'false'); return; }
  render();
  const r = root();
  on(r, 'input', '#pw-form', paintRules);
  on(r, 'click', '[data-toggle]', (e, b) => {
    const inp = $('#' + b.dataset.toggle);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    b.setAttribute('aria-pressed', String(show)); b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    b.querySelector('[data-eye]').hidden = show; b.querySelector('[data-eye-off]').hidden = !show;
    inp.focus();
  });
  r.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = $('#pw-form');
    clearFieldErrors(f);
    const v = { current: f.elements.current.value, new: f.elements.new.value, confirm: f.elements.confirm.value };
    const errs = {};
    if (!v.current) errs.current = 'Enter your current password.';
    if (!RULES.every(([, , t]) => t(v.new))) errs.new = 'The new password does not meet all the requirements below.';
    if (v.new !== v.confirm) errs.confirm = 'The new passwords do not match.';
    if (Object.keys(errs).length) { showFieldErrors(f, errs); return; }
    await withBusy($('#pw-save'), async () => {
      try {
        const res = await post('account/password', v);
        ok('Password changed', res.message);
        sessions = res.sessions;
        f.reset(); paintRules(); renderSessions();
        reloadStatus();
        if (forced || account.must_change_password) setTimeout(() => location.assign(location.pathname.replace('security', 'dashboard')), 900);
      } catch (err) {
        if (err.code === 'invalid' && err.fields?.problems) fail('Password not accepted', err.fields.problems.join(' '));
        showFieldErrors(f, { [/current/i.test(err.message) ? 'current' : 'new']: err.message });
      }
    });
  });
  on(r, 'click', '[data-revoke]', async (e, b) => {
    await withBusy(b, async () => {
      try { sessions = (await del(`account/sessions/${b.dataset.revoke}`)).sessions; renderSessions(); ok('Device signed out'); } catch (err) { fail('Could not sign out', err.message); }
    });
  });
  on(r, 'click', '#revoke-others', async (e, b) => {
    if (!(await confirmDialog({ title: 'Sign out all other devices?', message: 'Every other browser signed in to this admin panel will be signed out.', confirmText: 'Sign them out' }))) return;
    await withBusy(b, async () => {
      try { const res = await post('account/sessions/revoke-others'); sessions = res.sessions; renderSessions(); ok(res.message); } catch (err) { fail('Could not sign out', err.message); }
    });
  });
}
