// Shared bits of the Tags & Badges UI: the pill, the "real service card" preview, colour maths and the rule that decides which tag a card shows.
// The CSP forbids style attributes, so colours travel as data-* attributes and are applied through the CSSOM by applyTags().
import { html, icon } from '../core/dom.js';
import { inr } from '../core/format.js';

// ── colours ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
/** Upper-case #RRGGBB from #RGB / RGB / #RRGGBB / RRGGBB (any case); null for anything else. Mirrors App\Support\Color::normalizeHex. */
export function normHex(v) {
  let s = String(v ?? '').trim().replace(/^#/, '');
  if (/^[0-9a-f]{3}$/i.test(s)) s = s.replace(/./g, (c) => c + c);
  return /^[0-9a-f]{6}$/i.test(s) ? '#' + s.toUpperCase() : null;
}
const lin = (c) => (c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4);
export function luminance(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => lin(parseInt(hex.slice(i, i + 2), 16) / 255));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
/** WCAG contrast ratio of two #RRGGBB colours (1..21). */
export function contrast(a, b) {
  const [x, y] = [luminance(a), luminance(b)].sort((m, n) => n - m);
  return (x + 0.05) / (y + 0.05);
}
export const textShadow = (fg) => (luminance(fg) > 0.5 ? 'rgb(0 0 0 / 0.28)' : 'rgb(255 255 255 / 0.35)');

/** Apply the colours stored in data-tag-bg / data-tag-fg / data-sw as CSS variables (allowed by the CSP, unlike style="…"). */
export function applyTags(root = document) {
  root.querySelectorAll('[data-tag-bg]').forEach((el) => {
    const bg = normHex(el.dataset.tagBg), fg = normHex(el.dataset.tagFg || '#FFFFFF');
    if (!bg || !fg) return;
    el.style.setProperty('--tag-bg', bg);
    el.style.setProperty('--tag-fg', fg);
    el.style.setProperty('--tag-ts', textShadow(fg));
  });
  root.querySelectorAll('[data-sw]').forEach((el) => { const c = normHex(el.dataset.sw); if (c) el.style.setProperty('--sw', c); });
}

// ── the rule (same as App\Services\Tags::effective) ─────────────────────────────────────────────────────────────────────────────────────
/**
 * The tag a service card shows: the automatic TRIAL PACK at exactly ₹5 (integer paise, never a float) if that tag is enabled, else the assigned
 * tag if it is enabled, else none. `meta` is the services meta from the API ({ tags, tag_rule }). Returns { ...tag, auto } or null.
 */
export function effectiveTag(pricePaise, assignedId, meta) {
  const rule = meta.tag_rule;
  const trial = meta.tags.find((t) => t.id === rule.tag_id);
  if (trial && trial.is_active && pricePaise === rule.price_paise) return { ...trial, auto: true };
  const t = assignedId ? meta.tags.find((x) => x.id === Number(assignedId)) : null;
  return t && t.is_active && !t.is_auto ? { ...t, auto: false } : null;
}

// ── markup ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
/** A badge pill (static, for lists and previews). */
export const pill = (tag, extra = '') => html`<span class="badge tagpill ${extra}" data-tag-bg="${tag.bg_color}" data-tag-fg="${tag.text_color}">${tag.name}</span>`;

const TYPE_ICON = { followers: 'user-plus', subscribers: 'bell-ring', likes: 'heart', views: 'eye', verification: 'badge-check', watch_time: 'clock' };

/** A miniature of the real storefront card (same classes, so the real CSS draws it) with the badge where it will appear. */
export function cardPreview({ title = 'Service title', subtitle = '', pricePaise = 0, tag = null, platform = 'instagram', type = 'followers' }) {
  return html`<div class="tagprev" aria-hidden="true" inert><article class="svc glass">
    ${tag ? html`<span class="badge svc-badge" data-tag-bg="${tag.bg_color}" data-tag-fg="${tag.text_color}">${tag.name}</span>` : ''}
    <div class="svc-top">
      <div class="svc-icon" data-platform="${platform}">${icon(TYPE_ICON[type] || 'sparkles')}</div>
      <div class="svc-body"><h3 class="svc-title">${title || 'Service title'}</h3>${subtitle ? html`<p class="svc-sub">${subtitle}</p>` : ''}</div>
      <div class="svc-price"><b class="num">${inr(pricePaise)}</b></div>
    </div>
    <button type="button" class="btn btn-success btn-lg btn-block" tabindex="-1">BUY NOW</button>
  </article></div>`;
}
