import{a as o,d as q,e as P,f as E,i as A,j as g,r as N,s as k,t as f,u as x,v as M}from"./chunk-PDH7P5MF.js";import{b as T,c as w,d as S}from"./chunk-CUNUMIEQ.js";import{c as C}from"./chunk-F5XV67FX.js";import{b as m,c as u}from"./chunk-77GGHPHY.js";import"./chunk-FHXP7Q2I.js";import{a as l,e as i,h as p,m as d}from"./chunk-3IHOMMDJ.js";var v=null,c=null,r=null,_=!1,y=!1,h=()=>l("#advertising-root"),F={not_started:"Not started",in_progress:"In progress",ended:"Ended"},D={ahead:["Ahead of target","chip-ok"],on_track:["On track","chip-info"],behind:["Behind pace","chip-warn"]};function B(e){let t=e?.target;return i`
  <form id="target-form" class="stack" novalidate autocomplete="off" aria-label="Set a sales target">
    <div class="grid-2">
      <div class="field"><label class="label req" for="tg-start">Period starts</label><input class="input" type="date" id="tg-start" name="period_start" value="${t?.period_start||""}"></div>
      <div class="field"><label class="label req" for="tg-end">Period ends</label><input class="input" type="date" id="tg-end" name="period_end" value="${t?.period_end||""}"></div>
    </div>
    <div class="field"><label class="label req" for="tg-amount">Revenue target</label>
      <div class="input-affix"><span class="affix">₹</span><input class="input" id="tg-amount" name="target" inputmode="decimal" value="${t?t.target:""}" placeholder="50000"></div></div>
    <div class="field"><label class="label" for="tg-note">Note</label><input class="input" id="tg-note" name="note" maxlength="255" value="${t?.note||""}" placeholder="Optional — e.g. “Diwali push”"></div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-shine" id="tg-save">${d("check")}<span>Save target</span></button>
      <button type="button" class="btn btn-ghost" id="tg-cancel">Cancel</button>
    </div>
  </form>`}function L(e){if(!e.has_target)return i`<section class="card" aria-labelledby="h-target"><div class="card-head"><h2 class="card-title" id="h-target">${d("target")}Sales target</h2></div>
      <p class="text-sm text-ink-soft mb-3">No revenue target is set yet. Set one to see real pacing against it — actual revenue so far, a projection to the end of the period, and the daily pace still needed.</p>
      ${_?B(e):i`<button type="button" class="btn btn-primary" id="tg-open">${d("target","icon-sm")}<span>Set a target</span></button>`}
    </section>`;let t=e.target,a=Math.max(0,Math.min(100,Math.round((e.pct_of_target_tenths||0)/10))),[n,s]=e.pace_label?D[e.pace_label]:["","chip-gray"];return i`<section class="card" aria-labelledby="h-target"><div class="card-head"><h2 class="card-title" id="h-target">${d("target")}Sales target</h2>
      <span class="chip ${e.status==="not_started"?"chip-gray":s}">${e.status==="not_started"?F.not_started:n}</span></div>
    <div class="stack">
      <div class="kv"><span>Period</span><b>${g(t.period_start)} – ${g(t.period_end)}</b></div>
      <div class="kv"><span>Target</span><b>${o(t.target_paise,!0)}</b></div>
      ${t.note?i`<div class="kv"><span>Note</span><b>${t.note}</b></div>`:""}
      <div class="kv"><span>Actual so far</span><b>${o(e.actual_paise,!0)}</b><span class="text-xs text-muted"> (${q(e.pct_of_target_tenths)} of target)</span></div>
      <div class="hbar-track" role="img" aria-label="${q(e.pct_of_target_tenths)} of target reached"><div class="hbar-fill" data-w="${a}"></div></div>
      ${e.status==="in_progress"?i`
        <div class="kv"><span>Projected finish</span><b>${o(e.projected_paise,!0)}</b><span class="text-xs text-muted"> (${q(e.projected_pct_tenths)})</span></div>
        ${e.required_daily_pace_paise!==null&&e.required_daily_pace_paise>0?i`<div class="kv"><span>Needed per day to hit it</span><b>${o(e.required_daily_pace_paise,!0)}</b></div>`:""}
        <p class="text-xs text-muted">Day ${e.elapsed_days} of ${e.total_days} — ${e.remaining_days} day${e.remaining_days===1?"":"s"} left.</p>
      `:""}
      ${e.status==="not_started"?i`<p class="text-xs text-muted">Starts ${g(t.period_start)} — ${e.total_days} day period.</p>`:""}
      ${e.status==="ended"?i`<p class="text-xs text-muted">The period ended with ${o(e.actual_paise,!0)} against a target of ${o(t.target_paise,!0)}.</p>`:""}
    </div>
    <div class="form-actions mt-3">
      <button type="button" class="btn btn-sm btn-ghost" id="tg-open">${d("pencil","icon-sm")}<span>Set a new target</span></button>
      ${e.status!=="ended"?i`<button type="button" class="btn btn-sm btn-ghost" id="tg-end">${d("undo-2","icon-sm")}<span>End this target early</span></button>`:""}
    </div>
    ${_?i`<div class="mt-3">${B(e)}</div>`:""}
  </section>`}function R(e){let t=e?.source==="override";return i`
  <form id="budget-form" class="stack" novalidate autocomplete="off" aria-label="Set an advertising budget">
    <div class="seg" role="radiogroup" aria-label="Budget scope">
      <button type="button" class="seg-btn" data-scope="default" aria-selected="${!t}">Standing budget</button>
      <button type="button" class="seg-btn" data-scope="override" aria-selected="${t}">For a date range</button>
    </div>
    <div id="bg-period" class="grid-2" hidden>
      <div class="field"><label class="label req" for="bg-start">Starts</label><input class="input" type="date" id="bg-start" name="period_start" value="${t?e.period_start:""}"></div>
      <div class="field"><label class="label req" for="bg-end">Ends</label><input class="input" type="date" id="bg-end" name="period_end" value="${t?e.period_end:""}"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label class="label" for="bg-daily">Daily budget</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="bg-daily" name="daily_budget" inputmode="decimal" value="${e?.daily_budget??""}" placeholder="No cap">
        </div><p class="field-hint">Informational for now — planning only, spend is never blocked.</p></div>
      <div class="field"><label class="label" for="bg-monthly">Monthly budget</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="bg-monthly" name="monthly_budget" inputmode="decimal" value="${e?.monthly_budget??""}" placeholder="No cap"></div></div>
    </div>
    <div class="field"><label class="label" for="bg-note">Note</label><input class="input" id="bg-note" name="note" maxlength="255" value="${e?.note||""}" placeholder="Optional"></div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-shine" id="bg-save">${d("check")}<span>Save budget</span></button>
      <button type="button" class="btn btn-ghost" id="bg-cancel">Cancel</button>
    </div>
  </form>`}function O(e){let t=e.daily_budget_paise!==null||e.monthly_budget_paise!==null;return i`<section class="card" aria-labelledby="h-budget"><div class="card-head"><h2 class="card-title" id="h-budget">${d("wallet")}Advertising budget</h2>
      <span class="chip ${e.source==="override"?"chip-brand":t?"chip-info":"chip-gray"}">${e.source==="override"?"date-range budget":t?"standing budget":"no cap set"}</span></div>
    <div class="stack">
      ${e.source==="override"?i`<div class="kv"><span>Applies</span><b>${g(e.period_start)} – ${g(e.period_end)}</b></div>`:""}
      <div class="kv"><span>Daily</span><b>${e.daily_budget_paise!==null?o(e.daily_budget_paise,!0):"No cap"}</b></div>
      <div class="kv"><span>Monthly</span><b>${e.monthly_budget_paise!==null?o(e.monthly_budget_paise,!0):"No cap"}</b></div>
      ${e.note?i`<div class="kv"><span>Note</span><b>${e.note}</b></div>`:""}
    </div>
    <div class="form-actions mt-3">
      <button type="button" class="btn btn-sm btn-ghost" id="bg-open">${d("pencil","icon-sm")}<span>Change budget</span></button>
      ${e.source==="override"?i`<button type="button" class="btn btn-sm btn-ghost" id="bg-end-override">${d("undo-2","icon-sm")}<span>End this override early</span></button>`:""}
      ${t?i`<button type="button" class="btn btn-sm btn-ghost" id="bg-reset">${d("rotate-ccw","icon-sm")}<span>Remove all caps</span></button>`:""}
    </div>
    ${y?i`<div class="mt-3">${R(e)}</div>`:""}
  </section>`}function Q(){return i`<section class="card" aria-labelledby="h-forecast"><div class="card-head"><h2 class="card-title" id="h-forecast">${d("trending-up")}Budget forecast</h2></div>
    <p class="text-sm text-ink-soft mb-3">What a planned spend is expected to buy, projected from the last 30 days' real cost per qualified visitor, conversion rate and revenue per customer — never a demand forecast.</p>
    <form id="forecast-form" class="grid-2" novalidate autocomplete="off" aria-label="Forecast a budget">
      <div class="field"><label class="label req" for="fc-budget">Planned spend</label>
        <div class="input-affix"><span class="affix">₹</span><input class="input" id="fc-budget" name="budget" inputmode="decimal" placeholder="10000"></div></div>
      <div class="field"><label class="label" for="fc-campaign">Campaign (optional)</label><input class="input" id="fc-campaign" name="campaign" placeholder="Leave blank for the whole shop"></div>
    </form>
    <div class="form-actions"><button type="button" class="btn btn-primary" id="fc-run">${d("trending-up","icon-sm")}<span>Forecast</span></button></div>
    <div id="fc-result" aria-live="polite"></div>
  </section>`}function j(e){return e.customers===null?i`<div class="callout callout-warning mt-3">${d("triangle-alert")}<span>Not enough recent data${e.campaign?` for "${e.campaign}"`:""} to estimate a conversion rate — showing visitors only.</span></div>
      <div class="kv mt-2"><span>Qualified visitors</span><b>${E(e.qualified_visitors)}</b></div>`:i`
  <div class="grid-2 mt-3">
    <div class="kv"><span>Qualified visitors</span><b>${E(e.qualified_visitors)}</b></div>
    <div class="kv"><span>Customers</span><b>${E(e.customers)}</b></div>
    <div class="kv"><span>Revenue</span><b>${e.revenue_paise!==null?o(e.revenue_paise,!0):"\u2014"}</b></div>
    <div class="kv"><span>Contribution after this spend</span><b class="${e.contribution_paise!==null&&e.contribution_paise<0?"text-bad-700":""}">${e.contribution_paise!==null?o(e.contribution_paise,!0):"\u2014"}</b></div>
  </div>
  <p class="text-xs text-muted mt-2">Basis: last ${e.window.days} days (${g(e.window.from)} – ${g(e.window.to)}) — ₹${(e.basis.cost_per_qualified_paise/100).toFixed(2)} per qualified visitor
    (${e.basis.cost_per_qualified_is_real?"real spend":"CPC estimate, no actual spend entered yet"}), ${e.basis.conversion_tenths!==null?(e.basis.conversion_tenths/10).toFixed(1)+"%":"no"} conversion from ${e.basis.customers_in_window} customer${e.basis.customers_in_window===1?"":"s"}.</p>`}function b(e,t,a){v=e,c=t,r=a,h().innerHTML=String(i`
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
        <div class="kv"><span>Current CPC</span><b>${o(e.cpc_paise,!0)}</b></div>
        <div class="kv"><span>Estimated ad cost carried by one order</span><b>${o(e.est_per_order_paise,!0)}</b></div>
        <p class="text-xs text-muted">Basis: ${e.est_basis}</p></div></section>
  </div>

  <div class="grid-2 mt-5">${L(t)}${O(a)}</div>
  <div class="mt-5">${Q()}</div>

  <section class="card mt-5" aria-labelledby="h-hist"><div class="card-head"><h2 class="card-title" id="h-hist">${d("history")}CPC history</h2></div>
    <div class="tbl-scroll" tabindex="0" role="region" aria-label="Earlier CPC settings"><table class="tbl tbl-resp"><thead><tr><th>Set on</th><th class="num">CPC</th><th class="num">Qualify after</th><th>Changed by</th></tr></thead>
    <tbody>${e.history.map(s=>i`<tr><td data-label="Set on">${A(s.at)}</td><td data-label="CPC" class="num"><b>${s.cpc}</b></td><td data-label="Qualify after" class="num">${s.qualify_seconds} s</td><td data-label="By">${s.by}</td></tr>`)}</tbody></table></div></section>`),requestAnimationFrame(()=>h().querySelectorAll("[data-w]").forEach(s=>{s.style.width=s.dataset.w+"%"}));let n=l("#bg-period");n&&(n.hidden=r.source!=="override"),M(h()),h().setAttribute("aria-busy","false")}async function H(){let e=await T("advertising");b(e.ads,e.sales_target,e.ad_budget)}async function W(){try{await H()}catch(t){h().innerHTML=N(t.message).toString(),h().setAttribute("aria-busy","false");return}let e=h();p(e,"click","[data-retry]",()=>location.reload()),e.addEventListener("submit",async t=>{if(t.target.id!=="ads-form")return;t.preventDefault();let a=l("#ads-form");x(a);let n=a.elements.cpc.value.trim();if(P(n)===null){f(a,{cpc:"Enter the CPC in rupees with at most two decimals (for example 1.08)."});return}await k(l("#ad-save"),async()=>{try{let s=await S("advertising",{cpc:n,qualify_seconds:a.elements.qualify_seconds.value,assumed_conversion:a.elements.assumed_conversion.value},{idempotency:!0});m("Advertising settings saved",`CPC is now ${o(s.ads.cpc_paise,!0)}`),b(s.ads,c,r)}catch(s){s.fields?(f(a,s.fields),u("Please check the highlighted fields",s.message)):u("Could not save",s.message)}})}),p(e,"click","#tg-open",()=>{_=!0,b(v,c,r),l("#tg-start")?.focus()}),p(e,"click","#tg-cancel",()=>{_=!1,b(v,c,r)}),p(e,"click","#tg-end",async()=>{if(await C({title:"End this target early?",message:"The period ends today instead of its planned end date. This cannot be undone, though you can always set a new target.",confirmText:"End target",tone:"danger"}))try{c=(await w("sales-target/revoke")).sales_target,_=!1,m("Target ended","The sales target no longer applies."),b(v,c,r)}catch(a){u("Could not end the target",a.message)}}),e.addEventListener("submit",async t=>{if(t.target.id!=="target-form")return;t.preventDefault();let a=l("#target-form");x(a),await k(l("#tg-save"),async()=>{try{c=(await S("sales-target",{period_start:a.elements.period_start.value,period_end:a.elements.period_end.value,target:a.elements.target.value.trim(),note:a.elements.note.value.trim()},{idempotency:!0})).sales_target,_=!1,m("Target saved",`${o(c.target.target_paise,!0)} by ${c.target.period_end}`),b(v,c,r)}catch(n){n.fields?(f(a,n.fields),u("Please check the highlighted fields",n.message)):u("Could not save",n.message)}})}),p(e,"click","#bg-open",()=>{y=!0,b(v,c,r)}),p(e,"click","#bg-cancel",()=>{y=!1,b(v,c,r)}),p(e,"click",".seg-btn",(t,a)=>{l("#budget-form").querySelectorAll(".seg-btn").forEach(s=>s.setAttribute("aria-selected",String(s===a))),l("#bg-period").hidden=a.dataset.scope!=="override"}),p(e,"click","#bg-reset",async()=>{if(await C({title:"Remove all budget caps?",message:"Both the daily and monthly budgets go back to \u201Cno cap\u201D. This never changes what has already been spent.",confirmText:"Remove caps"}))try{r=(await w("ad-budget/reset")).ad_budget,y=!1,m("Budget caps removed","No daily or monthly cap is set."),b(v,c,r)}catch(a){u("Could not reset the budget",a.message)}}),p(e,"click","#bg-end-override",async()=>{if(await C({title:"End this budget override early?",message:"The standing budget (if any) applies again from today.",confirmText:"End override",tone:"danger"}))try{r=(await w("ad-budget/revoke-override")).ad_budget,y=!1,m("Override ended","The standing budget applies again."),b(v,c,r)}catch(a){u("Could not end the override",a.message)}}),e.addEventListener("submit",async t=>{if(t.target.id!=="budget-form")return;t.preventDefault();let a=l("#budget-form");x(a);let n=a.querySelector('.seg-btn[aria-selected="true"]')?.dataset.scope||"default",s={scope:n,daily_budget:a.elements.daily_budget.value.trim(),monthly_budget:a.elements.monthly_budget.value.trim(),note:a.elements.note.value.trim()};n==="override"&&(s.period_start=a.elements.period_start.value,s.period_end=a.elements.period_end.value),await k(l("#bg-save"),async()=>{try{r=(await S("ad-budget",s,{idempotency:!0})).ad_budget,y=!1,m("Budget saved",r.daily_budget_paise!==null||r.monthly_budget_paise!==null?"The new budget is in force.":"No cap is set."),b(v,c,r)}catch($){$.fields?(f(a,$.fields),u("Please check the highlighted fields",$.message)):u("Could not save",$.message)}})}),p(e,"click","#fc-run",async()=>{let t=l("#forecast-form");x(t);let a=t.elements.budget.value.trim();if(P(a)===null){f(t,{budget:"Enter the budget in rupees with at most two decimals."});return}await k(l("#fc-run"),async()=>{try{let n=await w("ad-budget/forecast",{budget:a,campaign:t.elements.campaign.value.trim()});l("#fc-result").innerHTML=String(j(n.forecast))}catch(n){n.fields&&f(t,n.fields),u("Could not forecast",n.message)}})})}export{W as default};
