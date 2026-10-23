#!/usr/bin/env node
// Mini Max shows every amount as coins, not rupees, and the one coin
// graphic is always <CoinAsset> (see src/components/CoinAsset.tsx) —
// never a raw rupee symbol or a hand-rolled coin <Svg> that would drift
// from it. This walks app/ and src/ and fails if it finds either
// outside the files allowed to define shapes/symbols directly.
//
// Run via `npm run check:currency`. No test runner is configured in
// this project yet (see AGENTS.md's verification commands), so this is
// a standalone script rather than a Jest/etc. test case.

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const SCAN_DIRS = ['app', 'src'];
const ALLOWED_SVG_FILES = [
  path.join(ROOT, 'src', 'theme', 'icons.tsx'),
  // Purely decorative background glow (a radial gradient) — no coin or currency imagery.
  path.join(ROOT, 'src', 'components', 'AmbientGlow.tsx'),
];
const RUPEE_PATTERN = /₹/;
const SVG_TAG_PATTERN = /<Svg\b/;

function walk(dir, files = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(fullPath, files);
    } else if (/\.(ts|tsx)$/.test(entry.name)) {
      files.push(fullPath);
    }
  }
  return files;
}

function main() {
  const files = SCAN_DIRS.flatMap((dir) => {
    const fullDir = path.join(ROOT, dir);
    return fs.existsSync(fullDir) ? walk(fullDir) : [];
  });

  const offenders = [];

  for (const file of files) {
    const contents = fs.readFileSync(file, 'utf8');

    if (RUPEE_PATTERN.test(contents)) {
      offenders.push(`${file}: raw ₹ symbol`);
    }

    if (!ALLOWED_SVG_FILES.includes(file) && SVG_TAG_PATTERN.test(contents)) {
      offenders.push(`${file}: inline <Svg> tag outside the allowed SVG files`);
    }
  }

  if (offenders.length > 0) {
    console.error('check-no-raw-currency failed:\n' + offenders.map((o) => `  - ${o}`).join('\n'));
    process.exit(1);
  }

  console.log(`check-no-raw-currency: ${files.length} file(s) scanned, clean.`);
}

main();
