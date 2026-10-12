"""Render the shareable M06/M07 diagrams and keep editable SVG sources.

Run from repo root: python infra/scripts/render-m06-diagrams.py
Requires Pillow. No credentials, database, browser data or photos are read.
"""
from pathlib import Path
from html import escape
import json
import math
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'docs/evidence/m06/diagrams'
OUT.mkdir(parents=True, exist_ok=True)
FONT = ROOT / 'mobile/assets/fonts/PublicSans-Regular.ttf'
HEAD = ROOT / 'mobile/assets/fonts/BricolageGrotesque-Bold.ttf'
NAVY, SKY, YELLOW = '#0b1f3a', '#eaf6ff', '#f9c846'

# Nodes are [id, x, y, width, height, lines, emphasis]. Edges are node-id pairs.
specs = [
    ('mindmap', 'Ruang lingkup RT05 TAKEDA M06 dan M07', 'M06 aktif | M07 rancangan setelah penerimaan M06', [
        ['root',520,350,350,120,['RT05 TAKEDA','M06 lalu M07'],True],
        ['access',60,160,370,140,['Akses M06','Daftar utama dan tambahan','Login logout dan pemulihan'],False],
        ['security',950,160,380,140,['Perlindungan M06','Token RT dan CSRF','Role status serta audit'],False],
        ['family',60,590,370,150,['Pendataan M07','Rumah keluarga dan warga','Verifikasi dan arsip'],False],
        ['history',950,590,380,150,['Histori M07','Hunian membership link akun','Perpindahan atomik'],False],
    ], [('root','access'),('root','security'),('root','family'),('root','history')]),
    ('use-case', 'Aktor dan layanan akses serta pendataan', 'Pengunjung publik tidak memerlukan akun | seluruh pengurus setara', [
        ['public',45,150,330,130,['Pengunjung','Baca website publik','Artikel dan dokumentasi'],False],
        ['primary',45,360,330,160,['Warga akun utama','Daftar login dan reset','Baca dan edit keluarga sendiri'],False],
        ['extra',45,620,330,150,['Warga akun tambahan','Status permohonan dan logout','Keluarga read only setelah aktif'],False],
        ['api',535,330,330,170,['REST API','Validasi dan otorisasi','Transaksi serta audit'],True],
        ['admin',1025,160,330,170,['Pengurus','Review tambahan dan token','Pendataan verifikasi arsip'],False],
        ['operator',1025,550,330,150,['Operator','Bootstrap grant revoke','Command yang diaudit'],False],
    ], [('public','api'),('primary','api'),('extra','api'),('admin','api'),('operator','api')]),
    ('architecture', 'Arsitektur akses akun RT05 TAKEDA', 'Database dan aturan bisnis dimiliki rest api | satu origin HTTPS', [
        ['browser',40,170,330,130,['Browser','Website publik dan CMS','CMS cookie serta CSRF'],False],
        ['mobile',40,480,330,130,['Android Flutter','Bearer per perangkat','OS secure storage'],False],
        ['gateway',535,170,330,130,['Gateway HTTPS','API health csrf dan reset','Rute lain ke website'],True],
        ['api',535,480,330,140,['Laravel REST API','Role status policy','Transaksi audit outbox'],True],
        ['db',1010,160,330,120,['PostgreSQL Neon','Sumber data utama','Runtime DML terpisah DDL'],False],
        ['queue',1010,390,330,120,['Redis','Queue dan cache terpisah','Session serta rate limit'],False],
        ['worker',1010,650,330,120,['Worker dan SMTP','Reset antrean after commit','Inbox harus dibuktikan'],False],
    ], [('browser','gateway'),('mobile','gateway'),('gateway','api'),('api','db'),('api','queue'),('queue','worker')]),
    ('registration', 'Alur registrasi akun utama', 'Keputusan pemilik | akun langsung aktif dan keluarga belum diverifikasi', [
        ['input',50,170,350,150,['Email password aplikasi','Token RT string 6 digit','Data rumah dan keluarga'],False],
        ['validate',525,170,350,150,['Validasi server','Token alamat email','Benturan tidak auto merge'],True],
        ['transaction',995,170,350,150,['Transaksi atomik','Akun rumah keluarga anggota','Hunian audit dan bearer'],False],
        ['fix',525,550,350,140,['Token salah atau bentrok','Perbaiki isian','Atau hubungi pengurus'],False],
        ['active',995,550,350,140,['Akun utama aktif','Peran warga','Keluarga belum verifikasi'],True],
    ], [('input','validate'),('validate','transaction'),('validate','fix'),('transaction','active')]),
    ('approval', 'Alur akun tambahan dan persetujuan', 'Pendataan dan review lengkap pada M07 | pending tidak membaca layanan warga', [
        ['request',45,170,360,150,['Daftar akun tambahan','Email password token','Referensi alamat keluarga'],False],
        ['pending',520,170,360,150,['Akun menunggu','Hanya profil status sendiri','Dan logout'],True],
        ['review',995,170,360,150,['Pengurus review M07','Cocokkan keluarga yang benar','Keputusan serta audit'],False],
        ['reject',520,550,360,150,['Permohonan ditolak','Status dan alasan terlihat','Akses warga tetap ditolak'],False],
        ['approve',995,550,360,150,['Tambahan aktif sebagai warga','Link keluarga benar','Keluarga hanya dapat dibaca'],True],
    ], [('request','pending'),('pending','review'),('review','reject'),('review','approve')]),
    ('family-move', 'Alur keluarga dan perpindahan hunian', 'Rancangan M07 | implementasi baru menunggu M06 passed', [
        ['read',40,160,365,150,['Keluarga sendiri','Utama dapat edit','Tambahan hanya membaca'],False],
        ['admin',515,160,365,150,['Pengurus mulai perpindahan','Rumah tujuan dan tanggal','Validasi hunian primary'],True],
        ['atomic',990,160,365,150,['Satu transaksi','Tutup hunian dan link lama','Buka hunian dan link baru'],False],
        ['history',515,560,365,150,['Histori dipertahankan','Membership dan tautan akun','Backfill tanpa tebak tanggal'],False],
        ['dues',990,560,365,150,['Tagihan rumah asal tetap','Tunggakan tidak dipindahkan','Arsip tidak menghapus kas'],True],
    ], [('read','admin'),('admin','atomic'),('atomic','history'),('atomic','dues')]),
]

def connector(a, b):
    ax, ay = a[1]+a[3]/2, a[2]+a[4]/2
    bx, by = b[1]+b[3]/2, b[2]+b[4]/2
    dx, dy = bx-ax, by-ay
    ta = min(a[3]/2/abs(dx) if dx else math.inf, a[4]/2/abs(dy) if dy else math.inf)
    tb = min(b[3]/2/abs(dx) if dx else math.inf, b[4]/2/abs(dy) if dy else math.inf)
    return (ax+ta*dx, ay+ta*dy), (bx-tb*dx, by-tb*dy)

for slug, title, subtitle, nodes, edges in specs:
    img = Image.new('RGB',(1400,880),'white')
    draw = ImageDraw.Draw(img)
    draw.text((40,30),title,font=ImageFont.truetype(str(HEAD),38),fill=NAVY)
    draw.text((40,90),subtitle,font=ImageFont.truetype(str(FONT),23),fill='#35465b')
    svg = ['<svg xmlns="http://www.w3.org/2000/svg" width="1400" height="880" viewBox="0 0 1400 880">', '<rect width="1400" height="880" fill="white"/>', '<defs><marker id="arrow" markerWidth="10" markerHeight="10" refX="8" refY="5" orient="auto"><path d="M0 0 L10 5 L0 10" fill="#0b1f3a"/></marker></defs>']
    svg.append(f'<text x="40" y="65" font-family="sans-serif" font-size="38" font-weight="bold" fill="{NAVY}">{escape(title)}</text>')
    svg.append(f'<text x="40" y="113" font-family="sans-serif" font-size="23" fill="#35465b">{escape(subtitle)}</text>')
    lookup={n[0]:n for n in nodes}
    for source, target in edges:
        a,b=connector(lookup[source],lookup[target])
        draw.line([a,b], fill=NAVY,width=3)
        angle=math.atan2(b[1]-a[1],b[0]-a[0])
        draw.polygon([b,(b[0]-15*math.cos(angle-.5),b[1]-15*math.sin(angle-.5)),(b[0]-15*math.cos(angle+.5),b[1]-15*math.sin(angle+.5))],fill=NAVY)
        svg.append(f'<path d="M {a[0]} {a[1]} L {b[0]} {b[1]}" stroke="{NAVY}" stroke-width="3" fill="none" marker-end="url(#arrow)"/>')
    for nid,x,y,w,h,lines,emphasis in nodes:
        fill=YELLOW if emphasis else SKY
        draw.rounded_rectangle((x,y,x+w,y+h),radius=16,fill=fill,outline=NAVY,width=2)
        svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="16" fill="{fill}" stroke="{NAVY}" stroke-width="2"/>')
        for i,line in enumerate(lines):
            size=25 if i==0 else 21
            font=ImageFont.truetype(str(HEAD if i==0 else FONT),size)
            while draw.textlength(line,font=font) > w-32:
                size-=1
                font=ImageFont.truetype(str(HEAD if i==0 else FONT),size)
            ty=y+(h-len(lines)*32)/2+i*32
            draw.text((x+w/2,ty),line,font=font,fill=NAVY,anchor='mt')
            svg.append(f'<text x="{x+w/2}" y="{ty+22}" text-anchor="middle" font-family="sans-serif" font-size="{size}" fill="{NAVY}">{escape(line)}</text>')
    footer='Sumber PRD BUSINESS_RULES API ERD dan keputusan pemilik 12 Oktober 2026 | bukti penerimaan terpisah'
    draw.text((40,830),footer,font=ImageFont.truetype(str(FONT),19),fill='#35465b')
    svg.append(f'<text x="40" y="850" font-family="sans-serif" font-size="19" fill="#35465b">{escape(footer)}</text></svg>')
    (OUT/f'{slug}.svg').write_text('\n'.join(svg),encoding='utf-8')
    img.save(OUT/f'{slug}.png')
(OUT/'sources.json').write_text(json.dumps(specs,ensure_ascii=False,indent=2),encoding='utf-8')
print('Rendered 6 PNG diagrams and editable SVG sources.')
