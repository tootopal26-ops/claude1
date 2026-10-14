import { $, on } from './core/dom.js';
import { api, ApiError } from './core/api.js';

const form = $('#login-form');
const card = $('#login-card');
const alertBox = $('#login-alert');
const alertText = $('#login-alert-text');
const submit = $('#login-submit');
const pw = $('#password');
const user = $('#username');
let busy = false;
let lockTimer = 0;

function showAlert(type, text, { shake = false, reload = false } = {}) {
  alertBox.className = 'callout callout-' + type;
  alertBox.hidden = false;
  alertText.textContent = text;
  alertBox.querySelector('.reload-link')?.remove();
  if (reload) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'btn btn-sm reload-link callout-actions'; b.textContent = 'Reload page';
    b.addEventListener('click', () => location.reload());
    alertBox.appendChild(b);
  }
  if (shake) {
    card.classList.remove('is-shake');
    void card.offsetWidth;            // restart the animation
    card.classList.add('is-shake');
  }
}

function fieldError(input, on) {
  if (on) input.setAttribute('aria-invalid', 'true'); else input.removeAttribute('aria-invalid');
}

function setBusy(v) {
  busy = v;
  submit.classList.toggle('is-loading', v);
  submit.disabled = v || lockTimer > 0;
  form.setAttribute('aria-busy', String(v));
}

function startLock(seconds) {
  clearInterval(lockTimer);
  let left = Math.max(1, Math.round(seconds));
  const tick = () => {
    if (left <= 0) {
      clearInterval(lockTimer); lockTimer = 0;
      submit.disabled = false; submit.querySelector('span').textContent = 'Sign in';
      alertBox.hidden = true;
      return;
    }
    const m = Math.floor(left / 60), s = String(left % 60).padStart(2, '0');
    submit.querySelector('span').textContent = `Try again in ${m}:${s}`;
    submit.disabled = true;
    left -= 1;
  };
  tick();
  lockTimer = setInterval(tick, 1000);
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (busy || lockTimer) return;
  const username = user.value.trim();
  const password = pw.value;
  fieldError(user, !username); fieldError(pw, !password);
  if (!username || !password) {
    showAlert('error', !username ? 'Enter your username or email.' : 'Enter your password.', { shake: true });
    (username ? pw : user).focus();
    return;
  }
  alertBox.hidden = true;
  setBusy(true);
  try {
    const res = await api('/admin/login', {
      method: 'POST',
      body: { username, password, remember: $('#remember').checked, next: form.elements.next.value, _csrf: form.elements._csrf.value },
    });
    showAlert('success', 'Signed in. Redirecting…');
    location.assign(res.redirect);
  } catch (err) {
    setBusy(false);
    if (err instanceof ApiError && err.code === 'rate_limited' && err.retryAfter) {
      showAlert('warning', err.message, { shake: true });
      startLock(err.retryAfter);
    } else if (err instanceof ApiError && err.code === 'bad_csrf') {
      showAlert('error', err.message, { shake: true, reload: true });
    } else {
      showAlert('error', err.message || 'Could not sign in. Please try again.', { shake: true });
      fieldError(pw, true);
      pw.select();
    }
  }
});

// show / hide password (keeps focus and caret in the field)
on(document, 'click', '#pw-toggle', (e, btn) => {
  const show = pw.type === 'password';
  pw.type = show ? 'text' : 'password';
  btn.setAttribute('aria-pressed', String(show));
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  btn.querySelector('[data-eye]').hidden = show;
  btn.querySelector('[data-eye-off]').hidden = !show;
  pw.focus();
});

pw.addEventListener('input', () => fieldError(pw, false));
user.addEventListener('input', () => fieldError(user, false));
const caps = (e) => { $('#caps-hint').hidden = !(e.getModifierState && e.getModifierState('CapsLock')); };
pw.addEventListener('keydown', caps);
pw.addEventListener('keyup', caps);
pw.addEventListener('blur', () => { $('#caps-hint').hidden = true; });
