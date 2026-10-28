#!/usr/bin/env python3
"""
Cuts the individual icons out of the supplied icon artwork and writes them to mobile/assets.

Sources (both supplied by the product owner):
  - the transparent sheets 11.png, 12.png and 13.png (3464 px, real alpha) — used for every icon
    they contain, because they are the highest-quality originals;
  - photo_2026-10-23_21-20-56.jpg (1280 x 853, black background, no alpha) — used only for the
    five icons that exist nowhere else: the calendar without a clock, the bell without a
    notification dot, and the Solo / Duo / Squad mode icons.
The sheets have real alpha, so nothing is removed or redrawn: each icon is the sheet's own
pixels inside that icon's mask.

How an icon's mask is built:
  1. Alpha >= 40 is labelled into connected components on a 4 px grid (the faint specks the
     sheets carry around some icons are separate, tiny components and are left out).
  2. An icon is the union of the components whose centre falls inside its box, grown by two
     grid cells so the soft anti-aliased edge (alpha < 40) is kept.
  3. Where two pieces of artwork touch, a straight split line is applied: the three medals are
     split at their empty columns, and the status artwork is cut at the top edge of its baked
     "ONGOING" / "UPCOMING" / "COMPLETED" pill, so the app can draw the words as real text.
  4. The crop is padded to keep the whole icon, then scaled with premultiplied alpha
     (no dark fringes) so its longest side is the target size.

One small area is hidden in the supplied artwork and is completed from the artwork's own
pixels (nothing new is drawn): the Completed label pill covers the bottom ~10 % of the green
circle, so the circle is fitted to its visible outline and the hidden band continues the rim
and shading radially (each missing pixel copies the visible pixel at the same distance from
the centre on the cut line).

The JPEG icons sit on pure black and their own black outline touches it, so a colour
threshold would either eat the outline or keep a halo. Instead the coloured interior is
taken (holes filled), grown by the outline's width so the outline stays, and feathered.

Usage: python3 -I extract-alpha-icons.py <dir with 11.png 12.png 13.png> <the .jpg> <mobile/assets dir>
"""
import os
import sys
from collections import deque

import numpy as np
from PIL import Image

STEP = 4
THRESHOLD = 40

# The Completed label pill's top edge (sheet 12 rows); rows from COMPLETED_FILL_FROM down are rebuilt.
COMPLETED_CUT = 2826
COMPLETED_FILL_FROM = 2823

# name: (box x0, y0, x1, y1 in the JPEG, output path) — only icons the transparent sheets lack.
JPEG_ICONS = {
    'calendar': ((471, 17, 620, 145), 'icons/calendar.png'),
    'bell': ((664, 21, 800, 160), 'icons/bell.png'),
    'mode-solo': ((701, 529, 833, 646), 'icons/mode-solo.png'),
    'mode-duo': ((882, 526, 1037, 651), 'icons/mode-duo.png'),
    'mode-squad': ((1077, 520, 1243, 651), 'icons/mode-squad.png'),
}
JPEG_COLOUR_THRESHOLD = 45
JPEG_OUTLINE_PX = 4

# name: (sheet, box x0, y0, x1, y1, extra constraint, output path, longest side)
ICONS = {
    # Sheet 11: wallet actions and the top-three rank medals.
    'deposit': ('11', 272, 1228, 700, 1720, None, 'icons/deposit.png', 256),
    'withdraw': ('11', 704, 1232, 1188, 1728, None, 'icons/withdraw.png', 256),
    'medal-1': ('11', 2040, 1728, 3228, 2088, ('x', 2040, 2440), 'icons/medal-1.png', 256),
    'medal-2': ('11', 2040, 1728, 3228, 2088, ('x', 2440, 2836), 'icons/medal-2.png', 256),
    'medal-3': ('11', 2040, 1728, 3228, 2088, ('x', 2836, 3228), 'icons/medal-3.png', 256),
    # Sheet 12, first row: the profile menu icons.
    'profile': ('12', 520, 688, 852, 1020, None, 'icons/profile.png', 192),
    'wallet': ('12', 864, 676, 1192, 1012, None, 'icons/wallet.png', 192),
    'redeem': ('12', 1564, 696, 1956, 1024, None, 'icons/redeem.png', 192),
    'settings': ('12', 1988, 692, 2332, 1024, None, 'icons/settings.png', 192),
    'terms': ('12', 2408, 692, 2748, 1024, None, 'icons/terms.png', 192),
    'logout': ('12', 2796, 692, 3124, 1008, None, 'icons/logout.png', 192),
    # Sheet 12: Ads Earning, Customer Support, Total Matches.
    'ads-earning': ('12', 2020, 1112, 2552, 1560, None, 'icons/ads-earning.png', 192),
    'support': ('12', 908, 1608, 1368, 2104, None, 'icons/support.png', 192),
    'matches': ('12', 1816, 1636, 2256, 2092, None, 'icons/matches.png', 192),
    # Sheet 12, bottom row: status artwork without its baked label pill.
    'ongoing': ('12', 544, 2280, 1420, 3060, ('y', 0, 2830), 'status/ongoing.png', 320),
    'upcoming': ('12', 1368, 2300, 2152, 3060, ('y', 0, 2830), 'status/upcoming.png', 320),
    'completed': ('12', 2168, 2350, 2964, 3060, ('y', 0, 2826), 'status/completed.png', 320),
    # Sheet 13: the bottom navigation icons, now with real transparency.
    'nav-leaderboard': ('13', 732, 1432, 1348, 2016, None, 'nav/leaderboard.png', 144),
    'nav-home': ('13', 1436, 1476, 2008, 1996, None, 'nav/home.png', 144),
    'nav-profile': ('13', 2076, 1432, 2604, 2030, None, 'nav/profile.png', 144),
}


def label_cells(alpha: np.ndarray) -> tuple[np.ndarray, list[tuple[int, int, int, int, int]]]:
    h, w = alpha.shape
    cells = alpha[: h - h % STEP, : w - w % STEP].reshape(h // STEP, STEP, w // STEP, STEP).max(axis=(1, 3)) >= THRESHOLD
    labels = np.zeros(cells.shape, np.int32)
    boxes = [(0, 0, 0, 0, 0)]
    H, W = cells.shape
    for y in range(H):
        for x in range(W):
            if cells[y, x] and not labels[y, x]:
                n = len(boxes)
                labels[y, x] = n
                queue = deque([(y, x)])
                y0 = y1 = y
                x0 = x1 = x
                count = 0
                while queue:
                    cy, cx = queue.popleft()
                    count += 1
                    y0, y1, x0, x1 = min(y0, cy), max(y1, cy), min(x0, cx), max(x1, cx)
                    for dy in (-1, 0, 1):
                        for dx in (-1, 0, 1):
                            ny, nx = cy + dy, cx + dx
                            if 0 <= ny < H and 0 <= nx < W and cells[ny, nx] and not labels[ny, nx]:
                                labels[ny, nx] = n
                                queue.append((ny, nx))
                boxes.append((x0 * STEP, y0 * STEP, (x1 + 1) * STEP, (y1 + 1) * STEP, count))
    return labels, boxes


def grow(mask: np.ndarray, steps: int) -> np.ndarray:
    out = mask.copy()
    for _ in range(steps):
        g = out.copy()
        g[1:, :] |= out[:-1, :]
        g[:-1, :] |= out[1:, :]
        g[:, 1:] |= out[:, :-1]
        g[:, :-1] |= out[:, 1:]
        out = g
    return out


def premultiplied_resize(img: Image.Image, size: tuple[int, int]) -> Image.Image:
    a = np.asarray(img).astype(np.float64) / 255.0
    rgb = a[:, :, :3] * a[:, :, 3:4]
    channels = [Image.fromarray((rgb[:, :, i] * 255).round().astype(np.uint8)).resize(size, Image.LANCZOS) for i in range(3)]
    alpha = Image.fromarray((a[:, :, 3] * 255).round().astype(np.uint8)).resize(size, Image.LANCZOS)
    al = np.asarray(alpha).astype(np.float64) / 255.0
    out = np.zeros((size[1], size[0], 4), np.uint8)
    for i in range(3):
        c = np.asarray(channels[i]).astype(np.float64) / 255.0
        out[:, :, i] = np.where(al > 0, np.clip(c / np.maximum(al, 1e-6), 0, 1) * 255, 0).round().astype(np.uint8)
    out[:, :, 3] = np.asarray(alpha)
    return Image.fromarray(out, 'RGBA')


def complete_circle(rgba: np.ndarray, region: tuple[int, int, int, int]) -> np.ndarray:
    """Fits the circle in `region` (above the cut), rebuilds the rows the label pill hid, returns their mask."""
    x0, y0, x1, _ = region
    alpha = rgba[y0:COMPLETED_FILL_FROM, x0:x1, 3]
    solid = alpha >= 128
    # The circle is the widest run of solid pixels per row (the spark strokes sit outside it).
    points = []
    for y in range(solid.shape[0]):
        xs = np.nonzero(solid[y])[0]
        if len(xs) < 2:
            continue
        runs = np.split(xs, np.nonzero(np.diff(xs) > 1)[0] + 1)
        run = max(runs, key=len)
        if len(run) > 40:
            points += [(run[0] + x0, y + y0), (run[-1] + x0, y + y0)]
    pts = np.array(points, float)
    A = np.c_[2 * pts[:, 0], 2 * pts[:, 1], np.ones(len(pts))]
    cx, cy, c = np.linalg.lstsq(A, (pts ** 2).sum(1), rcond=None)[0]
    radius = float(np.sqrt(c + cx ** 2 + cy ** 2)) + 1.5
    source_row = COMPLETED_FILL_FROM - 1
    h = source_row - cy
    rebuilt = np.zeros(rgba.shape[:2], bool)
    for y in range(COMPLETED_FILL_FROM, int(cy + radius) + 2):
        for x in range(int(cx - radius) - 1, int(cx + radius) + 2):
            d = np.hypot(x - cx, y - cy)
            if d > radius:
                continue
            reach = np.sqrt(max(d * d - h * h, 0.0))
            sx = int(round(cx + (reach if x >= cx else -reach)))
            rgba[y, x] = rgba[source_row, sx]
            rebuilt[y, x] = True
    print(f'  completed: circle centre=({cx:.1f},{cy:.1f}) r={radius - 1.5:.1f}, rebuilt rows {COMPLETED_FILL_FROM}..{int(cy + radius) + 1}')
    return rebuilt



def cut_from_black(jpeg: Image.Image, box: tuple[int, int, int, int]) -> Image.Image:
    """Alpha for an icon drawn on pure black: filled coloured interior, grown by the outline width."""
    pad = JPEG_OUTLINE_PX + 4
    x0, y0, x1, y1 = box
    rgb = np.asarray(jpeg.convert('RGB'))[y0 - pad:y1 + pad, x0 - pad:x1 + pad]
    colour = rgb.max(axis=2) >= JPEG_COLOUR_THRESHOLD
    # Fill holes: anything not reachable from the border through non-colour pixels is inside.
    h, w = colour.shape
    outside = np.zeros_like(colour)
    queue = deque([(y, x) for y in range(h) for x in (0, w - 1)] + [(y, x) for x in range(w) for y in (0, h - 1)])
    for y, x in queue:
        outside[y, x] = not colour[y, x]
    while queue:
        y, x = queue.popleft()
        if not outside[y, x]:
            continue
        for ny, nx in ((y + 1, x), (y - 1, x), (y, x + 1), (y, x - 1)):
            if 0 <= ny < h and 0 <= nx < w and not outside[ny, nx] and not colour[ny, nx]:
                outside[ny, nx] = True
                queue.append((ny, nx))
    solid = grow(~outside, JPEG_OUTLINE_PX)
    # Feather: distance-ish ramp over 2 px at the edge.
    inner = grow(~grow(~solid, 1), 0)
    alpha = np.where(inner, 255, np.where(solid, 150, 0)).astype(np.uint8)
    ring = grow(solid, 1) & ~solid
    alpha[ring] = 60
    return Image.fromarray(np.dstack([rgb, alpha]), 'RGBA')


def main() -> None:
    src_dir, jpeg_path, out_dir = sys.argv[1], sys.argv[2], sys.argv[3]
    sheets = {}
    for name, (sheet, x0, y0, x1, y1, constraint, out_path, longest) in ICONS.items():
        if sheet not in sheets:
            img = Image.open(os.path.join(src_dir, f'{sheet}.png')).convert('RGBA')
            alpha = np.asarray(img)[:, :, 3]
            sheets[sheet] = (img, alpha, *label_cells(alpha))
        img, alpha, labels, boxes = sheets[sheet]
        chosen = [i for i, (bx0, by0, bx1, by1, n) in enumerate(boxes) if i and n >= 20
                  and x0 <= (bx0 + bx1) / 2 <= x1 and y0 <= (by0 + by1) / 2 <= y1]
        cell_mask = grow(np.isin(labels, chosen), 2)
        mask = np.kron(cell_mask, np.ones((STEP, STEP), bool))
        full = np.zeros(alpha.shape, bool)
        full[: mask.shape[0], : mask.shape[1]] = mask
        if constraint:
            axis, lo, hi = constraint
            if axis == 'x':
                full[:, :lo] = False
                full[:, hi:] = False
            else:
                full[:hi if hi else None, :] &= True
                full[hi:, :] = False
        rgba = np.asarray(img).copy()
        if name == 'completed':
            # The rebuilt rows sit below the pill's top edge, so the mask takes them in explicitly.
            full |= complete_circle(rgba, (x0, y0, x1, y1))
        rgba[:, :, 3] = np.where(full, rgba[:, :, 3], 0)
        ys, xs = np.nonzero(rgba[:, :, 3])
        bx0, bx1, by0, by1 = xs.min(), xs.max() + 1, ys.min(), ys.max() + 1
        pad = max(4, int(max(bx1 - bx0, by1 - by0) * 0.02))
        window = (slice(max(0, by0 - pad), by1 + pad), slice(max(0, bx0 - pad), bx1 + pad))
        crop = Image.fromarray(rgba[window], 'RGBA')
        w, h = crop.size
        scale = longest / max(w, h)
        target = (max(1, round(w * scale)), max(1, round(h * scale)))
        out = premultiplied_resize(crop, target)
        path = os.path.join(out_dir, out_path)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        out.save(path, optimize=True)
        print(f'{name:16s} components={chosen} source={w}x{h} -> {out_path} {target[0]}x{target[1]}')


    jpeg = Image.open(jpeg_path)
    for name, (box, out_path) in JPEG_ICONS.items():
        icon = cut_from_black(jpeg, box)
        path = os.path.join(out_dir, out_path)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        icon.save(path, optimize=True)
        print(f'{name:16s} jpeg box={box} -> {out_path} {icon.width}x{icon.height} (native size, not upscaled)')


if __name__ == '__main__':
    main()
