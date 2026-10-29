import{q as M,r as $,s as F,t as N}from"./chunk-3MSMKM7H.js";import{b as D,c as R,d as I}from"./chunk-CUNUMIEQ.js";import{c as B}from"./chunk-F5XV67FX.js";import{b as _,c as g}from"./chunk-77GGHPHY.js";import"./chunk-FHXP7Q2I.js";import{a as o,b as y,e as u,h as i,m as l}from"./chunk-3IHOMMDJ.js";var s=null,c=null,P=()=>o("#appearance-root"),U=t=>t<=.04045?t/12.92:((t+.055)/1.055)**2.4;function j(t){if(!/^#[0-9a-f]{6}$/i.test(t))return null;let[a,e,d]=[1,3,5].map(k=>U(parseInt(t.slice(k,k+2),16)/255)),b=.4122214708*a+.5363325363*e+.0514459929*d,r=.2119034982*a+.6806995451*e+.1073969566*d,p=.0883024619*a+.2817188376*e+.6299787005*d,[h,n,m]=[b,r,p].map(Math.cbrt),f=.86,x=(.2104542553*h+.793617785*n-.0040720468*m)*f,C=(1.9779984951*h-2.428592205*n+.4505937099*m)*f,L=(.0259040371*h+.7827717662*n-.808675766*m)*f,T=(x+.3963377774*C+.2158037573*L)**3,A=(x-.1055613458*C-.0638541728*L)**3,E=(x-.0894841775*C-1.291485548*L)**3,S=k=>Math.max(0,Math.min(1,k)),H=S(4.0767416621*T-3.3077115913*A+.2309699292*E),Y=S(-1.2684380046*T+2.6097574011*A-.3413193965*E),G=S(-.0041960863*T-.7034186147*A+1.707614701*E);return 1.05/(.2126*H+.7152*Y+.0722*G+.05)}var O={compact:"Tighter corners and a lighter glass blur \u2014 denser screens, more rows visible.",standard:"The balanced default used throughout this design.",premium:"Rounder corners and a stronger glass blur \u2014 a more luxurious, spacious feel."};function V(t,a){return u`<button type="button" class="theme-card${a?" is-selected":""}" data-theme-pick="${t.name}" role="radio" aria-checked="${a?"true":"false"}" aria-label="${t.label}">
    <span class="theme-card-preview" data-preview-bg>
      <span class="theme-swatch-row">
        <span class="theme-dot" data-dot="surface"></span><span class="theme-dot" data-dot="accent"></span><span class="theme-dot" data-dot="ink"></span>
      </span>
    </span>
    <span class="theme-card-body"><b>${t.label}</b><small>${t.blurb}</small></span>
    ${a?u`<span class="theme-card-check">${l("check-circle","icon-sm")}</span>`:""}
  </button>`}function w(){P().innerHTML=String(u`
  <div class="grid gap-5">
    ${s.can_revert||s.can_reset?u`<div class="flex justify-end gap-2">
      ${s.can_revert?u`<button type="button" class="btn btn-sm btn-ghost" id="revert">${l("undo-2","icon-sm")}<span>Revert last change</span></button>`:""}
      ${s.can_reset?u`<button type="button" class="btn btn-sm btn-ghost" id="reset">${l("rotate-ccw","icon-sm")}<span>Restore defaults</span></button>`:""}
    </div>`:""}
    <section class="card" aria-labelledby="h-themes">
      <div class="card-head"><h2 class="card-title" id="h-themes">${l("palette")}Theme</h2></div>
      <div class="theme-grid" role="radiogroup" aria-label="Theme">${s.themes.map(t=>V(t,t.name===c.theme))}</div>
    </section>

    <section class="card" aria-labelledby="h-density">
      <div class="card-head"><h2 class="card-title" id="h-density">${l("layout-grid")}Density</h2></div>
      <div class="seg" role="radiogroup" aria-label="Density">
        ${s.densities.map(t=>u`<button type="button" role="radio" aria-checked="${t===c.density?"true":"false"}" data-density-pick="${t}">${t[0].toUpperCase()+t.slice(1)}</button>`)}
      </div>
      <p class="field-hint" data-density-hint>${O[c.density]}</p>
    </section>

    <section class="card" aria-labelledby="h-accent">
      <div class="card-head"><h2 class="card-title" id="h-accent">${l("gem")}Accent colours</h2></div>
      <div class="field-row">
        <div class="field"><label class="label" for="ap-from">Accent — start</label>
          <div class="color-field"><input type="color" id="ap-from-c" value="${c.accent_from}" aria-label="Pick colour"><input class="input input-sm mono" id="ap-from" value="${c.accent_from}" maxlength="7" spellcheck="false"></div>
          <p class="field-hint" data-contrast="from"></p></div>
        <div class="field"><label class="label" for="ap-to">Accent — end</label>
          <div class="color-field"><input type="color" id="ap-to-c" value="${c.accent_to}" aria-label="Pick colour"><input class="input input-sm mono" id="ap-to" value="${c.accent_to}" maxlength="7" spellcheck="false"></div>
          <p class="field-hint" data-contrast="to"></p></div>
      </div>
      <p class="field-hint">Switching theme resets these to that theme's own default — edit them afterwards for a custom brand gradient.</p>
    </section>

    <section class="card" aria-labelledby="h-preview">
      <div class="card-head"><h2 class="card-title" id="h-preview">${l("eye")}Live preview</h2>
        <div class="seg" role="radiogroup" aria-label="Preview viewport">
          <button type="button" role="radio" aria-checked="true" data-viewport="desktop">${l("monitor","icon-sm")}</button>
          <button type="button" role="radio" aria-checked="false" data-viewport="tablet">${l("tablet","icon-sm")}</button>
          <button type="button" role="radio" aria-checked="false" data-viewport="mobile">${l("smartphone","icon-sm")}</button>
        </div>
      </div>
      <div class="theme-preview-wrap" data-viewport-box="desktop">
        <div class="theme-preview" id="theme-preview">
          <div class="theme-preview-bar glass-strong"><b>SOCIAL MEDIA BOOSTER</b></div>
          <div class="theme-preview-card glass">
            <div class="theme-preview-row"><span class="theme-preview-title">100 Likes</span><span class="theme-preview-price">₹59</span></div>
            <p class="theme-preview-sub">Instagram Post Likes</p>
            <button type="button" class="btn btn-success btn-block" tabindex="-1">BUY NOW</button>
          </div>
        </div>
      </div>
      <p class="field-hint">This preview updates instantly as you pick — nothing is applied to the live site until you save.</p>
    </section>

    <div class="savebar" id="savebar" hidden><span class="text-sm font-semibold">You have unsaved appearance changes</span>
      <span class="flex gap-2">
        <button type="button" class="btn btn-sm" id="discard">Discard</button>
        <button type="button" class="btn btn-sm btn-primary" id="save">${l("check","icon-sm")}<span>Save & publish</span></button>
      </span></div>
  </div>`),v()}function v(){y(".theme-card").forEach(r=>{let p=r.dataset.themePick===c.theme;r.classList.toggle("is-selected",p),r.setAttribute("aria-checked",String(p))}),y("[data-density-pick]").forEach(r=>r.setAttribute("aria-checked",String(r.dataset.densityPick===c.density)));let t=o("[data-density-hint]");t&&(t.textContent=O[c.density]);let a=s.themes.find(r=>r.name===c.theme);y(".theme-card").forEach(r=>{let p=s.themes.find(f=>f.name===r.dataset.themePick);o("[data-preview-bg]",r).style.setProperty("background",p.swatch.canvas);let h=o('[data-dot="surface"]',r),n=o('[data-dot="accent"]',r),m=o('[data-dot="ink"]',r);h.style.setProperty("background",p.swatch.surface),n.style.setProperty("background",p.accent_from),m.style.setProperty("background",p.swatch.ink)});for(let[r,p]of[["from","ap-from"],["to","ap-to"]]){let h=o(`#${p}`).value.toLowerCase(),n=j(h),m=o(`[data-contrast="${r}"]`);m.textContent=n===null?"Enter a colour like #ff2d87.":n>=4.5?`Button text contrast ${n.toFixed(1)}:1 \u2014 readable.`:`Button text contrast ${n.toFixed(1)}:1 \u2014 too light, pick a deeper shade (needs 4.5:1).`,m.className="field-hint "+(n===null||n<4.5?"text-bad-700 font-semibold":"text-ok-800")}let e=o("#theme-preview");if(e&&a){let r=a.swatch;e.style.setProperty("--color-canvas",r.canvas),e.style.setProperty("--color-surface",r.surface),e.style.setProperty("--color-ink",r.ink),e.style.setProperty("--color-muted",r.muted),e.style.setProperty("--color-line",r.line),e.style.setProperty("--accent-from",o("#ap-from").value),e.style.setProperty("--accent-to",o("#ap-to").value),e.style.setProperty("--color-brand-500",o("#ap-from").value),e.style.setProperty("background",r.canvas),e.style.setProperty("color",r.ink),r["glass-bg"]?(e.style.setProperty("--glass-bg",r["glass-bg"]),e.style.setProperty("--glass-bg-strong",r["glass-bg"])):(e.style.removeProperty("--glass-bg"),e.style.removeProperty("--glass-bg-strong")),r["glass-border"]?(e.style.setProperty("--glass-border",r["glass-border"]),e.style.setProperty("--glass-border-strong",r["glass-border"])):(e.style.removeProperty("--glass-border"),e.style.removeProperty("--glass-border-strong"))}let d=c.theme!==s.theme||c.density!==s.density||c.accent_from.toLowerCase()!==s.accent_from.toLowerCase()||c.accent_to.toLowerCase()!==s.accent_to.toLowerCase(),b=o("#savebar");b&&(b.hidden=!d)}function W(t){let a=s.themes.find(e=>e.name===t);a&&(c.theme=t,c.accent_from=a.accent_from,c.accent_to=a.accent_to,o("#ap-from").value=a.accent_from,o("#ap-from-c").value=a.accent_from,o("#ap-to").value=a.accent_to,o("#ap-to-c").value=a.accent_to,v())}async function q(t){await $(t,async()=>{N(P());try{s=(await I("appearance",c,{idempotency:!0})).appearance,c={theme:s.theme,density:s.density,accent_from:s.accent_from,accent_to:s.accent_to},_("Appearance saved","The new theme is live on the store and admin panel."),w()}catch(a){a.fields?(F(P(),a.fields),g("Please check the highlighted fields",a.message)):g("Could not save",a.message)}})}async function z(){let t=P();try{s=(await D("appearance")).appearance}catch(a){t.innerHTML=M(a.message).toString(),t.setAttribute("aria-busy","false");return}c={theme:s.theme,density:s.density,accent_from:s.accent_from,accent_to:s.accent_to},w(),t.setAttribute("aria-busy","false"),i(t,"click","[data-theme-pick]",(a,e)=>W(e.dataset.themePick)),i(t,"click","[data-density-pick]",(a,e)=>{c.density=e.dataset.densityPick,v()}),i(t,"click","[data-viewport]",(a,e)=>{y("[data-viewport]").forEach(d=>d.setAttribute("aria-checked",String(d===e))),o("[data-viewport-box]").dataset.viewportBox=e.dataset.viewport}),i(t,"input","#ap-from-c",(a,e)=>{o("#ap-from").value=e.value,c.accent_from=e.value,v()}),i(t,"input","#ap-to-c",(a,e)=>{o("#ap-to").value=e.value,c.accent_to=e.value,v()}),i(t,"input","#ap-from",(a,e)=>{/^#[0-9a-f]{6}$/i.test(e.value)&&(o("#ap-from-c").value=e.value),c.accent_from=e.value,v()}),i(t,"input","#ap-to",(a,e)=>{/^#[0-9a-f]{6}$/i.test(e.value)&&(o("#ap-to-c").value=e.value),c.accent_to=e.value,v()}),i(t,"click","#discard",()=>{c={theme:s.theme,density:s.density,accent_from:s.accent_from,accent_to:s.accent_to},w()}),i(t,"click","#save",(a,e)=>q(e)),i(t,"click","#reset",async(a,e)=>{await B({title:"Restore the default appearance?",message:"Rose Glass, standard density and the default accent colours.",confirmText:"Restore defaults"})&&await $(e,async()=>{try{s=(await R("appearance/reset",{},{idempotency:!0})).appearance,c={theme:s.theme,density:s.density,accent_from:s.accent_from,accent_to:s.accent_to},_("Restored the default appearance"),w()}catch(b){g("Could not reset",b.message)}})}),i(t,"click","#revert",async(a,e)=>{await $(e,async()=>{try{s=(await R("appearance/revert",{},{idempotency:!0})).appearance,c={theme:s.theme,density:s.density,accent_from:s.accent_from,accent_to:s.accent_to},_("Reverted to the previous appearance"),w()}catch(d){g("Could not revert",d.message)}})}),window.addEventListener("beforeunload",a=>{o("#savebar")&&!o("#savebar").hidden&&(a.preventDefault(),a.returnValue="")})}export{z as default};
