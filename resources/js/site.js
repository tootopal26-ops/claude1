import { $, $$, on, readJson, debounce, esc, html, setHtml, icon, raw, store, uuid, reduceMotion, url } from './core/dom.js';
import { api } from './core/api.js';
import { toast } from './core/toast.js';
import { openDialog, closeDialog } from './core/dialog.js';
import { validateTarget } from './site/targets.js';
import './core/dialog.js';

const cfg = readJson('smb-config');
const ORDERS_KEY = 'smb_orders';
const PLATFORM_KEY = 'smb_platform';

// ═════════════════════════ platform tabs ═════════════════════════
function initTabs() {
  const tabs = $$('[data-platform-tab]');
  if (!tabs.length) return;
  const panels = new Map($$('[data-panel]').map((p) => [p.dataset.panel, p]));

  function select(slug, { focus = false, animate = true } = {}) {
    if (!panels.has(slug)) return;
    tabs.forEach((t) => {
      const on = t.dataset.platformTab === slug;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      if (on && focus) t.focus();
    });
    panels.forEach((panel, key) => {
      const show = key === slug;
      if (show && panel.hidden) {
        closeAllCards();
        panel.hidden = false;
        if (animate && !reduceMotion()) {
          $$('.sec-head, .svc', panel).forEach((el, i) => {
            el.classList.remove('animate-fade-up');
            void el.offsetWidth;                       // restart the animation
            el.style.animationDelay = Math.min(i * 55, 400) + 'ms';
            el.classList.add('animate-fade-up');
          });
        }
      } else if (!show) {
        panel.hidden = true;
      }
    });
    store.set(PLATFORM_KEY, slug);
    try { history.replaceState(null, '', '#' + slug); } catch { /* ignore */ }
    window.dispatchEvent(new CustomEvent('smb:platform', { detail: slug }));
  }

  on(document, 'click', '[data-platform-tab]', (e, tab) => select(tab.dataset.platformTab));
  on(document, 'keydown', '[data-platform-tab]', (e, tab) => {
    const i = tabs.indexOf(tab);
    let n = null;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = (i + 1) % tabs.length;
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = (i - 1 + tabs.length) % tabs.length;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = tabs.length - 1;
    if (n !== null) { e.preventDefault(); select(tabs[n].dataset.platformTab, { focus: true }); }
  });
  window.addEventListener('hashchange', () => select(location.hash.slice(1), { animate: true }));

  const fromHash = location.hash.slice(1);
  const first = tabs[0].dataset.platformTab;
  const start = panels.has(fromHash) ? fromHash : (panels.has(store.get(PLATFORM_KEY)) ? store.get(PLATFORM_KEY) : first);
  select(start, { animate: start !== first });
}

// ═════════════════════════ service cards ═════════════════════════
function closeAllCards(except = null) {
  $$('[data-service][data-open="true"]').forEach((c) => { if (c !== except) closeCard(c); });
}

function openCard(card) {
  closeAllCards(card);
  const box = $('.expand', card);
  card.dataset.open = 'true';
  box.dataset.open = 'true';
  box.inert = false;
  $('[data-action=buy]', card).setAttribute('aria-expanded', 'true');
  const input = $('input[name=target]', card);
  setTimeout(() => {
    input.focus({ preventScroll: true });
    card.scrollIntoView({ block: 'nearest', behavior: reduceMotion() ? 'auto' : 'smooth' });
  }, reduceMotion() ? 0 : 380);
  window.dispatchEvent(new CustomEvent('smb:service', { detail: card.dataset.id || '' }));   // the visitor looked at a service (engagement)
}

function closeCard(card) {
  const box = $('.expand', card);
  card.dataset.open = 'false';
  box.dataset.open = 'false';
  box.inert = true;
  $('[data-action=buy]', card).setAttribute('aria-expanded', 'false');
  if (card.contains(document.activeElement) && document.activeElement !== $('[data-action=buy]', card)) $('[data-action=buy]', card).focus({ preventScroll: true });
}

function setMsg(card, state, content) {
  const msg = $('.field-msg', card);
  msg.dataset.state = state;
  setHtml(msg, content);
}

/** Validate the typed value, update the inline message and the proceed button. */
function validate(card, { showErrors = true } = {}) {
  const input = $('input[name=target]', card);
  const proceed = $('[data-action=proceed]', card);
  const value = input.value;
  const kind = card.dataset.kind;
  const platform = card.dataset.platform;
  const linkOnly = card.dataset.linkOnly === 'true';   // Instagram Followers / YouTube Subscribers: the account LINK, not a username

  if (value.trim() === '') {
    input.removeAttribute('aria-invalid');
    delete input.dataset.valid;
    proceed.disabled = true;
    setMsg(card, 'idle', html`${card._hint}`);
    return { ok: false, empty: true };
  }
  const r = validateTarget(platform, kind, value, linkOnly);
  if (r.ok) {
    input.removeAttribute('aria-invalid');
    input.dataset.valid = 'true';
    proceed.disabled = card._busy === true;
    setMsg(card, 'valid', html`${icon('circle-check')}<span><strong>${linkOnly ? 'Valid link' : 'Valid input'}</strong> · ${r.display}</span>`);
  } else {
    delete input.dataset.valid;
    proceed.disabled = true;
    if (showErrors) {
      input.setAttribute('aria-invalid', 'true');
      setMsg(card, 'invalid', html`${icon('triangle-alert')}<span><strong>${linkOnly ? 'Invalid link.' : 'Invalid username/link.'}</strong> ${r.error}</span>`);
    }
  }
  return r;
}

function shake(el) {
  if (reduceMotion()) return;
  el.classList.remove('shake'); void el.offsetWidth; el.classList.add('shake');
}

function initCards() {
  $$('[data-service]').forEach((c) => { c._hint = $('.field-msg', c).textContent.trim(); });
  const validateSoon = new WeakMap();

  on(document, 'click', '[data-action=buy]', (e, btn) => {
    const card = btn.closest('[data-service]');
    card.dataset.open === 'true' ? closeCard(card) : openCard(card);
  });
  on(document, 'click', '[data-action=cancel]', (e, btn) => closeCard(btn.closest('[data-service]')));
  on(document, 'input', '[data-service] input[name=target]', (e, input) => {
    const card = input.closest('[data-service]');
    if (!validateSoon.has(card)) validateSoon.set(card, debounce(() => validate(card), 140));
    // clear a stale error immediately so the field feels responsive
    if (input.getAttribute('aria-invalid') === 'true') input.removeAttribute('aria-invalid');
    validateSoon.get(card)();
  });
  on(document, 'paste', '[data-service] input[name=target]', (e, input) => setTimeout(() => validate(input.closest('[data-service]')), 0));
  on(document, 'blur', '[data-service] input[name=target]', (e, input) => { if (input.value.trim() !== '') validate(input.closest('[data-service]')); }, true);
  on(document, 'keydown', '[data-service] input[name=target]', (e, input) => { if (e.key === 'Escape') closeCard(input.closest('[data-service]')); });
  on(document, 'submit', '[data-service] form', (e, form) => { e.preventDefault(); proceed(form.closest('[data-service]')); });
}

async function proceed(card) {
  if (card._busy) return;                                   // double-click protection
  const input = $('input[name=target]', card);
  const btn = $('[data-action=proceed]', card);
  const r = validate(card);
  if (!r.ok) { shake(input); input.focus(); return; }

  card._busy = true;
  btn.disabled = true;
  btn.classList.add('is-loading');
  btn.setAttribute('aria-busy', 'true');
  input.readOnly = true;
  setMsg(card, 'idle', html`<span class="spinner icon-sm"></span><span>Creating secure payment…</span>`);

  try {
    // One key per attempt: a double click / retry reuses it and can never create a second order. It is KEPT when the outcome is
    // unknown (connection lost, timeout: the server may well have created the order) so the retry is answered with that very order;
    // it changes only when the typed value changes or the server gave a definite answer.
    if (!card._idem || card._idem.value !== input.value) card._idem = { key: uuid(), value: input.value };
    const res = await api('/api/checkout', {
      method: 'POST', timeout: 30000,
      body: { service_id: Number(card.dataset.id), target: input.value },
      headers: { 'Idempotency-Key': card._idem.key },
    });
    remember(res, card);
    setMsg(card, 'valid', html`${icon('circle-check')}<span><strong>Order created.</strong> Opening secure payment…</span>`);
    toast('success', 'Order created', 'Opening secure payment…', { duration: 2500 });
    document.body.classList.add('is-leaving');
    setTimeout(() => location.assign(res.url), reduceMotion() ? 0 : 280);
  } catch (err) {
    if (err.code !== 'network' && err.code !== 'timeout') card._idem = null;   // a definite answer: the next click is a fresh attempt
    card._busy = false;
    btn.classList.remove('is-loading');
    btn.removeAttribute('aria-busy');
    input.readOnly = false;
    const field = err.fields?.target;
    if (field) {
      input.setAttribute('aria-invalid', 'true');
      setMsg(card, 'invalid', html`${icon('triangle-alert')}<span><strong>${card.dataset.linkOnly === 'true' ? 'Invalid link.' : 'Invalid username/link.'}</strong> ${field}</span>`);
      btn.disabled = true;
      shake(input);
    } else {
      btn.disabled = false;
      setMsg(card, 'invalid', html`${icon('triangle-alert')}<span>${err.message}</span>`);
      toast(err.status === 429 ? 'warning' : 'error', err.status === 503 ? 'Payments unavailable' : 'Could not create the order', err.message);
      shake(card);
    }
  }
}

// back/forward cache: never come back to a card stuck in "loading"
window.addEventListener('pageshow', (e) => {
  document.body.classList.remove('is-leaving');
  if (!e.persisted) return;
  $$('[data-service]').forEach((card) => {
    card._busy = false; card._idem = null;
    const btn = $('[data-action=proceed]', card);
    btn.classList.remove('is-loading'); btn.removeAttribute('aria-busy');
    $('input[name=target]', card).readOnly = false;
    validate(card, { showErrors: false });
  });
});

// ═════════════════════════ "Your orders" drawer ═════════════════════════
function remember(res, card) {
  const list = (store.get(ORDERS_KEY, []) || []).filter((o) => o.token !== res.token);
  list.unshift({ token: res.token, order_id: res.order_id, title: card.dataset.title, at: Date.now() });
  store.set(ORDERS_KEY, list.slice(0, 10));
}

const CHIP = (o) => {
  const s = o.status;
  if (['COMPLETED', 'MANUAL_APPROVED'].includes(s)) return ['chip-ok', 'Completed'];
  if (s === 'PENDING_PAYMENT') return o.pay_url ? ['chip-warn', 'Awaiting payment'] : ['chip-gray', 'Unpaid'];
  if (['CANCELLED', 'REFUNDED', 'EXPIRED', 'FAILED'].includes(s)) return s === 'FAILED' ? ['chip-bad', 'Needs attention'] : ['chip-gray', s === 'REFUNDED' ? 'Refunded' : s === 'EXPIRED' ? 'Expired' : 'Cancelled'];
  if (s === 'PARTIAL') return ['chip-info', 'Partial'];
  return ['chip-info', 'In progress'];
};

async function fetchOrders() {
  const saved = store.get(ORDERS_KEY, []) || [];
  if (!saved.length) return [];
  const res = await api('/api/orders/lookup', { method: 'POST', body: { tokens: saved.map((o) => o.token) } });
  const order = new Map(saved.map((o, i) => [o.token, i]));
  return res.orders.sort((a, b) => order.get(a.token) - order.get(b.token));
}

const isActive = (o) => (o.status === 'PENDING_PAYMENT' && o.pay_url) || ['PAID', 'PROCESSING', 'SUBMITTED', 'IN_PROGRESS', 'MANUAL_PENDING', 'MANUAL_APPROVED'].includes(o.status) && !o.done;

function renderOrders(list) {
  const body = $('[data-orders-body]');
  if (!list.length) {
    setHtml(body, html`<div class="site-empty"><div class="state-icon state-warn !mt-0 mb-3">${icon('package')}</div>
      <p class="font-bold text-ink">No orders yet</p><p class="mt-1 text-sm">Pick a service and your orders will show up here.</p></div>`);
    return;
  }
  setHtml(body, html`<ul class="grid gap-2.5">${list.map((o) => {
    const [cls, label] = CHIP(o);
    const href = o.pay_url || o.track_url;
    return html`<li><a class="order-row" href="${href}">
      <div class="min-w-0 flex-1">
        <div class="truncate font-bold">${o.service}</div>
        <div class="mt-0.5 text-xs text-muted">${o.order_id} · <span class="num">${o.amount}</span></div>
        ${o.target ? html`<div class="mt-0.5 truncate text-xs text-muted" title="${o.target}">${o.target}</div>` : ''}
        <div class="mt-1 text-xs text-muted">${o.pay_url ? 'Tap to continue paying' : o.status_label}</div>
      </div>
      <span class="chip ${cls}">${label}</span>${icon('chevron-right', 'text-faint')}</a></li>`;
  })}</ul>`);
}

function setBadge(n) {
  const dot = $('[data-orders-count]');
  if (!dot) return;
  dot.textContent = n > 9 ? '9+' : String(n);
  dot.hidden = n < 1;
}

function initOrders() {
  const dlg = $('#orders-drawer');
  if (!dlg) return;
  const saved = store.get(ORDERS_KEY, []) || [];
  if (saved.length) fetchOrders().then((l) => setBadge(l.filter(isActive).length)).catch(() => {});

  on(document, 'click', '[data-action=open-orders]', async (e, btn) => {
    openDialog(dlg, btn);
    setHtml($('[data-orders-body]'), html`<div class="grid gap-2.5">${[1, 2, 3].map(() => html`<div class="skeleton h-[4.5rem]"></div>`)}</div>`);
    try {
      const list = await fetchOrders();
      renderOrders(list);
      setBadge(list.filter(isActive).length);
    } catch (err) {
      setHtml($('[data-orders-body]'), html`<div class="site-empty"><p class="font-bold text-ink">Could not load your orders</p><p class="mt-1 text-sm">${err.message}</p>
        <button type="button" class="btn mt-4" data-action="open-orders">Try again</button></div>`);
    }
  });

  // "?orders=1": other pages (order tracking) send the Shop icon here, so it opens the drawer on arrival
  if (/[?&]orders=1(&|$)/.test(location.search)) {
    try { history.replaceState(null, '', location.pathname + location.hash); } catch { /* ignore */ }
    queueMicrotask(() => $('[data-action=open-orders]')?.click());
  }
}

initTabs();
initCards();
initOrders();
