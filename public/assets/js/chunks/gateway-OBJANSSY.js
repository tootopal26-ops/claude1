import{a as w,b as T}from"./chunk-YBNALHGL.js";import{b}from"./chunk-K6YWPFPE.js";import{i as $,j as m,q as P,r as y,s as f,t as U,u as A,v as L}from"./chunk-O6CLEF34.js";import{b as k,c as _,d as v}from"./chunk-3M4BCHGM.js";import"./chunk-F5XV67FX.js";import{b as u,c as l}from"./chunk-LLJUBRQ3.js";import"./chunk-F47YFC3V.js";import{a as d,e as r,h as p,m as o}from"./chunk-3IHOMMDJ.js";var e=null,n=()=>d("#gateway-root");function R(t){let i=t.level==="ok"?"success":t.level==="warn"?"warning":"error",a=t.level==="ok"?"circle-check":t.level==="warn"?"triangle-alert":"circle-alert";return r`<div class="callout callout-${i}" role="status">${o(a)}<div>${t.message}${t.duration_ms?r`<br><small>${t.duration_ms} ms</small>`:""}</div></div>`}function g(t,i=null){e=t;let a=e.environment==="production";n().innerHTML=String(r`
  <form id="gw-form" class="stack" novalidate autocomplete="off">
    <section class="card" aria-labelledby="h-gw">
      <div class="card-head"><h2 class="card-title" id="h-gw">${o("wallet")}${e.name}</h2>
        <span class="flex flex-wrap gap-2"><span class="chip ${e.is_active?"chip-ok":"chip-gray"}">${e.is_active?"Active":"Not active"}</span><span class="chip ${a?"chip-brand":"chip-warn"}">${a?"Production":"Test mode"}</span>
        ${e.last_test_ok===null?"":r`<span class="chip ${e.last_test_ok?"chip-ok":"chip-bad"}" title="${e.last_test_message||""}">Last test: ${e.last_test_ok?"passed":"failed"} ${e.last_tested_at?m(e.last_tested_at):""}</span>`}</span></div>

      <div class="switch-row"><div><b>Accept payments through this gateway</b><small>Customers see the QR payment page only while a gateway is active.</small></div><label class="switch"><input type="checkbox" name="is_active" ${e.is_active?"checked":""} aria-label="Gateway active"><span></span></label></div>

      <div class="form-section"><h3>Environment</h3>
        <div class="seg" role="radiogroup" aria-label="Environment">
          <button type="button" role="radio" data-env="test" aria-checked="${!a}" aria-selected="${!a}">Test</button>
          <button type="button" role="radio" data-env="production" aria-checked="${a}" aria-selected="${a}">Production</button></div>
        <input type="hidden" name="environment" value="${e.environment}">
        <p class="field-hint">Test mode is only a label for you — it does not change the gateway URL. Use your provider’s sandbox URL while testing, then switch both to production.</p></div>

      <div class="form-section"><h3>Connection</h3>
        <div class="field-row"><div class="field"><label class="label req" for="gw-url">Gateway base URL</label>
          <input class="input mono" id="gw-url" name="base_url" value="${e.base_url}" placeholder="https://your-alpha-upi-host" spellcheck="false" autocapitalize="none">
          <p class="field-hint">Without a trailing <span class="kbd">/api</span>. Must be https:// in production.</p></div>
        <div class="field"><label class="label" for="gw-pref">Preferred UPI provider</label>
          <select class="select" id="gw-pref" name="provider_pref"><option value="auto" ${e.provider_pref==="auto"?"selected":""}>Automatic</option><option value="bharatpe" ${e.provider_pref==="bharatpe"?"selected":""}>BharatPe</option><option value="paytm" ${e.provider_pref==="paytm"?"selected":""}>Paytm</option></select></div></div>
        ${w({name:"api_key",label:"API key",has:e.has_api_key,hint:e.api_key_hint,placeholder:"Paste the API key from your Alpha UPI dashboard",help:"Stored encrypted on the server. It is never shown again after saving."})}
        ${w({name:"webhook_secret",label:"Webhook secret",has:e.has_webhook_secret,hint:e.webhook_secret_hint,placeholder:"Paste the webhook secret",help:"Used to verify that payment notifications really come from the gateway."})}
      </div>

      <div class="form-actions"><span class="spacer text-sm text-muted">${e.last_tested_at?"Last tested "+$(e.last_tested_at):"Not tested yet"}</span>
        <button type="button" class="btn" id="gw-test">${o("activity","icon-sm")}<span>Test connection</span></button>
        <button type="submit" class="btn btn-primary btn-shine" id="gw-save">${o("check")}<span>Save gateway</span></button></div>
      <div id="gw-result" class="mt-4" aria-live="polite">${i?R(i):""}</div>
    </section>
  </form>

  <section class="card mt-5" aria-labelledby="h-wh"><div class="card-head"><h2 class="card-title" id="h-wh">${o("webhook")}Webhook</h2>
    ${e.last_webhook?r`<span class="chip ${e.last_webhook.signature_valid?"chip-ok":"chip-bad"}">Last delivery ${m(e.last_webhook.at)} · ${e.last_webhook.signature_valid?e.last_webhook.status:"bad signature"}</span>`:r`<span class="chip chip-gray">No deliveries yet</span>`}</div>
    <p class="text-sm text-ink-soft mb-3">Give this URL to your payment gateway as the webhook (callback) endpoint. Payments are confirmed by the gateway’s signed notification and double-checked with the gateway before an order is marked paid.</p>
    <div class="copy-field"><input class="input" readonly value="${e.webhook_url}" aria-label="Webhook URL" id="wh-url"><button type="button" class="btn" data-copy-url>${o("copy","icon-sm")}<span>Copy</span></button></div>
    <ol class="mt-4 grid gap-1.5 text-[13px] text-muted list-decimal pl-5"><li>Open your Alpha UPI dashboard and find the webhook / callback setting.</li><li>Paste the URL above and use the same webhook secret you saved here.</li><li>Press “Test connection” — then make a ₹1–₹5 test payment from the store to see a delivery appear here.</li></ol>
    ${e.webhook_url.startsWith("http://")?r`<div class="callout callout-warning mt-4">${o("triangle-alert")}<div><strong>This URL is not HTTPS.</strong> Real gateways usually require a public https:// URL. Set <span class="kbd">APP_URL</span> to your https:// domain in <span class="kbd">.env</span>.</div></div>`:""}
  </section>

  <section class="card mt-5" aria-labelledby="h-ref"><div class="card-head"><h2 class="card-title" id="h-ref">${o("undo-2")}Refunds</h2></div>
    <p class="text-sm text-ink-soft">Alpha UPI has no refund API, so refunds are made by hand from your bank or UPI app and then recorded on the order (<b>Orders → open an order → Record refund</b>). Reports subtract recorded refunds from revenue and profit.</p></section>`),A(n()),n().setAttribute("aria-busy","false")}function x(){let t=d("#gw-form"),i={base_url:t.elements.base_url.value,provider_pref:t.elements.provider_pref.value,environment:t.elements.environment.value,is_active:t.elements.is_active.checked};for(let a of["api_key","webhook_secret"]){let s=t.elements[a];s&&!s.disabled&&s.value.trim()&&(i[a]=s.value.trim())}return i}async function S(){try{g((await k("gateway")).gateway)}catch(i){n().innerHTML=P(i.message).toString(),n().setAttribute("aria-busy","false");return}let t=n();T(t),p(t,"click","[data-env]",(i,a)=>{d('[name="environment"]').value=a.dataset.env,t.querySelectorAll("[data-env]").forEach(s=>{let h=s===a;s.setAttribute("aria-checked",String(h)),s.setAttribute("aria-selected",String(h))})}),p(t,"click","[data-copy-url]",()=>L(e.webhook_url,"Webhook URL copied")),p(t,"click","[data-retry]",()=>location.reload()),t.addEventListener("submit",async i=>{i.preventDefault(),U(t),await y(d("#gw-save"),async()=>{try{let a=await v(`gateway/${e.id}`,x(),{idempotency:!0});u("Payment gateway saved",a.gateway.is_active?"The gateway is active.":"The gateway is not active yet."),g(a.gateway),b()}catch(a){a.fields?(f(t,a.fields),l("Please check the highlighted fields",a.message)):l("Could not save",a.message)}})}),p(t,"click","#gw-test",async(i,a)=>{await y(a,async()=>{try{let s=x();(s.base_url!==e.base_url||s.api_key||s.webhook_secret||s.provider_pref!==e.provider_pref)&&await v(`gateway/${e.id}`,{...s,is_active:e.is_active},{idempotency:!0});let c=await _(`gateway/${e.id}/test`);g(c.gateway,c.result),b(),(c.result.ok?u:l)(c.result.ok?"Connection test passed":"Connection test failed",c.result.message)}catch(s){s.fields?(f(t,s.fields),l("Please check the highlighted fields",s.message)):l("Test failed",s.message)}})})}export{S as default};
