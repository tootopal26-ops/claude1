// Small dependency-free SVG charts (line/area/bars, sparkline, donut) with tooltips and keyboard support.
import { esc, reduceMotion } from '../core/dom.js';

const NS = 'http://www.w3.org/2000/svg';
let uid = 0;

function niceNum(range, round) {
  const exp = Math.floor(Math.log10(range || 1));
  const f = range / 10 ** exp;
  const nf = round ? (f < 1.5 ? 1 : f < 3 ? 2 : f < 7 ? 5 : 10) : (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10);
  return nf * 10 ** exp;
}

/** Round axis limits to friendly numbers: niceScale(0, 8300, 4) → { min: 0, max: 10000, ticks: [0, 2500, …] } */
export function niceScale(min, max, count = 4, floorMax = 4) {
  if (!isFinite(min) || !isFinite(max)) { min = 0; max = floorMax; }
  min = Math.min(0, min);
  max = Math.max(max, floorMax);
  const range = niceNum(max - min, false);
  const step = niceNum(range / count, true);
  const lo = Math.floor(min / step) * step;
  const hi = Math.ceil(max / step) * step;
  const ticks = [];
  for (let v = lo; v <= hi + step / 2; v += step) ticks.push(Math.round(v));
  return { min: lo, max: hi, ticks };
}

/** Monotone cubic interpolation (no overshoot) through [[x, y], …] */
function monotone(pts) {
  const n = pts.length;
  if (n === 0) return '';
  if (n === 1) return `M${pts[0][0]},${pts[0][1]}`;
  if (n === 2) return `M${pts[0][0]},${pts[0][1]}L${pts[1][0]},${pts[1][1]}`;
  const dx = [], m = [], t = new Array(n);
  for (let i = 0; i < n - 1; i++) { dx[i] = pts[i + 1][0] - pts[i][0]; m[i] = (pts[i + 1][1] - pts[i][1]) / dx[i]; }
  t[0] = m[0]; t[n - 1] = m[n - 2];
  for (let i = 1; i < n - 1; i++) t[i] = m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2;
  for (let i = 0; i < n - 1; i++) {
    if (m[i] === 0) { t[i] = 0; t[i + 1] = 0; continue; }
    const a = t[i] / m[i], b = t[i + 1] / m[i], s = a * a + b * b;
    if (s > 9) { const k = 3 / Math.sqrt(s); t[i] = k * a * m[i]; t[i + 1] = k * b * m[i]; }
  }
  let d = `M${pts[0][0]},${pts[0][1]}`;
  for (let i = 0; i < n - 1; i++) {
    const h = dx[i] / 3;
    d += `C${(pts[i][0] + h).toFixed(2)},${(pts[i][1] + t[i] * h).toFixed(2)} ${(pts[i + 1][0] - h).toFixed(2)},${(pts[i + 1][1] - t[i + 1] * h).toFixed(2)} ${pts[i + 1][0].toFixed(2)},${pts[i + 1][1].toFixed(2)}`;
  }
  return d;
}

const el = (name, attrs = {}) => {
  const n = document.createElementNS(NS, name);
  for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, String(v));
  return n;
};

/**
 * @param {HTMLElement} host
 * @param {{points:{label:string,title?:string}[], series:{id:string,label:string,color:string,values:number[],type?:'line'|'area'|'bar',format:(v:number)=>string,tipOnly?:boolean,hidden?:boolean}[],
 *   yFormat:(v:number)=>string, height?:number, emptyText?:string, floorMax?:number, ariaLabel?:string}} opts
 */
export function lineChart(host, opts) {
  const state = { opts, active: -1, drawn: false, ro: null };
  host.classList.add('chart');
  host.tabIndex = 0;
  host.setAttribute('role', 'group');
  host.innerHTML = '';
  const live = document.createElement('div');
  live.className = 'sr-only'; live.setAttribute('aria-live', 'polite');
  const tip = document.createElement('div');
  tip.className = 'chart-tip'; tip.setAttribute('aria-hidden', 'true');
  const svg = el('svg', { role: 'presentation', focusable: 'false' });
  host.append(svg, tip, live);

  function draw(animate) {
    const o = state.opts;
    const W = Math.max(240, host.clientWidth);
    const H = o.height || host.clientHeight || 260;
    host.style.height = H + 'px';
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.setAttribute('width', W); svg.setAttribute('height', H);
    svg.innerHTML = '';
    host.querySelector('.chart-empty')?.remove();
    host.setAttribute('aria-label', o.ariaLabel || 'Chart');

    const drawn = o.series.filter((s) => !s.tipOnly && !s.hidden);
    const n = o.points.length;
    const all = drawn.flatMap((s) => s.values);
    const hasData = all.some((v) => v !== 0);
    const sc = niceScale(Math.min(0, ...all, 0), Math.max(0, ...all, 0), 4, o.floorMax ?? 4);
    const labelW = Math.max(...sc.ticks.map((t) => o.yFormat(t).length)) * 6.6 + 14;
    const m = { t: 12, r: 12, b: 28, l: Math.min(76, Math.max(34, labelW)) };
    const pw = W - m.l - m.r, ph = H - m.t - m.b;
    const hasBars = drawn.some((s) => s.type === 'bar');
    const band = n > 0 ? pw / n : pw;
    const x = (i) => m.l + (hasBars || n === 1 ? band * (i + 0.5) : (n === 1 ? pw / 2 : (pw * i) / (n - 1)));
    const y = (v) => m.t + ph - ((v - sc.min) / (sc.max - sc.min)) * ph;
    state.geom = { m, W, H, x, y, n, pw, hasBars, band };

    const defs = el('defs');
    svg.appendChild(defs);

    // grid + y labels
    const grid = el('g', { class: 'chart-grid' });
    const axis = el('g', { class: 'chart-axis' });
    for (const tv of sc.ticks) {
      const yy = y(tv);
      grid.appendChild(el('line', { x1: m.l, x2: W - m.r, y1: yy, y2: yy }));
      const tx = el('text', { x: m.l - 8, y: yy + 4, 'text-anchor': 'end' });
      tx.textContent = o.yFormat(tv);
      axis.appendChild(tx);
    }
    svg.append(grid);
    if (sc.min < 0) svg.appendChild(el('line', { x1: m.l, x2: W - m.r, y1: y(0), y2: y(0), stroke: '#9aa0b8', 'stroke-width': 1 }));

    // x labels: anchored on the latest point and stepped backwards so they never collide
    const every = Math.max(1, Math.ceil(n / Math.max(1, Math.floor(pw / 62))));
    for (let i = n - 1; i >= 0; i -= every) {
      const tx = el('text', { x: x(i), y: H - 8, 'text-anchor': n === 1 ? 'middle' : i === 0 && !hasBars ? 'start' : i === n - 1 && !hasBars ? 'end' : 'middle' });
      tx.textContent = o.points[i].label;
      axis.appendChild(tx);
    }
    svg.append(axis);

    // series
    const barSeries = drawn.filter((s) => s.type === 'bar');
    const gap = Math.min(10, band * 0.22);
    const groupW = Math.max(3, band - gap * 2);
    const bw = barSeries.length ? Math.max(2, Math.min(34, groupW / barSeries.length - 2)) : 0;
    barSeries.forEach((s, si) => {
      const g = el('g');
      s.values.forEach((v, i) => {
        if (!v) return;
        const total = barSeries.length * bw + (barSeries.length - 1) * 2;
        const bx = x(i) - total / 2 + si * (bw + 2);
        const y0 = y(0), y1 = y(v);
        const top = Math.min(y0, y1), h = Math.max(1.5, Math.abs(y1 - y0));
        const r = Math.min(4, bw / 2, h);
        const rect = el('rect', { x: bx.toFixed(1), y: top.toFixed(1), width: bw, height: h.toFixed(1), rx: r, fill: s.color, class: 'chart-bar', 'data-i': i });
        if (animate && !reduceMotion()) { rect.style.transformBox = 'fill-box'; rect.style.transformOrigin = v >= 0 ? '50% 100%' : '50% 0%'; rect.style.animation = `bar-grow .6s ${Math.min(0.5, i * 0.015)}s var(--ease-soft) both`; }
        g.appendChild(rect);
      });
      svg.appendChild(g);
    });
    drawn.filter((s) => s.type !== 'bar').forEach((s) => {
      const pts = s.values.map((v, i) => [x(i), y(v)]);
      const d = monotone(pts);
      const gid = `cg${++uid}`;
      if (s.type === 'area' && n > 1) {
        const grad = el('linearGradient', { id: gid, x1: 0, y1: 0, x2: 0, y2: 1 });
        grad.append(el('stop', { offset: '0%', 'stop-color': s.color, 'stop-opacity': 0.28 }), el('stop', { offset: '100%', 'stop-color': s.color, 'stop-opacity': 0 }));
        defs.appendChild(grad);
        svg.appendChild(el('path', { d: `${d}L${pts[n - 1][0]},${y(0)}L${pts[0][0]},${y(0)}Z`, fill: `url(#${gid})`, stroke: 'none' }));
      }
      const path = el('path', { d, class: 'chart-line', stroke: s.color });
      svg.appendChild(path);
      if (n === 1) svg.appendChild(el('circle', { cx: pts[0][0], cy: pts[0][1], r: 4, fill: s.color, class: 'chart-dot' }));
      if (animate && !reduceMotion() && n > 1) {
        const len = path.getTotalLength();
        path.style.strokeDasharray = String(len); path.style.strokeDashoffset = String(len);
        path.style.animation = 'draw 0.9s var(--ease-soft) forwards';
      }
    });

    // hover layer
    state.cross = el('line', { class: 'chart-cross', y1: m.t, y2: m.t + ph, x1: -10, x2: -10, opacity: 0 });
    state.dots = drawn.filter((s) => s.type !== 'bar').map((s) => el('circle', { r: 4.5, fill: s.color, class: 'chart-dot', opacity: 0 }));
    svg.append(state.cross, ...state.dots);

    if (!hasData && !o.series.some((s) => s.tipOnly && s.values.some((v) => v))) {
      const e = document.createElement('div');
      e.className = 'chart-empty'; e.textContent = o.emptyText || 'No data in this range yet.';
      host.appendChild(e);
    }
    if (state.active >= 0) show(Math.min(state.active, n - 1));
  }

  function fillTip(i) {
    const o = state.opts;
    tip.textContent = '';
    const title = document.createElement('b');
    title.textContent = o.points[i].title || o.points[i].label;
    tip.appendChild(title);
    for (const s of o.series) {
      if (s.hidden) continue;
      const row = document.createElement('div');
      const name = document.createElement('span');
      const sw = document.createElement('i');
      sw.style.background = s.color;
      name.append(sw, s.label);
      const val = document.createElement('em');
      val.textContent = s.format(s.values[i] ?? 0);
      row.append(name, val);
      tip.appendChild(row);
    }
  }

  function show(i) {
    const g = state.geom;
    if (!g || i < 0 || i >= g.n) return;
    state.active = i;
    const o = state.opts;
    const px = g.x(i);
    state.cross.setAttribute('x1', px); state.cross.setAttribute('x2', px); state.cross.setAttribute('opacity', 1);
    const drawn = o.series.filter((s) => !s.tipOnly && !s.hidden && s.type !== 'bar');
    state.dots.forEach((d, k) => { d.setAttribute('cx', px); d.setAttribute('cy', g.y(drawn[k].values[i])); d.setAttribute('opacity', 1); });
    fillTip(i);
    tip.classList.add('is-on');
    const tw = tip.offsetWidth;
    let left = px + 14;
    if (left + tw > g.W - 4) left = px - tw - 14;
    tip.style.left = Math.max(4, left) + 'px';
    tip.style.top = Math.max(4, g.m.t + 6) + 'px';
    live.textContent = tip.textContent;
  }
  function hide() {
    state.active = -1;
    state.cross?.setAttribute('opacity', 0);
    state.dots?.forEach((d) => d.setAttribute('opacity', 0));
    tip.classList.remove('is-on');
  }

  const idxAt = (clientX) => {
    const g = state.geom; const r = svg.getBoundingClientRect();
    const px = clientX - r.left;
    let best = 0, bd = Infinity;
    for (let i = 0; i < g.n; i++) { const d = Math.abs(g.x(i) - px); if (d < bd) { bd = d; best = i; } }
    return best;
  };
  svg.addEventListener('pointermove', (e) => { if (state.geom?.n) show(idxAt(e.clientX)); });
  svg.addEventListener('pointerdown', (e) => { if (state.geom?.n) show(idxAt(e.clientX)); });
  svg.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') hide(); });
  host.addEventListener('keydown', (e) => {
    const n = state.geom?.n || 0; if (!n) return;
    if (e.key === 'ArrowRight') { show(Math.min(n - 1, state.active + 1)); e.preventDefault(); }
    else if (e.key === 'ArrowLeft') { show(Math.max(0, (state.active < 0 ? n : state.active) - 1)); e.preventDefault(); }
    else if (e.key === 'Home') { show(0); e.preventDefault(); }
    else if (e.key === 'End') { show(n - 1); e.preventDefault(); }
    else if (e.key === 'Escape') hide();
  });
  host.addEventListener('blur', hide);

  let raf = 0, lastW = 0;
  state.ro = new ResizeObserver(() => {
    if (Math.abs(host.clientWidth - lastW) < 2) return;
    lastW = host.clientWidth;
    cancelAnimationFrame(raf);
    raf = requestAnimationFrame(() => draw(false));
  });
  state.ro.observe(host);
  lastW = host.clientWidth;
  draw(true);

  return {
    update(next, animate = true) { state.opts = next; hide(); draw(animate); },
    destroy() { state.ro?.disconnect(); },
  };
}

/** Tiny trend line for KPI cards. */
export function sparkline(host, values, color = '#ff2d87') {
  host.innerHTML = '';
  const v = values.length ? values : [0];
  const min = Math.min(...v), max = Math.max(...v);
  const span = max - min || 1;
  const pts = v.map((val, i) => [v.length === 1 ? 50 : (100 * i) / (v.length - 1), max === min ? 15 : 27 - ((val - min) / span) * 24]);
  const d = monotone(pts);
  const gid = `sp${++uid}`;
  const svg = el('svg', { viewBox: '0 0 100 30', preserveAspectRatio: 'none', role: 'presentation', focusable: 'false' });
  const defs = el('defs');
  const grad = el('linearGradient', { id: gid, x1: 0, y1: 0, x2: 0, y2: 1 });
  grad.append(el('stop', { offset: '0%', 'stop-color': color, 'stop-opacity': 0.25 }), el('stop', { offset: '100%', 'stop-color': color, 'stop-opacity': 0 }));
  defs.appendChild(grad);
  svg.append(defs, el('path', { d: `${d}L100,30L0,30Z`, fill: `url(#${gid})` }), el('path', { d, fill: 'none', stroke: color, 'stroke-width': 1.8, 'vector-effect': 'non-scaling-stroke', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
  svg.style.width = '100%'; svg.style.height = '100%';
  host.appendChild(svg);
}

/** Donut with a centre label. slices: [{label,value,color}] */
export function donut(host, slices, { center = '', sub = '' } = {}) {
  host.innerHTML = '';
  const total = slices.reduce((a, s) => a + s.value, 0);
  const svg = el('svg', { viewBox: '0 0 42 42', class: 'donut', role: 'img', 'aria-label': slices.map((s) => `${s.label}: ${s.value}`).join(', ') });
  svg.appendChild(el('circle', { cx: 21, cy: 21, r: 15.9155, fill: 'none', stroke: '#ececf4', 'stroke-width': 4.6 }));
  let offset = 25;
  if (total > 0) {
    slices.forEach((s) => {
      if (s.value <= 0) return;
      const pct = (s.value / total) * 100;
      const c = el('circle', { cx: 21, cy: 21, r: 15.9155, fill: 'none', stroke: s.color, 'stroke-width': 4.6, 'stroke-dasharray': `0 100`, 'stroke-dashoffset': offset });
      c.style.transition = reduceMotion() ? 'none' : 'stroke-dasharray .9s cubic-bezier(.22,1,.36,1)';
      svg.appendChild(c);
      requestAnimationFrame(() => requestAnimationFrame(() => c.setAttribute('stroke-dasharray', `${Math.max(0, pct - (slices.filter((x) => x.value > 0).length > 1 ? 0.6 : 0)).toFixed(2)} ${(100 - pct + (slices.filter((x) => x.value > 0).length > 1 ? 0.6 : 0)).toFixed(2)}`)));
      offset -= pct;
    });
  }
  const t = el('text', { x: 21, y: 22.2, 'text-anchor': 'middle', class: 'donut-center' }); t.textContent = center;
  const u = el('text', { x: 21, y: 27.2, 'text-anchor': 'middle', class: 'donut-center-sub' }); u.textContent = sub;
  svg.append(t, u);
  host.appendChild(svg);
}

/** Animate a number from its current displayed value to `to`. */
export function countUp(node, to, format, ms = 700) {
  const from = Number(node.dataset.v ?? 0);
  node.dataset.v = String(to);
  if (reduceMotion() || from === to) { node.textContent = format(to); return; }
  const t0 = performance.now();
  const step = (t) => {
    const k = Math.min(1, (t - t0) / ms);
    const e = 1 - (1 - k) ** 3;
    node.textContent = format(Math.round(from + (to - from) * e));
    if (k < 1) requestAnimationFrame(step); else node.textContent = format(to);
  };
  requestAnimationFrame(step);
}
