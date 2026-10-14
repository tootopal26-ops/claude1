// Renders the raster brand assets from the SVG sources with the pre-installed Chromium:
//   public/assets/img/og-default.png (1200×630), apple-touch-icon.png (180), icon-192.png, icon-512.png, icon-maskable-512.png,
//   public/favicon.ico (16/32/48)
// Run:  node tools/build-brand-assets.mjs     (the PNGs are committed; cPanel needs no build step)
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { launch } from './qa/browser.mjs';

const root = new URL('..', import.meta.url).pathname;
const img = `${root}public/assets/img`;
const b64 = (f) => readFileSync(f).toString('base64');
const svgUri = (f) => `data:image/svg+xml;base64,${b64(f)}`;
const logo = svgUri(`${img}/logo-mark.svg`);
const ig = svgUri(`${img}/platforms/instagram.svg`);
const yt = svgUri(`${img}/platforms/youtube.svg`);
const font = `data:font/woff2;base64,${b64(`${root}public/assets/fonts/inter-latin.woff2`)}`;
const rupee = `data:font/woff2;base64,${b64(`${root}public/assets/fonts/inter-rupee.woff2`)}`;

const base = `<style>
@font-face{font-family:Inter;src:url(${font}) format("woff2");font-weight:100 900;unicode-range:U+0000-00FF,U+2000-206F,U+2212}
@font-face{font-family:Inter;src:url(${rupee}) format("woff2");font-weight:100 900;unicode-range:U+20B9}
*{box-sizing:border-box;margin:0}body{font-family:Inter,system-ui,sans-serif;-webkit-font-smoothing:antialiased}</style>`;

const og = `<!doctype html><meta charset="utf-8">${base}
<style>
body{width:1200px;height:630px;position:relative;overflow:hidden;background:#fff;
 background-image:radial-gradient(700px 460px at 4% -8%,rgba(255,45,135,.22),transparent 62%),radial-gradient(620px 420px at 100% 0%,rgba(255,59,59,.16),transparent 60%),radial-gradient(640px 400px at 60% 118%,rgba(168,85,247,.16),transparent 62%)}
.card{position:absolute;inset:44px;border-radius:44px;background:rgba(255,255,255,.72);border:2px solid rgba(255,255,255,.9);box-shadow:0 30px 80px -30px rgba(15,16,32,.35),inset 0 2px 0 #fff;display:flex;align-items:center;gap:36px;padding:0 64px 0 48px}
.ring{position:relative;width:340px;height:340px;flex:none;border-radius:50%;padding:7px;background:conic-gradient(from 20deg,#ff2d87,#ff3b3b,#ff9a3d,#d946ef,#ff2d87);box-shadow:0 0 70px rgba(255,45,135,.38)}
.ring>div{width:100%;height:100%;border-radius:50%;background:#fff;display:grid;place-items:center}
.ring img{width:92%;height:92%}
h1{font-size:92px;line-height:.98;font-weight:900;letter-spacing:-.035em;text-transform:uppercase;background:linear-gradient(100deg,#e01070,#ff2d4a 70%);-webkit-background-clip:text;background-clip:text;color:transparent}
p{margin-top:22px;font-size:31px;line-height:1.35;font-weight:500;color:#2b2d44;max-width:600px}
.row{display:flex;align-items:center;gap:14px;margin-top:30px}
.row img{width:62px;height:62px;border-radius:50%;box-shadow:0 8px 20px -8px rgba(15,16,32,.45)}
.pill{padding:13px 22px;border-radius:999px;font-size:23px;font-weight:700;background:#fff;border:2px solid #e8e9f0;color:#15162a}
.pill.hot{background:#15803d;border-color:#15803d;color:#fff}
</style>
<div class="card"><div class="ring"><div><img src="${logo}"></div></div>
<div><h1>Social Media<br>Booster</h1><p>Instagram &amp; YouTube growth — followers, likes, views and subscribers. Pay with UPI, start in minutes.</p>
<div class="row"><img src="${ig}"><img src="${yt}"><span class="pill hot">Trial pack ₹5</span><span class="pill">Secure UPI</span></div></div></div>`;

const icon = (size, pad, bg) => `<!doctype html><meta charset="utf-8">${base}
<style>body{width:${size}px;height:${size}px;display:grid;place-items:center;background:${bg}}img{width:${100 - pad * 2}%;height:${100 - pad * 2}%}</style><img src="${logo}">`;

const browser = await launch();
const shoot = async (html, w, h, out, opts = {}) => {
  const page = await browser.newPage({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
  await page.setContent(html, { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: out, omitBackground: !!opts.transparent });
  await page.close();
  console.log('wrote', out.replace(root, ''));
};
mkdirSync(img, { recursive: true });
await shoot(og, 1200, 630, `${img}/og-default.png`);
await shoot(icon(180, 8, '#ffffff'), 180, 180, `${img}/apple-touch-icon.png`);
await shoot(icon(192, 6, '#ffffff'), 192, 192, `${img}/icon-192.png`);
await shoot(icon(512, 6, '#ffffff'), 512, 512, `${img}/icon-512.png`);
await shoot(icon(512, 16, '#ffffff'), 512, 512, `${img}/icon-maskable-512.png`);   // maskable: content inside the 80% safe zone
// favicon.ico (16/32/48) from the small-size favicon.svg
const fav = svgUri(`${img}/favicon.svg`);
for (const s of [16, 32, 48]) await shoot(`<!doctype html><body style="margin:0;background:transparent"><img src="${fav}" width="${s}" height="${s}" style="display:block">`, s, s, `/tmp/fav-${s}.png`, { transparent: true });
await browser.close();
execFileSync('python3', ['-I', '-c', `
from PIL import Image
ims=[Image.open(f"/tmp/fav-{s}.png").convert("RGBA") for s in (16,32,48)]
ims[2].save("${root}public/favicon.ico", format="ICO", sizes=[(16,16),(32,32),(48,48)], append_images=ims[:2])
# compress the big PNGs (palette quantisation keeps gradients smooth at this size)
for f in ("og-default.png",):
    p="${img}/"+f
    im=Image.open(p).convert("RGB").quantize(colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.FLOYDSTEINBERG)
    im.save(p, optimize=True)
`]);
console.log('wrote public/favicon.ico');
