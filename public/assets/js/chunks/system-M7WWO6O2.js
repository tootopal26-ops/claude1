import{b as h}from"./chunk-NGUMD7B4.js";import{f as l,j as y,q as $,r as b,v as f}from"./chunk-O325ZPNA.js";import{b as d,c as u}from"./chunk-CUNUMIEQ.js";import"./chunk-F5XV67FX.js";import{b as p,c as m}from"./chunk-77GGHPHY.js";import"./chunk-FHXP7Q2I.js";import{a as v,e as i,h as o,m as t}from"./chunk-3IHOMMDJ.js";var k=null,c=()=>v("#system-root"),r=(e,n,s="")=>i`<div class="health-item ${e?"ok":"bad"}">${t(e?"circle-check":"circle-alert")}<span><b>${n}</b>${s?i`<br><small class="text-muted">${s}</small>`:""}</span></div>`;function g(e){k=e;let n=e.cron.age_seconds,s=n!==null&&n<=900;c().innerHTML=String(i`
  <div class="stack-lg">
    <section class="card" aria-labelledby="h-env"><div class="card-head"><h2 class="card-title" id="h-env">${t("server")}Server</h2></div>
      <div class="health">
        ${r(e.php_ok,"PHP "+e.php,e.php_ok?"Meets the 8.1+ requirement":"PHP 8.1 or newer is required")}
        ${r(!0,"Database",e.database)}
        ${r(e.https,e.https?"HTTPS enabled":"Not using HTTPS",e.https?"Secure cookies and HSTS are active":"Set APP_URL to your https:// address in .env before going live")}
        ${r(e.env==="production"&&!e.debug,"Environment: "+e.env,e.debug?"Debug output is ON \u2014 turn APP_DEBUG off in production":"Debug output is off")}
        ${e.extensions.map(a=>r(a.ok||!a.required,`PHP ${a.name}`,a.ok?"loaded":a.required?"MISSING \u2014 enable it in cPanel \u2192 Select PHP Version":"optional (faster password hashing)"))}
        ${e.paths.map(a=>r(a.ok,a.path,a.ok?"writable":"NOT writable \u2014 set permissions to 755/775"))}
        ${r(!0,"Business time zone",e.timezone)}
      </div>
      <p class="field-hint mt-3">Site address: <span class="mono">${e.app_url||"not set"}</span> · Server: ${e.server}</p>
    </section>

    <section class="card" aria-labelledby="h-cron"><div class="card-head"><h2 class="card-title" id="h-cron">${t("clock")}Background tasks (cron)</h2>
      <span class="chip ${s?"chip-ok":"chip-warn"}">${e.cron.last_run_at?"Last run "+y(e.cron.last_run_at):"Never run"}</span></div>
      <p class="text-sm text-ink-soft mb-3">The cron job expires unpaid payment sessions, retries and syncs provider orders, refreshes yesterday’s reports and cleans old data. Run it <b>every minute</b>.</p>
      <div class="field"><span class="label">cPanel → Cron Jobs → command</span>
        <div class="copy-field"><input class="input" readonly value="* * * * * ${e.cron.command} >/dev/null 2>&1" aria-label="Cron command"><button type="button" class="btn" data-copy="* * * * * ${e.cron.command} >/dev/null 2>&1">${t("copy","icon-sm")}<span>Copy</span></button></div></div>
      ${e.cron.http_enabled?i`<div class="field"><span class="label">Or ping this URL every minute (cron-job.org, UptimeRobot…)</span><div class="copy-field"><input class="input" readonly value="${e.cron.url}" aria-label="Cron URL"><button type="button" class="btn" data-copy="${e.cron.url}">${t("copy","icon-sm")}<span>Copy</span></button></div></div>`:i`<p class="field-hint">No HTTP cron key is set. Add <span class="kbd">CRON_KEY=some-long-random-text</span> to <span class="kbd">.env</span> to enable the URL variant.</p>`}
      <div class="form-actions"><button type="button" class="btn" id="run-cron">${t("refresh-cw","icon-sm")}<span>Run background tasks now</span></button></div></section>

    <section class="card" aria-labelledby="h-db"><div class="card-head"><h2 class="card-title" id="h-db">${t("database")}Database updates</h2>
      <span class="chip ${e.migrations.pending.length?"chip-warn":"chip-ok"}">${e.migrations.pending.length?e.migrations.pending.length+" pending":"Up to date"}</span></div>
      <p class="text-sm text-ink-soft mb-3">${e.migrations.pending.length?i`A newer release needs these changes: <span class="mono">${e.migrations.pending.join(", ")}</span>. Take a backup, then apply them.`:i`${e.migrations.applied} update${e.migrations.applied===1?" has":"s have"} been applied. After uploading a newer release, come back here (or run <span class="kbd">php bin/migrate.php</span>) if it ships new files in <span class="kbd">database/migrations/</span>.`}</p>
      <div class="form-actions"><button type="button" class="btn ${e.migrations.pending.length?"btn-primary":""}" id="run-migrate" ${e.migrations.pending.length?"":"disabled"}>${t("database","icon-sm")}<span>Apply database updates</span></button></div></section>

    <section class="card" aria-labelledby="h-q"><div class="card-head"><h2 class="card-title" id="h-q">${t("activity")}Queues</h2></div>
      <div class="stat-grid-4">
        <div class="stat"><small>Open payment sessions</small><strong>${l(e.queue.payments_open)}</strong><span>waiting for the customer</span></div>
        <div class="stat"><small>AUTO orders to submit</small><strong>${l(e.queue.orders_submit_due)}</strong><span>paid, not yet at the provider</span></div>
        <div class="stat"><small>Orders at the provider</small><strong>${l(e.queue.orders_in_flight)}</strong><span>being status-checked</span></div>
        <div class="stat"><small>Webhook problems (24 h)</small><strong class="${e.queue.webhook_errors_24h?"amt-neg":""}">${l(e.queue.webhook_errors_24h)}</strong><span>invalid or failed deliveries</span></div></div></section>
  </div>`),c().setAttribute("aria-busy","false")}async function w(){try{g((await d("system")).system)}catch(n){c().innerHTML=$(n.message).toString(),c().setAttribute("aria-busy","false");return}let e=c();o(e,"click","[data-copy]",(n,s)=>f(s.dataset.copy,"Copied")),o(e,"click","[data-retry]",()=>location.reload()),o(e,"click","#run-migrate",async(n,s)=>{await b(s,async()=>{try{let a=await u("system/migrate");p(a.message),g((await d("system")).system),h()}catch(a){m("Could not apply the update",a.message)}})}),o(e,"click","#run-cron",async(n,s)=>{await b(s,async()=>{try{let a=await u("system/cron");p(a.message),g((await d("system")).system),h()}catch(a){m("Could not run tasks",a.message)}})})}export{w as default};
