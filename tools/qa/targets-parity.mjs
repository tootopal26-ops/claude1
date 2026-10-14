// Verifies resources/js/site/targets.js against the SAME fixtures the PHP implementation passes.
//   node tools/qa/targets-parity.mjs
import { readFileSync } from 'node:fs';
import { validateTarget } from '../../resources/js/site/targets.js';

const cases = JSON.parse(readFileSync(new URL('../../tests/fixtures/targets.json', import.meta.url), 'utf8'));
let bad = 0;
for (const c of cases) {
  const r = validateTarget(c.platform, c.kind, c.input);
  const same = r.ok === c.ok && (!c.ok || (r.url === c.url && r.display === c.display));
  if (!same) {
    bad++;
    console.log(`✗ ${c.platform}/${c.kind} ${JSON.stringify(c.input)}\n    expected ${c.ok ? c.url : 'reject'}  got ${r.ok ? r.url : 'reject (' + r.error + ')'}`);
  }
}
console.log(bad === 0 ? `✓ JS matches PHP on all ${cases.length} fixtures` : `${bad} of ${cases.length} fixtures differ`);
process.exit(bad ? 1 : 0);
