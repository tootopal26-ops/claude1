// Shared building blocks for the admin pages. All text is escaped by html``; only raw() is trusted markup.
import { $, $$, esc, html, raw, icon, on, url } from '../core/dom.js';
import { ok, fail, warn } from '../core/toast.js';
import { openDialog, closeDialog } from '../core/dialog.js';
import { inr, num, fmtDateTime, timeAgo } from '../core/format.js';

export { $, $$, esc, html, raw, icon, on, url, ok, fail, warn, inr, num, fmtDateTime, timeAgo };

// ── status vocabulary ────────────────────────────────────────────────────────
const ORDER = {
  PENDING_PAYMENT: ['Pending payment', 'amber'], PAID: ['Paid', 'blue'], PROCESSING: ['Processing', 'blue'], SUBMITTED: ['Submitted', 'blue'],
  IN_PROGRESS: ['In progress', 'blue'], COMPLETED: ['Completed', 'green'], PARTIAL: ['Partial', 'amber'], CANCELLED: ['Cancelled', 'gray'],
  REFUNDED: ['Refunded', 'purple'], FAILED: ['Failed', 'red'], EXPIRED: ['Expired', 'gray'], MANUAL_PENDING: ['Manual pending', 'pink'], MANUAL_APPROVED: ['Manual approved', 'green'],
};
export const ORDER_STATUSES = Object.entries(ORDER).map(([value, [label]]) => ({ value, label }));
export const orderLabel = (s) => ORDER[s]?.[0] ?? s;
export function orderChip(s, label) {
  const [l, tone] = ORDER[s] ?? [s, 'gray'];
  return html`<span class="st st-${tone}">${label || l}</span>`;
}
const PAY = {
  creating: ['Creating', 'gray'], pending: ['Pending', 'amber'], success: ['Paid', 'green'], failed: ['Failed', 'red'], expired: ['Expired', 'gray'],
  mismatch: ['Needs review', 'red'], refunded: ['Refunded', 'purple'], partially_refunded: ['Part refunded', 'purple'],
};
export const payLabel = (s) => PAY[s]?.[0] ?? s;
export function payChip(s) {
  const [l, tone] = PAY[s] ?? [s, 'gray'];
  return html`<span class="st st-${tone}">${l}</span>`;
}
export const modeChip = (m) => html`<span class="mode mode-${String(m).toLowerCase()}">${m}</span>`;

// ── states ───────────────────────────────────────────────────────────────────
export function skeletonTable(rows = 6) {
  return html`<div class="tbl-wrap" aria-hidden="true">${Array.from({ length: rows }, () => html`<div class="skeleton skel-row"></div>`)}</div>`;
}
export function emptyState({ ico = 'package', title, text = '', action = '' }) {
  return html`<div class="empty"><span class="empty-ico">${icon(ico)}</span><h3>${title}</h3>${text ? html`<p>${text}</p>` : ''}${action ? raw(action) : ''}</div>`;
}
export function errorState(message, retryLabel = 'Try again') {
  return html`<div class="empty" role="alert"><span class="empty-ico is-bad">${icon('wifi-off')}</span>
    <h3>Could not load this section</h3><p>${message}</p><button type="button" class="btn btn-sm" data-retry>${icon('refresh-cw', 'icon-sm')}<span>${retryLabel}</span></button></div>`;
}

// ── buttons / forms ──────────────────────────────────────────────────────────
export function setBusy(btn, busy) {
  if (!btn) return;
  btn.classList.toggle('is-loading', busy);
  btn.disabled = busy;
  btn.setAttribute('aria-busy', String(busy));
}

/** Run an async action behind a button: blocks double clicks, shows the spinner, always re-enables. */
export async function withBusy(btn, fn) {
  if (!btn || btn.disabled) return undefined;
  setBusy(btn, true);
  try { return await fn(); } finally { setBusy(btn, false); }
}

/** Mark inputs invalid from a server `fields` map and focus the first one. */
export function showFieldErrors(root, fields = {}) {
  clearFieldErrors(root);
  let first = null;
  for (const [name, msg] of Object.entries(fields)) {
    const el = root.querySelector(`[name="${CSS.escape(name)}"]`);
    if (!el) continue;
    el.setAttribute('aria-invalid', 'true');
    const wrap = el.closest('.field') || el.parentElement;
    const p = document.createElement('p');
    p.className = 'field-error'; p.dataset.err = name; p.id = `err-${name}-${Math.random().toString(36).slice(2, 7)}`;
    p.innerHTML = icon('circle-alert', 'icon-sm').toString();
    const span = document.createElement('span'); span.textContent = String(msg); p.appendChild(span);
    wrap.appendChild(p);
    el.setAttribute('aria-describedby', p.id);
    first ??= el;
  }
  first?.focus();
  return first;
}
export function clearFieldErrors(root) {
  $$('[data-err]', root).forEach((n) => n.remove());
  $$('[aria-invalid="true"]', root).forEach((n) => { n.removeAttribute('aria-invalid'); n.removeAttribute('aria-describedby'); });
}
// editing a field clears its own error
export function autoClearErrors(root) {
  root.addEventListener('input', (e) => {
    const t = e.target;
    if (t instanceof Element && t.getAttribute('aria-invalid') === 'true') {
      t.removeAttribute('aria-invalid');
      t.closest('.field')?.querySelectorAll('[data-err]').forEach((n) => n.remove());
    }
  });
}

export async function copyText(text, label = 'Copied') {
  try {
    await navigator.clipboard.writeText(text);
    ok(label);
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); ok(label); } catch { fail('Copy failed', 'Select the text and copy it manually.'); }
    ta.remove();
  }
}

// ── pagination ───────────────────────────────────────────────────────────────
export function pager({ page, pages, total, per }, noun = 'items') {
  if (total === 0) return '';
  const from = (page - 1) * per + 1;
  const to = Math.min(total, page * per);
  return html`<div class="pager"><span>Showing <b>${num(from)}–${num(to)}</b> of <b>${num(total)}</b> ${noun}</span>
    <span class="pager-btns"><button type="button" class="btn btn-sm" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''} aria-label="Previous page">${icon('chevron-left', 'icon-sm')}<span>Prev</span></button>
    <span class="px-2 font-semibold text-ink-soft">${page} / ${pages}</span>
    <button type="button" class="btn btn-sm" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''} aria-label="Next page"><span>Next</span>${icon('chevron-right', 'icon-sm')}</button></span></div>`;
}

export const platformImg = (slug, platforms = []) => {
  const p = platforms.find((x) => x.slug === slug);
  return p?.logo_url || '';
};

/** Escape a value for a data-* attribute inside html`` (html`` already escapes; kept for readability). */
export const attr = esc;

export function money(p) { return inr(p); }
export function signedMoney(p) {
  const v = Number(p) || 0;
  return html`<span class="${v > 0 ? 'amt-pos' : v < 0 ? 'amt-neg' : 'amt-mute'}">${inr(v)}</span>`;
}

/** Live search input: fires fn after the user pauses typing. */
export function onSearch(input, fn, ms = 300) {
  let t;
  input.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => fn(input.value.trim()), ms); });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { clearTimeout(t); fn(input.value.trim()); } });
}

export function whenVisible(fn, ms) {
  let last = Date.now();
  const tick = () => { if (!document.hidden && Date.now() - last >= ms) { last = Date.now(); fn(); } };
  const id = setInterval(tick, Math.min(ms, 15000));
  document.addEventListener('visibilitychange', tick);
  return () => clearInterval(id);
}

export function hash(obj) { return JSON.stringify(obj); }

export { url as appUrl };

/**
 * Modal with a small form. Resolves with an object of the entered values, or null when cancelled.
 * `submit(values)` may be async and throw ApiError: field errors are shown in place and the dialog stays open.
 *
 *   await formDialog({ title, message, fields: [{ name, label, type, value, placeholder, required, hint, rows }], confirmText, tone, submit })
 */
export function formDialog({ title, message = '', fields = [], confirmText = 'Confirm', cancelText = 'Cancel', tone = 'primary', submit = null, extra = '' }) {
  return new Promise((resolve) => {
    const dlg = document.createElement('dialog');
    dlg.className = 'dlg dlg-modal';
    const tid = 'fd-' + Math.random().toString(36).slice(2, 8);
    dlg.setAttribute('aria-labelledby', tid);
    const fieldHtml = fields.map((f) => {
      const id = `${tid}-${f.name}`;
      const control = f.type === 'textarea'
        ? html`<textarea class="textarea" id="${id}" name="${f.name}" rows="${f.rows || 3}" maxlength="${f.max || 400}" placeholder="${f.placeholder || ''}">${f.value || ''}</textarea>`
        : html`<input class="input" id="${id}" name="${f.name}" type="${f.type || 'text'}" value="${f.value ?? ''}" placeholder="${f.placeholder || ''}" maxlength="${f.max || 200}" ${f.inputmode ? html`inputmode="${f.inputmode}"` : ''} autocomplete="off" ${f.required ? 'required' : ''}>`;
      return html`<div class="field"><label class="label${f.required ? ' req' : ''}" for="${id}">${f.label}</label>${control}${f.hint ? html`<p class="field-hint">${f.hint}</p>` : ''}</div>`;
    });
    dlg.innerHTML = String(html`<form class="dlg-panel" novalidate>
      <div class="dlg-head"><h2 id="${tid}" class="text-lg font-extrabold">${title}</h2>
        <button type="button" class="btn btn-ghost btn-icon btn-sm" data-act="no" aria-label="Close">${icon('x')}</button></div>
      <div class="dlg-body">${message ? html`<p class="text-[15px] text-ink-soft mb-3">${message}</p>` : ''}${extra ? raw(extra) : ''}${fieldHtml}<div class="mt-3 callout callout-error" data-form-error role="alert" hidden></div></div>
      <div class="dlg-foot"><button type="button" class="btn" data-act="no">${cancelText}</button>
        <button type="submit" class="btn btn-${tone}" data-act="yes">${confirmText}</button></div></form>`);
    document.body.appendChild(dlg);
    const form = dlg.querySelector('form');
    const errBox = dlg.querySelector('[data-form-error]');
    let result = null;
    const finish = () => closeDialog(dlg);
    form.addEventListener('click', (e) => { if (e.target instanceof Element && e.target.closest('[data-act="no"]')) { result = null; finish(); } });
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('[data-act="yes"]');
      if (btn.disabled) return;
      clearFieldErrors(form);
      errBox.hidden = true;
      const values = Object.fromEntries(fields.map((f) => [f.name, form.elements[f.name].value.trim()]));
      const missing = fields.filter((f) => f.required && !values[f.name]);
      if (missing.length) {
        showFieldErrors(form, Object.fromEntries(missing.map((f) => [f.name, 'This field is required.'])));
        return;
      }
      if (submit) {
        setBusy(btn, true);
        try { await submit(values); } catch (err) {
          setBusy(btn, false);
          if (err.fields) showFieldErrors(form, err.fields);
          else { errBox.textContent = err.message || 'That did not work. Please try again.'; errBox.hidden = false; }
          return;
        }
        setBusy(btn, false);
      }
      result = values;
      finish();
    });
    dlg.addEventListener('close', () => { dlg.remove(); resolve(result); });
    openDialog(dlg);
    form.querySelector('input,textarea')?.focus();
    if (!fields.length) form.querySelector('[data-act="yes"]').focus();
  });
}
