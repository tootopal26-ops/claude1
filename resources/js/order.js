import { $, readJson, html, setHtml, icon, esc } from './core/dom.js';
import { api } from './core/api.js';

const data = readJson('order-data');
const root = $('#order-root');
let timer = null, failures = 0, polls = 0;

const FINAL = ['COMPLETED', 'MANUAL_APPROVED', 'CANCELLED', 'REFUNDED', 'EXPIRED'];
const fmt = (iso) => { const d = new Date(iso); return isNaN(d) ? '' : d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC' }) + ' UTC'; };

function render(o) {
  $('#o-heading').textContent = o.status_label;
  const steps = o.steps || [];
  setHtml($('#o-steps'), html`${steps.map((s, i) => html`<div class="step" data-state="${i === steps.length - 1 && !o.done ? 'now' : 'done'}">
    <span class="step-dot">${icon('check')}</span><div><b>${s.label}</b><small><time datetime="${s.at}">${fmt(s.at)}</time></small></div></div>`)}`);
}

async function poll() {
  clearTimeout(timer);
  try {
    const r = await api(data.statusUrl, { timeout: 12000 });
    failures = 0;
    render(r.order);
    if (FINAL.includes(r.order.status)) return;
  } catch { failures++; }
  polls++;
  const delay = failures ? Math.min(5000 * 2 ** failures, 60000) : (polls > 30 ? 30000 : 10000);
  if (!document.hidden || failures === 0) timer = setTimeout(poll, document.hidden ? 30000 : delay);
}

if (root && !FINAL.includes(data.status)) poll();
document.addEventListener('visibilitychange', () => { if (!document.hidden && root && !FINAL.includes(data.status)) poll(); });
