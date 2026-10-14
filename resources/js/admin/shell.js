import { $, $$, html, icon, on, url } from '../core/dom.js';
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

function bannerHtml(s) {
  const out = [];
  if (s.maintenance) {
    out.push(html`<div class="callout callout-warning" role="status">${icon('triangle-alert')}<div><strong>Maintenance mode is ON.</strong> Customers see a maintenance page instead of the store.</div>
      <span class="callout-actions"><a class="btn btn-sm" href="${url('/admin/website')}">Turn off</a></span></div>`);
  }
  const gw = s.setup.find((i) => i.key === 'gateway');
  if (gw && !gw.ok) {
    out.push(html`<div class="callout callout-error" role="status">${icon('circle-alert')}<div><strong>Payments are not set up.</strong> Customers cannot pay until a payment gateway is active.</div>
      <span class="callout-actions"><a class="btn btn-sm" href="${url('/admin/gateway')}">Set up gateway</a></span></div>`);
  } else if (s.gateway_env === 'test') {
    out.push(html`<div class="callout callout-info" role="status">${icon('info')}<div><strong>Payment gateway is in test mode.</strong> Switch it to production before you take real orders.</div>
      <span class="callout-actions"><a class="btn btn-sm" href="${url('/admin/gateway')}">Review</a></span></div>`);
  }
  if (s.auto_services > 0 && !s.smm_ok) {
    out.push(html`<div class="callout callout-warning" role="status">${icon('triangle-alert')}<div><strong>${s.auto_services} AUTO service${s.auto_services === 1 ? '' : 's'} need an SMM provider.</strong> Paid orders cannot be sent to a provider until one is connected.</div>
      <span class="callout-actions"><a class="btn btn-sm" href="${url('/admin/smm')}">Connect provider</a></span></div>`);
  }
  if (!s.cron.ok) {
    out.push(html`<div class="callout callout-warning" role="status">${icon('clock')}<div><strong>Background tasks are not running.</strong> Pending payments will not expire, provider orders will not sync and reports will lag until the cron job runs.</div>
      <span class="callout-actions"><a class="btn btn-sm" href="${url('/admin/system')}">How to fix</a></span></div>`);
  }
  return out.map(String).join('');
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
