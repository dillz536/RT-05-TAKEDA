"""Gambar suplemen Drive M06: use case UML, dua sequence diagram, ERD M06, dan rancangan M07.

Jalankan dari root repo: python docs/evidence/m06/drive/supplement/render_supplement.py
Butuh Pillow. Palet dan font sama dengan infra/scripts/render-m06-diagrams.py
(navy/kuning/sky, Public Sans + Bricolage Grotesque dari mobile/assets/fonts).
Isi diturunkan dari migration rest-api/database/migrations, AuthController, Registration,
PasswordReset, dan ResetPasswordNotification. Tidak membaca kredensial, database, inbox, atau data warga.
"""
from pathlib import Path
import math
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[5]
OUT = Path(__file__).resolve().parent
FONTS = ROOT / 'mobile/assets/fonts'
NAVY, SKY, YELLOW, MUTED, GREY = '#0b1f3a', '#eaf6ff', '#f9c846', '#35465b', '#c9d3df'
PALE_YELLOW = '#fff4cc'

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
        assert draw.textlength(item, font=font) <= width + 1, f'Label terlalu panjang: {item}'
    return lines


def text_block(draw, cx, top, text, font, width, fill=NAVY, gap=6, anchor='mt'):
    y = top
    for line in wrap(draw, text, font, width):
        if anchor == 'mt':
            draw.text((cx, y), line, font=font, fill=fill, anchor='mt')
        else:
            draw.text((cx, y), line, font=font, fill=fill, anchor='lt')
        y += font.size + gap
    return y


def canvas(w, h, title, subtitle):
    img = Image.new('RGB', (w, h), 'white')
    d = ImageDraw.Draw(img)
    d.rectangle((0, 0, w, 14), fill=YELLOW)
    d.text((40, 36), title, font=F(40, 'Head'), fill=NAVY)
    d.text((40, 96), subtitle, font=F(24), fill=MUTED)
    return img, d


def footer(d, w, h, text):
    d.line((40, h - 64, w - 40, h - 64), fill=GREY, width=2)
    d.text((40, h - 46), text, font=F(21), fill=MUTED)


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


# ---------------------------------------------------------------- 1. Use case UML

def actor(d, x, y, label, note=None):
    d.ellipse((x - 24, y - 92, x + 24, y - 44), outline=NAVY, width=4, fill=SKY)
    d.line((x, y - 44, x, y + 20), fill=NAVY, width=4)
    d.line((x - 38, y - 22, x + 38, y - 22), fill=NAVY, width=4)
    d.line((x, y + 20, x - 30, y + 70), fill=NAVY, width=4)
    d.line((x, y + 20, x + 30, y + 70), fill=NAVY, width=4)
    bottom = text_block(d, x, y + 82, label, F(24, 'Bold'), 230)
    if note:
        text_block(d, x, bottom + 2, note, F(20), 230, fill=MUTED)


def use_case_diagram():
    W, H = 1900, 1600
    img, d = canvas(W, H, 'Diagram use case UML akses akun M06',
                    'Elips = layanan yang sudah ada di kode M06 | segitiga kosong = generalisasi | pengurus (admin aplikasi) berbeda dari operator teknis')
    d.rounded_rectangle((320, 160, 1580, 1200), radius=18, outline=NAVY, width=4, fill='white')
    d.text((344, 176), 'Sistem RT05 TAKEDA (REST API, aplikasi Android, website)', font=F(24, 'Bold'), fill=NAVY)
    op_box = (1270, 880, 1562, 1184)
    dashed_rect(d, op_box)
    text_block(d, (op_box[0] + op_box[2]) / 2, 892, 'Command server teraudit, bukan API', F(20, 'SemiBold'), 260)

    E = {}
    def uc(key, cx, cy, label, w=420, h=104, emph=False):
        E[key] = (cx, cy, w, h, label, emph)

    uc('web', 600, 260, 'Membaca website publik')
    uc('utama', 600, 400, 'Daftar akun utama')
    uc('keluarga', 600, 550, 'Melihat data keluarga sendiri (utama juga mengubah)', h=116)
    uc('tambah', 600, 700, 'Daftar akun tambahan')
    uc('status', 600, 850, 'Melihat status permohonan')
    uc('token', 1020, 560, 'Verifikasi token RT 6 digit', w=410, emph=True)
    uc('rotasi', 1330, 260, 'Melihat, menyalin, merotasi token RT', w=380)
    uc('review', 1330, 400, 'Menyetujui atau menolak akun tambahan', w=380)
    uc('login', 640, 1100, 'Masuk dan keluar', w=380)
    uc('reset', 1050, 1100, 'Lupa dan reset kata sandi', w=380)
    uc('admin', 1416, 1060, 'Buat, grant, revoke peran pengurus', w=264, h=136)

    def edge_point(key, toward):
        cx, cy, w, h, *_ = E[key]
        ang = math.atan2(toward[1] - cy, toward[0] - cx)
        a, b = w / 2, h / 2
        r = a * b / math.hypot(b * math.cos(ang), a * math.sin(ang))
        return cx + r * math.cos(ang), cy + r * math.sin(ang)

    actors = {
        'publik': (175, 270, 'Pengunjung publik', 'tanpa akun'),
        'w_utama': (175, 560, 'Warga akun utama', 'aktif langsung'),
        'w_tambah': (175, 880, 'Warga akun tambahan', 'menunggu lalu aktif'),
        'pengurus': (1730, 400, 'Pengurus', 'admin aplikasi, semua setara'),
        'operator': (1730, 1010, 'Operator teknis', 'akses server, bukan akun harian'),
        'terdaftar': (950, 1350, 'Pengguna terdaftar', 'warga utama, warga tambahan, pengurus'),
    }
    links = [('publik', 'web'), ('w_utama', 'utama'), ('w_utama', 'keluarga'), ('w_tambah', 'keluarga'),
             ('w_tambah', 'tambah'), ('w_tambah', 'status'), ('pengurus', 'rotasi'), ('pengurus', 'review'), ('operator', 'admin')]
    for who, key in links:
        ax, ay = actors[who][:2]
        start = (ax + 46, ay - 22) if ax < W / 2 else (ax - 46, ay - 22)
        # Keluarga dihubungkan ke ujung kiri elips agar garis warga tambahan tidak memotong elips lain.
        end = (E[key][0] - E[key][2] / 2, E[key][1]) if key == 'keluarga' else edge_point(key, start)
        d.line((start, end), fill=NAVY, width=3)
    for key in ('login', 'reset'):
        start = (950, 1256)
        d.line((start, edge_point(key, start)), fill=NAVY, width=3)

    def generalize(points):
        poly(d, points)
        tip, frm = points[-1], points[-2]
        ang = math.atan2(tip[1] - frm[1], tip[0] - frm[0])
        p1 = (tip[0] - 26 * math.cos(ang - .5), tip[1] - 26 * math.sin(ang - .5))
        p2 = (tip[0] - 26 * math.cos(ang + .5), tip[1] - 26 * math.sin(ang + .5))
        d.polygon([tip, p1, p2], fill='white', outline=NAVY, width=3)
    trunk_y = 1328
    poly(d, [(129, 538), (36, 538), (36, trunk_y)])
    poly(d, [(129, 858), (36, 858)])
    generalize([(36, trunk_y), (902, trunk_y)])
    generalize([(1776, 378), (1870, 378), (1870, trunk_y), (998, trunk_y)])

    includes = []
    for src in ('utama', 'tambah'):
        a = edge_point(src, E['token'][:2])
        b = edge_point('token', E[src][:2])
        dashed(d, a, b, width=3)
        arrowhead(d, b, a, open_=True)
        includes.append(((a[0] + b[0]) / 2, (a[1] + b[1]) / 2))

    for key, (cx, cy, w, h, label, emph) in E.items():
        d.ellipse((cx - w / 2, cy - h / 2, cx + w / 2, cy + h / 2), fill=YELLOW if emph else SKY, outline=NAVY, width=3)
        lines = wrap(d, label, F(23, 'SemiBold'), w - 70)
        top = cy - (len(lines) * 29 - 6) / 2
        for i, line in enumerate(lines):
            d.text((cx, top + i * 29), line, font=F(23, 'SemiBold'), fill=NAVY, anchor='mt')
    # Warga tambahan hanya membaca data keluarga setelah disetujui; label diletakkan di celah antar elips.
    font = F(19, 'SemiBold')
    tw = d.textlength('hanya setelah disetujui (aktif)', font=font)
    d.rectangle((380, 615, 388 + tw, 639), fill='white')
    d.text((384, 616), 'hanya setelah disetujui (aktif)', font=font, fill=NAVY)
    for (mx, my), dy in zip(includes, (-24, 24)):
        font = F(20, 'SemiBold')
        tw = d.textlength('«include»', font=font)
        box = (mx + 14, my + dy - 13, mx + 14 + tw + 8, my + dy + 13)
        d.rectangle(box, fill='white')
        d.text((box[0] + 4, my + dy), '«include»', font=font, fill=NAVY, anchor='lm')
    for key, (x, y, label, note) in actors.items():
        actor(d, x, y, label, note)
    footer(d, W, H, 'Sumber: rest-api/routes/api.php, BUSINESS_RULES BR01-BR07, AuthController, command rt05:pengurus | tanpa akun atau data warga nyata')
    img.save(OUT / '01-use-case-uml-m06.png')


# ---------------------------------------------------------------- 2/3. Sequence diagrams

class Seq:
    """Kanvas tinggi; lifeline digambar lebih dulu, label diberi latar putih, lalu dipotong pada finish()."""

    def __init__(self, W, title, subtitle, lanes, external=(), top=170):
        self.img, self.d = canvas(W, 4000, title, subtitle)
        self.W, self.lanes, self.top, self.external = W, lanes, top, external
        d = self.d
        for key, (x, label) in lanes.items():
            dashed(d, (x, top + 96), (x, 3900), fill='#9a7a12' if key in external else MUTED, width=2, dash=10, gap=8)
            box = (x - 135, top, x + 135, top + 96)
            if key in external:
                d.rectangle(box, fill=PALE_YELLOW)
                dashed_rect(d, box)
            else:
                d.rounded_rectangle(box, radius=12, fill=SKY, outline=NAVY, width=3)
            lines = wrap(d, label, F(22, 'Bold'), 250)
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

    def frame(self, x1, y1, x2, tag, fill=YELLOW, dashed_border=False, guard=None):
        d = self.d
        y2 = self.y - 6
        if dashed_border:
            dashed_rect(d, (x1, y1, x2, y2), width=3)
        else:
            d.rectangle((x1, y1, x2, y2), outline=NAVY, width=3)
        tw = d.textlength(tag, font=F(20, 'Bold')) + 28
        d.polygon([(x1, y1), (x1 + tw, y1), (x1 + tw, y1 + 22), (x1 + tw - 14, y1 + 36), (x1, y1 + 36)], fill=fill, outline=NAVY)
        d.text((x1 + 12, y1 + 7), tag, font=F(20, 'Bold'), fill=NAVY)
        if guard:
            self.label(x1 + tw + 14, y1 + 8, [guard], F(20, 'SemiBold'), anchor='lt')
        self.y = y2 + 24

    def operand(self, x1, x2, guard):
        dashed(self.d, (x1, self.y), (x2, self.y), width=2)
        self.label(x1 + 14, self.y + 10, [guard], F(20, 'SemiBold'), anchor='lt')
        self.y += 50

    def note(self, text):
        d = self.d
        x1, x2 = 40, self.W - 40
        lines = wrap(d, text, F(21), x2 - x1 - 40)
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


def registration_sequence():
    lanes = {'app': (200, 'Warga (aplikasi Android)'), 'api': (680, 'REST API Laravel'),
             'redis': (1160, 'Redis (batas percobaan)'), 'db': (1640, 'PostgreSQL Neon')}
    s = Seq(1880, 'Sequence diagram registrasi akun utama',
            'Semua rekaman dibuat dalam SATU transaksi database; gagal di langkah mana pun berarti tidak ada data yang tersisa', lanes)
    s.msg('app', 'api', 'POST /auth/register: email, kata sandi, token RT 6 digit, data rumah dan keluarga', num=1)
    s.self_msg('api', 'Validasi format isian; isian tidak lengkap dijawab 422', num=2)
    s.msg('api', 'redis', 'Cadangkan kuota percobaan token RT per alamat IP', num=3)
    t = s.open_frame()
    s.msg('api', 'db', 'BEGIN; kunci advisory registrasi; cocokkan token RT aktif lewat digest HMAC', num=4)
    s.msg('api', 'db', 'Pastikan email belum dipakai dan alamat rumah (alamat_key) belum terdaftar', num=5)
    s.msg('api', 'db', 'INSERT rumah, keluarga (belum_verifikasi), warga kepala dan anggota, penghunian', num=6)
    s.msg('api', 'db', 'INSERT akun (jenis utama, status aktif, peran warga), permohonan_akun (disetujui), preferensi_notifikasi', num=7)
    s.msg('api', 'db', 'INSERT audit_logs, outbox_events, bearer perangkat (personal_access_tokens)', num=8)
    s.frame(540, t, 1800, 'transaksi atomik')
    a = s.open_frame()
    s.label(140, a + 8, ['[semua pemeriksaan lolos]'], F(20, 'SemiBold'), anchor='lt')
    s.msg('api', 'db', 'COMMIT: semua rekaman tersimpan bersama', num=9)
    s.msg('api', 'redis', 'Kembalikan kuota percobaan', num=10)
    s.msg('api', 'app', '201 Created: bearer token (berlaku 30 hari) dan profil akun', ret=True, num=11)
    s.self_msg('app', 'Simpan bearer di secure storage Android', num=12)
    s.operand(60, 1840, '[token salah, email terpakai, rumah sudah terdaftar, atau bentrok bersamaan]')
    s.msg('api', 'db', 'ROLLBACK: tidak ada rumah, keluarga, akun, atau bearer yang tersimpan', ret=True)
    s.msg('api', 'redis', 'Kuota dikembalikan, kecuali token RT memang salah (dihitung sebagai percobaan gagal)', width=640)
    s.msg('api', 'app', '422 atau 409 dengan pesan generik; warga memperbaiki isian atau menghubungi pengurus', ret=True, width=700)
    s.frame(60, a, 1840, 'alt', fill=SKY)
    s.note('Hasil: akun utama langsung aktif sebagai warga; data keluarga berstatus belum terverifikasi sampai pengurus meninjaunya. '
           'Akun tambahan memakai alur serupa, tetapi status akunnya menunggu persetujuan pengurus dan responsnya 202. '
           'Token registrasi tidak pernah memberi hak pengurus.')
    s.finish('Sumber: AuthController::register, Domain/Identity/Registration::primary, migration akun dan rumah | bukti: tes CI dan probe staging 26a0b7e',
             '02-sequence-registrasi-akun-utama.png')


def reset_sequence():
    lanes = {'app': (160, 'Warga (aplikasi atau peramban)'), 'api': (470, 'REST API Laravel'), 'db': (790, 'PostgreSQL Neon'),
             'redis': (1100, 'Redis antrean'), 'worker': (1390, 'Worker antrean'), 'smtp': (1730, 'SMTP dan inbox (eksternal)')}
    s = Seq(1900, 'Sequence diagram lupa dan reset kata sandi',
            'Token reset dibuat dalam transaksi; email diantrekan SETELAH commit | pengiriman SMTP ke inbox nyata masih DIBLOKIR', lanes,
            external=('smtp',))
    s.msg('app', 'api', 'POST /auth/forgot-password: email', num=1)
    t = s.open_frame()
    s.msg('api', 'db', 'BEGIN; cari akun aktif atau menunggu dengan email itu', num=2, width=460)
    s.msg('api', 'db', 'Simpan token reset (hash, berlaku 60 menit, minta ulang paling cepat 60 detik); COMMIT', num=3, width=460)
    s.frame(240, t, 1000, 'transaksi')
    t = s.open_frame()
    s.msg('api', 'redis', 'Antrekan email reset; isi job terenkripsi', num=4)
    s.frame(240, t, 1240, 'setelah commit', fill=SKY)
    s.msg('api', 'app', '202 pesan generik yang sama, email terdaftar atau tidak', ret=True, num=5)
    s.msg('redis', 'worker', 'Worker mengambil job', num=6, width=270)
    s.self_msg('worker', 'Cek konfigurasi mailer; bila mailer hanya menulis ke log, email tidak dikirim agar kode tidak bocor', num=7, width=250)
    t = s.open_frame()
    s.msg('worker', 'smtp', 'Kirim email berisi kode dan tautan', num=8, width=300)
    s.msg('smtp', 'app', 'Email tiba di inbox warga', ret=True, num=9)
    s.frame(40, t, 1870, 'DIBLOKIR: SMTP staging belum dikonfigurasi; pengiriman ke inbox belum terbukti', fill=PALE_YELLOW, dashed_border=True)
    s.msg('app', 'api', 'POST /auth/reset-password: email, kode, kata sandi baru', num=10)
    t = s.open_frame()
    s.msg('api', 'db', 'BEGIN; kunci baris token; cek kode dan masa berlaku', num=11, width=560)
    s.msg('api', 'db', 'Simpan hash kata sandi baru; cabut semua bearer; naikkan versi_sesi; tulis audit; hapus token; COMMIT', num=12, width=560)
    s.frame(180, t, 1080, 'transaksi reset')
    s.msg('api', 'app', '200: silakan masuk kembali (kode salah atau kedaluwarsa dijawab 422)', ret=True, num=13)
    s.note('Gagal antre atau gagal kirim email tidak membatalkan token yang sudah commit; warga cukup meminta ulang setelah SMTP pulih. '
           'Di staging 26a0b7e langkah 1 sampai 7 berjalan: respons 202, lalu worker mencatat email tidak dikirim karena SMTP belum ada. '
           'Langkah 10 sampai 13 lulus tes otomatis dan 17 cek staging memakai kode yang dibuat di server, bukan dari inbox. '
           'Langkah 8 dan 9 belum terbukti sampai operator menyiapkan SMTP staging.')
    s.finish('Sumber: AuthController::forgotPassword dan resetPassword, PasswordReset, ResetPasswordNotification (ShouldQueueAfterCommit)',
             '03-sequence-reset-kata-sandi.png')


# ---------------------------------------------------------------- 4/5. ERD

ROW_H, HEAD_H = 32, 50


def table(d, x, y, w, name, cols, planned=False, existing_muted=False):
    h = HEAD_H + len(cols) * ROW_H + 12
    body = PALE_YELLOW if planned else ('#f5f7fa' if existing_muted else 'white')
    head = YELLOW if planned else NAVY
    d.rectangle((x, y, x + w, y + h), fill=body)
    d.rectangle((x, y, x + w, y + HEAD_H), fill=head)
    if planned:
        dashed_rect(d, (x, y, x + w, y + h), width=3)
    else:
        d.rectangle((x, y, x + w, y + h), outline=NAVY, width=3)
    d.text((x + w / 2, y + HEAD_H / 2), name, font=F(24, 'Bold'), fill=NAVY if planned else 'white', anchor='mm')
    for i, col in enumerate(cols):
        cy = y + HEAD_H + 6 + i * ROW_H
        key = col.split(' ')[0] if col.split(' ')[0] in ('PK', 'FK', 'UK') else ''
        text = col[len(key):].strip() if key else col
        if key:
            d.text((x + 12, cy + 3), key, font=F(18, 'Bold'), fill='#9a6b00' if key != 'PK' else NAVY)
        font = F(20, 'SemiBold' if key == 'PK' else 'Regular')
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
    d.text((x + dx, y + dy), text, font=font, fill='#9a6b00')


def poly(d, pts, dashed_line=False):
    for a, b in zip(pts, pts[1:]):
        if dashed_line:
            dashed(d, a, b, width=3)
        else:
            d.line((a, b), fill=NAVY, width=3)


def erd_m06():
    W, H = 1900, 1500
    img, d = canvas(W, H, 'ERD akses akun dan pendataan M06 (sudah ada di database)',
                    'Diturunkan dari migration rest-api/database/migrations | hanya kolom kunci; tabel iuran, pengaduan, dan lainnya tidak digambar')
    w = 320
    X = [30, 412, 794, 1176, 1558]
    r1, r2, r3 = 170, 560, 1150
    pemilik = table(d, X[0], r1, w, 'pemilik_rumah', ['PK id', 'nama', 'whatsapp (opsional)', 'catatan', 'archived_at'])
    rumah = table(d, X[1], r1, w, 'rumah', ['PK id', 'FK pemilik_id (boleh kosong)', 'UK kode_rumah', 'blok, jalan, nomor',
                                            'UK alamat_key (dinormalisasi)', 'tagihan_aktif, mulai_tagih', 'archived_at'])
    hun = table(d, X[2], r1, w, 'penghunian', ['PK id', 'FK rumah_id', 'FK keluarga_id', 'jenis: pemilik | kontrak',
                                               'mulai_tanggal', 'selesai_tanggal (opsional)', 'aktif: maks. 1 per rumah', 'aktif: maks. 1 per keluarga'])
    kel = table(d, X[3], r1, w, 'keluarga', ['PK id', 'status: belum_verifikasi |', '   terverifikasi', 'versi', 'archived_at'])
    warga = table(d, X[4], r1, w, 'warga', ['PK id', 'FK keluarga_id', 'nama, hubungan_keluarga', 'is_kepala (1 kepala aktif)',
                                            'whatsapp (opsional)', 'status: aktif | pindah |', '   meninggal', 'archived_at'])
    token = table(d, X[0], r2, w, 'token_registrasi', ['PK id', 'kode_terenkripsi', 'kode_digest (HMAC)', 'aktif (hanya 1 aktif)',
                                                        'berlaku_sampai, dicabut_at', 'FK dibuat_oleh_id'])
    perm = table(d, X[1], r2, w, 'permohonan_akun', ['PK id', 'FK akun_id (unik)', 'FK token_id', 'FK rumah_id (boleh kosong)', 'jenis: utama | tambahan',
                                                     'status: diajukan |', '   disetujui | ditolak', 'FK diproses_oleh_id', 'diproses_at, catatan'])
    akun = table(d, X[4], r2, w, 'akun', ['PK id', 'FK warga_id (unik, opsional)', 'UK email (huruf kecil)', 'password_hash',
                                          'peran: warga | pengurus', 'jenis: utama | tambahan', 'status: menunggu | aktif |',
                                          '   nonaktif | diarsipkan', 'versi_sesi (baru di M06)', 'archived_at'])
    prt = table(d, X[0], r3, w, 'password_reset_tokens', ['PK email', 'token (hash)', 'created_at (60 menit)'])
    audit = table(d, X[2], r3, w, 'audit_logs', ['PK id', 'FK actor_id', 'action, entity_type', 'entity_id, safe_diff'])
    pat = table(d, X[3], r3, w, 'personal_access_tokens', ['PK id', 'tokenable (akun)', 'token (hash), name', 'expires_at (30 hari)'])
    pref = table(d, X[4], r3, w, 'preferensi_notifikasi', ['PK FK akun_id', 'pengumuman, agenda', 'pengaduan, aspirasi, iuran'])

    def mid_y(b, frac=.5):
        return b[1] + (b[3] - b[1]) * frac
    # Baris 1, horizontal.
    for left, right, lc, rc, frac in ((pemilik, rumah, '0..1', '0..*', .3), (rumah, hun, '1', '0..*', .3),
                                      (hun, kel, '0..*', '1', .3), (kel, warga, '1', '0..*', .3)):
        y = left[1] + 120
        poly(d, [(left[2], y), (right[0], y)])
        card(d, (left[2], y), lc, 'ra')
        card(d, (right[0], y), rc, 'lb')
    # warga - akun, vertikal.
    x = (akun[0] + akun[2]) / 2
    poly(d, [(x, warga[3]), (x, akun[1])])
    card(d, (x, warga[3]), '0..1', 'rb')
    card(d, (x, akun[1]), '0..1', 'ra')
    # permohonan - rumah, vertikal.
    x = (perm[0] + perm[2]) / 2
    poly(d, [(x, perm[1]), (x, rumah[3])])
    card(d, (x, rumah[3]), '0..1', 'rb')
    card(d, (x, perm[1]), '0..*', 'ra')
    # permohonan - token.
    y = perm[1] + 150
    poly(d, [(perm[0], y), (token[2], y)])
    card(d, (token[2], y), '0..1', 'ra')
    card(d, (perm[0], y), '0..*', 'lb')
    # permohonan - akun (pemohon dan pemroses).
    for frac_y, lc, label in ((akun[1] + 90, '0..1', 'akun_id: pemohon'), (akun[1] + 170, '0..*', 'diproses_oleh_id: pengurus')):
        poly(d, [(perm[2], frac_y), (akun[0], frac_y)])
        card(d, (perm[2], frac_y), lc, 'ra')
        card(d, (akun[0], frac_y), '1' if lc == '0..1' else '0..1', 'la')
        d.text(((perm[2] + akun[0]) / 2, frac_y - 4), label, font=F(19, 'SemiBold'), fill=NAVY, anchor='mb')
    # token.dibuat_oleh_id - akun, lewat bawah.
    ty = akun[3] + 30
    poly(d, [((token[0] + token[2]) / 2, token[3]), ((token[0] + token[2]) / 2, ty), (akun[0] + 30, ty), (akun[0] + 30, akun[3])])
    card(d, ((token[0] + token[2]) / 2, token[3]), '0..*', 'rb')
    card(d, (akun[0] + 30, akun[3]), '0..1', 'lb')
    d.text((1000, ty - 6), 'dibuat_oleh_id: pengurus pembuat token', font=F(19, 'SemiBold'), fill=NAVY, anchor='mb')
    # akun -> baris 3.
    levels = [(prt, akun[0] + 80, ty + 50, True, 'email sama, tanpa FK'), (audit, akun[0] + 140, ty + 90, False, None),
              (pat, akun[0] + 200, ty + 130, True, 'polimorfik, tanpa FK')]
    for tgt, sx, ly, dash_, lab in levels:
        tx = (tgt[0] + tgt[2]) / 2
        poly(d, [(sx, akun[3]), (sx, ly), (tx, ly), (tx, tgt[1])], dashed_line=dash_)
        card(d, (tx, tgt[1]), '0..*', 'ra')
        if lab:
            d.text((tx + 12, ly - 4), lab, font=F(18), fill=MUTED, anchor='lb')
    x = akun[0] + 280
    poly(d, [(x, akun[3]), (x, pref[1])])
    card(d, (x, pref[1]), '0..1', 'ra')
    # Catatan di ruang kosong tengah.
    nx1, ny1 = 790, r2 + 230
    lines = ['Saat registrasi juga ditulis outbox_events (tanpa FK ke akun).',
             'Penghapusan memakai archived_at; FK memakai RESTRICT,',
             'sehingga riwayat tidak terhapus berantai.']
    d.rectangle((nx1, ny1, nx1 + 700, ny1 + 22 + len(lines) * 30), fill=SKY, outline=NAVY, width=2)
    for i, line in enumerate(lines):
        d.text((nx1 + 16, ny1 + 12 + i * 30), line, font=F(20), fill=NAVY)
    # Legenda.
    ly = H - 120
    d.text((40, ly), 'Legenda: PK kunci utama | FK kunci asing | UK unik | garis putus-putus = hubungan logis tanpa FK | angka kuning = kardinalitas',
           font=F(20, 'SemiBold'), fill=NAVY)
    footer(d, W, H, 'Sumber: migration 2026_10_10_000200, 2026_10_11_000100, 000200, 000400, 2026_10_12_000100 | M07 digambar terpisah sebagai rencana')
    img.save(OUT / '04-erd-m06-akses-pendataan.png')


def erd_m07_plan():
    W, H = 1900, 1220
    img, d = canvas(W, H, 'Rencana M07: keanggotaan keluarga dan tautan akun',
                    'BELUM DIIMPLEMENTASI | M07 baru dibuka setelah M06 dinyatakan lulus | nama tabel dan kolom final ditetapkan saat M07')
    d.rectangle((40, 140, W - 40, 196), fill=PALE_YELLOW, outline=NAVY, width=2)
    d.text((60, 154), 'Kotak kuning bergaris putus-putus = rancangan dari docs/ERD.md; tabel ini TIDAK ada di database staging saat ini.',
           font=F(22, 'SemiBold'), fill=NAVY)
    kel = table(d, 60, 250, 330, 'keluarga', ['PK id', 'status verifikasi'], existing_muted=True)
    warga = table(d, 60, 540, 330, 'warga', ['PK id', 'FK keluarga_id (saat ini)', 'nama'], existing_muted=True)
    rumah = table(d, 1510, 250, 330, 'rumah', ['PK id', 'UK alamat_key'], existing_muted=True)
    akun = table(d, 1510, 600, 330, 'akun', ['PK id', 'FK warga_id (saat ini)', 'jenis: utama | tambahan'], existing_muted=True)
    mem = table(d, 520, 330, 400, 'keanggotaan keluarga', ['PK id', 'FK keluarga', 'FK warga', 'hubungan keluarga', 'mulai, selesai',
                                                            '(selesai kosong = aktif)'], planned=True)
    link = table(d, 1030, 520, 410, 'tautan akun', ['PK id', 'FK akun', 'FK warga', 'FK keluarga', 'FK rumah', 'utama (primary) ya/tidak',
                                                    'mulai, selesai'], planned=True)
    for box in (kel, warga, rumah, akun):
        d.text((box[0], box[3] + 8), 'sudah ada (M06)', font=F(19, 'SemiBold'), fill=MUTED)

    def rel(points, start_card, start_side, end_card, end_side, start_pad=8):
        poly(d, points, dashed_line=True)
        card(d, points[0], start_card, start_side, start_pad)
        card(d, points[-1], end_card, end_side)
    # keanggotaan keluarga: keluarga 1 - 0..* keanggotaan, warga 1 - 0..* keanggotaan.
    rel([(kel[2], 360), (mem[0], 360)], '1', 'ra', '0..*', 'lb')
    rel([(warga[2], 572), (mem[0], 572)], '1', 'ra', '0..*', 'lb')
    # tautan akun menunjuk akun, warga, keluarga, dan rumah.
    rel([(warga[2], 670), (link[0], 670)], '1', 'ra', '0..*', 'lb')
    rel([(kel[2], 285), (1130, 285), (1130, link[1])], '1', 'ra', '0..*', 'ra')
    rel([(rumah[0], 345), (1330, 345), (1330, link[1])], '1', 'la', '0..*', 'ra', start_pad=24)
    rel([(akun[0], 700), (link[2], 700)], '1', 'lb', '0..*', 'ra', start_pad=24)
    rules = ['Aturan yang direncanakan (BUSINESS_RULES dan DATABASE.md):',
             '- satu tautan akun utama aktif per rumah; satu tautan aktif per akun',
             '- keanggotaan dan tautan memiliki rentang waktu; riwayat perpindahan tidak dihapus',
             '- perpindahan keluarga menutup tautan lama dan membuka yang baru dalam satu transaksi',
             '- tagihan tetap melekat pada rumah; tunggakan tidak ikut pindah']
    y = 940
    for i, line in enumerate(rules):
        d.text((60, y + i * 32), line, font=F(21, 'SemiBold' if i == 0 else 'Regular'), fill=NAVY)
    footer(d, W, H, 'Sumber: docs/ERD.md (family_memberships, account_links), docs/DATABASE.md, ROADMAP M07 | bukan bukti implementasi')
    img.save(OUT / '05-erd-rencana-m07.png')


use_case_diagram()
registration_sequence()
reset_sequence()
erd_m06()
erd_m07_plan()
assert not TOO_LONG, TOO_LONG
print('Rendered 5 PNG ke', OUT)
