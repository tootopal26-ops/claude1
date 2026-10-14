import { esc, icon, raw } from './dom.js';

const ICONS = { success: 'circle-check', error: 'circle-alert', warning: 'triangle-alert', info: 'info' };

/** Professional toast: toast('success', 'Settings saved', 'Optional detail') */
export function toast(type, title, message = '', { duration } = {}) {
  const host = document.getElementById('toasts');
  if (!host) return;
  const el = document.createElement('div');
  el.className = 'toast';
  el.dataset.type = type;
  el.setAttribute('role', type === 'error' || type === 'warning' ? 'alert' : 'status');
  el.innerHTML = `<span class="toast-ico">${icon(ICONS[type] || 'info', 'icon-lg')}</span>
    <div class="min-w-0 flex-1"><div class="toast-title">${esc(title)}</div>${message ? `<div class="toast-msg">${esc(message)}</div>` : ''}</div>
    <button type="button" class="btn btn-ghost btn-icon btn-sm -mr-1 -mt-1" aria-label="Dismiss notification">${icon('x')}</button>`;
  host.appendChild(el);
  while (host.children.length > 4) host.firstElementChild.remove();

  const close = () => { if (el.classList.contains('is-leaving')) return; el.classList.add('is-leaving'); setTimeout(() => el.remove(), 260); };
  el.querySelector('button').addEventListener('click', close);
  const ms = duration ?? (type === 'error' ? 7000 : type === 'warning' ? 6000 : 3800);
  let timer = setTimeout(close, ms);
  el.addEventListener('mouseenter', () => clearTimeout(timer));
  el.addEventListener('mouseleave', () => { timer = setTimeout(close, 1800); });
  return close;
}
export const ok = (t, m, o) => toast('success', t, m, o);
export const fail = (t, m, o) => toast('error', t, m, o);
export const warn = (t, m, o) => toast('warning', t, m, o);
export const info = (t, m, o) => toast('info', t, m, o);
