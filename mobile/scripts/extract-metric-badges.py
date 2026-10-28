#!/usr/bin/env python3
"""
Cuts the three tournament-metric badges (Prize Pool, Kill, Entry Fee) out of the supplied
"Esports Prize, Kill, and Entry Fee Badges.png" and writes them to mobile/assets/icons.

The source is a 1983 x 793 RGB picture with the three badges side by side on a white
background and no alpha. Each badge is kept whole — artwork and its lettered banner — as
supplied; only the white background around it is removed, so nothing is redrawn:
  1. Near-white pixels connected to the edge of the badge's box are background (a flood fill
     from the border, so white inside the artwork — the skull, the "POOL" and "KILL" letters —
     is never touched because the badge's dark outline encloses it).
  2. Along the edge of the artwork (a few pixels wide) the white the anti-aliasing was blended
     with is taken back out ("colour to alpha" against white), so there is no white halo on a
     dark card.
  3. Loose specks that are not part of the badge are dropped, the result is trimmed to the
     badge, padded slightly, and scaled with premultiplied alpha so its longest side is 256 px.

Usage: python3 -I extract-metric-badges.py <the supplied .png> <mobile/assets dir>
"""
import os
import sys
from collections import deque

import numpy as np
from PIL import Image

# name: (box x0, y0, x1, y1 in the source — each badge with a little white around it, output path)
BADGES = {
    'prize-pool': ((20, 40, 676, 760), 'icons/prize-pool.png'),
    'per-kill': ((690, 40, 1342, 760), 'icons/per-kill.png'),
    'entry-fee': ((1352, 40, 1966, 760), 'icons/entry-fee.png'),
}
LONGEST_SIDE = 256
NEAR_WHITE = 232        # every channel at least this bright ...
NEAR_WHITE_SPREAD = 22  # ... and nearly grey, to count as background
EDGE_BAND_PX = 3
MIN_PIECE_PX = 400      # smaller detached pieces are background specks


def flood_background(rgb: np.ndarray) -> np.ndarray:
    h, w, _ = rgb.shape
    lo = rgb.min(axis=2)
    candidate = (lo >= NEAR_WHITE) & ((rgb.max(axis=2) - lo) <= NEAR_WHITE_SPREAD)
    seen = np.zeros((h, w), bool)
    queue = deque()
    for x in range(w):
        for y in (0, h - 1):
            if candidate[y, x] and not seen[y, x]:
                seen[y, x] = True
                queue.append((y, x))
    for y in range(h):
        for x in (0, w - 1):
            if candidate[y, x] and not seen[y, x]:
                seen[y, x] = True
                queue.append((y, x))
    while queue:
        y, x = queue.popleft()
        for ny, nx in ((y - 1, x), (y + 1, x), (y, x - 1), (y, x + 1)):
            if 0 <= ny < h and 0 <= nx < w and candidate[ny, nx] and not seen[ny, nx]:
                seen[ny, nx] = True
                queue.append((ny, nx))
    return seen


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


def drop_specks(alpha: np.ndarray) -> np.ndarray:
    """Keeps only connected pieces of at least MIN_PIECE_PX visible pixels."""
    h, w = alpha.shape
    solid = alpha > 0.1
    label = np.zeros((h, w), np.int32)
    keep = np.zeros((h, w), bool)
    current = 0
    for sy, sx in zip(*np.nonzero(solid)):
        if label[sy, sx]:
            continue
        current += 1
        label[sy, sx] = current
        piece = [(sy, sx)]
        queue = deque(piece)
        while queue:
            y, x = queue.popleft()
            for ny, nx in ((y - 1, x), (y + 1, x), (y, x - 1), (y, x + 1)):
                if 0 <= ny < h and 0 <= nx < w and solid[ny, nx] and not label[ny, nx]:
                    label[ny, nx] = current
                    queue.append((ny, nx))
                    piece.append((ny, nx))
        if len(piece) >= MIN_PIECE_PX:
            ys, xs = zip(*piece)
            keep[list(ys), list(xs)] = True
    # Keep the soft edge that belongs to a kept piece.
    return np.where(grow(keep, EDGE_BAND_PX), alpha, 0.0)


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


def extract(source: Image.Image, box: tuple[int, int, int, int]) -> Image.Image:
    rgb = np.asarray(source.crop(box).convert('RGB')).astype(np.float64)
    background = flood_background(rgb.astype(np.int32))

    alpha = np.where(background, 0.0, 1.0)
    colour = rgb.copy()

    # Unmix the white from the anti-aliased edge: the least opacity that explains each pixel
    # as the badge's colour blended over white, and the colour that blend started from.
    band = grow(background, EDGE_BAND_PX) & ~background
    edge_alpha = np.clip((255.0 - rgb).max(axis=2) / 255.0, 0.0, 1.0)
    alpha = np.where(band, edge_alpha, alpha)
    safe = np.maximum(alpha, 1e-6)[:, :, None]
    unmixed = np.clip((rgb - (1.0 - alpha[:, :, None]) * 255.0) / safe, 0, 255)
    colour = np.where(band[:, :, None], unmixed, colour)
    alpha = np.where(alpha < 0.03, 0.0, alpha)

    alpha = drop_specks(alpha)

    ys, xs = np.nonzero(alpha > 0)
    y0, y1, x0, x1 = ys.min(), ys.max() + 1, xs.min(), xs.max() + 1
    pad = 4
    out = np.zeros((y1 - y0 + 2 * pad, x1 - x0 + 2 * pad, 4), np.uint8)
    out[pad:-pad, pad:-pad, :3] = colour[y0:y1, x0:x1].round().astype(np.uint8)
    out[pad:-pad, pad:-pad, 3] = (alpha[y0:y1, x0:x1] * 255).round().astype(np.uint8)
    return Image.fromarray(out, 'RGBA')


def main() -> None:
    if len(sys.argv) != 3:
        sys.exit(__doc__)

    source = Image.open(sys.argv[1])
    assets = sys.argv[2]

    for name, (box, relative) in BADGES.items():
        badge = extract(source, box)
        w, h = badge.size
        scale = LONGEST_SIDE / max(w, h)
        badge = premultiplied_resize(badge, (max(1, round(w * scale)), max(1, round(h * scale))))
        path = os.path.join(assets, relative)
        badge.save(path, optimize=True)
        print(f'{name}: {badge.size[0]}x{badge.size[1]} -> {relative}')


if __name__ == '__main__':
    main()
