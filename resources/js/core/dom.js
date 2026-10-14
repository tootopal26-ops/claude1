// Tiny DOM helpers. Rendering rule for the whole app: text is ALWAYS escaped; only `raw()` is trusted.
export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };
export const esc = (v) => String(v ?? '').replace(/[&<>"'`]/g, (c) => ESC[c]);

class Raw { constructor(s) { this.s = s; } toString() { return this.s; } }
/** Mark a string as already-safe HTML (only for markup built by this code, never for user data). */
export const raw = (s) => new Raw(String(s ?? ''));

function part(v) {
  if (v == null || v === false) return '';
  if (v instanceof Raw) return v.s;
  if (Array.isArray(v)) return v.map(part).join('');
  return esc(v);
}
/** Tagged template: html`<p>${userText}</p>` escapes every interpolation automatically. */
export function html(strings, ...vals) {
  let out = strings[0];
  vals.forEach((v, i) => { out += part(v) + strings[i + 1]; });
  return new Raw(out);
}
export const setHtml = (el, h) => { el.innerHTML = String(h); return el; };

/** Read a <script type="application/json" id=…> island. */
export function readJson(id, fallback = {}) {
  const el = document.getElementById(id);
  if (!el) return fallback;
  try { return JSON.parse(el.textContent || '{}'); } catch { return fallback; }
}

/** Event delegation: on(root, 'click', '[data-action=x]', (e, el) => …) */
export function on(root, type, selector, handler, opts) {
  root.addEventListener(type, (e) => {
    const t = e.target instanceof Element ? e.target.closest(selector) : null;
    if (t && root.contains(t)) handler(e, t);
  }, opts);
}

export const debounce = (fn, ms = 150) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export const reduceMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export const base = () => document.body.dataset.base || '';
export const url = (p) => base() + '/' + String(p).replace(/^\//, '');

/** Inline SVG icon from the sprite. */
export function icon(name, cls = '') {
  return raw(`<svg class="icon ${esc(cls)}" aria-hidden="true" focusable="false"><use href="${esc(url('assets/img/icons.svg'))}#i-${esc(name)}"/></svg>`);
}

export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
}

/** localStorage that never throws (private mode, blocked storage…). */
export const store = {
  get(k, d = null) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch { return d; } },
  set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch { return false; } },
  del(k) { try { localStorage.removeItem(k); } catch { /* ignore */ } },
};
