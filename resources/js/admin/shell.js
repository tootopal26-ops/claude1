import { $, $$, html, icon, on, url, store } from '../core/dom.js';
import { openDialog } from '../core/dialog.js';
import { confirmDialog } from '../core/dialog.js';
import { fail } from '../core/toast.js';
import { call, get } from './net.js';

let latest = null;
const listeners = new Set();
/** Pages subscribe to live status (approve count, setup checklist). */
export function onStatus(fn) { listeners.add(fn); if (latest) fn(latest); return () => listeners.delete(fn); }
export const getStatus = () => latest;

function paintBadges(s) {
  const n = s.approvable || 0;
  $$('[data-approve-count]').forEach((el) => { el.hidden = n === 0; el.textContent = n > 99 ? '99+' : String(n); });
  $$('[data-approve-chip]').forEach((el) => {
    el.hidden = n === 0;
    el.textContent = n + ' to approve';
  });
}

const DISMISS_KEY = 'smb.admin.dismissed.v1';
const DISMISS_MS = 12 * 3600 * 1000;
const isDismissed = (id) => { const m = store.get(DISMISS_KEY, {}) || {}; return !!m[id] && Date.now() - m[id] < DISMISS_MS; };
const dismiss = (id) => { const m = store.get(DISMISS_KEY, {}) || {}; m[id] = Date.now(); store.set(DISMISS_KEY, m); };

/** One notice. Errors (payments cannot work) stay; everything else can be dismissed for 12 hours. */
function banner(id, tone, ico, body, action, canDismiss = true) {
  return html`<div class="callout callout-${tone}" role="status" data-banner="${id}">${icon(ico)}<div class="min-w-0 flex-1">${body}</div>
    <span class="callout-actions">${action}${canDismiss ? html`<button type="button" class="btn btn-ghost btn-icon btn-sm" data-dismiss="${id}" aria-label="Dismiss this notice">${icon('x', 'icon-sm')}</button>` : ''}</span></div>`;
}

function bannerHtml(s) {
  const out = [];
  const link = (href, text) => html`<a class="btn btn-sm" href="${url(href)}">${text}</a>`;
  if (s.maintenance) out.push(banner('maintenance', 'warning', 'triangle-alert', html`<strong>Maintenance mode is ON.</strong> Customers see a maintenance page instead of the store.`, link('/admin/website', 'Turn off'), false));
  if (s.pending_migrations > 0) out.push(banner('migrations', 'warning', 'database', html`<strong>A database update is waiting.</strong> New files were uploaded that need ${s.pending_migrations === 1 ? 'one database change' : s.pending_migrations + ' database changes'}; parts of the site may misbehave until it is applied.`, link('/admin/system', 'Apply update'), false));
  const gw = s.setup.find((i) => i.key === 'gateway');
  if (gw && !gw.ok) out.push(banner('gateway-missing', 'error', 'circle-alert', html`<strong>Payments are not set up.</strong> Customers cannot pay until a payment gateway is active.`, link('/admin/gateway', 'Set up gateway'), false));
  else if (s.gateway_env === 'test') out.push(banner('gateway-test', 'info', 'info', html`<strong>Payment gateway is in test mode.</strong> Switch it to production before you take real orders.`, link('/admin/gateway', 'Review')));
  if (s.auto_services > 0 && !s.smm_ok) out.push(banner('smm-missing', 'warning', 'triangle-alert', html`<strong>${s.auto_services} AUTO service${s.auto_services === 1 ? ' needs' : 's need'} an SMM provider.</strong> ${s.auto_services === 1 ? 'It is' : 'They are'} hidden from the store until one is connected, so no customer pays for something that cannot be delivered.`, link('/admin/smm', 'Connect provider')));
  if (!s.cron.ok) out.push(banner('cron', 'warning', 'clock', html`<strong>Background tasks are not running.</strong> Pending payments will not expire, provider orders will not sync and reports will lag until the cron job runs.`, link('/admin/system', 'How to fix')));
  return out.filter((h) => { const id = /data-banner="([^"]+)"/.exec(String(h))?.[1]; return !id || !isDismissed(id); }).map(String).join('');
}

async function refreshStatus() {
  try {
    const { status } = await get('status');
    latest = status;
    paintBadges(status);
    const box = $('#banners');
    if (box) box.innerHTML = bannerHtml(status);
    listeners.forEach((fn) => fn(status));
  } catch { /* the page itself reports connectivity problems */ }
}
export const reloadStatus = refreshStatus;

export function initShell() {
  // dismiss a notice (remembered for 12 hours in this browser)
  on(document, 'click', '[data-dismiss]', (e, b) => { dismiss(b.dataset.dismiss); b.closest('[data-banner]')?.remove(); });
  // mobile navigation drawer
  on(document, 'click', '[data-action="open-nav"]', (e, btn) => openDialog($('#nav-dialog'), btn));

  // sign out (both the sidebar and the drawer have a button)
  on(document, 'click', '[data-action="logout"]', async (e, btn) => {
    btn.disabled = true;
    try {
      const res = await call('/admin/logout', { method: 'POST', body: {} }).catch(() => null);
      location.assign(res?.redirect || url('/admin/login?out=1'));
    } catch {
      fail('Could not sign out', 'Please try again.');
      btn.disabled = false;
    }
  });

  $$('[data-tz-label]').forEach((el) => { el.textContent = document.body.dataset.tz || 'your business time zone'; });
  refreshStatus();
  setInterval(() => { if (!document.hidden) refreshStatus(); }, 60000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshStatus(); });
}

export { confirmDialog };
