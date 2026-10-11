"""Ikon launcher Android dari logo gapura resmi (website/public/images/logo/rt05-mark.svg).

Konversi deterministik tanpa menggambar ulang: setiap elemen SVG (rect, path, circle,
clipPath, stroke) disalin dengan koordinat dan warna yang sama ke
- res/drawable/ic_launcher_foreground.xml (VectorDrawable, adaptive icon API 26+),
- res/mipmap-*/ic_launcher.png (API 24-25), dirender Pillow dengan supersampling.

Jalankan dari mobile/:  python tool/launcher_icon.py
Butuh Pillow. Hasil di-commit; skrip gagal bila SVG memakai elemen di luar subset ini.
"""

import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
SVG = ROOT / 'assets' / 'brand' / 'rt05-mark.svg'
RES = ROOT / 'android' / 'app' / 'src' / 'main' / 'res'
NS = '{http://www.w3.org/2000/svg}'
ANDROID = 'http://schemas.android.com/apk/res/android'

# Adaptive icon 108dp; isi logo (bbox x 6..58, y 3.6..57.7 pada viewBox 64) dimuat di zona aman 66dp.
SCALE = 0.875
CENTER = (32.0, 30.65)
LEGACY = {'mdpi': 48, 'hdpi': 72, 'xhdpi': 96, 'xxhdpi': 144, 'xxxhdpi': 192}
BACKGROUND = '#FFFFFF'


def num(v):
    s = f'{float(v):.4f}'.rstrip('0').rstrip('.')
    return s if s not in ('-0', '') else '0'


def parse_path(d):
    """Path garis lurus saja (M/L/H/V/Z, absolut atau relatif) → titik absolut."""
    tokens = re.findall(r'[MLHVZmlhvz]|-?\d*\.?\d+', d)
    pts, cmd, i, x, y = [], None, 0, 0.0, 0.0
    while i < len(tokens):
        t = tokens[i]
        if t.isalpha():
            cmd = t
            i += 1
            if cmd in 'Zz':
                continue
            continue
        if cmd in 'Mm':
            dx, dy = float(tokens[i]), float(tokens[i + 1])
            x, y = (x + dx, y + dy) if cmd == 'm' else (dx, dy)
            i += 2
            cmd = 'l' if cmd == 'm' else 'L'  # pasangan berikutnya = lineto implisit
        elif cmd in 'Ll':
            dx, dy = float(tokens[i]), float(tokens[i + 1])
            x, y = (x + dx, y + dy) if cmd == 'l' else (dx, dy)
            i += 2
        elif cmd in 'Hh':
            x = x + float(tokens[i]) if cmd == 'h' else float(tokens[i])
            i += 1
        elif cmd in 'Vv':
            y = y + float(tokens[i]) if cmd == 'v' else float(tokens[i])
            i += 1
        else:
            sys.exit(f'perintah path tidak didukung: {cmd}')
        pts.append((x, y))
    if not d.strip().lower().endswith('z'):
        sys.exit('path harus tertutup (z)')
    return pts


def poly_data(pts):
    return 'M' + ' L'.join(f'{num(x)},{num(y)}' for x, y in pts) + ' Z'


def rect_data(x, y, w, h, r):
    r = min(r, w / 2, h / 2)
    if r == 0:
        return f'M{num(x)},{num(y)} h{num(w)} v{num(h)} h{num(-w)} Z'
    a = f'a{num(r)},{num(r)} 0 0 1'
    return (f'M{num(x + r)},{num(y)} h{num(w - 2 * r)} {a} {num(r)},{num(r)} v{num(h - 2 * r)} '
            f'{a} {num(-r)},{num(r)} h{num(-(w - 2 * r))} {a} {num(-r)},{num(-r)} v{num(-(h - 2 * r))} '
            f'{a} {num(r)},{num(-r)} Z')


def circle_data(cx, cy, r):
    return (f'M{num(cx - r)},{num(cy)} a{num(r)},{num(r)} 0 1 0 {num(2 * r)},0 '
            f'a{num(r)},{num(r)} 0 1 0 {num(-2 * r)},0 Z')


def load_shapes():
    """SVG → daftar bentuk berurutan: (kind, geom, fill, stroke, stroke_width, clip_points)."""
    root = ET.parse(SVG).getroot()
    if root.get('viewBox') != '0 0 64 64':
        sys.exit('viewBox logo berubah; tinjau ulang SCALE/CENTER')
    clips = {}
    for cp in root.iter(f'{NS}clipPath'):
        (p,) = list(cp)
        clips[cp.get('id')] = parse_path(p.get('d'))
    shapes = []

    def walk(el, clip):
        for c in el:
            tag = c.tag.replace(NS, '')
            if tag == 'defs':
                continue
            if tag == 'g':
                ref = re.fullmatch(r'url\(#(\w+)\)', c.get('clip-path', ''))
                if not ref or set(c.attrib) != {'clip-path'}:
                    sys.exit('hanya <g clip-path> yang didukung')
                walk(c, clips[ref.group(1)])
                continue
            fill = c.get('fill')
            stroke = c.get('stroke')
            if tag == 'rect':
                geom = tuple(float(c.get(k, 0)) for k in ('x', 'y', 'width', 'height', 'rx'))
            elif tag == 'circle':
                geom = tuple(float(c.get(k)) for k in ('cx', 'cy', 'r'))
            elif tag == 'path':
                geom = parse_path(c.get('d'))
                if stroke and c.get('stroke-linejoin') != 'round':
                    sys.exit('stroke hanya linejoin round')
            else:
                sys.exit(f'elemen tidak didukung: {tag}')
            shapes.append((tag, geom, None if fill == 'none' else fill, stroke, float(c.get('stroke-width', 0)), clip))

    walk(root, None)
    return shapes


def color(hex6):
    return '#FF' + hex6.lstrip('#').upper()


def vector_drawable(shapes):
    tx, ty = 54 - CENTER[0] * SCALE, 54 - CENTER[1] * SCALE
    out = ['<?xml version="1.0" encoding="utf-8"?>',
           '<!-- Dihasilkan tool/launcher_icon.py dari assets/brand/rt05-mark.svg (logo resmi website). Jangan diedit manual. -->',
           f'<vector xmlns:android="{ANDROID}"',
           '    android:width="108dp" android:height="108dp"',
           '    android:viewportWidth="108" android:viewportHeight="108">',
           f'    <group android:translateX="{num(tx)}" android:translateY="{num(ty)}" android:scaleX="{num(SCALE)}" android:scaleY="{num(SCALE)}">']
    open_clip = None
    for tag, geom, fill, stroke, sw, clip in shapes:
        if clip is not open_clip:
            if open_clip is not None:
                out.append('        </group>')
            if clip is not None:
                out += ['        <group>', f'            <clip-path android:pathData="{poly_data(clip)}" />']
            open_clip = clip
        ind = '            ' if clip is not None else '        '
        data = rect_data(*geom) if tag == 'rect' else circle_data(*geom) if tag == 'circle' else poly_data(geom)
        attrs = [f'android:pathData="{data}"']
        if fill:
            attrs.append(f'android:fillColor="{color(fill)}"')
        if stroke:
            attrs += [f'android:strokeColor="{color(stroke)}"', f'android:strokeWidth="{num(sw)}"', 'android:strokeLineJoin="round"']
        out.append(f'{ind}<path {" ".join(attrs)} />')
    if open_clip is not None:
        out.append('        </group>')
    out += ['    </group>', '</vector>', '']
    return '\n'.join(out)


def render_png(shapes, size):
    """Ikon legacy: latar putih sudut membulat + logo; supersampling 8× lalu LANCZOS."""
    ss = 8
    n = size * ss
    k = n * 0.84 / 64
    ox, oy = n / 2 - CENTER[0] * k, n / 2 - CENTER[1] * k
    P = lambda x, y: (ox + x * k, oy + y * k)  # noqa: E731
    img = Image.new('RGBA', (n, n), (0, 0, 0, 0))
    ImageDraw.Draw(img).rounded_rectangle([0, 0, n - 1, n - 1], radius=n * 0.18, fill=BACKGROUND)
    for tag, geom, fill, stroke, sw, clip in shapes:
        layer = Image.new('RGBA', (n, n), (0, 0, 0, 0))
        d = ImageDraw.Draw(layer)
        if tag == 'rect':
            x, y, w, h, r = geom
            (x0, y0), (x1, y1) = P(x, y), P(x + w, y + h)
            d.rounded_rectangle([x0, y0, x1, y1], radius=min(r, w / 2, h / 2) * k, fill=fill)
        elif tag == 'circle':
            cx, cy, r = geom
            (x0, y0), (x1, y1) = P(cx - r, cy - r), P(cx + r, cy + r)
            d.ellipse([x0, y0, x1, y1], fill=fill)
        else:
            pts = [P(*p) for p in geom]
            if fill:
                d.polygon(pts, fill=fill)
            if stroke:
                w = sw * k
                d.line(pts + [pts[0]], fill=stroke, width=round(w), joint='curve')
                for px, py in pts:  # linejoin round di setiap sudut, termasuk titik awal
                    d.ellipse([px - w / 2, py - w / 2, px + w / 2, py + w / 2], fill=stroke)
        if clip is not None:
            mask = Image.new('L', (n, n), 0)
            ImageDraw.Draw(mask).polygon([P(*p) for p in clip], fill=255)
            alpha = Image.composite(layer.getchannel('A'), Image.new('L', (n, n), 0), mask)
            layer.putalpha(alpha)
        img = Image.alpha_composite(img, layer)
    return img.resize((size, size), Image.LANCZOS)


def main():
    shapes = load_shapes()
    (RES / 'drawable').mkdir(parents=True, exist_ok=True)
    (RES / 'drawable' / 'ic_launcher_foreground.xml').write_text(vector_drawable(shapes), encoding='utf-8', newline='\n')
    for density, size in LEGACY.items():
        render_png(shapes, size).save(RES / f'mipmap-{density}' / 'ic_launcher.png', optimize=True)
    print(f'{len(shapes)} elemen dari {SVG.name} -> drawable/ic_launcher_foreground.xml + {len(LEGACY)} PNG legacy')


if __name__ == '__main__':
    main()
