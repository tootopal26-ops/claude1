import { $, $$, readJson, esc, html, setHtml, icon } from './core/dom.js';
import { api } from './core/api.js';
import { toast } from './core/toast.js';

const data = readJson('pay-data');
const root = $('#pay-root');
if (!root) throw new Error('payment page markup missing');

let state = data.state;                         // pending | paid | expired | failed | review
let offset = data.serverNow - Date.now();        // server clock − browser clock (the 5:00 is the SERVER's 5:00)
let expiresAt = data.expiresAt;
let pollTimer = null, tickTimer = null, failures = 0, stopped = false;
const ttlMs = (data.ttl || 300) * 1000;
const now = () => Date.now() + offset;
const terminal = () => state === 'paid' || state === 'expired' || state === 'failed';

// ───────────────────────── views ─────────────────────────
function showView(name) {
  $$('[data-view]', root).forEach((v) => { v.hidden = v.dataset.view !== name; });
  root.dataset.state = name;
  const heading = $(`[data-view="${name}"] h1`, root);
  if (heading) { heading.tabIndex = -1; heading.focus({ preventScroll: true }); }
}

function killQr() {
  $('#qr-frame')?.setAttribute('data-dead', 'true');
  for (const id of ['btn-download', 'btn-open']) {
    const a = $('#' + id);
    if (!a) continue;
    a.setAttribute('aria-disabled', 'true');
    a.removeAttribute('href');
    a.removeAttribute('download');
    a.tabIndex = -1;
  }
  const img = $('#qr-img');
  if (img) img.removeAttribute('src');          // the QR must not stay usable after expiry
}

function setState(next, extra = {}) {
  if (next === state || state === 'paid') return;
  const was = state;
  state = next;
  if (next === 'paid') {
    stopTimers();
    if (was === 'expired') {
      const note = $('#paid-note');
      if (note) setHtml(note, html`We received your payment <b class="text-ink">after</b> the payment session had ended, and it has been accepted. Order <b class="text-ink">${data.orderId}</b> is being processed.`);
    }
    showView('paid');
    toast('success', 'Payment successful', 'Your order has been received.');
  } else if (next === 'expired') {
    killQr();
    showView('expired');
    stopTimers();
    schedule(true);        // keep a slow watch: a late payment can still arrive
  } else if (next === 'failed') {
    stopTimers();
    showView('failed');
    toast('error', 'Payment failed', 'We could not confirm your payment.');
  } else if (next === 'review') {
    showView('review');
  } else if (next === 'pending') {
    showView('pending');
  }
}

// ───────────────────────── countdown ─────────────────────────
const pad = (n) => String(n).padStart(2, '0');
function tick() {
  if (state !== 'pending') return;
  const remaining = Math.max(0, expiresAt - now());
  const secs = Math.ceil(remaining / 1000);
  const label = `${pad(Math.floor(secs / 60))}:${pad(secs % 60)}`;
  const val = $('#timer-value');
  if (val && val.textContent !== label) val.textContent = label;
  const timer = $('#timer');
  const level = secs <= 30 ? 'critical' : secs <= 60 ? 'warn' : 'ok';
  if (timer && timer.dataset.level !== level) timer.dataset.level = level;
  const bar = $('#timer-bar');
  if (bar) bar.style.transform = `scaleX(${Math.min(1, remaining / ttlMs).toFixed(4)})`;
  if (remaining <= 0) onExpire();
}

let expiring = false;
async function onExpire() {
  if (expiring || state !== 'pending') return;
  expiring = true;
  // Never claim success locally. Show "expired" and let the server confirm (or reveal a late payment).
  setState('expired');
  await poll(true);
}

function stopTimers() { clearInterval(tickTimer); tickTimer = null; }

// ───────────────────────── server polling ─────────────────────────
function applyServer(r) {
  if (typeof r.server_now === 'number') offset = r.server_now - Date.now();
  if (typeof r.expires_at === 'number') expiresAt = r.expires_at;
  if (r.state && r.state !== state) {
    if (state === 'pending' && r.state === 'expired' && (expiresAt - now()) > 1500) return;   // server disagrees by clock skew only
    setState(r.state, r);
  }
}

async function poll(once = false) {
  if (stopped) return;
  clearTimeout(pollTimer);
  try {
    const r = await api(data.statusUrl, { timeout: 12000 });
    failures = 0;
    $('#pay-offline')?.setAttribute('hidden', '');
    applyServer(r);
  } catch (e) {
    failures++;
    if (failures >= 2) $('#pay-offline')?.removeAttribute('hidden');
  }
  if (!once) schedule();
}

function schedule(slow = false) {
  clearTimeout(pollTimer);
  if (stopped || state === 'paid' || state === 'failed') return;
  let delay = document.hidden ? 8000 : 3000;
  if (slow || state === 'expired' || state === 'review') delay = 10000;
  if (failures) delay = Math.min(2500 * 2 ** failures, 20000);
  pollTimer = setTimeout(() => poll(), delay);
}

document.addEventListener('visibilitychange', () => {
  if (!document.hidden && !stopped) { tick(); poll(); }
});

// ───────────────────────── boot ─────────────────────────
if (state === 'pending') {
  tick();
  tickTimer = setInterval(tick, 250);
  if (data.returned) {
    // The customer just came back from the gateway: verify before showing anything.
    showView('verifying');
    poll(true).then(() => { if (state === 'pending') showView('pending'); schedule(); });
  } else {
    schedule();
  }
} else if (state === 'expired' || state === 'review') {
  killQr();
  schedule(true);
}

// Download QR: the browser handles the download itself; tell the user it worked.
$('#btn-download')?.addEventListener('click', () => { if (state === 'pending') toast('info', 'QR code downloading', 'Check your downloads folder.', { duration: 2500 }); });
window.addEventListener('pagehide', () => { stopped = true; });
