"""Cut the three icons out of a 3-up sheet whose 'transparent' background is a baked-in grey
checkerboard. Usage: python3 -I extract.py <sheet.png> <outdir> <name1,name2,name3>
Writes <outdir>/<name>.png (RGBA, cropped to content) and a preview on dark/light backgrounds."""
import sys
from collections import deque

from PIL import Image, ImageFilter

sheet, outdir, names = sys.argv[1], sys.argv[2], sys.argv[3].split(',')
im = Image.open(sheet).convert('RGB')
W, H = im.size
px = im.load()

# --- 1. fit the checkerboard grid from the top rows (period and phase) -----------------
def level(v):
    return 1 if v > 166 else 0

def fit_axis(samples):
    best = None
    for s10 in range(100, 140):  # square size 10.0 .. 13.9 px
        s = s10 / 10
        for o10 in range(0, int(s * 10), 2):
            o = o10 / 10
            ok = sum(1 for i, v in samples if v is not None and level(v) == (int((i - o) // s) % 2))
            if best is None or ok > best[0]:
                best = (ok, s, o)
    return best

row = [(x, px[x, 5][0]) for x in range(0, 400)]
col = [(y, px[5, y][0]) for y in range(0, 400)]
_, sx, ox = fit_axis(row)
_, sy, oy = fit_axis(col)
# which parity is the light square?
lightParity = 1 if level(px[5, 5][0]) == 1 else 0
base = (int((5 - ox) // sx) + int((5 - oy) // sy)) % 2
print(f'grid sx={sx} ox={ox} sy={sy} oy={oy}')

def expected(x, y):
    parity = (int((x - ox) // sx) + int((y - oy) // sy)) % 2
    return 200 if parity == base else 133

def chroma(c):
    return max(c) - min(c)

# candidate background: neutral grey in the checkerboard's range
cand = bytearray(W * H)
match = bytearray(W * H)
for y in range(H):
    for x in range(W):
        c = px[x, y]
        v = sum(c) / 3
        if chroma(c) < 22 and 108 <= v <= 222:
            cand[y * W + x] = 1
            if abs(v - expected(x, y)) < 34:
                match[y * W + x] = 1

# --- 2. flood fill from the border through candidates ----------------------------------
bg = bytearray(W * H)
q = deque()
for x in range(W):
    for y in (0, H - 1):
        if cand[y * W + x] and not bg[y * W + x]:
            bg[y * W + x] = 1
            q.append((x, y))
for y in range(H):
    for x in (0, W - 1):
        if cand[y * W + x] and not bg[y * W + x]:
            bg[y * W + x] = 1
            q.append((x, y))
while q:
    x, y = q.popleft()
    for nx, ny in ((x + 1, y), (x - 1, y), (x, y + 1), (x, y - 1)):
        if 0 <= nx < W and 0 <= ny < H:
            i = ny * W + nx
            if cand[i] and not bg[i]:
                bg[i] = 1
                q.append((nx, ny))

# --- 3. enclosed pockets that follow the checker pattern are background too -----------
seen = bytearray(W * H)
holes = 0
for y in range(H):
    for x in range(W):
        i = y * W + x
        if cand[i] and not bg[i] and not seen[i]:
            comp = []
            q = deque([(x, y)])
            seen[i] = 1
            while q:
                cx, cy = q.popleft()
                comp.append((cx, cy))
                for nx, ny in ((cx + 1, cy), (cx - 1, cy), (cx, cy + 1), (cx, cy - 1)):
                    if 0 <= nx < W and 0 <= ny < H:
                        j = ny * W + nx
                        if cand[j] and not bg[j] and not seen[j]:
                            seen[j] = 1
                            q.append((nx, ny))
            if len(comp) >= 120:
                inside = set(comp)
                jumps = pairs = 0
                lights = 0
                for cx, cy in comp:
                    v0 = sum(px[cx, cy]) / 3
                    if v0 > 166:
                        lights += 1
                    for dx, dy in ((6, 0), (0, 6)):
                        if (cx + dx, cy + dy) in inside:
                            pairs += 1
                            if abs(sum(px[cx + dx, cy + dy]) / 3 - v0) > 40:
                                jumps += 1
                frac_light = lights / len(comp)
                jump_rate = jumps / pairs if pairs else 0
                xs = [c[0] for c in comp]; ys = [c[1] for c in comp]
                verdict = 0.38 < jump_rate and 0.2 < frac_light < 0.8
                print(f'  pocket size={len(comp)} bbox=({min(xs)},{min(ys)})-({max(xs)},{max(ys)}) light={frac_light:.2f} jumps={jump_rate:.2f} -> {"bg" if verdict else "keep"}')
                if verdict:
                    holes += 1
                    for cx, cy in comp:
                        bg[cy * W + cx] = 1
print('enclosed pockets removed:', holes)

# --- 4. alpha with a soft fringe: light-grey pixels next to the background become
#        partly transparent black (they are outline anti-aliasing over the checker) -------
out = Image.new('RGBA', (W, H))
op = out.load()
for y in range(H):
    for x in range(W):
        i = y * W + x
        r, g, b = px[x, y]
        if bg[i]:
            op[x, y] = (0, 0, 0, 0)
            continue
        near = False
        for dy in (-2, -1, 0, 1, 2):
            for dx in (-2, -1, 0, 1, 2):
                nx, ny = x + dx, y + dy
                if 0 <= nx < W and 0 <= ny < H and bg[ny * W + nx]:
                    near = True
                    break
            if near:
                break
        v = (r + g + b) / 3
        if near and chroma((r, g, b)) < 30 and v > 40:
            vals = [sum(px[x + dx, y + dy]) / 3 for dy in (-2, -1, 0, 1, 2) for dx in (-2, -1, 0, 1, 2)
                    if 0 <= x + dx < W and 0 <= y + dy < H and bg[(y + dy) * W + x + dx]]
            e = max(vals) if vals else 200
            a = max(0.0, min(1.0, 1 - v / max(e, 1)))
            op[x, y] = (0, 0, 0, int(round(a * 255)))
        else:
            op[x, y] = (r, g, b, 255)

# --- 5. drop tiny detached specks (checker noise) --------------------------------------
alpha = out.split()[3]
ap = alpha.load()
seen = bytearray(W * H)
for y in range(H):
    for x in range(W):
        i = y * W + x
        if ap[x, y] > 0 and not seen[i]:
            comp = []
            q = deque([(x, y)])
            seen[i] = 1
            while q:
                cx, cy = q.popleft()
                comp.append((cx, cy))
                for nx, ny in ((cx + 1, cy), (cx - 1, cy), (cx, cy + 1), (cx, cy - 1)):
                    if 0 <= nx < W and 0 <= ny < H:
                        j = ny * W + nx
                        if ap[nx, ny] > 0 and not seen[j]:
                            seen[j] = 1
                            q.append((nx, ny))
            if len(comp) < 250:
                for cx, cy in comp:
                    op[cx, cy] = (0, 0, 0, 0)

# --- 6. split at the two widest empty column gaps (icons may cross the exact thirds) ----
alpha = out.split()[3]
cols = [any(alpha.getpixel((x, y)) > 0 for y in range(0, H, 2)) for x in range(W)]
gaps = []
x = 0
while x < W:
    if not cols[x]:
        start = x
        while x < W and not cols[x]:
            x += 1
        if start > 0 and x < W:
            gaps.append((x - start, start, x))
    else:
        x += 1
gaps = sorted(sorted(gaps, reverse=True)[:2], key=lambda g: g[1])
cuts = [0] + [(g[1] + g[2]) // 2 for g in gaps] + [W]
print('cuts at', cuts[1:-1], 'gap widths', [g[0] for g in gaps])
for k, name in enumerate(names):
    part = out.crop((cuts[k], 0, cuts[k + 1], H))
    box = part.getbbox()
    part = part.crop(box)
    pad = 8
    canvas = Image.new('RGBA', (part.width + pad * 2, part.height + pad * 2), (0, 0, 0, 0))
    canvas.paste(part, (pad, pad))
    canvas.save(f'{outdir}/{name}.png')
    print(name, canvas.size)

# preview on dark and light
prev = Image.new('RGB', (W, H * 2), (13, 10, 18))
prev.paste(Image.new('RGB', (W, H), (240, 240, 240)), (0, H))
prev.paste(out, (0, 0), out)
prev.paste(out, (0, H), out)
prev.save(f'{outdir}/_preview.png')
