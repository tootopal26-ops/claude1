// Display formatting. Money is ALWAYS integer paise — never a float.
export function groupIndian(digits) {
  const s = String(digits);
  if (s.length <= 3) return s;
  return s.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + s.slice(-3);
}

/** 1080 → "₹10.80", 500 → "₹5", 12345650 → "₹1,23,456.50" (matches App\Support\Money::inr). */
export function inr(paise, forceDecimals = false) {
  const n = Math.trunc(Number(paise) || 0);
  const abs = Math.abs(n);
  const frac = abs % 100;
  let out = '₹' + groupIndian(String(Math.floor(abs / 100)));
  if (frac !== 0 || forceDecimals) out += '.' + String(frac).padStart(2, '0');
  return (n < 0 ? '-' : '') + out;
}

/** Plain machine format "10.80" (used for CPC inputs etc.). */
export function plain(paise) {
  const n = Math.trunc(Number(paise) || 0), a = Math.abs(n);
  return (n < 0 ? '-' : '') + Math.floor(a / 100) + '.' + String(a % 100).padStart(2, '0');
}

/** "59.2" from tenths of a percent. */
export const tenths = (t) => { const n = Math.trunc(Number(t) || 0), a = Math.abs(n); return (n < 0 ? '-' : '') + Math.floor(a / 10) + '.' + (a % 10); };
export const pct = (t) => tenths(t) + '%';

/** Parse "1.08" → 108 (paise) with integer arithmetic; null if invalid. */
export function parseRupees(s) {
  const m = /^(\d{1,9})(?:\.(\d{1,2})0*)?$/.exec(String(s ?? '').trim());
  return m ? Number(m[1]) * 100 + Number((m[2] ?? '0').padEnd(2, '0')) : null;
}

export const num = (n) => new Intl.NumberFormat('en-IN').format(Math.trunc(Number(n) || 0));
export function compact(n) {
  const v = Math.abs(Number(n) || 0);
  if (v >= 1e7) return (n / 1e7).toFixed(v >= 1e8 ? 0 : 1).replace(/\.0$/, '') + 'Cr';
  if (v >= 1e5) return (n / 1e5).toFixed(v >= 1e6 ? 0 : 1).replace(/\.0$/, '') + 'L';
  if (v >= 1e3) return (n / 1e3).toFixed(v >= 1e4 ? 0 : 1).replace(/\.0$/, '') + 'K';
  return String(Math.trunc(n));
}
/** Compact rupees for chart axes. */
export const inrCompact = (paise) => '₹' + compact(Math.trunc(paise / 100));

const TZ = () => document.body.dataset.tz || undefined;
export function fmtDateTime(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d)) return '—';
  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: true, timeZone: TZ() }).format(d);
}
export function fmtDate(iso) {
  if (!iso) return '—';
  const d = typeof iso === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(iso + 'T00:00:00Z') : new Date(iso);
  if (isNaN(d)) return '—';
  return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: /^\d{4}-\d{2}-\d{2}$/.test(iso) ? 'UTC' : TZ() }).format(d);
}
export function timeAgo(iso) {
  const t = new Date(iso).getTime();
  if (isNaN(t)) return '';
  const s = Math.round((Date.now() - t) / 1000);
  if (s < 45) return 'just now';
  if (s < 3600) return Math.round(s / 60) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago';
  if (s < 86400 * 7) return Math.round(s / 86400) + ' d ago';
  return fmtDate(iso);
}
