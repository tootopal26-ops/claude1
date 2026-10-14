import { $, html, icon, on } from '../core/dom.js';
import { inr, fmtDateTime, parseRupees } from '../core/format.js';
import { get, put } from './net.js';
import { ok, fail, errorState, showFieldErrors, clearFieldErrors, autoClearErrors, withBusy } from './ui.js';

let ads = null;
const root = () => $('#advertising-root');

function render(a) {
  ads = a;
  root().innerHTML = String(html`
  <div class="grid-2">
    <form id="ads-form" class="card" novalidate autocomplete="off" aria-labelledby="h-cpc">
      <div class="card-head"><h2 class="card-title" id="h-cpc">${icon('megaphone')}Advertising cost</h2></div>
      <div class="field"><label class="label req" for="ad-cpc">Cost per click (CPC)</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="ad-cpc" name="cpc" inputmode="decimal" value="${a.cpc}" placeholder="1.08"></div>
        <p class="field-hint">What one qualified visitor costs you in ads. Default ₹1.08. Changing it only affects visitors from now on — past days keep the CPC they were charged at.</p></div>
      <div class="field"><label class="label req" for="ad-secs">Qualified visitor after</label>
        <div class="input-affix"><input class="input" id="ad-secs" name="qualify_seconds" inputmode="numeric" value="${a.qualify_seconds}"><span class="affix-r">seconds</span></div>
        <p class="field-hint">A visitor counts (and is charged) once they stay on the page this long — 3 seconds by default. Quick bounces cost nothing.</p></div>
      <div class="field"><label class="label req" for="ad-conv">Assumed conversion rate</label>
        <div class="input-affix"><input class="input" id="ad-conv" name="assumed_conversion" inputmode="decimal" value="${a.assumed_conversion}"><span class="affix-r">% of visitors buy</span></div>
        <p class="field-hint">Only used for the profit preview in Services until you have 10 paid orders in 30 days of real data.</p></div>
      <div class="form-actions"><button type="submit" class="btn btn-primary btn-shine" id="ad-save">${icon('check')}<span>Save advertising settings</span></button></div>
    </form>

    <section class="card" aria-labelledby="h-how"><div class="card-head"><h2 class="card-title" id="h-how">${icon('percent')}How ad spend is calculated</h2></div>
      <div class="stack text-sm text-ink-soft">
        <div class="profit-box"><div class="profit-line total !border-0 !mt-0 !pt-0"><span>Ads spend</span><b>qualified visitors × CPC</b></div></div>
        <p>Each qualified visitor is charged <b>once per day</b>, however many times they come back that day. The charge is stored with the visitor, so editing the CPC later never rewrites old reports.</p>
        <p>Net profit = <b>revenue − SMM cost − ads spend</b>. Manual orders have no SMM cost, so their full price counts before ads.</p>
        <div class="kv"><span>Current CPC</span><b>${inr(a.cpc_paise, true)}</b></div>
        <div class="kv"><span>Estimated ad cost carried by one order</span><b>${inr(a.est_per_order_paise, true)}</b></div>
        <p class="text-xs text-muted">Basis: ${a.est_basis}</p></div></section>
  </div>

  <section class="card mt-5" aria-labelledby="h-hist"><div class="card-head"><h2 class="card-title" id="h-hist">${icon('history')}CPC history</h2></div>
    <div class="tbl-scroll"><table class="tbl tbl-resp"><thead><tr><th>Set on</th><th class="num">CPC</th><th class="num">Qualify after</th><th>Changed by</th></tr></thead>
    <tbody>${a.history.map((h) => html`<tr><td data-label="Set on">${fmtDateTime(h.at)}</td><td data-label="CPC" class="num"><b>${h.cpc}</b></td><td data-label="Qualify after" class="num">${h.qualify_seconds} s</td><td data-label="By">${h.by}</td></tr>`)}</tbody></table></div></section>`);
  autoClearErrors(root());
  root().setAttribute('aria-busy', 'false');
}

export default async function init() {
  try { render((await get('advertising')).ads); } catch (e) { root().innerHTML = errorState(e.message).toString(); root().setAttribute('aria-busy', 'false'); return; }
  const r = root();
  on(r, 'click', '[data-retry]', () => location.reload());
  r.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = $('#ads-form');
    clearFieldErrors(f);
    const cpc = f.elements.cpc.value.trim();
    if (parseRupees(cpc) === null) { showFieldErrors(f, { cpc: 'Enter the CPC in rupees with at most two decimals (for example 1.08).' }); return; }
    await withBusy($('#ad-save'), async () => {
      try {
        const res = await put('advertising', { cpc, qualify_seconds: f.elements.qualify_seconds.value, assumed_conversion: f.elements.assumed_conversion.value }, { idempotency: true });
        ok('Advertising settings saved', `CPC is now ${inr(res.ads.cpc_paise, true)}`);
        render(res.ads);
      } catch (err) {
        if (err.fields) { showFieldErrors(f, err.fields); fail('Please check the highlighted fields', err.message); } else fail('Could not save', err.message);
      }
    });
  });
}
