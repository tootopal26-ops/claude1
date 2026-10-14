// Render SVG/HTML files to PNG with the pre-installed Chromium:  node tools/qa/render-svg.mjs out.png size in1.svg [in2.svg …]  (contact sheet)
import { launch } from './browser.mjs';
import { readFileSync, writeFileSync } from 'node:fs';
const [out, size, ...files] = process.argv.slice(2);
const s = Number(size || 256);
const html = `<!doctype html><body style="margin:0;background:#f3f3f7;display:flex;gap:16px;padding:16px;flex-wrap:wrap">${files.map((f) =>
  `<div style="background:#fff;border-radius:12px;padding:8px;box-shadow:0 1px 4px #0002"><img src="data:image/svg+xml;base64,${readFileSync(f).toString('base64')}" width="${s}" height="${s}"><div style="font:11px sans-serif;color:#666;text-align:center">${f.split('/').pop()}</div></div>`).join('')}</body>`;
const browser = await launch();
const page = await browser.newPage({ viewport: { width: Math.min(1600, files.length * (s + 50) + 16), height: s + 90 } });
await page.setContent(html);
await page.screenshot({ path: out, fullPage: true });
await browser.close();
console.log('wrote', out);
