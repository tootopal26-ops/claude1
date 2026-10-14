import { $, html, icon, on, esc } from '../core/dom.js';
import { fmtDateTime, timeAgo } from '../core/format.js';
import { get } from './net.js';
import { skeletonTable, emptyState, errorState, pager, onSearch, fail } from './ui.js';

const GROUPS = [['all', 'Everything'], ['security', 'Security'], ['login', 'Sign-ins'], ['orders', 'Orders'], ['catalog', 'Catalog'], ['settings', 'Settings']];
const state = { group: 'all', q: '', page: 1 };
let data = null;
let seq = 0;
const root = () => $('#audit-root');

/** One readable line from the metadata (never contains secrets — the server only logs field names). */
function summary(a) {
  const m = a.meta;
  if (!m) return '';
  const bits = [];
  if (m.code) bits.push(m.code);
  if (m.changed) bits.push('changed: ' + (Array.isArray(m.changed) ? m.changed.join(', ') : m.changed));
  if (m.from !== undefined && m.to !== undefined) bits.push(`${m.from} → ${m.to}`);
  if (m.cpc_from !== undefined) bits.push(`CPC ₹${m.cpc_from} → ₹${m.cpc_to}`);
  if (m.approved !== undefined) bits.push(`${m.approved} approved`);
  if (m.action) bits.push(m.action);
  if (m.reason) bits.push(m.reason);
  if (m.identifier) bits.push(m.identifier);
  if (m.ok !== undefined) bits.push(m.ok ? 'succeeded' : 'failed');
  return bits.join(' · ');
}

function render(d) {
  data = d;
  $('#audit-chips').innerHTML = GROUPS.map(([k, l]) => `<button type="button" class="fchip" data-group="${k}" aria-pressed="${state.group === k}">${esc(l)}</button>`).join('');
  root().innerHTML = String(d.rows.length ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Audit log"><table class="tbl tbl-resp">
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>Where from</th></tr></thead>
    <tbody>${d.rows.map((a) => html`<tr>
      <td data-label="When"><span class="cell-main"><b>${fmtDateTime(a.at)}</b><small>${timeAgo(a.at)}</small></span></td>
      <td data-label="Who">${a.admin || 'system'}</td>
      <td data-label="Action" class="span2"><span class="cell-main"><b>${a.label}</b><small class="mono">${a.action}${a.entity ? ' · ' + a.entity + (a.entity_id ? ' #' + a.entity_id : '') : ''}</small></span></td>
      <td data-label="Details" class="span2"><span class="text-[13px] text-ink-soft">${summary(a) || '—'}</span></td>
      <td data-label="From"><span class="cell-main"><b class="mono">${a.ip || '—'}</b><small>${a.device || ''}</small></span></td></tr>`)}</tbody></table></div>
    ${pager({ page: d.page, pages: d.pages, total: d.total, per: 30 }, 'entries')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'history', title: 'No entries found', text: state.q || state.group !== 'all' ? 'Try a different filter or search.' : 'Sensitive actions will be recorded here.' })}</div>`);
}

async function load() {
  const my = ++seq;
  if (!data) root().innerHTML = String(skeletonTable(8));
  root().setAttribute('aria-busy', 'true');
  try {
    const d = await get('audit', state);
    if (my !== seq) return;
    render(d);
  } catch (e) {
    if (my !== seq) return;
    if (!data) root().innerHTML = errorState(e.message).toString(); else fail('Could not refresh', e.message);
  } finally { if (my === seq) root().setAttribute('aria-busy', 'false'); }
}

export default function init() {
  on(document, 'click', '[data-group]', (e, b) => { state.group = b.dataset.group; state.page = 1; load(); });
  onSearch($('#a-search'), (v) => { state.q = v; state.page = 1; load(); });
  on(root(), 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); load(); });
  on(root(), 'click', '[data-retry]', () => load());
  load();
}
