"""Gambar Drive M07: sebelum/sesudah, userflow akun utama vs tambahan, use case pengurus,
sequence pindah anggota atomik + 409, dan ERD aktual dengan partial unique index.

Jalankan dari root repo: python docs/evidence/m07/drive/render_m07_drive.py
Butuh Pillow. Palet dan font sama dengan docs/evidence/m06/drive/supplement/render_supplement.py
(navy/kuning/sky, Public Sans + Bricolage Grotesque dari mobile/assets/fonts). Helper disalin, bukan
di-import, karena skrip M06 merender ulang lima PNG M06 saat di-import.
Isi diturunkan dari migration 2026_10_13_000100_m07_keanggotaan_tautan_akun, app/Domain/Residents,
Registration::approve, docs/evidence/m07/CONTRACT.md, dan mobile/lib/features/household.
Tidak membaca kredensial, database, staging, atau data warga. Nama "Keluarga A", "Anggota B" fiktif.
"""
from pathlib import Path
import math
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[4]
OUT = Path(__file__).resolve().parent
FONTS = ROOT / 'mobile/assets/fonts'
NAVY, SKY, YELLOW, MUTED, GREY = '#0b1f3a', '#eaf6ff', '#f9c846', '#35465b', '#c9d3df'
PALE_YELLOW, OCHRE, RED = '#fff4cc', '#9a6b00', '#a23b2a'

_cache = {}
TOO_LONG = []


def F(size, weight='Regular'):
    key = (size, weight)
    if key not in _cache:
        name = 'BricolageGrotesque-Bold.ttf' if weight == 'Head' else f'PublicSans-{weight}.ttf'
        _cache[key] = ImageFont.truetype(str(FONTS / name), size)
    return _cache[key]


def wrap(draw, text, font, width):
    lines, line = [], ''
    for word in text.split(' '):
        trial = f'{line} {word}'.strip()
        if draw.textlength(trial, font=font) <= width or not line:
            line = trial
        else:
            lines.append(line)
            line = word
    lines.append(line)
    for item in lines:
        if draw.textlength(item, font=font) > width + 1:
            TOO_LONG.append(f'kata terlalu panjang: {item}')
    return lines


def canvas(w, h, title, subtitle):
    img = Image.new('RGB', (w, h), 'white')
    d = ImageDraw.Draw(img)
    d.rectangle((0, 0, w, 14), fill=YELLOW)
    d.text((40, 36), title, font=F(40, 'Head'), fill=NAVY)
    assert d.textlength(title, font=F(40, 'Head')) <= w - 80, title
    for i, line in enumerate(wrap(d, subtitle, F(23), w - 80)):
        d.text((40, 96 + i * 30), line, font=F(23), fill=MUTED)
    return img, d


def footer(d, w, h, text):
    d.line((40, h - 64, w - 40, h - 64), fill=GREY, width=2)
    assert d.textlength(text, font=F(20)) <= w - 80, text
    d.text((40, h - 46), text, font=F(20), fill=MUTED)


def dashed(d, a, b, fill=NAVY, width=3, dash=14, gap=9):
    (x1, y1), (x2, y2) = a, b
    length = math.hypot(x2 - x1, y2 - y1)
    if length == 0:
        return
    ux, uy = (x2 - x1) / length, (y2 - y1) / length
    pos = 0
    while pos < length:
        end = min(pos + dash, length)
        d.line((x1 + ux * pos, y1 + uy * pos, x1 + ux * end, y1 + uy * end), fill=fill, width=width)
        pos = end + gap


def dashed_rect(d, box, fill=NAVY, width=3):
    x1, y1, x2, y2 = box
    for a, b in (((x1, y1), (x2, y1)), ((x2, y1), (x2, y2)), ((x2, y2), (x1, y2)), ((x1, y2), (x1, y1))):
        dashed(d, a, b, fill, width)


def arrowhead(d, tip, frm, fill=NAVY, size=18, open_=False):
    ang = math.atan2(tip[1] - frm[1], tip[0] - frm[0])
    p1 = (tip[0] - size * math.cos(ang - .45), tip[1] - size * math.sin(ang - .45))
    p2 = (tip[0] - size * math.cos(ang + .45), tip[1] - size * math.sin(ang + .45))
    if open_:
        d.line((p1, tip, p2), fill=fill, width=3)
    else:
        d.polygon([tip, p1, p2], fill=fill)


def poly(d, pts, dashed_line=False, arrow=False):
    for a, b in zip(pts, pts[1:]):
        if dashed_line:
            dashed(d, a, b, width=3)
        else:
            d.line((a, b), fill=NAVY, width=3)
    if arrow:
        arrowhead(d, pts[-1], pts[-2])


def box(d, rect, text, fill=SKY, font=None, outline=NAVY, radius=14, align='center', pad=18, gap=6, dashed_border=False, color=NAVY):
    """Kotak dengan teks terbungkus; gagal (TOO_LONG) bila teks tidak muat tingginya."""
    font = font or F(22, 'SemiBold')
    x1, y1, x2, y2 = rect
    if dashed_border:
        d.rectangle(rect, fill=fill)
        dashed_rect(d, rect)
    else:
        d.rounded_rectangle(rect, radius=radius, fill=fill, outline=outline, width=3)
    lines = []
    for para in text.split('\n'):
        lines += wrap(d, para, font, x2 - x1 - 2 * pad)
    lh = font.size + gap
    total = len(lines) * lh - gap
    if total > y2 - y1 - 16:
        TOO_LONG.append(f'teks melebihi tinggi kotak: {text[:50]}')
    top = (y1 + y2) / 2 - total / 2
    for i, line in enumerate(lines):
        if align == 'center':
            d.text(((x1 + x2) / 2, top + i * lh), line, font=font, fill=color, anchor='mt')
        else:
            d.text((x1 + pad, top + i * lh), line, font=font, fill=color)
    return rect


def lines_at(d, x, y, items, font, width, fill=NAVY, gap=8, bullet=True):
    """Daftar poin; mengembalikan y akhir."""
    for item in items:
        ls = wrap(d, item, font, width - (26 if bullet else 0))
        for i, line in enumerate(ls):
            if bullet and i == 0:
                d.text((x, y), '•', font=font, fill=fill)
            d.text((x + (26 if bullet else 0), y), line, font=font, fill=fill)
            y += font.size + gap
        y += 6
    return y


def tag(d, x, y, text, fill=YELLOW, font=None):
    font = font or F(19, 'Bold')
    tw = d.textlength(text, font=font)
    d.rounded_rectangle((x, y, x + tw + 20, y + 32), radius=8, fill=fill, outline=NAVY, width=2)
    d.text((x + 10, y + 16), text, font=font, fill=NAVY, anchor='lm')
    return x + tw + 20


def label_on_line(d, x, y, text, font=None, anchor='mm'):
    font = font or F(19, 'SemiBold')
    tw = d.textlength(text, font=font)
    if anchor == 'mm':
        d.rectangle((x - tw / 2 - 5, y - 13, x + tw / 2 + 5, y + 13), fill='white')
    else:
        d.rectangle((x - 3, y - 13, x + tw + 7, y + 13), fill='white')
    d.text((x, y) if anchor == 'mm' else (x + 2, y), text, font=font, fill=NAVY, anchor='mm' if anchor == 'mm' else 'lm')


# ---------------------------------------------------------------- 1. Sebelum dan sesudah

def before_after():
    W, H = 1900, 1430
    img, d = canvas(W, H, 'Sebelum dan sesudah M07: dari penunjuk "saat ini" ke riwayat',
                    'Implementasi M07: alur dan aturan; hasil penerimaan ada di laporan. Kiri = M06, kanan = M07. Contoh pengandaian dengan nama fiktif "Keluarga A", "Keluarga C", dan "Anggota B".')
    L, R = (40, 920), (980, 1860)
    for (x1, x2), head, fill in ((L, 'SEBELUM: M06', SKY), (R, 'SESUDAH: M07', PALE_YELLOW)):
        d.rounded_rectangle((x1, 180, x2, 1040), radius=18, outline=NAVY, width=3, fill='white')
        d.rounded_rectangle((x1, 180, x2, 236), radius=18, fill=NAVY)
        d.rectangle((x1, 210, x2, 236), fill=NAVY)
        d.text(((x1 + x2) / 2, 208), head, font=F(26, 'Bold'), fill='white', anchor='mm')

    # Kiri: pointer tunggal.
    x1, x2 = L
    d.text((x1 + 24, 258), 'Hanya penunjuk "saat ini" di baris warga dan akun:', font=F(22, 'Bold'), fill=NAVY)
    box(d, (x1 + 24, 300, x1 + 420, 420), 'warga\nkeluarga_id, is_kepala', fill='#f5f7fa', font=F(22, 'SemiBold'))
    box(d, (x1 + 456, 300, x2 - 24, 420), 'akun\nwarga_id, jenis: utama | tambahan', fill='#f5f7fa', font=F(22, 'SemiBold'))
    d.text((x1 + 24, 448), 'Andaikan Anggota B pindah dari Keluarga A ke Keluarga C', font=F(21, 'Bold'), fill=NAVY)
    box(d, (x1 + 24, 490, x2 - 24, 600), 'M06 belum punya fitur pindah. Bila penunjuk diubah, keluarga_id = Keluarga C\nmenimpa "Keluarga A"; tidak ada jejak kapan dan mengapa',
        fill='white', font=F(21, 'SemiBold'), align='left')
    d.text((x1 + 24, 630), 'Kelemahan yang ditemukan saat membuka M07:', font=F(22, 'Bold'), fill=NAVY)
    lines_at(d, x1 + 24, 672, [
        'Riwayat keluarga dan tautan akun tidak tersimpan; perpindahan menimpa nilai lama.',
        'Hak mengubah data keluarga dibaca dari akun.jenis, bukan dari hubungan yang sedang berlaku.',
        'Database belum mencegah dua akun utama aktif dalam satu keluarga.',
        'Mutasi anggota belum memakai versi, sehingga dua perubahan bersamaan bisa saling menimpa.',
        'Pemindahan anggota, akun utama, dan arsip akun belum ada.',
    ], F(21), x2 - x1 - 48)

    # Kanan: histori.
    x1, x2 = R
    d.text((x1 + 24, 258), 'Dua tabel riwayat baru + penunjuk lama tetap disinkronkan:', font=F(22, 'Bold'), fill=NAVY)
    box(d, (x1 + 24, 300, x1 + 420, 420), 'keanggotaan_keluarga\nwarga di keluarga, mulai_at s.d. selesai_at', fill=PALE_YELLOW, font=F(21, 'SemiBold'))
    box(d, (x1 + 456, 300, x2 - 24, 420), 'tautan_akun\nakun ke warga dan keluarga, is_utama, mulai s.d. selesai', fill=PALE_YELLOW, font=F(21, 'SemiBold'))
    d.text((x1 + 24, 448), 'Contoh yang sama, kini tersimpan sebagai dua baris riwayat:', font=F(21, 'Bold'), fill=NAVY)
    cols = [(x1 + 24, 'Keluarga'), (x1 + 194, 'mulai_at'), (x1 + 404, 'selesai_at'), (x1 + 604, 'alasan_selesai')]
    ty = 490
    d.rectangle((x1 + 24, ty, x2 - 24, ty + 40), fill=NAVY)
    for cx, label in cols:
        d.text((cx + 10, ty + 20), label, font=F(19, 'Bold'), fill='white', anchor='lm')
    rows = [('Keluarga A', 'NULL (sebelum M07)', 'saat pindah', 'pindah_keluarga'), ('Keluarga C', 'saat pindah', 'kosong = aktif', '-')]
    for r, row in enumerate(rows):
        y = ty + 40 + r * 44
        d.rectangle((x1 + 24, y, x2 - 24, y + 44), fill='white' if r == 0 else SKY, outline=GREY)
        for (cx, _), val in zip(cols, row):
            f = F(19, 'SemiBold' if r == 1 else 'Regular')
            if d.textlength(val, font=f) > 196:
                TOO_LONG.append(f'sel: {val}')
            d.text((cx + 10, y + 22), val, font=f, fill=NAVY, anchor='lm')
    d.text((x1 + 24, 630), 'Yang berubah:', font=F(22, 'Bold'), fill=NAVY)
    lines_at(d, x1 + 24, 672, [
        'Penunjuk lama (warga.keluarga_id, akun.warga_id, akun.jenis) tetap ada dan diperbarui dalam transaksi yang sama, jadi aplikasi M06 tetap membaca data yang benar.',
        'Akses keluarga dan hak ubah dibaca dari tautan aktif (is_utama), bukan dari akun.jenis.',
        'Partial unique index menolak akun utama ganda dan keanggotaan aktif ganda; pelanggaran dijawab 409.',
        'versi wajib saat mengubah keluarga, anggota, verifikasi, akun utama, dan rumah; basi = 409. Arsip, hunian, dan status akun: cek status di bawah kunci, 409.',
        'Data lama dibackfill dengan mulai_at NULL: tanggal yang tidak diketahui tidak dikarang.',
    ], F(21), x2 - x1 - 48)

    # Mengapa.
    d.text((40, 1070), 'Mengapa perubahan ini dibuat', font=F(28, 'Head'), fill=NAVY)
    why = [('Riwayat utuh', 'Perpindahan anggota dan pergantian akun utama tidak menghapus catatan lama; pengurus dapat menelusuri kapan dan oleh siapa.'),
           ('Hak akses tepat', 'Hanya akun utama yang sedang tertaut boleh mengubah. Setelah akun utama dipindah, hak lama langsung tidak berlaku.'),
           ('Aman saat bersamaan', 'Dua pengurus atau dua perangkat yang menyimpan bersamaan tidak saling menimpa: satu berhasil, lainnya 409 dan diminta muat ulang.'),
           ('Kompatibel', 'Perubahan hanya menambah tabel dan kolom (additive). Rute dan field lama tidak diubah; rollback hanya menghapus tambahan M07.')]
    cw = (W - 80 - 3 * 24) / 4
    for i, (head, body) in enumerate(why):
        bx = 40 + i * (cw + 24)
        d.rounded_rectangle((bx, 1120, bx + cw, 1330), radius=14, fill=SKY, outline=NAVY, width=3)
        d.rectangle((bx + 3, 1123, bx + 14, 1327), fill=YELLOW)
        d.text((bx + 30, 1138), head, font=F(23, 'Bold'), fill=NAVY)
        y = 1176
        for line in wrap(d, body, F(19), cw - 52):
            d.text((bx + 30, y), line, font=F(19), fill=NAVY)
            y += 26
        if y > 1322:
            TOO_LONG.append(f'kartu {head}')
    footer(d, W, H, 'Sumber: migration 2026_10_13_000100, Domain/Residents/Links, docs/evidence/m07/CONTRACT.md | Implementasi M07: alur dan aturan; hasil penerimaan ada di laporan')
    img.save(OUT / '01-sebelum-sesudah-m07.png')


# ---------------------------------------------------------------- 2. Userflow utama vs tambahan

def diamond(d, cx, cy, w, h, text, font=None):
    font = font or F(21, 'Bold')
    d.polygon([(cx, cy - h / 2), (cx + w / 2, cy), (cx, cy + h / 2), (cx - w / 2, cy)], fill=YELLOW, outline=NAVY, width=3)
    lines = wrap(d, text, font, w * .55)
    lh = font.size + 5
    top = cy - (len(lines) * lh - 5) / 2
    if len(lines) * lh > h * .6:
        TOO_LONG.append(f'diamond: {text}')
    for i, line in enumerate(lines):
        d.text((cx, top + i * lh), line, font=font, fill=NAVY, anchor='mt')
    return (cx - w / 2, cy - h / 2, cx + w / 2, cy + h / 2)


def userflow():
    W, H = 1900, 1860
    img, d = canvas(W, H, 'Userflow warga: akun utama dan akun tambahan yang disetujui',
                    'Kotak biru = layar atau langkah; belah ketupat kuning = keputusan server; kotak putih bergaris = jawaban penolakan. '
                    'Alur dari mobile/lib/features/household dan CONTRACT.md. Implementasi M07: alur dan aturan; hasil penerimaan ada di laporan.')
    sf = F(21, 'SemiBold')
    # Pendaftaran.
    A, B = 470, 1430
    box(d, (A - 300, 190, A + 300, 290), 'Daftar akun utama dengan token RT 6 digit', font=sf)
    box(d, (A - 300, 330, A + 300, 430), 'Akun langsung aktif; tautan utama dibuat bersama rumah dan keluarga', font=F(20, 'SemiBold'))
    poly(d, [(A, 290), (A, 330)], arrow=True)
    box(d, (B - 300, 190, B + 300, 290), 'Daftar akun tambahan dengan token RT 6 digit', font=sf)
    box(d, (B - 300, 330, B + 300, 430), 'Status menunggu: hanya melihat status permohonan; data keluarga 403', font=F(20, 'SemiBold'))
    poly(d, [(B, 290), (B, 330)], arrow=True)
    diamond(d, B, 530, 420, 150, 'Pengurus memproses?')
    poly(d, [(B, 430), (B, 455)], arrow=True)
    box(d, (1660, 590, 1880, 790), 'Permohonan ditolak: akun tetap menunggu, sesi dicabut; hanya status dan alasan', fill='white', font=F(19, 'SemiBold'), dashed_border=True)
    poly(d, [(B + 210, 530), (1770, 530), (1770, 590)], arrow=True)
    label_on_line(d, 1690, 530, 'tolak')
    box(d, (B - 300, 640, B + 160, 760), 'Disetujui: tautkan ke anggota yang ada atau anggota baru; tautan TAMBAHAN dibuat', font=F(20, 'SemiBold'))
    poly(d, [(B, 605), (B, 640)], arrow=True)
    label_on_line(d, B + 12, 620, 'setujui', anchor='l')

    # Masuk dan keputusan tautan.
    C = 950
    box(d, (C - 220, 800, C + 220, 880), 'Masuk ke aplikasi', font=F(22, 'Bold'))
    poly(d, [(A, 430), (A, 840), (C - 220, 840)], arrow=True)
    poly(d, [(B - 70, 760), (B - 70, 840), (C + 220, 840)], arrow=True)
    diamond(d, C, 1000, 470, 170, 'GET /household: tautan aktif akun ini?')
    poly(d, [(C, 880), (C, 915)], arrow=True)
    box(d, (C - 190, 1130, C + 190, 1260), 'Belum tertaut: 404 HOUSEHOLD_NOT_LINKED, "Akun belum terhubung dengan keluarga."', fill='white',
        font=F(19, 'SemiBold'), dashed_border=True)
    poly(d, [(C, 1085), (C, 1130)], arrow=True)
    label_on_line(d, C, 1106, 'tidak ada', anchor='l')

    # Utama.
    UX = 360
    poly(d, [(C - 235, 1000), (UX, 1000), (UX, 1060)], arrow=True)
    label_on_line(d, 600, 1000, 'utama (is_utama)')
    box(d, (UX - 300, 1060, UX + 300, 1190), 'Keluarga saya: data rumah, anggota, status verifikasi; tombol "Ubah data keluarga" dan "Tambah anggota"', font=F(20, 'SemiBold'))
    box(d, (UX - 300, 1230, UX + 300, 1320), 'Simpan: aplikasi mengirim versi keluarga yang terakhir dibaca', font=F(20, 'SemiBold'))
    poly(d, [(UX, 1190), (UX, 1230)], arrow=True)
    diamond(d, UX, 1430, 400, 160, 'Versi masih sama di server?')
    poly(d, [(UX, 1320), (UX, 1350)], arrow=True)
    box(d, (60, 1580, 330, 1720), 'Ya: tersimpan (200/201/204), versi naik', font=F(20, 'SemiBold'))
    poly(d, [(UX - 50, 1490), (195, 1490), (195, 1580)], arrow=True)
    label_on_line(d, 250, 1490, 'ya')
    box(d, (390, 1580, 720, 1760), 'Tidak: 409 VERSION_CONFLICT; data terbaru dimuat ulang, pengguna mengisi lagi', fill='white',
        font=F(19, 'SemiBold'), dashed_border=True)
    poly(d, [(UX + 50, 1490), (555, 1490), (555, 1580)], arrow=True)
    label_on_line(d, 480, 1490, 'tidak')

    # Tambahan.
    TX = 1500
    poly(d, [(C + 235, 1000), (TX, 1000), (TX, 1060)], arrow=True)
    label_on_line(d, 1320, 1000, 'tambahan (disetujui)')
    box(d, (TX - 330, 1060, TX + 330, 1190), 'Keluarga saya: hanya membaca. Tombol ubah tidak ditampilkan; teks "Perubahan data keluarga dilakukan oleh akun utama."',
        font=F(20, 'SemiBold'))
    box(d, (TX - 330, 1240, TX + 330, 1350), 'Bila mutasi tetap dikirim lewat API: 403 PRIMARY_ACCOUNT_REQUIRED, data tidak berubah', fill='white',
        font=F(19, 'SemiBold'), dashed_border=True)
    poly(d, [(TX, 1190), (TX, 1240)], arrow=True)
    # Catatan.
    nx1, ny1, nx2 = 800, 1420, 1860
    lines = wrap(d, 'Layanan warga lain (pengumuman, agenda, iuran, laporan) tetap tersedia untuk akun aktif, utama maupun tambahan. Akun nonaktif atau diarsipkan: sesi dicabut dan tidak dapat memakai layanan. Data keluarga lain selalu dijawab 404, bukan 403. '
                    'Pengurus dapat memindahkan status utama ke akun tambahan aktif di keluarga yang sama; hak ubah akun utama lama langsung berakhir.',
                 F(20), nx2 - nx1 - 40)
    ny2 = ny1 + len(lines) * 27 + 28
    d.polygon([(nx1, ny1), (nx2 - 22, ny1), (nx2, ny1 + 22), (nx2, ny2), (nx1, ny2)], fill=PALE_YELLOW, outline=NAVY)
    for i, line in enumerate(lines):
        d.text((nx1 + 20, ny1 + 14 + i * 27), line, font=F(20), fill=NAVY)
    assert ny2 < H - 80, 'catatan userflow menabrak footer'
    footer(d, W, H, 'Sumber: CONTRACT.md §4, Links::assertEditor, Registration::reject, HouseholdController, family_page.dart, family_actions.dart')
    img.save(OUT / '02-userflow-akun-utama-tambahan.png')


# ---------------------------------------------------------------- 3. Use case pengurus

def actor(d, x, y, label, note=None):
    d.ellipse((x - 24, y - 92, x + 24, y - 44), outline=NAVY, width=4, fill=SKY)
    d.line((x, y - 44, x, y + 20), fill=NAVY, width=4)
    d.line((x - 38, y - 22, x + 38, y - 22), fill=NAVY, width=4)
    d.line((x, y + 20, x - 30, y + 70), fill=NAVY, width=4)
    d.line((x, y + 20, x + 30, y + 70), fill=NAVY, width=4)
    y2 = y + 82
    for line in wrap(d, label, F(24, 'Bold'), 220):
        d.text((x, y2), line, font=F(24, 'Bold'), fill=NAVY, anchor='mt')
        y2 += 30
    if note:
        for line in wrap(d, note, F(19), 220):
            d.text((x, y2), line, font=F(19), fill=MUTED, anchor='mt')
            y2 += 25


def ellipse(d, cx, cy, w, h, label, emph=False):
    d.ellipse((cx - w / 2, cy - h / 2, cx + w / 2, cy + h / 2), fill=YELLOW if emph else SKY, outline=NAVY, width=3)
    font = F(20, 'SemiBold')
    lines = wrap(d, label, font, w * .74)
    if len(lines) * 25 > h * .78:
        TOO_LONG.append(f'elips: {label}')
    top = cy - (len(lines) * 25 - 5) / 2
    for i, line in enumerate(lines):
        d.text((cx, top + i * 25), line, font=font, fill=NAVY, anchor='mt')


def use_case_admin():
    W, H = 1900, 1640
    img, d = canvas(W, H, 'Use case pengurus M07: rumah, keluarga, anggota, akun, hunian',
                    'Notasi UML: aktor, batas sistem, elips = use case. Garis ke sebuah paket berarti pengurus dapat menjalankan semua use case di dalamnya. '
                    'Semua pengurus setara; API tidak menerima field peran. Implementasi M07: alur dan aturan; hasil penerimaan ada di laporan.')
    d.rounded_rectangle((290, 200, 1640, 1430), radius=18, outline=NAVY, width=4, fill='white')
    d.text((314, 214), 'Sistem RT05 TAKEDA: REST API dan aplikasi Android pengurus', font=F(23, 'Bold'), fill=NAVY)
    P = {'kel': (320, 260, 940, 830, 'Keluarga dan anggota'), 'akun': (990, 260, 1610, 830, 'Akun warga'),
         'rumah': (320, 880, 940, 1260, 'Rumah'), 'hun': (990, 880, 1610, 1260, 'Hunian (penghunian)')}
    for x1, y1, x2, y2, name in P.values():
        d.rectangle((x1, y1, x2, y2), outline=NAVY, width=2, fill='#fbfdff')
        tw = d.textlength(name, font=F(21, 'Bold'))
        d.polygon([(x1, y1), (x1 + tw + 28, y1), (x1 + tw + 28, y1 + 22), (x1 + tw + 14, y1 + 36), (x1, y1 + 36)], fill=SKY, outline=NAVY)
        d.text((x1 + 12, y1 + 7), name, font=F(21, 'Bold'), fill=NAVY)
    ew, eh = 270, 112
    k = P['kel']
    for i, label in enumerate(['Buat keluarga (hunian opsional dibuat sekaligus)', 'Verifikasi keluarga', 'Ganti kepala keluarga',
                               'Tambah, ubah, atau akhiri anggota', 'Pindah anggota ke keluarga lain', 'Arsip keluarga (tanpa hunian dan akun aktif)']):
        cx = k[0] + 160 + (i % 2) * 300
        cy = k[1] + 130 + (i // 2) * 165
        ellipse(d, cx, cy, ew, eh, label, emph=(label.startswith('Pindah')))
    a = P['akun']
    for i, label in enumerate(['Setujui akun tambahan: tautkan ke anggota', 'Pindah akun utama ke akun tambahan aktif', 'Nonaktifkan atau aktifkan akun',
                               'Arsip akun: tautan berakhir, sesi dicabut', 'Lihat riwayat tautan akun', 'Tolak permohonan akun tambahan']):
        cx = a[0] + 160 + (i % 2) * 300
        cy = a[1] + 130 + (i // 2) * 165
        ellipse(d, cx, cy, ew, eh, label, emph=(label.startswith('Pindah')))
    r = P['rumah']
    for i, label in enumerate(['Tambah rumah', 'Ubah alamat dan tagihan (versi rumah)', 'Kelola pemilik rumah (terpisah dari penghuni)', 'Arsip rumah (tagihan lama tetap)']):
        cx = r[0] + 160 + (i % 2) * 300
        cy = r[1] + 110 + (i // 2) * 150
        ellipse(d, cx, cy, ew, eh, label)
    h_ = P['hun']
    for i, label in enumerate(['Mulai hunian keluarga di rumah kosong', 'Akhiri hunian', 'Pindah rumah: akhiri dan mulai atomik', 'Lihat riwayat hunian rumah']):
        cx = h_[0] + 160 + (i % 2) * 300
        cy = h_[1] + 110 + (i // 2) * 150
        ellipse(d, cx, cy, ew, eh, label, emph=label.startswith('Pindah'))
    # Asosiasi pengurus ke paket; paket kanan lewat koridor antar kolom (x=965).
    ax, ay = 150, 860
    hand = (ax + 40, ay - 22)
    poly(d, [hand, (320, 545)])
    poly(d, [hand, (320, 1070)])
    poly(d, [hand, (965, 855), (965, 545), (990, 545)])
    poly(d, [(965, 855), (965, 1070), (990, 1070)])
    actor(d, ax, ay, 'Pengurus', 'admin aplikasi, semua setara')
    # Include bersama.
    ix, iy = 965, 1340
    ellipse(d, ix, iy, 640, 104, 'Kunci baris; cek versi (ubah data) atau status (arsip, hunian, status akun); 409 bila berubah', emph=True)
    for sx in (630, 1300):
        top = (sx, 1260)
        tip = (ix + (sx - ix) * .42, iy - 44)
        dashed(d, top, tip, width=3)
        arrowhead(d, tip, top, open_=True)
        label_on_line(d, (top[0] + tip[0]) / 2 + (40 if sx > ix else -40), (top[1] + tip[1]) / 2, '«include»')
    nx = 1660
    lines_at(d, nx, 300, ['Elips kuning = perpindahan atomik: data lama ditutup dan data baru dibuka dalam satu transaksi.',
                          '«include» berlaku untuk semua use case pengubah di keempat paket, bukan hanya yang digambar garisnya.',
                          'Tidak ada hapus permanen, NIK/KTP, atau pembayaran online.'], F(19), 220)
    lines_at(d, 40, 1460, ['Layar pengurus untuk buat keluarga, tambah anggota, pindah akun utama, pindah anggota, ubah alamat, dan pemilik rumah ada di source mobile '
                           '(admin_edits.dart, admin_links.dart, admin_census.dart). Warga tidak memakai use case ini: lihat userflow M7.2.'],
             F(20), W - 80, bullet=False)
    footer(d, W, H, 'Sumber: routes/api.php, Domain/Residents (Households, Occupancies, Accounts), Registration::approve, CONTRACT.md §6 | tanpa data warga')
    img.save(OUT / '03-use-case-pengurus-m07.png')


# ---------------------------------------------------------------- 4. Sequence pindah anggota + 409

class Seq:
    def __init__(self, W, title, subtitle, lanes, top=200):
        self.img, self.d = canvas(W, 4200, title, subtitle)
        self.W, self.lanes = W, lanes
        d = self.d
        for key, (x, label) in lanes.items():
            dashed(d, (x, top + 96), (x, 4100), fill=MUTED, width=2, dash=10, gap=8)
            d.rounded_rectangle((x - 150, top, x + 150, top + 96), radius=12, fill=SKY, outline=NAVY, width=3)
            lines = wrap(d, label, F(22, 'Bold'), 270)
            t = top + 48 - (len(lines) * 28 - 6) / 2
            for i, line in enumerate(lines):
                d.text((x, t + i * 28), line, font=F(22, 'Bold'), fill=NAVY, anchor='mt')
        self.y = top + 140

    def label(self, x, y, lines, font, anchor='mt'):
        for i, line in enumerate(lines):
            tw = self.d.textlength(line, font=font)
            left = x - tw / 2 if anchor == 'mt' else x
            self.d.rectangle((left - 5, y + i * 27 - 2, left + tw + 5, y + i * 27 + 25), fill='white')
            self.d.text((x, y + i * 27), line, font=font, fill=NAVY, anchor=anchor)

    def msg(self, a, b, text, ret=False, num=None, width=None):
        d = self.d
        xa, xb = self.lanes[a][0], self.lanes[b][0]
        font = F(21, 'Regular' if ret else 'SemiBold')
        lines = wrap(d, f'{num}. {text}' if num else text, font, width or max(abs(xb - xa) - 40, 420))
        self.label((xa + xb) / 2, self.y, lines, font)
        ly = self.y + len(lines) * 27 + 8
        if ret:
            dashed(d, (xa, ly), (xb, ly), width=3)
        else:
            d.line((xa, ly, xb, ly), fill=NAVY, width=3)
        arrowhead(d, (xb, ly), (xa, ly), open_=ret)
        self.y = ly + 30

    def self_msg(self, a, text, num=None, width=330):
        d = self.d
        x = self.lanes[a][0]
        font = F(21, 'SemiBold')
        lines = wrap(d, f'{num}. {text}' if num else text, font, width)
        self.label(x + 70, self.y, lines, font, anchor='lt')
        y1, y2 = self.y + 4, self.y + len(lines) * 27 + 10
        d.line((x, y1, x + 55, y1, x + 55, y2, x + 4, y2), fill=NAVY, width=3)
        arrowhead(d, (x + 4, y2), (x + 55, y2))
        self.y = y2 + 30

    def open_frame(self):
        top = self.y
        self.y += 50
        return top

    def frame(self, x1, y1, x2, tag_, fill=YELLOW):
        d = self.d
        y2 = self.y - 6
        d.rectangle((x1, y1, x2, y2), outline=NAVY, width=3)
        tw = d.textlength(tag_, font=F(20, 'Bold')) + 28
        d.polygon([(x1, y1), (x1 + tw, y1), (x1 + tw, y1 + 22), (x1 + tw - 14, y1 + 36), (x1, y1 + 36)], fill=fill, outline=NAVY)
        d.text((x1 + 12, y1 + 7), tag_, font=F(20, 'Bold'), fill=NAVY)
        self.y = y2 + 24

    def operand(self, x1, x2, guard):
        dashed(self.d, (x1, self.y), (x2, self.y), width=2)
        self.label(x1 + 14, self.y + 10, [guard], F(20, 'SemiBold'), anchor='lt')
        self.y += 50

    def note(self, text):
        d = self.d
        x1, x2 = 40, self.W - 40
        lines = []
        for para in text.split('\n'):
            lines += wrap(d, para, F(21), x2 - x1 - 40)
        y1 = self.y + 10
        y2 = y1 + len(lines) * 28 + 26
        d.polygon([(x1, y1), (x2 - 22, y1), (x2, y1 + 22), (x2, y2), (x1, y2)], fill=PALE_YELLOW, outline=NAVY)
        for i, line in enumerate(lines):
            d.text((x1 + 20, y1 + 13 + i * 28), line, font=F(21), fill=NAVY)
        self.y = y2

    def finish(self, source, name):
        H = int(self.y + 100)
        img = self.img.crop((0, 0, self.W, H))
        ImageDraw.Draw(img).rectangle((0, self.y + 2, self.W, H), fill='white')
        footer(ImageDraw.Draw(img), self.W, H, source)
        img.save(OUT / name)


def move_sequence():
    lanes = {'a': (200, 'Pengurus A (aplikasi)'), 'b': (560, 'Pengurus B (aplikasi)'),
             'api': (1060, 'REST API Laravel'), 'db': (1620, 'PostgreSQL')}
    s = Seq(1900, 'Sequence diagram: pindah anggota antar keluarga secara atomik, dan 409 untuk yang kalah',
            'Dua pengurus memindahkan Anggota B (fiktif) hampir bersamaan dengan versi yang sama. Urutan kunci tetap: permohonan, rumah, '
            'keluarga (id naik), tautan, akun. Implementasi M07: alur dan aturan; hasil penerimaan ada di laporan.', lanes)
    s.msg('a', 'api', 'POST /admin/residents/{id}/move: keluarga_id tujuan, hubungan_keluarga, alasan, versi asal = n', num=1, width=760)
    s.msg('b', 'api', 'Permintaan yang sama dari pengurus B, versi asal = n', num=2, width=420)
    t = s.open_frame()
    s.msg('api', 'db', 'BEGIN; kunci baris keluarga asal dan tujuan dengan FOR UPDATE, urut id naik', num=3, width=500)
    s.msg('api', 'db', 'Baca ulang di bawah kunci: keanggotaan aktif masih di keluarga asal, versi = n, bukan kepala keluarga', num=4, width=500)
    s.msg('api', 'db', 'Tutup keanggotaan lama (selesai_at, alasan pindah_keluarga); buka keanggotaan baru di keluarga tujuan', num=5, width=500)
    s.msg('api', 'db', 'Bila anggota punya akun: tutup tautan lama, buka tautan baru sebagai TAMBAHAN (bukan utama)', num=6, width=500)
    s.msg('api', 'db', 'Perbarui penunjuk lama, naikkan versi kedua keluarga, tulis audit; COMMIT', num=7, width=500)
    s.frame(800, t, 1860, 'transaksi A (menang)')
    s.msg('api', 'a', '200: detail anggota di keluarga tujuan', ret=True, num=8, width=600)
    t = s.open_frame()
    s.msg('api', 'db', 'BEGIN; minta kunci keluarga yang sama: MENUNGGU sampai transaksi A selesai', num=9, width=500)
    s.msg('api', 'db', 'Baca ulang: anggota sudah pindah atau versi tidak lagi n; ROLLBACK, tidak ada baris yang berubah', num=10, width=500)
    s.frame(800, t, 1860, 'transaksi B (kalah)', fill=SKY)
    s.msg('api', 'b', '409 CONFLICT atau VERSION_CONFLICT: "Data berubah. Muat ulang lalu coba lagi."', ret=True, num=11, width=820)
    s.self_msg('b', 'Muat ulang detail anggota; isian tetap terbaca', num=12, width=300)
    s.note('Pertahanan terakhir: partial unique index keanggotaan_satu_aktif_per_warga dan tautan_satu_aktif_per_warga. Bila tetap ada '
           'penulis bersamaan yang lolos, pelanggaran constraint (juga deadlock atau serialisasi) dipetakan menjadi 409, bukan 500.\n'
           'Pola yang sama dipakai pindah akun utama (409 VERSION_CONFLICT atau PRIMARY_ACCOUNT_EXISTS dari tautan_satu_utama_per_keluarga) '
           'dan pindah rumah (rumah lama dan baru dikunci dulu; 409 HOUSE_OCCUPIED; tagihan tetap di rumah lama).\n'
           'Uji konkurensi dengan proses PHP terpisah memeriksa hasil ini: satu 200, satu 409, tepat satu keanggotaan dan satu tautan aktif. '
           'Hasil penerimaan ada di laporan.')
    s.finish('Sumber: Households::moveMember, Links::transaction/lockFamilies/assertVersion, M07ConcurrencyTest, CONTRACT.md §7 | tanpa data warga',
             '04-sequence-pindah-anggota-409.png')


# ---------------------------------------------------------------- 5. ERD aktual

ROW_H, HEAD_H = 32, 50


def table(d, x, y, w, name, cols, new=False):
    h = HEAD_H + len(cols) * ROW_H + 12
    d.rectangle((x, y, x + w, y + h), fill=PALE_YELLOW if new else 'white', outline=NAVY, width=3)
    d.rectangle((x, y, x + w, y + HEAD_H), fill=YELLOW if new else NAVY, outline=NAVY, width=3)
    d.text((x + w / 2, y + HEAD_H / 2), name, font=F(24, 'Bold'), fill=NAVY if new else 'white', anchor='mm')
    for i, col in enumerate(cols):
        cy = y + HEAD_H + 6 + i * ROW_H
        first = col.split(' ')[0]
        key = first if first in ('PK', 'FK', 'UK', 'NEW') else ''
        text = col[len(key):].strip() if key else col
        if key == 'NEW':
            key = '+'
        if key:
            d.text((x + 12, cy + 3), key, font=F(18, 'Bold'), fill=NAVY if key == 'PK' else OCHRE)
        font = F(20, 'SemiBold' if key in ('PK', '+') else 'Regular')
        if d.textlength(text, font=font) > w - 62:
            TOO_LONG.append(f'{name}: {text}')
        d.text((x + 52, cy + 1), text, font=font, fill=NAVY)
    return (x, y, x + w, y + h)


def card(d, pos, text, side, pad=8):
    x, y = pos
    font = F(19, 'Bold')
    tw = d.textlength(text, font=font)
    dx = {'l': -tw - pad, 'r': pad, 'c': -tw / 2}[side[0]]
    dy = {'a': -26, 'b': 6}[side[1]]
    d.rectangle((x + dx - 3, y + dy - 1, x + dx + tw + 3, y + dy + 22), fill='white')
    d.text((x + dx, y + dy), text, font=font, fill=OCHRE)


def erd_actual():
    W, H = 1900, 1560
    img, d = canvas(W, H, 'ERD aktual M07: keanggotaan keluarga, tautan akun, dan partial unique index',
                    'Diturunkan dari migration 2026_10_13_000100_m07_keanggotaan_tautan_akun. Implementasi M07: alur dan aturan; hasil penerimaan ada di laporan. '
                    'Hanya kolom kunci; tabel iuran dan layanan lain tidak digambar.')
    rumah = table(d, 40, 200, 380, 'rumah', ['PK id', 'FK pemilik_id (opsional)', 'UK kode_rumah', 'UK alamat_key', 'tagihan_aktif, mulai_tagih',
                                              'NEW versi (baru M07)', 'archived_at'])
    hun = table(d, 480, 200, 420, 'penghunian', ['PK id', 'FK rumah_id', 'FK keluarga_id', 'jenis_hunian: pemilik | kontrak', 'mulai_tanggal',
                                                  'selesai_tanggal (kosong = aktif)'])
    kel = table(d, 960, 200, 360, 'keluarga', ['PK id', 'status: belum_verifikasi |', '   terverifikasi', 'versi (wajib saat ubah)', 'archived_at'])
    pem = table(d, 40, 590, 380, 'pemilik_rumah', ['PK id', 'nama', 'whatsapp (opsional)', 'catatan', 'hanya untuk pengurus'])
    mem = table(d, 460, 560, 440, 'keanggotaan_keluarga', ['PK id', 'FK keluarga_id', 'FK warga_id', 'hubungan_keluarga (snapshot)',
                                                            'mulai_at (NULL = sebelum M07)', 'selesai_at (kosong = aktif)', 'alasan_selesai', 'sumber',
                                                            'FK dicatat_oleh_id (akun)', 'FK diakhiri_oleh_id (akun)'], new=True)
    link = table(d, 1400, 560, 460, 'tautan_akun', ['PK id', 'FK akun_id', 'FK warga_id', 'FK keluarga_id', 'is_utama', 'mulai_at (NULL = sebelum M07)',
                                                     'selesai_at (kosong = aktif)', 'alasan_selesai', 'sumber', 'FK dicatat_oleh_id (akun)',
                                                     'FK diakhiri_oleh_id (akun)'], new=True)
    warga = table(d, 960, 1040, 380, 'warga', ['PK id', 'FK keluarga_id (penunjuk lama)', 'hubungan_keluarga', 'is_kepala', 'status: aktif | pindah |',
                                                '   meninggal', 'archived_at'])
    akun = table(d, 1460, 1100, 400, 'akun', ['PK id', 'FK warga_id (penunjuk lama)', 'jenis: utama | tambahan', '   (penunjuk lama)',
                                               'peran: warga | pengurus', 'status: menunggu | aktif |', '   nonaktif | diarsipkan'])
    # Relasi.
    poly(d, [(rumah[2], 290), (hun[0], 290)]); card(d, (rumah[2], 290), '1', 'ra'); card(d, (hun[0], 290), '0..*', 'lb')
    poly(d, [(hun[2], 290), (kel[0], 290)]); card(d, (hun[2], 290), '0..*', 'ra'); card(d, (kel[0], 290), '1', 'lb')
    poly(d, [(230, rumah[3]), (230, pem[1])]); card(d, (230, rumah[3]), '0..*', 'rb'); card(d, (230, pem[1]), '0..1', 'ra')
    # keluarga -> keanggotaan (kiri bawah) dan -> tautan (kanan bawah).
    poly(d, [(1000, kel[3]), (1000, 640), (mem[2], 640)]); card(d, (1000, kel[3]), '1', 'rb'); card(d, (mem[2], 640), '0..*', 'rb')
    poly(d, [(1280, kel[3]), (1280, 640), (link[0], 640)]); card(d, (1280, kel[3]), '1', 'rb'); card(d, (link[0], 640), '0..*', 'lb')
    # warga -> keanggotaan dan -> tautan.
    poly(d, [(warga[0], 1080), (860, 1080), (860, mem[3])]); card(d, (warga[0], 1080), '1', 'lb'); card(d, (860, mem[3]), '0..*', 'lb')
    poly(d, [(warga[2], 1070), (1430, 1070), (1430, link[3])]); card(d, (warga[2], 1070), '1', 'ra'); card(d, (1430, link[3]), '0..*', 'rb')
    # akun -> tautan; warga -> akun (penunjuk lama).
    poly(d, [(1780, akun[1]), (1780, link[3])]); card(d, (1780, akun[1]), '1', 'ra'); card(d, (1780, link[3]), '0..*', 'rb')
    poly(d, [(warga[2], 1190), (akun[0], 1190)], dashed_line=True); card(d, (warga[2], 1190), '0..1', 'rb'); card(d, (akun[0], 1190), '0..1', 'lb')
    # keluarga -> warga penunjuk lama (garis putus-putus, lewat tengah).
    poly(d, [(1150, kel[3]), (1150, warga[1])], dashed_line=True)
    card(d, (1150, kel[3]), '1', 'rb'); card(d, (1150, warga[1]), '0..*', 'ra')
    label_on_line(d, 1150, 900, 'penunjuk lama keluarga_id', F(18, 'SemiBold'))
    # Panel partial unique index.
    px1, py1, px2 = 40, 1010, 830
    d.rectangle((px1, py1, px2, py1 + 46), fill=NAVY)
    d.text((px1 + 16, py1 + 23), 'Partial unique index aktif (pertahanan terakhir, pelanggaran = 409)', font=F(21, 'Bold'), fill='white', anchor='lm')
    rows = [('keanggotaan_satu_aktif_per_warga', '(warga_id) WHERE selesai_at IS NULL', True),
            ('tautan_satu_aktif_per_akun', '(akun_id) WHERE selesai_at IS NULL', True),
            ('tautan_satu_aktif_per_warga', '(warga_id) WHERE selesai_at IS NULL', True),
            ('tautan_satu_utama_per_keluarga', '(keluarga_id) WHERE is_utama AND selesai_at IS NULL', True),
            ('penghunian_satu_aktif_per_rumah / _per_keluarga', 'rumah_id / keluarga_id WHERE selesai_tanggal IS NULL', False),
            ('warga_satu_kepala_aktif', '(keluarga_id) WHERE is_kepala AND archived_at IS NULL', False)]
    y = py1 + 46
    for i, (name, cond, new) in enumerate(rows):
        d.rectangle((px1, y, px2, y + 64), fill=PALE_YELLOW if new else 'white', outline=GREY)
        d.text((px1 + 16, y + 6), name + ('  (baru M07)' if new else '  (sudah ada M06)'), font=F(19, 'Bold'), fill=NAVY)
        d.text((px1 + 16, y + 34), cond, font=F(18), fill=MUTED)
        if d.textlength(name + '  (sudah ada M06)', font=F(19, 'Bold')) > px2 - px1 - 32:
            TOO_LONG.append(f'index: {name}')
        y += 64
    d.rectangle((px1, py1, px2, y), outline=NAVY, width=3)
    # Catatan beda dengan rencana L5.
    nb = (1400, 200, 1860, 430)
    box(d, nb, 'Beda dengan rencana L5 (M06): tautan_akun TIDAK menyimpan rumah. Rumah diturunkan dari penghunian aktif keluarga, '
               'sehingga satu akun utama per keluarga = satu per rumah, dan tautan tidak perlu diputar saat keluarga pindah rumah.',
        fill=SKY, font=F(19, 'SemiBold'), align='left', radius=10)
    d.text((40, H - 108), 'Legenda: kotak kuning = tabel baru M07 | + = kolom baru M07 | garis putus-putus = penunjuk lama yang tetap disinkronkan | '
                          'angka kuning = kardinalitas', font=F(19, 'SemiBold'), fill=NAVY)
    assert d.textlength('Legenda: kotak kuning = tabel baru M07 | + = kolom baru M07 | garis putus-putus = penunjuk lama yang tetap disinkronkan | '
                        'angka kuning = kardinalitas', font=F(19, 'SemiBold')) <= W - 80
    footer(d, W, H, 'Sumber: migration 2026_10_13_000100 dan 2026_10_11_000100, docs/ERD.md, CONTRACT.md §2 | FK RESTRICT, tanpa cascade')
    img.save(OUT / '05-erd-aktual-m07.png')


before_after()
userflow()
use_case_admin()
move_sequence()
erd_actual()
assert not TOO_LONG, TOO_LONG
print('Rendered 5 PNG ke', OUT)
