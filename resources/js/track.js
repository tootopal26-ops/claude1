// Privacy-conscious visitor analytics. Sends: path, referrer, utm_* (source, medium, campaign, content, term), whether this is the first
// page of the browser tab, and how many different service cards were opened. No IP, no fingerprinting — see App\Services\Tracking.
import { base, readJson } from './core/dom.js';

(function () {
  if (!('fetch' in window) || !navigator.cookieEnabled) return;
  const api = (p) => base() + '/api/track/' + p;
  const q = new URLSearchParams(location.search);
  const post = (path, body) => fetch(api(path), {
    method: 'POST', credentials: 'same-origin', keepalive: true,
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  }).then((r) => (r.ok ? r.json().catch(() => ({})) : null)).catch(() => null);

  let platform = null;
  window.addEventListener('smb:platform', (e) => { platform = e.detail; });

  // "engaged" = stayed the qualifying time AND opened a service card: count the different cards opened (never which) and tell the server at once
  const opened = new Set();
  window.addEventListener('smb:service', (e) => {
    const id = String(e.detail || '');
    if (!id || opened.has(id) || opened.size >= 50) return;
    opened.add(id);
    sendPing();
  });

  // visible-time accounting (hidden tabs do not count)
  let visibleMs = 0, last = performance.now(), qualified = false, qualifyAfter = 3000, timer = null;
  const tick = () => {
    const now = performance.now();
    if (!document.hidden) visibleMs += now - last;
    last = now;
  };

  async function start() {
    let first = true;
    try { first = !sessionStorage.getItem('smb_hit'); } catch { /* storage blocked */ }
    const res = await post('hit', {
      first, path: location.pathname, ref: document.referrer || '',
      utm_source: q.get('utm_source') || '', utm_medium: q.get('utm_medium') || '', utm_campaign: q.get('utm_campaign') || '',
      utm_content: q.get('utm_content') || '', utm_term: q.get('utm_term') || '',
    });
    if (!res || !res.tracked) return;
    try { sessionStorage.setItem('smb_hit', '1'); } catch { /* ignore */ }
    if (res.qualified) { qualified = true; } else { qualifyAfter = res.qualify_ms || 3000; }

    timer = setInterval(async () => {
      tick();
      if (qualified) { clearInterval(timer); return; }
      if (visibleMs >= qualifyAfter) {
        clearInterval(timer);
        const r = await post('qualify', {});
        if (r && r.qualified) { qualified = true; }
        else if (r && r.retry_ms) { setTimeout(() => post('qualify', {}), r.retry_ms + 300); }   // clock skew safety: ask once more
      }
    }, 250);
  }

  function sendPing() {
    tick();
    const body = { active_s: Math.round(visibleMs / 1000), platform, services: opened.size };
    const blob = new Blob([JSON.stringify(body)], { type: 'application/json' });
    if (!(navigator.sendBeacon && navigator.sendBeacon(api('ping'), blob))) post('ping', body);
  }
  document.addEventListener('visibilitychange', () => { if (document.hidden) sendPing(); else last = performance.now(); });
  window.addEventListener('pagehide', sendPing);

  if (document.readyState === 'complete') start(); else window.addEventListener('load', start, { once: true });
})();
