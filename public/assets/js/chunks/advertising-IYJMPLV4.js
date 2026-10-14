import{a as l,e as b,i as m,q as y,r as g,s as o,t as C,u as w}from"./chunk-ZZ2LXP5P.js";import{b as f,d as h}from"./chunk-OGGRQBAA.js";import"./chunk-F5XV67FX.js";import{b as v,c as n}from"./chunk-ZPOCOIRG.js";import"./chunk-UADDT5XU.js";import{a as r,e as c,h as u,m as d}from"./chunk-3IHOMMDJ.js";var _=null,i=()=>r("#advertising-root");function $(s){_=s,i().innerHTML=String(c`
  <div class="grid-2">
    <form id="ads-form" class="card" novalidate autocomplete="off" aria-labelledby="h-cpc">
      <div class="card-head"><h2 class="card-title" id="h-cpc">${d("megaphone")}Advertising cost</h2></div>
      <div class="field"><label class="label req" for="ad-cpc">Cost per click (CPC)</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="ad-cpc" name="cpc" inputmode="decimal" value="${s.cpc}" placeholder="1.08"></div>
        <p class="field-hint">What one qualified visitor costs you in ads. Default ₹1.08. Changing it only affects visitors from now on — past days keep the CPC they were charged at.</p></div>
      <div class="field"><label class="label req" for="ad-secs">Qualified visitor after</label>
        <div class="input-affix"><input class="input" id="ad-secs" name="qualify_seconds" inputmode="numeric" value="${s.qualify_seconds}"><span class="affix-r">seconds</span></div>
        <p class="field-hint">A visitor counts (and is charged) once they stay on the page this long — 3 seconds by default. Quick bounces cost nothing.</p></div>
      <div class="field"><label class="label req" for="ad-conv">Assumed conversion rate</label>
        <div class="input-affix"><input class="input" id="ad-conv" name="assumed_conversion" inputmode="decimal" value="${s.assumed_conversion}"><span class="affix-r">% of visitors buy</span></div>
        <p class="field-hint">Only used for the profit preview in Services until you have 10 paid orders in 30 days of real data.</p></div>
      <div class="form-actions"><button type="submit" class="btn btn-primary btn-shine" id="ad-save">${d("check")}<span>Save advertising settings</span></button></div>
    </form>

    <section class="card" aria-labelledby="h-how"><div class="card-head"><h2 class="card-title" id="h-how">${d("percent")}How ad spend is calculated</h2></div>
      <div class="stack text-sm text-ink-soft">
        <div class="profit-box"><div class="profit-line total !border-0 !mt-0 !pt-0"><span>Ads spend</span><b>qualified visitors × CPC</b></div></div>
        <p>Each qualified visitor is charged <b>once per day</b>, however many times they come back that day. The charge is stored with the visitor, so editing the CPC later never rewrites old reports.</p>
        <p>Net profit = <b>revenue − SMM cost − ads spend</b>. Manual orders have no SMM cost, so their full price counts before ads.</p>
        <div class="kv"><span>Current CPC</span><b>${l(s.cpc_paise,!0)}</b></div>
        <div class="kv"><span>Estimated ad cost carried by one order</span><b>${l(s.est_per_order_paise,!0)}</b></div>
        <p class="text-xs text-muted">Basis: ${s.est_basis}</p></div></section>
  </div>

  <section class="card mt-5" aria-labelledby="h-hist"><div class="card-head"><h2 class="card-title" id="h-hist">${d("history")}CPC history</h2></div>
    <div class="tbl-scroll"><table class="tbl tbl-resp"><thead><tr><th>Set on</th><th class="num">CPC</th><th class="num">Qualify after</th><th>Changed by</th></tr></thead>
    <tbody>${s.history.map(e=>c`<tr><td data-label="Set on">${m(e.at)}</td><td data-label="CPC" class="num"><b>${e.cpc}</b></td><td data-label="Qualify after" class="num">${e.qualify_seconds} s</td><td data-label="By">${e.by}</td></tr>`)}</tbody></table></div></section>`),w(i()),i().setAttribute("aria-busy","false")}async function x(){try{$((await f("advertising")).ads)}catch(e){i().innerHTML=y(e.message).toString(),i().setAttribute("aria-busy","false");return}let s=i();u(s,"click","[data-retry]",()=>location.reload()),s.addEventListener("submit",async e=>{e.preventDefault();let t=r("#ads-form");C(t);let p=t.elements.cpc.value.trim();if(b(p)===null){o(t,{cpc:"Enter the CPC in rupees with at most two decimals (for example 1.08)."});return}await g(r("#ad-save"),async()=>{try{let a=await h("advertising",{cpc:p,qualify_seconds:t.elements.qualify_seconds.value,assumed_conversion:t.elements.assumed_conversion.value},{idempotency:!0});v("Advertising settings saved",`CPC is now ${l(a.ads.cpc_paise,!0)}`),$(a.ads)}catch(a){a.fields?(o(t,a.fields),n("Please check the highlighted fields",a.message)):n("Could not save",a.message)}})})}export{x as default};
