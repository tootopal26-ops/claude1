import './core/dialog.js';
import { initShell } from './admin/shell.js';

initShell();

const pages = {
  dashboard: () => import('./admin/dashboard.js'),
  finance: () => import('./admin/finance.js'),
  ads: () => import('./admin/ads.js'),
  alerts: () => import('./admin/alerts.js'),
  reports: () => import('./admin/reports.js'),
  support: () => import('./admin/support.js'),
  orders: () => import('./admin/orders.js'),
  recovery: () => import('./admin/recovery.js'),
  payments: () => import('./admin/payments.js'),
  'payment-checks': () => import('./admin/payment-checks.js'),
  services: () => import('./admin/services.js'),
  tags: () => import('./admin/tags.js'),
  website: () => import('./admin/website.js'),
  gateway: () => import('./admin/gateway.js'),
  smm: () => import('./admin/smm.js'),
  advertising: () => import('./admin/advertising.js'),
  security: () => import('./admin/security.js'),
  audit: () => import('./admin/audit.js'),
  system: () => import('./admin/system.js'),
};

const load = pages[document.body.dataset.page];
if (load) {
  load().then((m) => m.default()).catch((e) => {
    console.error(e);
    const root = document.querySelector('[id$="-root"]');
    if (root) root.innerHTML = '<div class="callout callout-error">This page failed to load. Please reload; if it keeps happening, check your connection.</div>';
  });
}
