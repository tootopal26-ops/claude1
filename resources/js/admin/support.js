// Admin → Support: the customer chat assistant's conversations, its on/off switch and retention, and — owner only — the AI
// provider it may optionally use. A reply that resolved a specific order is always `mode: "rules"`, never AI; see
// docs/AI-AND-SUPPORT.md.
import { $, $$, html, icon, on } from '../core/dom.js';
import { num, fmtDateTime, timeAgo } from '../core/format.js';
import { get, post, put } from './net.js';
import { skeletonTable, emptyState, errorState, pager, ok, fail, warn, whenVisible, withBusy, showFieldErrors, clearFieldErrors, autoClearErrors } from './ui.js';

const STATUS = { open: ['Open', 'chip-ok'], closed: ['Closed', 'chip-gray'] };
const INTENT_LABEL = {
  payment_status: 'Payment status', order_status: 'Order status', expired_session: 'Expired session', find_order_id: 'Find order id',
  refund_policy: 'Refund policy', delivery_time: 'Delivery time', payment_methods: 'Payment methods', general_contact: 'Contact', greeting: 'Greeting', unclear: 'Unclear', closed: 'Chat closed',
};

const state = { tab: 'conversations', status: '', page: 1, id: null };
let can = { configure: false };
let aiAllowed = false;   // whether THIS viewer may open the AI tab (owner only) — controls the tab, never the wording below
let aiForSupport = false; // whether AI-for-support is actually switched on — the real setting, independent of who is looking
let seq = 0;
let loaded = false;
const root = () => $('#sp-root');

function writeUrl() {
  const q = new URLSearchParams();
  if (state.tab !== 'conversations') q.set('tab', state.tab);
  if (state.tab === 'conversations') {
    if (state.id) q.set('id', String(state.id));
    else {
      if (state.status) q.set('status', state.status);
      if (state.page > 1) q.set('page', String(state.page));
    }
  }
  history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
}

// ── summary ───────────────────────────────────────────────────────────────────
const kpi = (tone, ico, label, value, sub) => html`<article class="kpi" data-tone="${tone}" aria-label="${label}"><div class="kpi-top"><span class="kpi-label">${icon(ico)}<span>${label}</span></span></div>
  <div class="kpi-row"><div class="kpi-value">${value}</div></div><div class="kpi-sub">${sub}</div></article>`;

function paintSummary(d) {
  const c = d.counts;
  $('#sp-summary').innerHTML = String(html`<div class="kpi-grid">
    ${kpi(d.enabled ? 'green' : 'gray', 'message-circle', 'Chat', d.enabled ? 'On' : 'Off', d.enabled ? `${num(c.open)} open conversation${c.open === 1 ? '' : 's'}` : 'switched off for customers')}
    ${kpi('gray', 'history', 'Closed', num(c.closed), 'ended or expired')}
    ${kpi(c.ai_messages ? 'info' : 'gray', 'bot', 'AI-polished replies', num(c.ai_messages), aiForSupport ? 'of all replies ever sent' : 'AI is off for support')}
    ${kpi('gray', 'clock', 'Today', num(c.messages_today), 'messages in the last 24 h')}
  </div>`);
  $('#sp-summary').setAttribute('aria-busy', 'false');
}

async function loadSummary() {
  try {
    const d = await get('support/overview');
    can = d.can;
    aiForSupport = !!d.ai_for_support;
    paintSummary(d);
    return d;
  } catch {
    $('#sp-summary').innerHTML = '';
    $('#sp-summary').setAttribute('aria-busy', 'false');
    return null;
  }
}

// ── tabs ──────────────────────────────────────────────────────────────────────
function paintTabs() {
  $$('#sp-tabs [data-tab]').forEach((b) => {
    const on_ = b.dataset.tab === state.tab;
    b.setAttribute('aria-selected', String(on_));
    b.tabIndex = on_ ? 0 : -1;
  });
  root().setAttribute('aria-labelledby', 'sp-tab-' + state.tab);
}

// ── conversations: list ───────────────────────────────────────────────────────
function convRow(c) {
  const [l, cls] = STATUS[c.status] || [c.status, 'chip-gray'];
  return html`<tr data-conv="${c.id}">
    <td data-label="Status">${icon(c.status === 'open' ? 'message-circle' : 'history', 'icon-sm')} <span class="chip ${cls}">${l}</span></td>
    <td data-label="Messages" class="num"><span class="cell-main"><b>${num(c.message_count)}</b>${c.ai_messages ? html`<small class="cell-wide">${num(c.ai_messages)} AI-polished</small>` : ''}</span></td>
    <td data-label="Started"><span class="cell-main"><b>${timeAgo(c.created_at)}</b><small class="cell-wide">${fmtDateTime(c.created_at)}</small></span></td>
    <td data-label="Last message">${c.last_message_at ? timeAgo(c.last_message_at) : '—'}</td>
    <td class="row-actions-cell"><span class="row-actions"><a class="btn btn-sm" href="?tab=conversations&id=${c.id}" data-open="${c.id}" aria-label="Open conversation ${c.id}">${icon('arrow-up-right', 'icon-sm')}<span>View</span></a></span></td></tr>`;
}

function renderList(d) {
  can = d.can || can;
  const bar = $('#sp-toolbar');
  bar.hidden = false;
  bar.innerHTML = String(html`<div class="chips" role="group" aria-label="Conversation status">${[['', 'All'], ['open', 'Open'], ['closed', 'Closed']].map(([k, l]) => html`<button type="button" class="fchip" data-status="${k}" aria-pressed="${String(k === state.status)}">${l}</button>`)}</div>`);
  root().innerHTML = String(d.rows.length
    ? html`<div class="tbl-wrap"><div class="tbl-scroll" tabindex="0" role="region" aria-label="Support conversations"><table class="tbl tbl-resp">
      <thead><tr><th>Status</th><th class="num">Messages</th><th>Started</th><th>Last message</th><th><span class="sr-only">Actions</span></th></tr></thead>
      <tbody>${d.rows.map(convRow)}</tbody></table></div>${pager({ page: d.page, pages: d.pages, total: d.total, per: d.per }, 'conversations')}</div>`
    : html`<div class="tbl-wrap">${emptyState({ ico: 'message-circle', title: state.status ? `No ${state.status} conversations` : 'No conversations yet', text: 'A conversation starts the moment a customer opens the chat on the storefront.' })}</div>`);
}

// ── conversations: one, in full ───────────────────────────────────────────────
function bubble(m) {
  const isAi = m.role === 'assistant';
  return html`<li class="flex flex-col gap-1 ${isAi ? 'items-start' : 'items-end'}">
    <div class="max-w-[85%] rounded-2xl px-4 py-2 ${isAi ? 'bg-slate-100' : 'bg-pink-50'}"><p class="text-sm whitespace-pre-wrap">${m.body}</p></div>
    <small class="text-xs text-muted px-1">${isAi ? 'Assistant' : 'Customer'}${isAi ? html` · <span class="chip ${m.mode === 'ai' ? 'chip-info' : 'chip-gray'}">${m.mode === 'ai' ? 'AI-polished' : 'rules'}</span>` : ''}${m.intent ? ' · ' + (INTENT_LABEL[m.intent] || m.intent) : ''}${m.order_id ? ' · about a verified order' : ''} · ${fmtDateTime(m.created_at)}</small></li>`;
}

function renderConversation(c) {
  $('#sp-toolbar').hidden = true;
  const [l, cls] = STATUS[c.status] || [c.status, 'chip-gray'];
  root().innerHTML = String(html`<div class="stack-lg">
    <div class="card"><div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-2"><span class="chip ${cls}">${l}</span><span class="text-sm text-muted">${num(c.message_count)} messages${c.ai_messages ? ` · ${num(c.ai_messages)} AI-polished` : ''} · started ${fmtDateTime(c.created_at)}</span></div>
      <button type="button" class="btn btn-sm" id="sp-back">${icon('arrow-left', 'icon-sm')}<span>All conversations</span></button></div></div>
    <section class="card" aria-label="Transcript"><ul class="grid gap-3" data-transcript>${c.messages.map(bubble)}</ul></section>
  </div>`);
}

// ── assistant settings ────────────────────────────────────────────────────────
function renderSettings(d) {
  const dis = can.configure ? '' : 'disabled';
  root().innerHTML = String(html`<form class="card stack-lg" id="sp-settings" novalidate aria-label="Assistant settings">
    <div class="callout callout-info" role="note">${icon('info')}<div class="min-w-0"><strong>This does not decide whether AI is used.</strong> AI for support is a separate, owner-only decision on the AI provider tab.${can.configure ? '' : ' Only an owner or manager can change these.'}</div></div>
    <label class="check"><input type="checkbox" id="sp-enabled" ${d.enabled ? 'checked' : ''} ${dis}><span>Offer the chat widget to customers</span></label>
    <div class="field max-w-xs"><label class="label" for="sp-retention">Keep a conversation for</label>
      <input class="input" id="sp-retention" type="number" inputmode="numeric" min="1" max="365" step="1" value="${d.retention_days}" ${dis}>
      <p class="field-hint">Days, 1–365. The cleanup job deletes a conversation (and its messages) once it is this old.</p></div>
    ${can.configure ? html`<div class="form-actions"><button type="submit" class="btn btn-primary" id="sp-save">${icon('check', 'icon-sm')}<span>Save</span></button></div>` : ''}
  </form>`);
  autoClearErrors($('#sp-settings'));
}

// ── AI provider (owner only — the tab itself is hidden for everyone else) ─────
const PROVIDER_LABEL = { none: 'Not configured', anthropic: 'Anthropic', openai_compatible: 'OpenAI-compatible' };

function renderAi(d) {
  const dis = '';
  root().innerHTML = String(html`<form class="card stack-lg" id="sp-ai" novalidate aria-label="AI provider">
    <div class="callout callout-info" role="note">${icon('info')}<div class="min-w-0"><strong>Off by default, and nothing here is required.</strong> Both the daily report and the support assistant are complete without AI. Turning it on is the one setting that lets any data leave this server for a third party — that is why only an owner can change it.</div></div>
    <label class="check"><input type="checkbox" id="ai-enabled" ${d.enabled ? 'checked' : ''} ${dis}><span>Enable the AI provider</span></label>
    <div class="field-row">
      <div class="field"><label class="label" for="ai-provider">Provider</label>
        <select class="select" id="ai-provider" ${dis}>${Object.entries(d.providers).map(([k, l]) => html`<option value="${k}" ${k === d.provider ? 'selected' : ''}>${l}</option>`)}</select></div>
      <div class="field"><label class="label" for="ai-model">Model</label><input class="input" id="ai-model" type="text" maxlength="80" autocomplete="off" value="${d.model}" placeholder="e.g. claude-sonnet-..." ${dis}></div>
    </div>
    <div class="field"><label class="label" for="ai-base">Base URL</label><input class="input" id="ai-base" type="text" maxlength="300" autocomplete="off" value="${d.base_url}" placeholder="https://api.example.com" ${dis}>
      <p class="field-hint">Anthropic has a default; an OpenAI-compatible endpoint has none and must be entered.</p></div>
    <div class="field max-w-md"><label class="label" for="ai-key">API key</label><input class="input" id="ai-key" type="password" maxlength="400" autocomplete="off" placeholder="${d.has_key ? '•••••••••••••• (saved — leave empty to keep it)' : 'Paste the provider’s API key'}" ${dis}>
      <p class="field-hint">${d.has_key ? 'A key is saved. It is never shown again; paste a new one to replace it.' : 'Encrypted at rest; never shown again once saved.'}</p>
      ${d.has_key ? html`<label class="check mt-1"><input type="checkbox" id="ai-clear-key">Clear the saved key</label>` : ''}</div>
    <div class="field-row">
      <label class="check"><input type="checkbox" id="ai-use-reports" ${d.use_for_reports ? 'checked' : ''} ${dis}><span>Use for the daily report's optional commentary</span></label>
      <label class="check"><input type="checkbox" id="ai-use-support" ${d.use_for_support ? 'checked' : ''} ${dis}><span>Use for the support assistant's order-free answers</span></label>
    </div>
    <div class="form-actions"><button type="button" class="btn" id="ai-test" ${d.available ? '' : 'disabled'} title="${d.available ? 'Send a one-word test prompt' : 'Save a provider, model and key first'}">${icon('send', 'icon-sm')}<span>Test connection</span></button>
      <button type="submit" class="btn btn-primary" id="ai-save">${icon('check', 'icon-sm')}<span>Save AI settings</span></button></div>
  </form>
  <section class="card mt-4" aria-labelledby="h-ai-usage"><div class="card-head"><h2 class="card-title" id="h-ai-usage">${icon('activity')}Usage, last 30 days</h2><span class="card-sub">never the prompt or the answer</span></div>
    ${d.usage?.by_feature?.length ? html`<div class="stat-grid">${d.usage.by_feature.map((f) => html`<div class="stat"><small>${f.feature}</small><strong>${num(f.calls)}</strong><span>${num(f.ok)} ok · ${num(f.error)} error · ${num(f.invalid)} invalid · ${num(f.limited)} limited</span></div>`)}</div>` : html`<p class="text-sm text-muted">No calls recorded yet.</p>`}</section>`);
  autoClearErrors($('#sp-ai'));
}

// ── loading ───────────────────────────────────────────────────────────────────
async function load({ silent = false } = {}) {
  const my = ++seq;
  paintTabs();
  if (!silent || !loaded) root().innerHTML = String(skeletonTable(5));
  root().setAttribute('aria-busy', 'true');
  try {
    if (state.tab === 'conversations') {
      if (state.id) {
        const d = await get(`support/conversations/${state.id}`);
        if (my !== seq) return;
        $('#sp-toolbar').hidden = true;
        renderConversation(d.conversation);
      } else {
        const d = await get('support/conversations', { status: state.status, page: state.page });
        if (my !== seq) return;
        renderList(d);
      }
    } else if (state.tab === 'settings') {
      $('#sp-toolbar').hidden = true;
      const d = await get('support/overview');
      if (my !== seq) return;
      can = d.can || can;
      renderSettings(d);
    } else {
      $('#sp-toolbar').hidden = true;
      const d = await get('ai/settings');
      if (my !== seq) return;
      const u = await get('ai/usage').catch(() => ({ by_feature: [] }));
      if (my !== seq) return;
      renderAi({ ...d, usage: u });
    }
    loaded = true;
    writeUrl();
  } catch (e) {
    if (my !== seq) return;
    if (state.tab === 'conversations' && state.id && e.status === 404) { state.id = null; warn('Not found', 'That conversation could not be found.'); return load(); }
    if (!loaded) root().innerHTML = errorState(e.message).toString(); else fail('Could not refresh', e.message);
  } finally {
    if (my === seq) root().setAttribute('aria-busy', 'false');
  }
}

const refresh = (opts) => Promise.all([load(opts), loadSummary()]);

// ── actions ───────────────────────────────────────────────────────────────────
async function saveSettings(form) {
  const btn = $('#sp-save');
  await withBusy(btn, async () => {
    clearFieldErrors(form);
    try {
      const d = await put('support/settings', { enabled: $('#sp-enabled').checked, retention_days: Number($('#sp-retention').value) }, { idempotency: true });
      ok('Settings saved');
      renderSettings({ ...d, can });
      loadSummary();
    } catch (e) {
      if (e.fields) showFieldErrors(form, e.fields); else fail('Could not save', e.message);
    }
  });
}

async function saveAi(form) {
  const btn = $('#ai-save');
  await withBusy(btn, async () => {
    clearFieldErrors(form);
    const body = {
      enabled: $('#ai-enabled').checked, provider: $('#ai-provider').value, model: $('#ai-model').value.trim(), base_url: $('#ai-base').value.trim(),
      use_for_reports: $('#ai-use-reports').checked, use_for_support: $('#ai-use-support').checked,
    };
    const key = $('#ai-key').value.trim();
    if (key) body.api_key = key; else if ($('#ai-clear-key')?.checked) body.clear_key = true;
    try {
      const d = await put('ai/settings', body, { idempotency: true });
      ok('AI settings saved');
      const u = await get('ai/usage').catch(() => ({ by_feature: [] }));
      renderAi({ ...d, usage: u });
    } catch (e) {
      if (e.fields) showFieldErrors(form, e.fields); else fail('Could not save', e.message);
    }
  });
}

async function testAi(btn) {
  await withBusy(btn, async () => {
    try {
      const r = (await post('ai/test')).result;
      (r.ok ? ok : warn)(r.ok ? 'Connected' : 'Could not confirm', r.message);
    } catch (e) {
      fail('Could not test the connection', e.message);
    }
  });
}

// ── boot ──────────────────────────────────────────────────────────────────────
export default function init() {
  const q = new URLSearchParams(location.search);
  state.tab = ['conversations', 'settings', 'ai'].includes(q.get('tab')) ? q.get('tab') : 'conversations';
  state.status = ['', 'open', 'closed'].includes(q.get('status') || '') ? q.get('status') || '' : '';
  state.page = Math.max(1, Number(q.get('page')) || 1);
  state.id = /^\d+$/.test(q.get('id') || '') ? Number(q.get('id')) : null;

  const tabs = $('#sp-tabs');
  on(tabs, 'click', '[data-tab]', (e, b) => { if (b.dataset.tab !== state.tab) { state.tab = b.dataset.tab; if (state.tab !== 'conversations') state.id = null; load(); } });
  on($('#sp-toolbar'), 'click', '[data-status]', (e, b) => { state.status = b.dataset.status; state.page = 1; load(); });
  const r = root();
  on(r, 'click', '[data-page]', (e, b) => { if (b.disabled) return; state.page = Number(b.dataset.page); load(); });
  on(r, 'click', '[data-retry]', () => refresh());
  on(r, 'click', '[data-open]', (e, a) => { e.preventDefault(); state.id = Number(a.dataset.open); load(); window.scrollTo({ top: 0 }); });
  on(r, 'click', '#sp-back', () => { state.id = null; load(); });
  on(r, 'click', '#ai-test', (e, b) => testAi(b));
  r.addEventListener('submit', (e) => {
    if (e.target.id === 'sp-settings') { e.preventDefault(); saveSettings(e.target); }
    if (e.target.id === 'sp-ai') { e.preventDefault(); saveAi(e.target); }
  });

  // the AI tab is owner-only; probe once and simply not show it for anyone the server would 403 anyway
  get('ai/settings').then(() => { aiAllowed = true; $('#sp-tab-ai').hidden = false; }).catch(() => {});

  refresh();
  whenVisible(() => { if (state.tab === 'conversations' && !state.id) refresh({ silent: true }); else loadSummary(); }, 45000);
}
