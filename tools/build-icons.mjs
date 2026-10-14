// Builds public/assets/img/icons.svg — one <symbol> per icon, used via <use href="icons.svg#i-name">.
// UI icons come from lucide-static (ISC licence); custom brand icons live in resources/icons/.
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';

const LUCIDE = [
  'menu', 'x', 'check', 'circle-check', 'triangle-alert', 'circle-alert', 'info', 'chevron-down', 'chevron-up', 'chevron-left', 'chevron-right',
  'arrow-right', 'arrow-up-right', 'arrow-down-right', 'trending-up', 'trending-down', 'minus', 'download', 'upload', 'eye', 'eye-off', 'log-out',
  'layout-dashboard', 'package', 'layers', 'credit-card', 'settings', 'server', 'shield-check', 'user', 'users', 'user-plus', 'search',
  'refresh-cw', 'clock', 'copy', 'external-link', 'filter', 'calendar', 'plus', 'pencil', 'trash-2', 'image', 'bell', 'bell-ring', 'heart',
  'badge-check', 'sparkles', 'zap', 'lock', 'key-round', 'link', 'globe', 'chart-column', 'chart-pie', 'activity', 'wallet', 'receipt', 'history',
  'qr-code', 'smartphone', 'monitor', 'tablet', 'megaphone', 'indian-rupee', 'percent', 'rocket', 'life-buoy', 'power', 'sliders-horizontal',
  'gem', 'crown', 'play', 'loader-circle', 'shield-alert', 'ban', 'rotate-ccw', 'undo-2', 'square-check-big', 'list-checks', 'store', 'webhook',
  'file-text', 'hourglass', 'circle-dollar-sign', 'badge-indian-rupee', 'panel-left', 'ellipsis', 'ellipsis-vertical', 'arrow-left', 'send', 'wifi-off', 'circle-x',
];

const symbols = [];
for (const name of LUCIDE) {
  let svg;
  try { svg = readFileSync(`node_modules/lucide-static/icons/${name}.svg`, 'utf8'); }
  catch { console.warn(`! lucide icon missing: ${name}`); continue; }
  const inner = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '').trim();
  symbols.push(`<symbol id="i-${name}" viewBox="0 0 24 24">${inner}</symbol>`);
}
for (const f of readdirSync('resources/icons').filter((n) => n.endsWith('.svg'))) {
  const svg = readFileSync(`resources/icons/${f}`, 'utf8');
  const vb = svg.match(/viewBox="([^"]+)"/)?.[1] ?? '0 0 24 24';
  const inner = svg.replace(/^[\s\S]*?<svg[^>]*>/, '').replace(/<\/svg>\s*$/, '').trim();
  symbols.push(`<symbol id="i-${f.replace(/\.svg$/, '')}" viewBox="${vb}">${inner}</symbol>`);
}
writeFileSync('public/assets/img/icons.svg',
  `<svg xmlns="http://www.w3.org/2000/svg">\n${symbols.join('\n')}\n</svg>\n`);
console.log(`icons.svg: ${symbols.length} symbols`);
