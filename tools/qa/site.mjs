// Helpers shared by the storefront QA suites (site-flows, site-layout, site-failures, security-audit, a11y, pay-real-expiry).
// DEV/QA ONLY: talks to the local MariaDB as root and to the two mock servers (mock Alpha UPI gateway :4010, mock SMM panel :4020).
import { execFileSync } from 'node:child_process';
import { PNG } from 'pngjs';
import jsQR from 'jsqr';
import { launch } from './browser.mjs';
import { newPage as rawNewPage, layoutReport, BASE } from './admin.mjs';

export { launch, layoutReport, BASE };
// Headless Chromium announces itself as "HeadlessChrome", which the visitor tracker (rightly) treats as a bot and never gives a visitor cookie.
// The storefront suites therefore present a normal browser user-agent so tracking, dedupe-by-visitor and CPC accounting behave as for real people.
export const DESKTOP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
export const MOBILE_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';
export const newPage = (browser, viewport, opts = {}) => rawNewPage(browser, viewport, { ua: opts.mobile ? MOBILE_UA : DESKTOP_UA, ...opts });
export const ROOT = new URL('../..', import.meta.url).pathname.replace(/\/$/, '');
export const ALPHA = process.env.ALPHA || 'http://127.0.0.1:4010';
export const SMM = process.env.SMM || 'http://127.0.0.1:4020';
export const DB = process.env.DB_NAME || 'smb_dev';

/** Every viewport the brief asks for (desktop, mobile, tablet). */
export const VIEWPORTS = [
  { name: 'desktop-1366x768', width: 1366, height: 768 },
  { name: 'desktop-1440x900', width: 1440, height: 900 },
  { name: 'desktop-1920x1080', width: 1920, height: 1080 },
  { name: 'mobile-360x800', width: 360, height: 800, mobile: true },
  { name: 'mobile-390x844', width: 390, height: 844, mobile: true },
  { name: 'mobile-412x915', width: 412, height: 915, mobile: true },
  { name: 'tablet-768x1024', width: 768, height: 1024, mobile: true },
  { name: 'tablet-820x1180', width: 820, height: 1180, mobile: true },
];

export const sql = (q) => execFileSync('mariadb', ['-uroot', '-N', '-B', DB, '-e', q], { encoding: 'utf8' }).trim();
export const rows = (q) => sql(q).split('\n').filter(Boolean).map((l) => l.split('\t'));
export const php = (script, ...args) => execFileSync('php', [script, ...args], { cwd: ROOT, encoding: 'utf8' });
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const post = async (url, body) => {
  const r = await fetch(url, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body ?? {}) });
  return r.json();
};
export const alpha = (path, body) => post(ALPHA + path, body);
export const smm = (path, body) => post(SMM + path, body);

export const gatewayId = () => Number(sql('SELECT id FROM payment_gateways ORDER BY id LIMIT 1'));
export const serviceId = (code) => Number(sql(`SELECT id FROM services WHERE code = '${code}'`));
export const resetLimits = () => php('tools/qa/reset-limits.php');

/** Map the seeded AUTO services onto service ids that the mock SMM panel actually offers (the demo data uses random ids). */
export function useMockProviderIds() {
  sql("UPDATE services SET smm_service_id = '1001' WHERE code = 'ig-followers-50'");
  sql("UPDATE services SET smm_service_id = '1002' WHERE code IN ('ig-likes-100', 'ig-likes-50k', 'ig-views-1k')");
  sql("UPDATE services SET smm_service_id = '2001' WHERE code IN ('yt-subs-50', 'yt-likes-100', 'yt-views-10', 'yt-views-10k')");
}

/** Put both mocks into their healthy state and point the gateway webhook at the dev server. */
export async function mocksReset() {
  await alpha('/__control', {
    reset: true, style: 'bearer', apiKey: 'test-api-key-123', apiSecret: 'test-api-secret-789', webhookSecret: 'test-webhook-secret-456',
    webhookUrl: `${BASE}/webhooks/payments/${gatewayId()}`,
    mode: { offline: false, failCreate: false, quota: false, slowMs: 0, noAmount: false, currency: 'INR', pageUpi: 'link' },
  });
  await smm('/__control', { offline: false, slowMs: 0, garbled: false, hang: false, currency: 'INR' });
  await smm('/__reset');
}

export const payment = (ref) => {
  const r = rows(`SELECT p.status, p.amount_paise, p.gateway_order_id, p.expires_at, p.paid_at, p.late, o.public_id, o.status, o.mode, o.amount_paise, o.target_url, o.access_token, o.id, o.provider_order_id, o.last_error, o.failure_kind, o.service_code, o.quantity
    FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.ref = '${ref}'`)[0];
  if (!r) return null;
  const [pStatus, pAmount, gwOrder, expires, paidAt, late, publicId, oStatus, mode, oAmount, targetUrl, token, id, providerOrder, lastError, failureKind, code, quantity] = r;
  return { pStatus, pAmount: Number(pAmount), gwOrder, expires, paidAt, late: late === '1', publicId, oStatus, mode, oAmount: Number(oAmount), targetUrl, token, id: Number(id), providerOrder, lastError, failureKind, code, quantity: Number(quantity) };
};

/**
 * Make the mock gateway's checkout page carry NO UPI request (`none`), a request for another amount (`wrong_amount`), …, so checkout has no
 * QR to show and continues at the gateway's own checkout. `link` (the default) is a page that carries a valid request for the order.
 */
export const gatewayPage = (pageUpi) => alpha('/__control', { mode: { pageUpi } });

/**
 * Like placeOrder, for a gateway whose checkout page gives no QR: checkout sends the customer straight to the gateway's own page
 * (never to a page of instructions). Returns { ref, gatewayUrl, card }; call gatewayPage('none') first.
 */
export async function placeOrderAtGateway(page, code, target) {
  const card = await openCard(page, code);
  await card.locator('input[name=target]').fill(target);
  await page.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card.elementHandle());
  await Promise.all([page.waitForURL((u) => u.href.startsWith(ALPHA + '/pay/'), { timeout: 30000 }), card.locator('[data-action=proceed]').click()]);
  const gatewayUrl = page.url();
  return { ref: sql(`SELECT ref FROM payments WHERE payment_url = '${gatewayUrl}'`), gatewayUrl, card };
}

/**
 * Give a payment a real UPI payment request of the gateway's own shape (what the gateway's checkout page carries) so a test does not
 * depend on the mock page. The amount is the payment's own. Returns the request.
 */
export function seedUpiQr(ref, { vpa = 'merchant.demo@okbizaxis', name = 'Social Media Booster' } = {}) {
  const o = payment(ref);
  const uri = `upi://pay?pa=${vpa}&pn=${encodeURIComponent(name)}&am=${(o.pAmount / 100).toFixed(2)}&cu=INR&tn=${encodeURIComponent('Order ' + o.publicId)}&tr=${o.gwOrder}`;
  sql(`UPDATE payments SET qr_payload = '${uri}' WHERE ref = '${ref}'`);
  return uri;
}

export const refFromUrl = (url) => (String(url).match(/\/pay\/([a-f0-9]{32})/) || [])[1];

/** Poll until fn() is truthy (returns it) or the time is up (returns the last value). For work that continues after an HTTP answer. */
export async function waitUntil(fn, { timeout = 20000, interval = 100 } = {}) {
  const end = Date.now() + timeout;
  for (;;) {
    const v = await fn();
    if (v || Date.now() >= end) return v;
    await sleep(interval);
  }
}

/**
 * The webhook is answered BEFORE an AUTO order is sent to the provider (that happens after the response). Wait until that
 * attempt has finished: the order is no longer being processed and at least one attempt was made. Returns the order row.
 */
export async function waitSubmission(ref, timeout = 20000) {
  await waitUntil(() => {
    const o = payment(ref);
    return o && o.oStatus !== 'PROCESSING' && Number(sql(`SELECT provider_attempts FROM orders WHERE id = ${o.id}`)) >= 1;
  }, { timeout });
  return payment(ref);
}

/** Pay an order through the mock gateway (it sends a correctly signed webhook to our server). */
export const payOk = (ref, body = {}) => alpha(`/__pay/${payment(ref).gwOrder}/success`, body);

/** Decode a PNG buffer as a QR code and return its text (null when no QR is found). */
export function decodeQr(buf) {
  const png = PNG.sync.read(buf);
  // add a white quiet zone: some decoders need it, and our PNGs may be tightly cropped
  const pad = 24, w = png.width + pad * 2, h = png.height + pad * 2;
  const data = new Uint8ClampedArray(w * h * 4).fill(255);
  for (let y = 0; y < png.height; y++) for (let x = 0; x < png.width; x++) {
    const s = (y * png.width + x) * 4, d = ((y + pad) * w + x + pad) * 4;
    data[d] = png.data[s]; data[d + 1] = png.data[s + 1]; data[d + 2] = png.data[s + 2]; data[d + 3] = 255;
  }
  const res = jsQR(data, w, h);
  return res ? res.data : null;
}

/** Open the storefront, pick a platform and open a card. */
export async function openCard(page, code, platform = code.startsWith('yt') ? 'youtube' : 'instagram') {
  if (!page.url().startsWith(BASE + '/') || /\/(pay|order)\//.test(page.url())) await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  await page.click(`[data-platform-tab="${platform}"]`);
  const card = page.locator(`[data-service][data-id="${serviceId(code)}"]`);
  await card.scrollIntoViewIfNeeded();
  if ((await card.getAttribute('data-open')) !== 'true') await card.locator('[data-action=buy]').click();
  await page.waitForFunction((el) => el.querySelector('.expand').dataset.open === 'true' && !el.querySelector('.expand').inert, await card.elementHandle());
  return card;
}

/** Full happy path through the UI: pick a service, type a target, PROCEED TO PAYMENT. Returns { ref, card }. */
export async function placeOrder(page, code, target) {
  const card = await openCard(page, code);
  await card.locator('input[name=target]').fill(target);
  await page.waitForFunction((el) => !el.querySelector('[data-action=proceed]').disabled, await card.elementHandle());
  await Promise.all([page.waitForURL(/\/pay\/[a-f0-9]{32}/, { timeout: 30000 }), card.locator('[data-action=proceed]').click()]);
  return { ref: refFromUrl(page.url()), card };
}

export const hexToRgb = (css) => (css.match(/\d+(\.\d+)?/g) || []).slice(0, 3).map(Number);
export const luminance = ([r, g, b]) => {
  const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};
export const contrast = (a, b) => { const [x, y] = [luminance(a), luminance(b)].sort((m, n) => n - m); return (x + 0.05) / (y + 0.05); };
