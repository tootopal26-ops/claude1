import{a as f,b as T}from"./chunk-YBNALHGL.js";import{b as v}from"./chunk-SARHE5OH.js";import{i as P,j as b,q as U,r as m,s as y,t as L,u as x,v as A}from"./chunk-ZZ2LXP5P.js";import{b as _,c as $,d as u}from"./chunk-5VG5NBP2.js";import"./chunk-F5XV67FX.js";import{b as h,c as n,d as k}from"./chunk-ZPOCOIRG.js";import"./chunk-FHXP7Q2I.js";import{a as d,e as l,h as p,m as o}from"./chunk-3IHOMMDJ.js";var e=null,c=()=>d("#gateway-root");function S(t){let i=t.level==="warning"||t.level==="warn",a=t.level==="ok"?"success":i?"warning":"error",s=t.level==="ok"?"circle-check":i?"triangle-alert":"circle-alert";return l`<div class="callout callout-${a}" role="status">${o(s)}<div>${t.message}${t.duration_ms?l`<br><small>${t.duration_ms} ms</small>`:""}</div></div>`}function w(t,i=null){e=t;let a=e.environment==="production";c().innerHTML=String(l`
  <form id="gw-form" class="stack" novalidate autocomplete="off">
    <section class="card" aria-labelledby="h-gw">
      <div class="card-head"><h2 class="card-title" id="h-gw">${o("wallet")}${e.name}</h2>
        <span class="flex flex-wrap gap-2"><span class="chip ${e.is_active?"chip-ok":"chip-gray"}">${e.is_active?"Active":"Not active"}</span><span class="chip ${a?"chip-brand":"chip-warn"}">${a?"Production":"Test mode"}</span>
        ${e.last_test_ok===null?"":l`<span class="chip ${e.last_test_ok?"chip-ok":"chip-bad"}" title="${e.last_test_message||""}">Last test: ${e.last_test_ok?"passed":"failed"} ${e.last_tested_at?b(e.last_tested_at):""}</span>`}</span></div>

      <div class="switch-row"><div><b>Accept payments through this gateway</b><small>Customers see the QR payment page only while a gateway is active.</small></div><label class="switch"><input type="checkbox" name="is_active" ${e.is_active?"checked":""} aria-label="Gateway active"><span></span></label></div>

      <div class="form-section"><h3>Environment</h3>
        <div class="seg" role="radiogroup" aria-label="Environment">
          <button type="button" role="radio" data-env="test" aria-checked="${a?"false":"true"}">Test</button>
          <button type="button" role="radio" data-env="production" aria-checked="${a?"true":"false"}">Production</button></div>
        <input type="hidden" name="environment" value="${e.environment}">
        <p class="field-hint">Test mode is only a label for you — it does not change the gateway URL. Use your provider’s sandbox URL while testing, then switch both to production.</p></div>

      <div class="form-section"><h3>Connection</h3>
        <div class="field-row"><div class="field"><label class="label req" for="gw-url">Gateway base URL</label>
          <input class="input mono" id="gw-url" name="base_url" value="${e.base_url}" placeholder="https://your-alpha-upi-host" spellcheck="false" autocapitalize="none">
          <p class="field-hint">Without a trailing <span class="kbd">/api</span>. Must be https:// in production.</p></div>
        <div class="field"><label class="label" for="gw-pref">Preferred UPI provider</label>
          <select class="select" id="gw-pref" name="provider_pref"><option value="auto" ${e.provider_pref==="auto"?"selected":""}>Automatic</option><option value="bharatpe" ${e.provider_pref==="bharatpe"?"selected":""}>BharatPe</option><option value="paytm" ${e.provider_pref==="paytm"?"selected":""}>Paytm</option></select></div></div>
        ${f({name:"api_key",label:"API key",has:e.has_api_key,hint:e.api_key_hint,placeholder:"Paste the API key from your Alpha UPI dashboard",help:"Stored encrypted on the server. It is never shown again after saving."})}
        ${f({name:"webhook_secret",label:"Webhook secret",has:e.has_webhook_secret,hint:e.webhook_secret_hint,placeholder:"Paste the webhook secret",help:"Used to verify that payment notifications really come from the gateway."})}
      </div>

      <div class="form-actions"><span class="spacer text-sm text-muted">${e.last_tested_at?"Last tested "+P(e.last_tested_at):"Not tested yet"}</span>
        <button type="button" class="btn" id="gw-test">${o("activity","icon-sm")}<span>Test connection</span></button>
        <button type="submit" class="btn btn-primary btn-shine" id="gw-save">${o("check")}<span>Save gateway</span></button></div>
      <div id="gw-result" class="mt-4" aria-live="polite">${i?S(i):""}</div>
    </section>
  </form>

  <section class="card mt-5" aria-labelledby="h-wh"><div class="card-head"><h2 class="card-title" id="h-wh">${o("webhook")}Webhook</h2>
    ${e.last_webhook?l`<span class="chip ${e.last_webhook.signature_valid?"chip-ok":"chip-bad"}">Last delivery ${b(e.last_webhook.at)} · ${e.last_webhook.signature_valid?e.last_webhook.status:"bad signature"}</span>`:l`<span class="chip chip-gray">No deliveries yet</span>`}</div>
    <p class="text-sm text-ink-soft mb-3">Give this URL to your payment gateway as the webhook (callback) endpoint. Payments are confirmed by the gateway’s signed notification and double-checked with the gateway before an order is marked paid.</p>
    <div class="copy-field"><input class="input" readonly value="${e.webhook_url}" aria-label="Webhook URL" id="wh-url"><button type="button" class="btn" data-copy-url>${o("copy","icon-sm")}<span>Copy</span></button></div>
    <ol class="mt-4 grid gap-1.5 text-[13px] text-muted list-decimal pl-5"><li>Open your Alpha UPI dashboard and find the webhook / callback setting.</li><li>Paste the URL above and use the same webhook secret you saved here.</li><li>Press “Test connection” — then make a ₹1–₹5 test payment from the store to see a delivery appear here.</li></ol>
    ${e.webhook_url.startsWith("http://")?l`<div class="callout callout-warning mt-4">${o("triangle-alert")}<div><strong>This URL is not HTTPS.</strong> Real gateways usually require a public https:// URL. Set <span class="kbd">APP_URL</span> to your https:// domain in <span class="kbd">.env</span>.</div></div>`:""}
  </section>

  <section class="card mt-5" aria-labelledby="h-ref"><div class="card-head"><h2 class="card-title" id="h-ref">${o("undo-2")}Refunds</h2></div>
    <p class="text-sm text-ink-soft">Alpha UPI has no refund API, so refunds are made by hand from your bank or UPI app and then recorded on the order (<b>Orders → open an order → Record refund</b>). Reports subtract recorded refunds from revenue and profit.</p></section>`),x(c()),c().setAttribute("aria-busy","false")}function R(){let t=d("#gw-form"),i={base_url:t.elements.base_url.value,provider_pref:t.elements.provider_pref.value,environment:t.elements.environment.value,is_active:t.elements.is_active.checked};for(let a of["api_key","webhook_secret"]){let s=t.elements[a];s&&!s.disabled&&s.value.trim()&&(i[a]=s.value.trim())}return i}async function I(){try{w((await _("gateway")).gateway)}catch(i){c().innerHTML=U(i.message).toString(),c().setAttribute("aria-busy","false");return}let t=c();T(t),p(t,"click","[data-env]",(i,a)=>{d('[name="environment"]').value=a.dataset.env,t.querySelectorAll("[data-env]").forEach(s=>{let g=s===a;s.setAttribute("aria-checked",String(g))})}),p(t,"click","[data-copy-url]",()=>A(e.webhook_url,"Webhook URL copied")),p(t,"click","[data-retry]",()=>location.reload()),t.addEventListener("submit",async i=>{i.preventDefault(),L(t),await m(d("#gw-save"),async()=>{try{let a=await u(`gateway/${e.id}`,R(),{idempotency:!0});h("Payment gateway saved",a.gateway.is_active?"The gateway is active.":"The gateway is not active yet."),w(a.gateway),v()}catch(a){a.fields?(y(t,a.fields),n("Please check the highlighted fields",a.message)):n("Could not save",a.message)}})}),p(t,"click","#gw-test",async(i,a)=>{await m(a,async()=>{try{let s=R();(s.base_url!==e.base_url||s.api_key||s.webhook_secret||s.provider_pref!==e.provider_pref)&&await u(`gateway/${e.id}`,{...s,is_active:e.is_active},{idempotency:!0});let r=await $(`gateway/${e.id}/test`);w(r.gateway,r.result),v(),(r.result.ok?r.result.level==="ok"?h:k:n)(r.result.ok?r.result.level==="ok"?"Connection test passed":"Gateway reachable":"Connection test failed",r.result.message)}catch(s){s.fields?(y(t,s.fields),n("Please check the highlighted fields",s.message)):n("Test failed",s.message)}})})}export{I as default};
