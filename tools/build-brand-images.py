#!/usr/bin/env python3
"""
Derives every raster brand asset from the ORIGINAL files the project owner supplied (resources/brand/):

    logo.webp       1254x1254  the full logo: purple S + gold arrow + Instagram/YouTube tiles + the SOCIAL MEDIA BOOSTER lettering
    instagram.png   512x512    Instagram icon (transparent corners)
    youtube.png     512x512    YouTube icon (transparent corners)

Writes (all committed, so a cPanel server needs no build step):
    public/assets/img/logo-mark.webp          192x192  the header / admin / login logo: the whole mark in a disc, lettering cropped away
    public/assets/img/favicon-48.png, favicon-32.png, public/favicon.ico (16/32/48)
    public/assets/img/apple-touch-icon.png (180), icon-192.png, icon-512.png, icon-maskable-512.png
    public/assets/img/platforms/instagram.webp, youtube.webp   192x192, transparent

The favicon / app icons use a tighter crop (the S and the arrow dominate) because the full mark turns to mush at 16-48 px.
The artwork itself is never redrawn or recoloured: it is only cropped, padded with its own white background and resized.

Run:  python3 tools/build-brand-images.py      (needs Pillow)        or via   node tools/build-brand-assets.mjs
"""
import io
import os
import struct
import sys

from PIL import Image, ImageFilter

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
SRC = f'{ROOT}/resources/brand'
IMG = f'{ROOT}/public/assets/img'
WHITE = (255, 255, 255)

# the supplied file has an off-white (254,254,254) background; lift it to pure white so it melts into the page and the CSS discs
logo = Image.open(f'{SRC}/logo.webp').convert('RGB').point(lambda v: 255 if v >= 252 else v)
W, H = logo.size
WORDMARK_TOP = 862          # the SOCIAL MEDIA BOOSTER lettering starts here; everything above is the mark
mark = logo.crop((0, 0, W, WORDMARK_TOP))


def content_box(img, threshold=235):
    """Bounding box of everything that is not (nearly) white."""
    gray = img.convert('L').point(lambda v: 255 if v < threshold else 0)
    return gray.getbbox()


def full_mark(size):
    """The whole mark (S, arrow, both platform tiles) centred in a square, then resized: used inside a circle by the CSS."""
    box = content_box(mark)
    side = 1180
    canvas = Image.new('RGB', (side, side), WHITE)
    cx, cy = (box[0] + box[2]) // 2, (box[1] + box[3]) // 2
    canvas.paste(mark, (side // 2 - cx, side // 2 - cy))
    return sharpen_small(canvas.resize((size, size), Image.LANCZOS), size)


def square_on_white(img):
    side = max(img.size)
    canvas = Image.new('RGB', (side, side), WHITE)
    canvas.paste(img, ((side - img.size[0]) // 2, (side - img.size[1]) // 2))
    return canvas


# the S and the arrow, with a little of the platform tiles peeking in at the sides
icon_src = square_on_white(logo.crop((300, 70, 1100, WORDMARK_TOP)))


def sharpen_small(img, size):
    return img.filter(ImageFilter.UnsharpMask(radius=0.6, percent=55, threshold=2)) if size <= 256 else img


def icon(size, pad):
    inner = round(size * (1 - 2 * pad))
    canvas = Image.new('RGB', (size, size), WHITE)
    tile = sharpen_small(icon_src.resize((inner, inner), Image.LANCZOS), size)
    canvas.paste(tile, ((size - inner) // 2, (size - inner) // 2))
    return canvas


def png_bytes(img):
    buf = io.BytesIO()
    img.save(buf, 'PNG', optimize=True)
    return buf.getvalue()


def write_ico(path, entries):
    """A multi-size .ico made of PNG images (supported by every current browser)."""
    head = struct.pack('<HHH', 0, 1, len(entries))
    offset = 6 + 16 * len(entries)
    directory, body = b'', b''
    for size, data in entries:
        directory += struct.pack('<BBBBHHII', size if size < 256 else 0, size if size < 256 else 0, 0, 0, 1, 32, len(data), offset)
        body += data
        offset += len(data)
    with open(path, 'wb') as f:
        f.write(head + directory + body)


def out(name, img, fmt=None, **kw):
    path = f'{IMG}/{name}'
    os.makedirs(os.path.dirname(path), exist_ok=True)
    img.save(path, fmt, **kw)
    print(f'wrote {os.path.relpath(path, ROOT)}  {os.path.getsize(path) / 1024:.1f} KB')


# header / admin / login / installer logo
out('logo-mark.webp', full_mark(192), 'WEBP', quality=92, method=6)

# favicons + app icons
out('favicon-32.png', icon(32, 0.0), 'PNG', optimize=True)
out('favicon-48.png', icon(48, 0.0), 'PNG', optimize=True)
write_ico(f'{ROOT}/public/favicon.ico', [(s, png_bytes(icon(s, 0.0))) for s in (16, 32, 48)])
print(f'wrote public/favicon.ico  {os.path.getsize(ROOT + "/public/favicon.ico") / 1024:.1f} KB')
out('apple-touch-icon.png', icon(180, 0.06), 'PNG', optimize=True)
out('icon-192.png', icon(192, 0.04), 'PNG', optimize=True)
out('icon-512.png', icon(512, 0.04), 'PNG', optimize=True)
out('icon-maskable-512.png', icon(512, 0.17), 'PNG', optimize=True)          # maskable: everything inside the 80 % safe zone

# the platform buttons use the owner's own Instagram / YouTube icons
for name in ('instagram', 'youtube'):
    im = Image.open(f'{SRC}/{name}.png').convert('RGBA').resize((192, 192), Image.LANCZOS)
    out(f'platforms/{name}.webp', im, 'WEBP', quality=92, alpha_quality=100, method=6)
