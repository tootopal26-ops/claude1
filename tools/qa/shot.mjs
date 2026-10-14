// node tools/qa/shot.mjs <url> <out.png> [width=1440] [height=900] [--full] [--wait=ms] [--click=selector] [--type=selector::text]
import { launch } from './browser.mjs';
const args = process.argv.slice(2);
const flags = Object.fromEntries(args.filter((a) => a.startsWith('--')).map((a) => { const [k, ...v] = a.slice(2).split('='); return [k, v.join('=') || true]; }));
const [url, out, w = '1440', h = '900'] = args.filter((a) => !a.startsWith('--'));
const browser = await launch();
const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 1, userAgent: flags.mobile ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1' : undefined });
const page = await ctx.newPage();
const problems = [];
page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) problems.push(`[console.${m.type()}] ${m.text()}`); });
page.on('pageerror', (e) => problems.push(`[pageerror] ${e.message}`));
page.on('requestfailed', (r) => problems.push(`[requestfailed] ${r.url()} ${r.failure()?.errorText}`));
page.on('response', (r) => { if (r.status() >= 400) problems.push(`[http ${r.status()}] ${r.url()}`); });
await page.goto(url, { waitUntil: 'networkidle' });
if (flags.wait) await page.waitForTimeout(+flags.wait);
for (const c of [].concat(flags.click || [])) { await page.click(c); await page.waitForTimeout(700); }
await page.screenshot({ path: out, fullPage: !!flags.full });
const metrics = await page.evaluate(() => ({ scrollW: document.documentElement.scrollWidth, clientW: document.documentElement.clientWidth, title: document.title }));
console.log(JSON.stringify(metrics), problems.length ? '\nPROBLEMS:\n' + problems.join('\n') : '\nno console/network problems');
await browser.close();
