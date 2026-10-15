#!/usr/bin/env node
/**
 * DEV/QA ONLY — a stand-in for the Alpha UPI gateway that speaks exactly the
 * protocol of the vendor SDK (app/Vendor/AlphaUpi), so the REAL PHP adapter is
 * exercised end to end:
 *   POST /api/create-order        Bearer auth, {order_id, amount, ..., redirect_url}
 *   POST /api/check-order-status  {order_id}
 *   GET  /pay/:id                 fake hosted payment page (buttons: pay / fail)
 *   webhooks                      HMAC-SHA256(rawBody, secret) in X-Alpha-Signature
 *
 * Test hooks (never exist in the real gateway):
 *   POST /__control   {apiKey, webhookSecret, webhookUrl, mode:{offline,failCreate,quota,slowMs,noAmount}}
 *   GET  /__state
 *   POST /__pay/:id/success   {amount?, utr?, noWebhook?}   → marks SUCCESS (+ sends signed webhook)
 *   POST /__pay/:id/fail
 *   POST /__pay/:id/expire
 *   POST /__resend/:id        → re-sends the last webhook for that order (duplicate-delivery test)
 *   POST /__webhook           {body, signature?}  → relays an arbitrary body to the webhook URL
 *
 * Run:  node tools/mock-alpha-upi/server.js [port]
 */
const http = require('http');
const crypto = require('crypto');

const PORT = Number(process.argv[2] || process.env.PORT || 4010);
const state = {
  apiKey: 'test-api-key-123',
  webhookSecret: 'test-webhook-secret-456',
  webhookUrl: '',
  mode: { offline: false, failCreate: false, quota: false, slowMs: 0, noAmount: false },
  orders: {},      // id -> order
  lastWebhook: {}, // id -> {body, signature}
  log: [],
};

const json = (res, code, obj) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(obj)); };
const readBody = (req) => new Promise((resolve) => { let b = ''; req.on('data', (c) => (b += c)); req.on('end', () => resolve(b)); });
const sign = (raw, secret) => crypto.createHmac('sha256', secret).update(raw).digest('hex');
const rid = (n = 6) => crypto.randomBytes(n).toString('hex');

async function sendWebhook(order, body, signatureOverride) {
  if (!state.webhookUrl) return { sent: false, reason: 'no webhookUrl configured' };
  const raw = JSON.stringify(body);
  const signature = signatureOverride !== undefined ? signatureOverride : sign(raw, state.webhookSecret);
  state.lastWebhook[order.order_id] = { body: raw, signature };
  try {
    const r = await fetch(state.webhookUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Alpha-Signature': signature }, body: raw });
    const text = await r.text();
    state.log.push({ t: Date.now(), webhook: order.order_id, status: r.status, text: text.slice(0, 80) });
    return { sent: true, status: r.status, text };
  } catch (e) {
    state.log.push({ t: Date.now(), webhook: order.order_id, error: String(e) });
    return { sent: false, reason: String(e) };
  }
}

function webhookBody(order, status, extra = {}) {
  return {
    event_id: 'evt_' + rid(8), order_id: order.order_id, status, amount: state.mode.noAmount ? '' : order.amount,
    utr: status === 'SUCCESS' ? order.utr : '', provider: 'bharatpe', paid_at: new Date().toISOString().replace('T', ' ').slice(0, 19), ...extra,
  };
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);
  const path = url.pathname;
  if (state.mode.slowMs) await new Promise((r) => setTimeout(r, state.mode.slowMs));
  if (state.mode.offline && !path.startsWith('/__')) { req.socket.destroy(); return; }

  // ── control plane ─────────────────────────────────────────────────────────
  if (path === '/__state') return json(res, 200, state);
  if (path === '/__control' && req.method === 'POST') {
    const b = JSON.parse((await readBody(req)) || '{}');
    for (const k of ['apiKey', 'webhookSecret', 'webhookUrl']) if (b[k] !== undefined) state[k] = b[k];
    if (b.mode) Object.assign(state.mode, b.mode);
    if (b.reset) { state.orders = {}; state.lastWebhook = {}; state.log = []; }
    return json(res, 200, { ok: true });
  }
  let m;
  if ((m = path.match(/^\/__pay\/([\w-]+)\/(success|fail|expire)$/)) && req.method === 'POST') {
    const order = state.orders[m[1]] || Object.values(state.orders).find((o) => o.order_id === m[1]);
    if (!order) return json(res, 404, { error: 'no such order' });
    const b = JSON.parse((await readBody(req)) || '{}');
    if (m[2] === 'success') {
      order.status = 'SUCCESS'; order.utr = b.utr || 'UTR' + rid(5).toUpperCase(); order.paid_at = new Date().toISOString().replace('T', ' ').slice(0, 19);
      const body = webhookBody(order, 'SUCCESS', b.amount !== undefined ? { amount: String(b.amount) } : {});
      if (b.noWebhook) return json(res, 200, { ok: true, webhook: 'skipped' });
      return json(res, 200, { ok: true, webhook: await sendWebhook(order, body) });
    }
    order.status = m[2] === 'fail' ? 'FAILED' : 'EXPIRED';
    return json(res, 200, { ok: true, webhook: b.noWebhook ? 'skipped' : await sendWebhook(order, webhookBody(order, order.status)) });
  }
  if ((m = path.match(/^\/__resend\/([\w-]+)$/)) && req.method === 'POST') {
    const last = state.lastWebhook[m[1]];
    if (!last) return json(res, 404, { error: 'nothing to resend' });
    const order = state.orders[m[1]];
    const r = await fetch(state.webhookUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Alpha-Signature': last.signature }, body: last.body });
    return json(res, 200, { ok: true, status: r.status, text: await r.text() });
  }
  if (path === '/__webhook' && req.method === 'POST') {
    const b = JSON.parse((await readBody(req)) || '{}');
    const raw = typeof b.body === 'string' ? b.body : JSON.stringify(b.body);
    const signature = b.signature !== undefined ? b.signature : sign(raw, b.secret || state.webhookSecret);
    const r = await fetch(state.webhookUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Alpha-Signature': signature }, body: raw });
    return json(res, 200, { status: r.status, text: await r.text() });
  }

  // ── the "real" API ────────────────────────────────────────────────────────
  if (path === '/api/create-order' || path === '/api/check-order-status') {
    if (req.method !== 'POST') return json(res, 405, { status: false, message: 'Method not allowed' });
    const auth = req.headers['authorization'] || '';
    if (auth !== `Bearer ${state.apiKey}`) return json(res, 401, { status: false, message: 'Invalid API key' });
    let b; try { b = JSON.parse((await readBody(req)) || '{}'); } catch { return json(res, 400, { status: false, message: 'Invalid JSON' }); }

    if (path === '/api/create-order') {
      if (state.mode.quota) return json(res, 200, { status: false, code: 'SUBSCRIPTION_QUOTA_EXHAUSTED', message: 'Your plan quota is used up.', usage: { used: 100, limit: 100, remaining: 0 } });
      if (state.mode.failCreate) return json(res, 200, { status: false, message: 'No connected provider.' });
      if (!b.order_id || !/^\d+(\.\d{1,2})?$/.test(String(b.amount || ''))) return json(res, 200, { status: false, message: 'Invalid amount or order_id' });
      if (state.orders[b.order_id]) return json(res, 200, { status: false, message: 'Duplicate order_id' });
      const order = { order_id: b.order_id, amount: String(b.amount), redirect_url: b.redirect_url || '', status: 'PENDING', utr: '', remark1: b.remark1 || '', created: Date.now() };
      state.orders[b.order_id] = order;
      return json(res, 200, { status: true, message: 'Order created', result: { orderId: b.order_id, payment_url: `http://127.0.0.1:${PORT}/pay/${b.order_id}` } });
    }
    const o = state.orders[b.order_id];
    if (!o) return json(res, 200, { status: false, message: 'Order not found', result: {} });
    return json(res, 200, { status: true, message: 'ok', result: { status: o.status, amount: state.mode.noAmount ? undefined : o.amount, utr: o.status === 'SUCCESS' ? o.utr : undefined, provider: 'bharatpe', paid_at: o.paid_at } });
  }

  if (path === '/favicon.ico') { res.writeHead(204); return res.end(); }   // keeps the browser console free of a pointless 404 on the fake payment page

  // ── fake hosted payment page ──────────────────────────────────────────────
  if ((m = path.match(/^\/pay\/([\w-]+)$/))) {
    const o = state.orders[m[1]];
    if (!o) { res.writeHead(404); return res.end('unknown order'); }
    res.writeHead(200, { 'Content-Type': 'text/html' });
    return res.end(`<!doctype html><meta charset=utf-8><title>Mock Alpha UPI</title><body style="font-family:sans-serif;padding:2rem">
      <h1>Mock Alpha UPI payment</h1><p>Order <b>${o.order_id}</b> — ₹${o.amount} — status <b>${o.status}</b></p>
      <form method=post action="/__ui/${o.order_id}/success"><button>Simulate payment success</button></form>
      <form method=post action="/__ui/${o.order_id}/fail" style="margin-top:1rem"><button>Simulate failure</button></form></body>`);
  }
  if ((m = path.match(/^\/__ui\/([\w-]+)\/(success|fail)$/)) && req.method === 'POST') {
    const o = state.orders[m[1]]; if (!o) { res.writeHead(404); return res.end(); }
    if (m[2] === 'success') { o.status = 'SUCCESS'; o.utr = 'UTR' + rid(5).toUpperCase(); await sendWebhook(o, webhookBody(o, 'SUCCESS')); }
    else { o.status = 'FAILED'; await sendWebhook(o, webhookBody(o, 'FAILED')); }
    res.writeHead(302, { Location: o.redirect_url || '/' }); return res.end();
  }

  json(res, 404, { status: false, message: 'Not found' });
});

server.listen(PORT, '127.0.0.1', () => console.log(`mock Alpha UPI listening on http://127.0.0.1:${PORT}`));
