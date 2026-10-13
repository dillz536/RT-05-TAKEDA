"""Gambar pendamping M07 untuk Google Doc native: versi ringkas dengan teks besar.

Jalankan dari root repo: python docs/evidence/m07/drive/render_m07_native.py
Lima PNG 06-10 menyederhanakan gambar teknis 01-05 (yang tetap menjadi file ukuran penuh).
Aturan: lebar 1900 px, tinggi maksimal 2100 px, dan SEMUA teks yang terlihat >= 44 px
(~9,8 pt bila gambar ditempel selebar 425 pt). Skrip gagal bila ada font < 44 px, teks
yang tidak muat di kotak/elips, atau konten yang melewati kanvas.
Palet navy/kuning/sky dan font Public Sans + Bricolage Grotesque dari mobile/assets/fonts.
Isi diturunkan dari migration M07, app/Domain/Residents, Registration, CONTRACT.md, dan
mobile/lib/features/household. Tanpa data warga, kontak, atau kredensial.
"""
from pathlib import Path
import math
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[4]
OUT = Path(__file__).resolve().parent
FONTS = ROOT / 'mobile/assets/fonts'
NAVY, SKY, YELLOW, MUTED, GREY = '#0b1f3a', '#eaf6ff', '#f9c846', '#35465b', '#c9d3df'
PALE_YELLOW, OCHRE = '#fff4cc', '#7a5500'
W, MAX_H, MIN_PX = 1900, 2100, 44
STATUS = 'Implementasi M07: alur dan aturan; hasil penerimaan ada di laporan'

_cache = {}
ERRORS = []


def F(size, weight='Regular'):
    assert size >= MIN_PX, f'font {size}px < {MIN_PX}px'
    key = (size, weight)
    if key not in _cache:
        name = 'BricolageGrotesque-Bold.ttf' if weight == 'Head' else f'PublicSans-{weight}.ttf'
        _cache[key] = ImageFont.truetype(str(FONTS / name), size)
    return _cache[key]


def wrap(d, text, font, width):
    lines, line = [], ''
    for word in text.split(' '):
        trial = f'{line} {word}'.strip()
        if d.textlength(trial, font=font) <= width or not line:
            line = trial
        else:
            lines.append(line)
            line = word
    lines.append(line)
    for item in lines:
        if d.textlength(item, font=font) > width + 1:
            ERRORS.append(f'kata terlalu panjang: {item}')
    return lines


def canvas(h, title):
    assert h <= MAX_H, f'tinggi {h} > {MAX_H}'
    img = Image.new('RGB', (W, h), 'white')
    d = ImageDraw.Draw(img)
    d.rectangle((0, 0, W, 16), fill=YELLOW)
    if d.textlength(title, font=F(64, 'Head')) > W - 80:
        ERRORS.append(f'judul terlalu panjang: {title}')
    d.text((40, 40), title, font=F(64, 'Head'), fill=NAVY)
    if d.textlength(STATUS, font=F(44)) > W - 80:
        ERRORS.append('status terlalu panjang')
    d.text((40, 130), STATUS, font=F(44), fill=MUTED)
    return img, d


def footer(d, h, text, content_bottom):
    y = h - 100
    if content_bottom > y - 10:
        ERRORS.append(f'konten {content_bottom} menabrak footer {y}')
    d.line((40, y, W - 40, y), fill=GREY, width=3)
    if d.textlength(text, font=F(44)) > W - 80:
        ERRORS.append(f'footer terlalu panjang: {text}')
    d.text((40, y + 22), text, font=F(44), fill=MUTED)


def dashed(d, a, b, width=4, dash=18, gap=12):
    (x1, y1), (x2, y2) = a, b
    length = math.hypot(x2 - x1, y2 - y1)
    if length == 0:
        return
    ux, uy = (x2 - x1) / length, (y2 - y1) / length
    pos = 0
    while pos < length:
        end = min(pos + dash, length)
        d.line((x1 + ux * pos, y1 + uy * pos, x1 + ux * end, y1 + uy * end), fill=NAVY, width=width)
        pos = end + gap


def dashed_rect(d, r):
    x1, y1, x2, y2 = r
    for a, b in (((x1, y1), (x2, y1)), ((x2, y1), (x2, y2)), ((x2, y2), (x1, y2)), ((x1, y2), (x1, y1))):
        dashed(d, a, b)


def arrowhead(d, tip, frm, size=26, open_=False):
    ang = math.atan2(tip[1] - frm[1], tip[0] - frm[0])
    p1 = (tip[0] - size * math.cos(ang - .45), tip[1] - size * math.sin(ang - .45))
    p2 = (tip[0] - size * math.cos(ang + .45), tip[1] - size * math.sin(ang + .45))
    if open_:
        d.line((p1, tip, p2), fill=NAVY, width=4)
    else:
        d.polygon([tip, p1, p2], fill=NAVY)


def poly(d, pts, arrow=False, dash=False):
    for a, b in zip(pts, pts[1:]):
        if dash:
            dashed(d, a, b)
        else:
            d.line((a, b), fill=NAVY, width=4)
    if arrow:
        arrowhead(d, pts[-1], pts[-2])


def box(d, r, text, fill=SKY, font=None, dashed_border=False, align='center', pad=24, color=NAVY):
    font = font or F(44, 'SemiBold')
    x1, y1, x2, y2 = r
    if dashed_border:
        d.rectangle(r, fill=fill)
        dashed_rect(d, r)
    else:
        d.rounded_rectangle(r, radius=18, fill=fill, outline=NAVY, width=4)
    lines = []
    for para in text.split('\n'):
        lines += wrap(d, para, font, x2 - x1 - 2 * pad)
    lh = font.size + 10
    total = len(lines) * lh - 10
    if total > y2 - y1 - 20:
        ERRORS.append(f'teks tidak muat: {text}')
    top = (y1 + y2) / 2 - total / 2
    for i, line in enumerate(lines):
        if align == 'center':
            d.text(((x1 + x2) / 2, top + i * lh), line, font=font, fill=color, anchor='mt')
        else:
            d.text((x1 + pad, top + i * lh), line, font=font, fill=color)


def label(d, x, y, text, font=None, anchor='mm', color=NAVY):
    font = font or F(44, 'SemiBold')
    tw = d.textlength(text, font=font)
    left = x - tw / 2 if anchor == 'mm' else x
    d.rectangle((left - 6, y - 30, left + tw + 6, y + 30), fill='white')
    d.text((left, y), text, font=font, fill=color, anchor='lm')


# ---------------------------------------------------------------- 06 sebelum/sesudah

def before_after():
    H = 1640
    img, d = canvas(H, 'Sebelum dan sesudah M07')
    cols = [((40, 930), 'Sebelum (M06)', SKY, ['Hanya data saat ini', 'Pindah menimpa data lama', 'Hak ubah dari jenis akun']),
            ((970, 1860), 'Sesudah (M07)', PALE_YELLOW, ['Riwayat keanggotaan', 'Riwayat tautan akun', 'Hak ubah dari tautan aktif'])]
    for (x1, x2), head, fill, items in cols:
        d.rounded_rectangle((x1, 230, x2, 790), radius=22, fill=fill, outline=NAVY, width=4)
        d.rounded_rectangle((x1, 230, x2, 330), radius=22, fill=NAVY)
        d.rectangle((x1, 300, x2, 330), fill=NAVY)
        d.text(((x1 + x2) / 2, 280), head, font=F(52, 'Bold'), fill='white', anchor='mm')
        for i, item in enumerate(items):
            y = 380 + i * 130
            d.ellipse((x1 + 34, y + 12, x1 + 58, y + 36), fill=NAVY)
            if d.textlength(item, font=F(46, 'SemiBold')) > x2 - x1 - 120:
                ERRORS.append(f'butir terlalu panjang: {item}')
            d.text((x1 + 84, y), item, font=F(46, 'SemiBold'), fill=NAVY)
    d.text((40, 830), 'Mengapa', font=F(60, 'Head'), fill=NAVY)
    reasons = ['Riwayat pindah tidak hilang', 'Satu akun utama aktif per keluarga',
               'Simpan bersamaan: satu berhasil, lainnya 409', 'Aplikasi lama tetap membaca data benar']
    y = 920
    for r in reasons:
        d.rounded_rectangle((40, y, W - 40, y + 120), radius=18, fill=SKY, outline=NAVY, width=4)
        d.rectangle((44, y + 4, 64, y + 116), fill=YELLOW)
        box(d, (64, y, W - 40, y + 120), r, fill=SKY, font=F(46, 'SemiBold'), align='left', pad=40)
        d.rounded_rectangle((40, y, W - 40, y + 120), radius=18, outline=NAVY, width=4)
        y += 144
    footer(d, H, 'Detail: gambar teknis M7.1 (file asli)', y)
    img.save(OUT / '06-native-before-after.png')


# ---------------------------------------------------------------- 07 userflow

def userflow():
    H = 2060
    img, d = canvas(H, 'Alur akun utama dan akun tambahan')
    box(d, (40, 240, 920, 400), 'Akun utama: aktif saat daftar')
    box(d, (980, 240, 1860, 400), 'Akun tambahan: menunggu pengurus')
    box(d, (980, 470, 1400, 690), 'Disetujui: tautan tambahan')
    box(d, (1440, 470, 1860, 690), 'Ditolak: tetap menunggu, lihat status', fill='white', dashed_border=True)
    poly(d, [(1190, 400), (1190, 470)], arrow=True)
    poly(d, [(1650, 400), (1650, 470)], arrow=True)
    box(d, (420, 760, 1480, 900), 'Masuk, server cek tautan aktif', fill=YELLOW, font=F(46, 'Bold'))
    poly(d, [(480, 400), (480, 760)], arrow=True)
    poly(d, [(1190, 690), (1190, 760)], arrow=True)
    xs = [(40, 620), (660, 1240), (1280, 1860)]
    poly(d, [(950, 900), (950, 950)])
    poly(d, [(330, 950), (1570, 950)])
    for x1, x2 in xs:
        poly(d, [((x1 + x2) / 2, 950), ((x1 + x2) / 2, 1010)], arrow=True)
    box(d, (40, 1010, 620, 1190), 'Utama: lihat dan ubah')
    box(d, (660, 1010, 1240, 1190), 'Tambahan: hanya lihat')
    box(d, (1280, 1010, 1860, 1190), 'Belum tertaut: 404', fill='white', dashed_border=True)
    box(d, (40, 1270, 620, 1450), 'Simpan dengan versi')
    poly(d, [(330, 1190), (330, 1270)], arrow=True)
    box(d, (40, 1530, 620, 1770), 'Versi berubah: 409, muat ulang', fill='white', dashed_border=True)
    poly(d, [(330, 1450), (330, 1530)], arrow=True)
    box(d, (660, 1270, 1240, 1450), 'Coba ubah: 403', fill='white', dashed_border=True)
    poly(d, [(950, 1190), (950, 1270)], arrow=True)
    box(d, (660, 1530, 1860, 1770), 'Layanan warga lain tetap ada untuk semua akun aktif', fill=PALE_YELLOW)
    footer(d, H, 'Detail: gambar teknis M7.2 (file asli)', 1770)
    img.save(OUT / '07-native-userflow.png')


# ---------------------------------------------------------------- 08 use case

def ellipse(d, cx, cy, w, h, text, emph=False):
    d.ellipse((cx - w / 2, cy - h / 2, cx + w / 2, cy + h / 2), fill=YELLOW if emph else SKY, outline=NAVY, width=4)
    font = F(44, 'SemiBold')
    lines = wrap(d, text, font, w * .78)
    if len(lines) * 54 > h * .82:
        ERRORS.append(f'elips tidak muat: {text}')
    top = cy - (len(lines) * 54 - 10) / 2
    for i, line in enumerate(lines):
        d.text((cx, top + i * 54), line, font=font, fill=NAVY, anchor='mt')


def use_case():
    H = 1960
    img, d = canvas(H, 'Use case pengurus M07')
    d.rounded_rectangle((300, 230, 1880, 1760), radius=22, outline=NAVY, width=5, fill='white')
    d.text((330, 246), 'Sistem RT05 TAKEDA', font=F(44, 'Bold'), fill=NAVY)
    packs = [((330, 320, 1080, 900), 'Keluarga dan anggota', ['Buat keluarga', 'Ganti kepala', 'Pindah anggota']),
             ((1120, 320, 1860, 900), 'Akun warga', ['Setujui akun tambahan', 'Pindah akun utama', 'Arsip akun']),
             ((330, 940, 1080, 1520), 'Rumah', ['Tambah, ubah rumah', 'Kelola pemilik', 'Arsip rumah']),
             ((1120, 940, 1860, 1520), 'Hunian', ['Mulai hunian', 'Akhiri hunian', 'Pindah rumah'])]
    for (x1, y1, x2, y2), name, items in packs:
        d.rectangle((x1, y1, x2, y2), outline=NAVY, width=3, fill='#fbfdff')
        tw = d.textlength(name, font=F(44, 'Bold'))
        d.polygon([(x1, y1), (x1 + tw + 40, y1), (x1 + tw + 40, y1 + 46), (x1 + tw + 20, y1 + 66), (x1, y1 + 66)], fill=SKY, outline=NAVY)
        d.text((x1 + 16, y1 + 8), name, font=F(44, 'Bold'), fill=NAVY)
        for i, item in enumerate(items):
            ellipse(d, (x1 + x2) / 2, y1 + 160 + i * 150, 680, 130, item, emph=item.startswith('Pindah'))
    # Aktor dan asosiasi; paket kanan lewat koridor antar baris (y=920) dan antar kolom (x=1100).
    ax, ay = 150, 990
    d.ellipse((ax - 34, ay - 130, ax + 34, ay - 62), outline=NAVY, width=5, fill=SKY)
    d.line((ax, ay - 62, ax, ay + 30), fill=NAVY, width=5)
    d.line((ax - 52, ay - 30, ax + 52, ay - 30), fill=NAVY, width=5)
    d.line((ax, ay + 30, ax - 42, ay + 100), fill=NAVY, width=5)
    d.line((ax, ay + 30, ax + 42, ay + 100), fill=NAVY, width=5)
    d.text((ax, ay + 120), 'Pengurus', font=F(44, 'Bold'), fill=NAVY, anchor='mt')
    d.text((ax, ay + 176), 'semua', font=F(44), fill=MUTED, anchor='mt')
    d.text((ax, ay + 230), 'setara', font=F(44), fill=MUTED, anchor='mt')
    hand = (ax + 52, ay - 30)
    poly(d, [hand, (330, 610)])
    poly(d, [hand, (330, 1230)])
    poly(d, [hand, (300, 920), (1100, 920), (1100, 610), (1120, 610)])
    poly(d, [(1100, 920), (1100, 1230), (1120, 1230)])
    # Include bersama.
    ix, iy = 1090, 1660
    ellipse(d, ix, iy, 1200, 120, 'Kunci data; 409 bila sudah berubah', emph=True)
    for (sx, sy), (tx, ty), lx in (((705, 1520), (860, 1604), 360), ((1490, 1520), (1320, 1604), 1500)):
        dashed(d, (sx, sy), (tx, ty))
        arrowhead(d, (tx, ty), (sx, sy), open_=True)
        label(d, lx, 1562, '«include»', anchor='l')
    footer(d, H, 'Kuning = pindah atomik. Detail: gambar teknis M7.3', 1760)
    img.save(OUT / '08-native-usecase.png')


# ---------------------------------------------------------------- 09 sequence

def sequence():
    lanes = {'a': (220, 'Pengurus A'), 'b': (660, 'Pengurus B'), 'api': (1130, 'Server API'), 'db': (1650, 'Database')}
    img = Image.new('RGB', (W, 3000), 'white')
    d = ImageDraw.Draw(img)
    for x, name in lanes.values():
        dashed(d, (x, 360), (x, 2900), width=3)
        box(d, (x - 190, 250, x + 190, 360), name, font=F(44, 'Bold'))
    state = {'y': 410}

    def msg(a, b, text, ret=False):
        xa, xb = lanes[a][0], lanes[b][0]
        font = F(44, 'Regular' if ret else 'SemiBold')
        lines = wrap(d, text, font, max(abs(xb - xa) - 40, 420))
        y = state['y']
        for i, line in enumerate(lines):
            tw = d.textlength(line, font=font)
            cx = (xa + xb) / 2
            d.rectangle((cx - tw / 2 - 6, y + i * 54 - 2, cx + tw / 2 + 6, y + i * 54 + 50), fill='white')
            d.text((cx, y + i * 54), line, font=font, fill=NAVY, anchor='mt')
        ly = y + len(lines) * 54 + 12
        if ret:
            dashed(d, (xa, ly), (xb, ly))
        else:
            d.line((xa, ly, xb, ly), fill=NAVY, width=4)
        arrowhead(d, (xb, ly), (xa, ly), open_=ret)
        state['y'] = ly + 40

    def frame(top, name, fill):
        x1, x2, y2 = 900, 1870, state['y'] - 10
        d.rectangle((x1, top, x2, y2), outline=NAVY, width=4)
        tw = d.textlength(name, font=F(44, 'Bold')) + 40
        d.polygon([(x1, top), (x1 + tw, top), (x1 + tw, top + 50), (x1 + tw - 20, top + 70), (x1, top + 70)], fill=fill, outline=NAVY)
        d.text((x1 + 16, top + 8), name, font=F(44, 'Bold'), fill=NAVY)
        state['y'] = y2 + 30

    msg('a', 'api', '1. Pindah anggota, versi 7')
    msg('b', 'api', '2. Sama, versi 7')
    top = state['y']; state['y'] += 90
    msg('api', 'db', '3. Kunci kedua keluarga')
    msg('api', 'db', '4. Tutup lama, buka baru')
    msg('api', 'db', '5. Versi 8, COMMIT')
    frame(top, 'transaksi A', YELLOW)
    msg('api', 'a', '6. 200 berhasil', ret=True)
    top = state['y']; state['y'] += 90
    msg('api', 'db', '7. Versi kini 8: batal')
    frame(top, 'transaksi B', SKY)
    msg('api', 'b', '8. 409: muat ulang', ret=True)
    y = state['y']
    box(d, (40, y, W - 40, y + 130), 'Database juga menolak data aktif ganda: 409, bukan 500', fill=PALE_YELLOW, font=F(44, 'SemiBold'))
    H = int(y + 130 + 140)
    assert H <= MAX_H, f'sequence {H} > {MAX_H}'
    out = img.crop((0, 0, W, H))
    od = ImageDraw.Draw(out)
    od.rectangle((0, y + 132, W, H), fill='white')
    od.rectangle((0, 0, W, 230), fill='white')
    od.rectangle((0, 0, W, 16), fill=YELLOW)
    od.text((40, 40), 'Pindah anggota bersamaan', font=F(64, 'Head'), fill=NAVY)
    od.text((40, 130), STATUS, font=F(44), fill=MUTED)
    footer(od, H, 'Detail: gambar teknis M7.4 (file asli)', y + 130)
    out.save(OUT / '09-native-sequence.png')


# ---------------------------------------------------------------- 10 ERD konseptual

def erd():
    H = 1720
    img, d = canvas(H, 'ERD M07 (konsep)')
    BW, BH = 480, 190
    cx = {1: 300, 2: 950, 3: 1600}
    ry = {1: 240, 2: 590, 3: 940, 4: 1290}
    boxes = {}

    def node(key, c, r, text, new=False):
        rect = (cx[c] - BW / 2, ry[r], cx[c] + BW / 2, ry[r] + BH)
        boxes[key] = rect
        box(d, rect, text, fill=PALE_YELLOW if new else 'white', font=F(46, 'Bold'))
        if new:
            d.rectangle((rect[0] + 4, rect[1] + 4, rect[0] + 22, rect[3] - 4), fill=YELLOW)

    node('pemilik', 1, 1, 'pemilik rumah')
    node('rumah', 2, 1, 'rumah')
    node('hun', 3, 1, 'penghunian')
    node('mem', 2, 2, 'keanggotaan keluarga\n(baru)', new=True)
    node('kel', 3, 2, 'keluarga')
    node('warga', 2, 3, 'warga')
    node('link', 3, 3, 'tautan akun\n(baru)', new=True)
    node('akun', 3, 4, 'akun')
    font = F(44, 'Bold')

    def card(x, y, text, anchor):
        tw = d.textlength(text, font=font)
        left = {'l': x - tw, 'r': x, 'c': x - tw / 2}[anchor]
        d.rectangle((left - 4, y - 2, left + tw + 4, y + 52), fill='white')
        d.text((left, y), text, font=font, fill=OCHRE)

    def horiz(left, right, lc, rc):
        a, b = boxes[left], boxes[right]
        y = (a[1] + a[3]) / 2
        poly(d, [(a[2], y), (b[0], y)])
        card(a[2] + 8, y - 60, lc, 'r')
        card(b[0] - 8, y + 6, rc, 'l')

    def vert(top, bottom, tc, bc):
        a, b = boxes[top], boxes[bottom]
        x = (a[0] + a[2]) / 2
        poly(d, [(x, a[3]), (x, b[1])])
        card(x + 12, a[3] + 4, tc, 'r')
        card(x + 12, b[1] - 56, bc, 'r')

    horiz('pemilik', 'rumah', '0..1', '0..*')
    horiz('rumah', 'hun', '1', '0..*')
    vert('hun', 'kel', '0..*', '1')
    horiz('mem', 'kel', '0..*', '1')
    vert('mem', 'warga', '0..*', '1')
    vert('kel', 'link', '1', '0..*')
    horiz('warga', 'link', '1', '0..*')
    vert('link', 'akun', '0..*', '1')
    # Aturan aktif.
    x1, y1, x2, y2 = 60, 590, 600, 1250
    d.rounded_rectangle((x1, y1, x2, y2), radius=18, fill=SKY, outline=NAVY, width=4)
    d.text((x1 + 28, y1 + 24), 'Aturan aktif', font=F(48, 'Head'), fill=NAVY)
    y = y1 + 100
    for rule in ['1 hunian aktif per rumah', '1 keanggotaan aktif per warga', '1 tautan aktif per akun', '1 akun utama aktif per keluarga']:
        for i, line in enumerate(wrap(d, rule, F(44, 'SemiBold'), x2 - x1 - 56)):
            d.text((x1 + 28, y), line, font=F(44, 'SemiBold'), fill=NAVY)
            y += 54
        y += 34
    if y > y2:
        ERRORS.append('aturan aktif tidak muat')
    footer(d, H, 'Kolom lengkap: gambar teknis M7.5 (file asli)', max(y2, boxes['akun'][3]))
    img.save(OUT / '10-native-erd.png')


before_after()
userflow()
use_case()
sequence()
erd()
assert not ERRORS, ERRORS
for name in ('06-native-before-after.png', '07-native-userflow.png', '08-native-usecase.png', '09-native-sequence.png', '10-native-erd.png'):
    w, h = Image.open(OUT / name).size
    assert w == W and h <= MAX_H, (name, w, h)
    print(f'{name}: {w} x {h}')
