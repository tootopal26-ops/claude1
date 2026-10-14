// Tiny test harness for the Playwright QA scripts: steps, assertions, failure screenshots, summary + exit code.
import { mkdirSync } from 'node:fs';

export function suite(name, outDir = process.env.QA_OUT || '/tmp/qa') {
  mkdirSync(outDir, { recursive: true });
  const results = [];
  let current = null;
  const api = {
    page: null,
    async step(title, fn) {
      if (process.env.QA_ONLY && !new RegExp(process.env.QA_ONLY, 'i').test(title)) return;   // QA_ONLY=regex runs just the matching steps
      current = title;
      const t0 = Date.now();
      try {
        await fn();
        results.push({ title, ok: true, ms: Date.now() - t0 });
        console.log(`  \x1b[32m✓\x1b[0m ${title} \x1b[2m(${Date.now() - t0} ms)\x1b[0m`);
      } catch (e) {
        results.push({ title, ok: false, error: e.message });
        console.log(`  \x1b[31m✗ ${title}\x1b[0m\n      ${String(e.message).split('\n').slice(0, 4).join('\n      ')}`);
        if (api.page) await api.page.screenshot({ path: `${outDir}/FAIL-${name}-${results.length}.png`, fullPage: false }).catch(() => {});
      }
    },
    eq(actual, expected, msg = '') { if (JSON.stringify(actual) !== JSON.stringify(expected)) throw new Error(`${msg || 'values differ'}\n   expected: ${JSON.stringify(expected)}\n   actual:   ${JSON.stringify(actual)}`); },
    ok(cond, msg = 'condition is false') { if (!cond) throw new Error(msg); },
    has(text, part, msg = '') { if (!String(text).includes(part)) throw new Error(`${msg || 'text mismatch'}\n   expected to include: ${part}\n   actual: ${String(text).slice(0, 300)}`); },
    async visible(locator, msg = '') { if (!(await locator.first().isVisible())) throw new Error(msg || `not visible: ${locator}`); },
    async text(locator) { return (await locator.first().innerText()).replace(/\s+/g, ' ').trim(); },
    finish() {
      const failed = results.filter((r) => !r.ok);
      console.log(`\n${name}: ${results.length - failed.length}/${results.length} passed${failed.length ? `, \x1b[31m${failed.length} failed\x1b[0m` : ''}`);
      return failed.length;
    },
  };
  return api;
}
