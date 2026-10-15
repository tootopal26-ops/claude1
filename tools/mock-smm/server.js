#!/usr/bin/env node
/**
 * DEV/QA ONLY — a stand-in for an SMM panel exposing the standard v2 API
 * (POST form: key, action = balance | services | add | status).
 *
 * API keys:   smm-key-ok (works)   smm-key-poor (add → "Not enough funds on balance")   anything else → "Invalid API key"
 * Services:   1001 (ok) … ; 9999 → "Incorrect service ID"; link containing "badlink" → "Incorrect link"
 * Control:    POST /__control {offline, slowMs, garbled, hang}   POST /__advance {order, status, remains?, charge?}   GET /__state
 *   offline  → connection reset before any answer      (client sees: unreachable / ambiguous)
 *   garbled  → 200 OK with non-JSON body               (client sees: ambiguous)
 *   hang     → never answers                           (client sees: timeout → ambiguous)
 *
 * Run:  node tools/mock-smm/server.js [port] [--auto-progress]
 */
const http = require('http');
const PORT = Number(process.argv.slice(2).find((a) => /^\d+$/.test(a)) || process.env.PORT || 4020);
const AUTO_PROGRESS = process.argv.includes('--auto-progress') || process.env.MOCK_SMM_AUTO_PROGRESS === '1';   // demo only: orders move on by themselves
const state = { mode: { offline: false, slowMs: 0, garbled: false, hang: false }, nextId: 70001, orders: {}, requests: [] };
const SERVICES = [
  { service: '1001', name: 'Instagram Followers [Non Drop]', category: 'Instagram', rate: '0.9000', min: '10', max: '100000', type: 'Default' },
  { service: '1002', name: 'Instagram Likes', category: 'Instagram', rate: '0.2500', min: '10', max: '50000', type: 'Default' },
  { service: '2001', name: 'YouTube Views', category: 'YouTube', rate: '1.2000', min: '100', max: '100000', type: 'Default' },
];

const send = (res, code, obj) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(typeof obj === 'string' ? obj : JSON.stringify(obj)); };
const body = (req) => new Promise((r) => { let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => r(b)); });

// demo mode: Pending → In progress (after 15 s, remains shrink) → Completed (after 60 s), like a real panel would
if (AUTO_PROGRESS) {
  setInterval(() => {
    for (const o of Object.values(state.orders)) {
      const age = (Date.now() - (o.created || Date.now())) / 1000;
      if (['Completed', 'Canceled', 'Partial'].includes(o.status)) continue;
      if (age >= 60) Object.assign(o, { status: 'Completed', remains: '0' });
      else if (age >= 15) Object.assign(o, { status: 'In progress', remains: String(Math.max(0, Math.round(o.quantity * (1 - (age - 15) / 45)))) });
    }
  }, 3000).unref();
}

http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);
  if (url.pathname === '/__state') return send(res, 200, state);
  if (url.pathname === '/__control' && req.method === 'POST') { Object.assign(state.mode, JSON.parse((await body(req)) || '{}')); return send(res, 200, { ok: true, mode: state.mode }); }
  if (url.pathname === '/__reset' && req.method === 'POST') { state.orders = {}; state.requests = []; state.nextId = 70001; return send(res, 200, { ok: true }); }
  if (url.pathname === '/__advance' && req.method === 'POST') {
    const b = JSON.parse((await body(req)) || '{}'); const o = state.orders[b.order];
    if (!o) return send(res, 404, { error: 'unknown' });
    Object.assign(o, { status: b.status || o.status }, b.remains !== undefined ? { remains: String(b.remains) } : {}, b.charge !== undefined ? { charge: String(b.charge) } : {});
    return send(res, 200, { ok: true, order: o });
  }
  if (url.pathname !== '/api/v2') return send(res, 404, { error: 'Not found' });

  if (state.mode.slowMs) await new Promise((r) => setTimeout(r, state.mode.slowMs));
  if (state.mode.hang) return;                      // never answer
  if (state.mode.offline) return req.socket.destroy();
  const p = new URLSearchParams(await body(req));
  state.requests.push({ action: p.get('action'), service: p.get('service'), link: p.get('link'), quantity: p.get('quantity'), order: p.get('order'), keySeen: !!p.get('key') });
  if (state.mode.garbled) return send(res, 200, '<html>Gateway time-out page</html>');

  const key = p.get('key');
  if (key !== 'smm-key-ok' && key !== 'smm-key-poor') return send(res, 200, { error: 'Invalid API key' });
  switch (p.get('action')) {
    case 'balance': return send(res, 200, { balance: key === 'smm-key-poor' ? '0.00' : '100.84292', currency: 'USD' });
    case 'services': return send(res, 200, SERVICES);
    case 'add': {
      if (key === 'smm-key-poor') return send(res, 200, { error: 'Not enough funds on balance' });
      if (p.get('service') === '9999' || !SERVICES.find((s) => s.service === p.get('service'))) return send(res, 200, { error: 'Incorrect service ID' });
      if ((p.get('link') || '').includes('badlink')) return send(res, 200, { error: 'Incorrect link' });
      const q = Number(p.get('quantity')); if (!Number.isInteger(q) || q < 1) return send(res, 200, { error: 'Incorrect quantity' });
      const id = String(state.nextId++);
      state.orders[id] = { id, service: p.get('service'), link: p.get('link'), quantity: q, status: 'Pending', charge: '0.27819', start_count: '100', remains: String(q), created: Date.now() };
      return send(res, 200, { order: Number(id) });
    }
    case 'status': {
      const o = state.orders[p.get('order')];
      if (!o) return send(res, 200, { error: 'Incorrect order ID' });
      return send(res, 200, { charge: o.charge, start_count: o.start_count, status: o.status, remains: o.remains, currency: 'USD' });
    }
    default: return send(res, 200, { error: 'Incorrect action' });
  }
}).listen(PORT, '127.0.0.1', () => console.log(`mock SMM panel listening on http://127.0.0.1:${PORT}/api/v2`));
