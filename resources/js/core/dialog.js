import { esc, icon, reduceMotion } from './dom.js';

/** Open a native <dialog> with an enter animation; focus returns to the opener on close. */
export function openDialog(dlg, opener = document.activeElement) {
  if (!dlg || dlg.open) return;
  dlg._opener = opener;
  dlg.showModal();
  document.body.classList.add('has-dialog');
  requestAnimationFrame(() => requestAnimationFrame(() => dlg.classList.add('is-open')));
}

export function closeDialog(dlg, result = '') {
  if (!dlg || !dlg.open || dlg._closing) return;
  dlg._closing = true;
  dlg.classList.remove('is-open');
  const finish = () => {
    dlg._closing = false;
    if (dlg.open) dlg.close(result);
    if (!document.querySelector('dialog.dlg[open]')) document.body.classList.remove('has-dialog');
    const o = dlg._opener; dlg._opener = null;
    if (o && o.isConnected && typeof o.focus === 'function') o.focus({ preventScroll: true });
  };
  if (reduceMotion()) finish(); else setTimeout(finish, 300);
}

// ESC, backdrop click and [data-dialog-close] work for every dialog on the page.
document.addEventListener('cancel', (e) => {
  if (e.target instanceof HTMLDialogElement && e.target.classList.contains('dlg')) { e.preventDefault(); closeDialog(e.target); }
}, true);
document.addEventListener('click', (e) => {
  const t = e.target;
  if (!(t instanceof Element)) return;
  if (t instanceof HTMLDialogElement && t.classList.contains('dlg') && t.open) { closeDialog(t); return; }
  const closer = t.closest('[data-dialog-close]');
  if (closer) {
    const dlg = closer.closest('dialog');
    if (dlg) closeDialog(dlg);
    const sel = closer.getAttribute('data-scroll');
    if (sel) setTimeout(() => document.querySelector(sel)?.scrollIntoView({ behavior: reduceMotion() ? 'auto' : 'smooth', block: 'start' }), 320);
  }
});

/**
 * Promise-based confirmation modal.
 *   const yes = await confirmDialog({ title: 'Approve all?', message: '…', confirmText: 'Approve', tone: 'success' });
 */
export function confirmDialog({ title, message = '', confirmText = 'Confirm', cancelText = 'Cancel', tone = 'primary', html = '' }) {
  return new Promise((resolve) => {
    const dlg = document.createElement('dialog');
    dlg.className = 'dlg dlg-modal';
    dlg.setAttribute('aria-labelledby', 'cd-title');
    dlg.innerHTML = `<div class="dlg-panel">
      <div class="dlg-head"><h2 id="cd-title" class="text-lg font-extrabold">${esc(title)}</h2>
        <button type="button" class="btn btn-ghost btn-icon btn-sm" data-act="no" aria-label="Close">${icon('x')}</button></div>
      <div class="dlg-body"><p class="text-[15px] text-ink-soft">${esc(message)}</p>${html}</div>
      <div class="dlg-foot"><button type="button" class="btn" data-act="no">${esc(cancelText)}</button>
        <button type="button" class="btn btn-${esc(tone)}" data-act="yes">${esc(confirmText)}</button></div></div>`;
    document.body.appendChild(dlg);
    let answer = false;
    dlg.addEventListener('click', (e) => {
      const b = e.target instanceof Element ? e.target.closest('[data-act]') : null;
      if (b) { answer = b.dataset.act === 'yes'; closeDialog(dlg); }
    });
    dlg.addEventListener('close', () => { dlg.remove(); resolve(answer); });
    openDialog(dlg);
    dlg.querySelector('[data-act=yes]')?.focus();
  });
}
