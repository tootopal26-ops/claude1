import{a as l,e as b,i as m,q as y,r as g,s as o,t as C,u as w}from"./chunk-O325ZPNA.js";import{b as f,d as h}from"./chunk-CUNUMIEQ.js";import"./chunk-F5XV67FX.js";import{b as v,c}from"./chunk-77GGHPHY.js";import"./chunk-FHXP7Q2I.js";import{a as r,e as n,h as u,m as d}from"./chunk-3IHOMMDJ.js";var x=null,i=()=>r("#advertising-root");function $(e){x=e,i().innerHTML=String(n`
  <div class="grid-2">
    <form id="ads-form" class="card" novalidate autocomplete="off" aria-labelledby="h-cpc">
      <div class="card-head"><h2 class="card-title" id="h-cpc">${d("megaphone")}Advertising cost</h2></div>
      <div class="field"><label class="label req" for="ad-cpc">Cost per click (CPC)</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="ad-cpc" name="cpc" inputmode="decimal" value="${e.cpc}" placeholder="1.08"></div>
        <p class="field-hint">What one qualified visitor costs you in ads. Default ₹1.08. Changing it only affects visitors from now on — past days keep the CPC they were charged at.</p></div>
      <div class="field"><label class="label req" for="ad-secs">Qualified visitor after</label>
        <div class="input-affix"><input class="input" id="ad-secs" name="qualify_seconds" inputmode="numeric" value="${e.qualify_seconds}"><span class="affix-r">seconds</span></div>
        <p class="field-hint">A visitor counts (and is charged) once they stay on the page this long — 3 seconds by default. Quick bounces cost nothing.</p></div>
      <div class="field"><label class="label req" for="ad-conv">Assumed conversion rate</label>
        <div class="input-affix"><input class="input" id="ad-conv" name="assumed_conversion" inputmode="decimal" value="${e.assumed_conversion}"><span class="affix-r">% of visitors buy</span></div>
        <p class="field-hint">Only used for the profit preview in Services until you have 10 paid orders in 30 days of real data.</p></div>
      <div class="form-actions"><button type="submit" class="btn btn-primary btn-shine" id="ad-save">${d("check")}<span>Save advertising settings</span></button></div>
    </form>

    <section class="card" aria-labelledby="h-how"><div class="card-head"><h2 class="card-title" id="h-how">${d("percent")}How ad spend is calculated</h2></div>
      <div class="stack text-sm text-ink-soft">
        <div class="profit-box"><div class="profit-line total !border-0 !mt-0 !pt-0"><span>Ads spend</span><b>qualified visitors × CPC</b></div></div>
        <p>Each qualified visitor is charged <b>once per day</b>, however many times they come back that day. The charge is stored with the visitor, so editing the CPC later never rewrites old reports.</p>
        <p>Net profit = <b>revenue − SMM cost − ads spend</b>. Manual orders have no SMM cost, so their full price counts before ads.</p>
        <div class="kv"><span>Current CPC</span><b>${l(e.cpc_paise,!0)}</b></div>
        <div class="kv"><span>Estimated ad cost carried by one order</span><b>${l(e.est_per_order_paise,!0)}</b></div>
        <p class="text-xs text-muted">Basis: ${e.est_basis}</p></div></section>
  </div>

  <section class="card mt-5" aria-labelledby="h-hist"><div class="card-head"><h2 class="card-title" id="h-hist">${d("history")}CPC history</h2></div>
    <div class="tbl-scroll" tabindex="0" role="region" aria-label="Earlier CPC settings"><table class="tbl tbl-resp"><thead><tr><th>Set on</th><th class="num">CPC</th><th class="num">Qualify after</th><th>Changed by</th></tr></thead>
    <tbody>${e.history.map(s=>n`<tr><td data-label="Set on">${m(s.at)}</td><td data-label="CPC" class="num"><b>${s.cpc}</b></td><td data-label="Qualify after" class="num">${s.qualify_seconds} s</td><td data-label="By">${s.by}</td></tr>`)}</tbody></table></div></section>`),w(i()),i().setAttribute("aria-busy","false")}async function _(){try{$((await f("advertising")).ads)}catch(s){i().innerHTML=y(s.message).toString(),i().setAttribute("aria-busy","false");return}let e=i();u(e,"click","[data-retry]",()=>location.reload()),e.addEventListener("submit",async s=>{s.preventDefault();let t=r("#ads-form");C(t);let p=t.elements.cpc.value.trim();if(b(p)===null){o(t,{cpc:"Enter the CPC in rupees with at most two decimals (for example 1.08)."});return}await g(r("#ad-save"),async()=>{try{let a=await h("advertising",{cpc:p,qualify_seconds:t.elements.qualify_seconds.value,assumed_conversion:t.elements.assumed_conversion.value},{idempotency:!0});v("Advertising settings saved",`CPC is now ${l(a.ads.cpc_paise,!0)}`),$(a.ads)}catch(a){a.fields?(o(t,a.fields),c("Please check the highlighted fields",a.message)):c("Could not save",a.message)}})})}export{_ as default};
