#!/usr/bin/env python3
"""
Cuts the three tournament-metric icons (trophy, skull, wallet) out of the supplied
"Trophy, Skull, and Wallet Icons.png" and writes them to mobile/assets/icons.

This replaces the earlier lettered badges (extract-metric-badges.py): this artwork carries no
text, so the app draws "Prize Pool" / "Per Kill" / "Entry Fee" itself (TournamentMetric.tsx) —
nothing is cropped or redrawn here, only the white background is removed:
  1. Near-white pixels connected to the edge of the icon's box are background (a flood fill
     from the border, so the white diamonds/eyes/coins inside the artwork are never touched
     because the artwork's own dark outline encloses them).
  2. Along the edge of the artwork (a few pixels wide) the white the anti-aliasing was blended
     with is taken back out ("colour to alpha" against white), so there is no white halo on a
     dark card.
  3. Loose specks that are not part of the icon are dropped, the result is trimmed to the
     artwork, padded slightly, and scaled with premultiplied alpha so its longest side is 256 px.

Usage: python3 -I extract-metric-icons.py <the supplied .png> <mobile/assets dir>
"""
import os
import sys
from collections import deque

import numpy as np
from PIL import Image

# name: (box x0, y0, x1, y1 in the source — each icon with a little white around it, output path)
ICONS = {
    'prize-pool': ((20, 40, 758, 705), 'icons/prize-pool.png'),
    'per-kill': ((736, 40, 1435, 705), 'icons/per-kill.png'),
    'entry-fee': ((1427, 40, 2152, 705), 'icons/entry-fee.png'),
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
    # as the artwork's colour blended over white, and the colour that blend started from.
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

    for name, (box, relative) in ICONS.items():
        icon = extract(source, box)
        w, h = icon.size
        scale = LONGEST_SIDE / max(w, h)
        icon = premultiplied_resize(icon, (max(1, round(w * scale)), max(1, round(h * scale))))
        path = os.path.join(assets, relative)
        icon.save(path, optimize=True)
        print(f'{name}: {icon.size[0]}x{icon.size[1]} -> {relative}')


if __name__ == '__main__':
    main()
