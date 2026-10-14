import{b as v}from"./chunk-HYLNTU7X.js";import{f as i,j as h,q as y,r as g,v as $}from"./chunk-ZZ2LXP5P.js";import{b as d,c as b}from"./chunk-QZ2EVAVG.js";import"./chunk-F5XV67FX.js";import{b as u,c as m}from"./chunk-ZPOCOIRG.js";import"./chunk-F47YFC3V.js";import{a as p,e as r,h as l,m as n}from"./chunk-3IHOMMDJ.js";var k=null,c=()=>p("#system-root"),o=(e,t,a="")=>r`<div class="health-item ${e?"ok":"bad"}">${n(e?"circle-check":"circle-alert")}<span><b>${t}</b>${a?r`<br><small class="text-muted">${a}</small>`:""}</span></div>`;function f(e){k=e;let t=e.cron.age_seconds,a=t!==null&&t<=900;c().innerHTML=String(r`
  <div class="stack-lg">
    <section class="card" aria-labelledby="h-env"><div class="card-head"><h2 class="card-title" id="h-env">${n("server")}Server</h2></div>
      <div class="health">
        ${o(e.php_ok,"PHP "+e.php,e.php_ok?"Meets the 8.1+ requirement":"PHP 8.1 or newer is required")}
        ${o(!0,"Database",e.database)}
        ${o(e.https,e.https?"HTTPS enabled":"Not using HTTPS",e.https?"Secure cookies and HSTS are active":"Set APP_URL to your https:// address in .env before going live")}
        ${o(e.env==="production"&&!e.debug,"Environment: "+e.env,e.debug?"Debug output is ON \u2014 turn APP_DEBUG off in production":"Debug output is off")}
        ${e.extensions.map(s=>o(s.ok||!s.required,`PHP ${s.name}`,s.ok?"loaded":s.required?"MISSING \u2014 enable it in cPanel \u2192 Select PHP Version":"optional (faster password hashing)"))}
        ${e.paths.map(s=>o(s.ok,s.path,s.ok?"writable":"NOT writable \u2014 set permissions to 755/775"))}
        ${o(!0,"Business time zone",e.timezone)}
      </div>
      <p class="field-hint mt-3">Site address: <span class="mono">${e.app_url||"not set"}</span> · Server: ${e.server}</p>
    </section>

    <section class="card" aria-labelledby="h-cron"><div class="card-head"><h2 class="card-title" id="h-cron">${n("clock")}Background tasks (cron)</h2>
      <span class="chip ${a?"chip-ok":"chip-warn"}">${e.cron.last_run_at?"Last run "+h(e.cron.last_run_at):"Never run"}</span></div>
      <p class="text-sm text-ink-soft mb-3">The cron job expires unpaid payment sessions, retries and syncs provider orders, refreshes yesterday’s reports and cleans old data. Run it <b>every minute</b>.</p>
      <div class="field"><span class="label">cPanel → Cron Jobs → command</span>
        <div class="copy-field"><input class="input" readonly value="* * * * * ${e.cron.command} >/dev/null 2>&1" aria-label="Cron command"><button type="button" class="btn" data-copy="* * * * * ${e.cron.command} >/dev/null 2>&1">${n("copy","icon-sm")}<span>Copy</span></button></div></div>
      ${e.cron.http_enabled?r`<div class="field"><span class="label">Or ping this URL every minute (cron-job.org, UptimeRobot…)</span><div class="copy-field"><input class="input" readonly value="${e.cron.url}" aria-label="Cron URL"><button type="button" class="btn" data-copy="${e.cron.url}">${n("copy","icon-sm")}<span>Copy</span></button></div></div>`:r`<p class="field-hint">No HTTP cron key is set. Add <span class="kbd">CRON_KEY=some-long-random-text</span> to <span class="kbd">.env</span> to enable the URL variant.</p>`}
      <div class="form-actions"><button type="button" class="btn" id="run-cron">${n("refresh-cw","icon-sm")}<span>Run background tasks now</span></button></div></section>

    <section class="card" aria-labelledby="h-q"><div class="card-head"><h2 class="card-title" id="h-q">${n("activity")}Queues</h2></div>
      <div class="stat-grid-4">
        <div class="stat"><small>Open payment sessions</small><strong>${i(e.queue.payments_open)}</strong><span>waiting for the customer</span></div>
        <div class="stat"><small>AUTO orders to submit</small><strong>${i(e.queue.orders_submit_due)}</strong><span>paid, not yet at the provider</span></div>
        <div class="stat"><small>Orders at the provider</small><strong>${i(e.queue.orders_in_flight)}</strong><span>being status-checked</span></div>
        <div class="stat"><small>Webhook problems (24 h)</small><strong class="${e.queue.webhook_errors_24h?"amt-neg":""}">${i(e.queue.webhook_errors_24h)}</strong><span>invalid or failed deliveries</span></div></div></section>
  </div>`),c().setAttribute("aria-busy","false")}async function _(){try{f((await d("system")).system)}catch(t){c().innerHTML=y(t.message).toString(),c().setAttribute("aria-busy","false");return}let e=c();l(e,"click","[data-copy]",(t,a)=>$(a.dataset.copy,"Copied")),l(e,"click","[data-retry]",()=>location.reload()),l(e,"click","#run-cron",async(t,a)=>{await g(a,async()=>{try{let s=await b("system/cron");u(s.message),f((await d("system")).system),v()}catch(s){m("Could not run tasks",s.message)}})})}export{_ as default};
