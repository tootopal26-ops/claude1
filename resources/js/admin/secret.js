// Write-only secret field: shows "saved ••••123" + Replace; never receives the real value from the server.
import { html, icon } from '../core/dom.js';

export function secretField({ name, label, has, hint, placeholder = '', help = '' }) {
  const id = `s-${name}`;
  return html`<div class="field" data-secret="${name}"><label class="label${has ? '' : ' req'}" for="${id}">${label}</label>
    ${has ? html`<div class="secret-set" data-secret-set>${icon('shield-check', 'icon-sm')}<span>Saved and encrypted</span><code>${hint}</code>
      <button type="button" class="btn btn-sm" data-secret-replace="${name}">Replace</button></div>` : ''}
    <div class="input-icon" data-secret-input ${has ? 'hidden' : ''}>${icon('key-round')}
      <input class="input pw-input mono" id="${id}" name="${name}" type="password" autocomplete="new-password" spellcheck="false" autocapitalize="none" placeholder="${placeholder}" maxlength="500" ${has ? 'disabled' : ''}>
      <button type="button" class="pw-toggle" data-secret-toggle="${name}" aria-label="Show ${label.toLowerCase()}" aria-pressed="false"><span data-eye>${icon('eye')}</span><span data-eye-off hidden>${icon('eye-off')}</span></button></div>
    ${has ? html`<button type="button" class="btn btn-sm btn-ghost mt-1 self-start" data-secret-cancel="${name}" hidden>Keep the saved value</button>` : ''}
    ${help ? html`<p class="field-hint">${help}</p>` : ''}</div>`;
}

/** Delegated wiring for secretField(); call once per page root. */
export function wireSecrets(root) {
  root.addEventListener('click', (e) => {
    const t = e.target instanceof Element ? e.target : null;
    if (!t) return;
    const rep = t.closest('[data-secret-replace]');
    if (rep) {
      const w = rep.closest('[data-secret]');
      w.querySelector('[data-secret-set]').hidden = true;
      w.querySelector('[data-secret-input]').hidden = false;
      const inp = w.querySelector('input'); inp.disabled = false; inp.focus();
      w.querySelector('[data-secret-cancel]')?.removeAttribute('hidden');
      return;
    }
    const cancel = t.closest('[data-secret-cancel]');
    if (cancel) {
      const w = cancel.closest('[data-secret]');
      const inp = w.querySelector('input'); inp.value = ''; inp.disabled = true;
      w.querySelector('[data-secret-set]').hidden = false;
      w.querySelector('[data-secret-input]').hidden = true;
      cancel.hidden = true;
      return;
    }
    const tog = t.closest('[data-secret-toggle]');
    if (tog) {
      const inp = tog.closest('[data-secret]').querySelector('input');
      const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      tog.setAttribute('aria-pressed', String(show));
      tog.querySelector('[data-eye]').hidden = show;
      tog.querySelector('[data-eye-off]').hidden = !show;
      inp.focus();
    }
  });
}
