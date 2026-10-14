// node tools/qa/admin-shot.mjs <outdir> <width> <height> [page ...]
import { mkdirSync } from 'node:fs';
import { launch, newPage, login, go, layoutReport, BASE } from './admin.mjs';

const [outDir, w = '1440', h = '900', ...pages] = process.argv.slice(2);
mkdirSync(outDir, { recursive: true });
const browser = await launch();
const mobile = Number(w) < 700;
const page = await newPage(browser, { width: Number(w), height: Number(h) }, { mobile, dpr: mobile ? 2 : 1 });
await login(page);
for (const p of pages.length ? pages : ['dashboard']) {
  await go(page, '/admin/' + p, { settle: 1200 });
  await page.screenshot({ path: `${outDir}/${p}-${w}.png`, fullPage: true });
  const rep = await layoutReport(page);
  console.log(p, JSON.stringify(rep));
}
console.log('problems:', page.problems.length ? '\n' + page.problems.join('\n') : 'none');
await browser.close();
