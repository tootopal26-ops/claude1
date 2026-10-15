// Builds the raster brand assets from the ORIGINAL files the project owner supplied in resources/brand/ (logo.webp, instagram.png, youtube.png):
//   1. python3 tools/build-brand-images.py → logo-mark.webp, favicon-32/48.png, favicon.ico, apple-touch-icon.png, icon-192/512.png,
//      icon-maskable-512.png, platforms/instagram.webp + youtube.webp   (crops / resizes only — the artwork is never redrawn)
//   2. the pre-installed Chromium lays out the social-sharing image public/assets/img/og-default.png (1200×630) around the real logo.
// Run:  node tools/build-brand-assets.mjs     (the results are committed; cPanel needs no build step)
import { readFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { launch } from './qa/browser.mjs';

const root = new URL('..', import.meta.url).pathname;
const img = `${root}public/assets/img`;
const b64 = (f) => readFileSync(f).toString('base64');
const uri = (f, mime) => `data:${mime};base64,${b64(f)}`;

execFileSync('python3', ['-I', `${root}tools/build-brand-images.py`], { stdio: 'inherit' });

const lockup = uri(`${root}resources/brand/logo.webp`, 'image/webp');
const ig = uri(`${root}resources/brand/instagram.png`, 'image/png');
const yt = uri(`${root}resources/brand/youtube.png`, 'image/png');
const font = uri(`${root}public/assets/fonts/inter-latin.woff2`, 'font/woff2');
const rupee = uri(`${root}public/assets/fonts/inter-rupee.woff2`, 'font/woff2');

const og = `<!doctype html><meta charset="utf-8"><style>
@font-face{font-family:Inter;src:url(${font}) format("woff2");font-weight:100 900;unicode-range:U+0000-00FF,U+2000-206F,U+2212}
@font-face{font-family:Inter;src:url(${rupee}) format("woff2");font-weight:100 900;unicode-range:U+20B9}
*{box-sizing:border-box;margin:0}body{font-family:Inter,system-ui,sans-serif;-webkit-font-smoothing:antialiased;width:1200px;height:630px;position:relative;overflow:hidden;background:#fff;
 background-image:radial-gradient(700px 460px at 4% -8%,rgba(255,45,135,.22),transparent 62%),radial-gradient(620px 420px at 100% 0%,rgba(255,59,59,.16),transparent 60%),radial-gradient(640px 400px at 60% 118%,rgba(168,85,247,.16),transparent 62%)}
.card{position:absolute;inset:40px;border-radius:44px;background:#fefefe;border:2px solid #fff;box-shadow:0 30px 80px -30px rgba(15,16,32,.35),inset 0 2px 0 #fff;display:flex;align-items:center;gap:20px;padding:0 64px 0 28px}
.logo{width:500px;height:500px;flex:none}
h1{font-size:60px;line-height:1.04;font-weight:900;letter-spacing:-.03em;background:linear-gradient(100deg,#e01070,#ff2d4a 70%);-webkit-background-clip:text;background-clip:text;color:transparent}
p{margin-top:20px;font-size:28px;line-height:1.38;font-weight:500;color:#2b2d44}
.row{display:flex;align-items:center;gap:12px;margin-top:30px}
.row img{width:56px;height:56px;border-radius:50%;box-shadow:0 8px 20px -8px rgba(15,16,32,.45)}
.pill{padding:12px 20px;border-radius:999px;font-size:21px;font-weight:700;white-space:nowrap;background:#fff;border:2px solid #e8e9f0;color:#15162a}
.pill.hot{background:#15803d;border-color:#15803d;color:#fff}
</style>
<div class="card"><img class="logo" src="${lockup}">
<div><h1>Instagram &amp; YouTube growth</h1><p>Followers, likes, views and subscribers. Pay with UPI and start in minutes.</p>
<div class="row"><img src="${ig}"><img src="${yt}"><span class="pill hot">Trial pack ₹5</span><span class="pill">Secure UPI</span></div></div></div>`;

mkdirSync(img, { recursive: true });
const browser = await launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
await page.setContent(og, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);
await page.screenshot({ path: `${img}/og-default.png` });
await page.close();
await browser.close();

// shrink the PNG: palette quantisation with dithering keeps the glossy gradients smooth at this size
execFileSync('python3', ['-I', '-c', `
from PIL import Image
p="${img}/og-default.png"
im=Image.open(p).convert("RGB").quantize(colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.FLOYDSTEINBERG)
im.save(p, optimize=True)
import os; print("wrote public/assets/img/og-default.png  %.0f KB" % (os.path.getsize(p)/1024))
`], { stdio: 'inherit' });
