// Bundles resources/js/* → public/assets/js (ES modules, minified, code-split). Committed output: no build on cPanel.
import { build } from 'esbuild';
import { rmSync, existsSync } from 'node:fs';

const entries = {
  site: 'resources/js/site.js',
  track: 'resources/js/track.js',
  pay: 'resources/js/pay.js',
  order: 'resources/js/order.js',
};
for (const [name, file] of Object.entries({ admin: 'resources/js/admin.js', 'admin-login': 'resources/js/admin-login.js', install: 'resources/js/install.js' })) {
  if (existsSync(file)) entries[name] = file;
}
rmSync('public/assets/js', { recursive: true, force: true });
const r = await build({
  entryPoints: entries, bundle: true, splitting: true, format: 'esm', outdir: 'public/assets/js', minify: true,
  target: ['es2020'], chunkNames: 'chunks/[name]-[hash]', legalComments: 'none', metafile: true, logLevel: 'warning',
});
for (const [f, m] of Object.entries(r.metafile.outputs)) console.log(f.padEnd(52), (m.bytes / 1024).toFixed(1) + ' KB');
