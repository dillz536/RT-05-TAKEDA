# KIMI — Usulan Update Google Doc Native: Penutupan M06 (SMTP/inbox PASSED) dan M07 Berikutnya

- Penyusun: Kimi (dokumentasi manusiawi untuk Drive capstone). Tanggal: 13 Oktober 2026.
- Kepemilikan file: `docs/evidence/m06/KIMI_SMTP_DRIVE_UPDATE.md`, ditambah (perluasan lingkup msg_b6de03485e6e) fungsi `reset_sequence` di `docs/evidence/m06/drive/supplement/render_supplement.py`, PNG `03-sequence-reset-kata-sandi.png`, dan bagian terkait di `SUPPLEMENT.md`. Tidak ada perubahan domain, sumber aplikasi, runtime, atau operasi Git.
- Target: Google Doc native existing `18yoO1-BY7-ylFyujpkDrpp_NkPQOwq-9-mTRPK_-KlM` pada folder Capstone → Tugas dan Project yang sama; laporan existing `1Dxax50Kckfuc2cCO4_PlanRIlNNQVLpsIN0S42s5VMU` tetap tertaut. Tidak ada file/folder duplikat baru di Drive.
- Sumber teks lama: plaintext native aktual `C:/Users/USER/.rt05/m06-stage-check/drive-guide-current.txt` (readback cloud oleh root, 13 Oktober 2026; isi checkpoint12Oktober). Semua "teks lama" di bawah dikutip persis dari readback itu.
- Batas: dokumen ini **usulan, belum dipublikasikan**. Root memiliki connector dan melakukan publikasi serta Git. Tidak ada alamat email, kode reset, key, atau credential yang dicetak di sini.

**Readback publikasi coordinator, 13 Oktober:** usulan berikut telah dipakai untuk
memperbarui panduan dan laporan native existing (teks dipadatkan untuk pembaca).
Diagram reset diganti di posisi yang sama, PNG ukuran penuh diperbarui dengan ID
Drive yang sama. Dua screenshot nyata ditambahkan: browser berhasil dan Android
Beranda sesudah reset/login. Panduan dibaca kembali **21 gambar, 4 tabel**; PDF
akhir **26 halaman**, halaman25/26 diperiksa visual dan tidak terpotong. Histori
M06 dan artefak akademik asli tetap; M07 diberi status sedang dikerjakan. Batas
"belum dipublikasikan" di atas adalah keadaan saat proposal ditulis.
- Gate final root (msg_4490c0c44216, 13 Oktober 2026): **M06 PASSED**. Catatan historis 12 Oktober tetap ditandai sebagai histori, bukan dihapus.
- Yang dipertahankan: 11 diagram dan 8 screenshot existing beserta dokumen akademik asli tidak diubah/dihapus; hanya gambar L3 yang dirender ulang pada kanvas yang sama persis (1900 × 1946).

## Mengapa diperbarui, sebelum, dan sesudah (ringkas)

Pengirim email staging adalah satu-satunya gerbang M06 yang tersisa. Sebelumnya (12 Oktober) SMTP staging belum pernah disiapkan, sehingga email reset tidak pernah terbukti sampai ke inbox dan dokumen menandainya DIBLOKIR. Pada 13 Oktober pemilik menyiapkan pengirim yang benar-benar dimiliki (domain Resend terverifikasi milik sendiri dengan API key sending-only khusus RT05), hanya container api dan worker-default yang dikonfigurasi tanpa mengubah kode `26a0b7e`, lalu alur reset dibuktikan end-to-end dua ronde lewat inbox Gmail pemilik — ronde browser dan ronde Android — dengan seluruh pemeriksaan keamanan lulus. Sesudahnya M06 diterima lulus oleh coordinator dan M07 menjadi pekerjaan berikutnya. Pembaruan dokumen ini hanya mengganti klaim status yang kedaluwarsa; arsitektur, aturan bisnis, dan bukti historis tidak berubah.

## Fakta aktual final (13 Oktober 2026, UTC)

1. Pengirim: domain Resend terverifikasi milik sendiri `mail.morriztech.cloud`, API key sending-only ter-scoped domain khusus RT05 (secret tetap privat, dibuat root).
2. Konfigurasi: hanya container staging **api** dan **worker-default** (recreate 02:43:29Z) pada source immutable `26a0b7e`; 7 container lain, volume, dan env secret dipertahankan (pergerakan StartedAt exports/media hanya daur ulang hourly alami).
3. Ronde A (browser): email tiba di INBOX Gmail **02:53:25Z**; tautan HTTPS fragment dari email asli membuka form (fragment terhapus dari address bar), reset sukses; verifikasi Claude **4/4 PASS** pukul **03:01:35Z** (bearer lama 401, sandi lama 422, sandi baru 200, reuse kode 422).
4. Ronde B (Android): forgot dari aplikasi **03:09:56.910Z**; email tiba di INBOX Gmail **03:10:10Z** (kode baru yang berbeda); reset di aplikasi **03:20:24.104Z**; masuk dengan sandi baru mencapai Beranda **03:22:55.348Z**; verifikasi Claude **4/4 PASS** final **03:29:00.769Z** (bearer lama 401, sandi lama 422, sandi baru 200, reuse kode 422).
5. Penerimaan helper B **03:01:47Z** dicatat sebagai transport-only dan **tergantikan (superseded)** — bukan bukti final; inbox uji awal hanya untuk uji transport, default terpilih adalah Gmail pemilik yang terhubung. Pada ronde Android kode diminta dari aplikasi terlebih dahulu lalu kode baru dari email dipakai; koreksi urutan uji ini **bukan cacat kode**.
6. Log sejak 02:43Z: 4× `ResetPasswordNotification` DONE, 0 FAIL/exception, 0 kebocoran hex 64 karakter/fragment reset.
7. Screenshot aman (tanpa alamat/kode/secret): `screenshots/reset-inbox-browser-success-26a0b7e.png` (browser) dan `screenshots/reset-b-beranda-26a0b7e.png` (Android, Beranda sesudah masuk dengan sandi baru).
8. Gambar L3 (sequence reset) dirender ulang dari sumber dengan status TERBUKTI pada kanvas **1900 × 1946 yang sama persis**; empat gambar suplemen lain byte-identik.
9. Bukti repo: `docs/evidence/m06/INBOX_ACCEPTANCE.md` merangkum penerimaan inbox dua ronde; verifikasi A 03:01:35Z dan B 03:29:00.769Z, ditambah dua screenshot aman pada butir 7.

## Pasangan teks lama → teks baru

### P1 — Header tanggal/status (baris 2)

- Alasan: gate final root menyatakan M06 lulus; M07 berganti dari terkunci menjadi pekerjaan berikutnya.
- Bukti terkini: fakta 1–6 di atas. Blocker: tidak ada untuk M06; M07 belum diimplementasikan.

> **Lama:** Tanggal: 12 Oktober 2026 · Status milestone: M06 blocked pada inbox reset; M07 masih rancangan dan terkunci sampai M06 dinyatakan lulus.

> **Baru:** Tanggal: 13 Oktober 2026 · Status milestone: M06 LULUS (gate final coordinator 13 Oktober 2026) — email reset terbukti sampai inbox pada ronde browser dan Android dengan verifikasi keamanan lengkap; M07 menjadi pekerjaan berikutnya dan belum diimplementasikan.

### P2 — §1 Ringkasan (baris 4, kalimat penutup)

- Alasan: kalimat "belum terbukti karena akun pengirim valid belum tersedia" sudah tidak benar.
- Bukti terkini: fakta 1–6. Blocker: tidak ada.

> **Lama:** Email reset sampai inbox belum terbukti karena akun pengirim valid belum tersedia. M06 belum ditutup; M07 masih rancangan.

> **Baru:** Pengirim SMTP staging disiapkan pemilik pada 13 Oktober 2026 (domain Resend terverifikasi milik sendiri, API key sending-only khusus RT05 yang disimpan privat; hanya container api dan worker-default yang dikonfigurasi pada source tetap 26a0b7e). Email reset terbukti tiba di inbox Gmail pemilik pada ronde browser (02:53:25Z) dan ronde Android (03:10:10Z); reset lewat tautan email asli di browser dan lewat kode di aplikasi Android sama-sama berhasil, dengan verifikasi 4/4 per ronde. M06 diterima lulus oleh coordinator; M07 menjadi pekerjaan berikutnya dan belum diimplementasikan.

### P3 — §3 tabel, baris "Pemulihan password", kolom Sesudah (baris 32)

- Alasan: kolom sesudah masih menyatakan pengiriman email blocked.
- Bukti terkini: fakta 3–4. Blocker: tidak ada.

> **Lama:** Reset antrean terenkripsi after-commit; kode/tautan sekali pakai dengan expiry dan anti-reuse; pengiriman email nyata masih blocked (SMTP belum disiapkan)

> **Baru:** Reset antrean terenkripsi after-commit; kode/tautan sekali pakai dengan expiry dan anti-reuse; pengiriman email nyata terbukti sampai inbox Gmail pemilik pada 13 Oktober 2026 (ronde browser dan Android)

### P4 — §7 bullet "Reset password", catatan jujur (baris 78, kalimat terakhir)

- Alasan: catatan jujur tentang SMTP yang belum disiapkan sudah terlampaui; diganti bukti aktual tanpa menghapus konteks antrean/generik di awal bullet.
- Bukti terkini: fakta 1–6. Blocker: tidak ada.

> **Lama:** Catatan jujur: konfigurasi pengirim SMTP di staging belum pernah disiapkan, sehingga bukti email sampai ke inbox masih blocked — langkah setup terdokumentasi di SMTP_OPERATOR.md.

> **Baru:** Pembaruan 13 Oktober 2026: pengirim SMTP disiapkan pemilik sesuai SMTP_OPERATOR.md — domain terverifikasi milik sendiri di Resend dengan API key sending-only khusus RT05 (privat); hanya container api dan worker-default staging yang dikonfigurasi pada source immutable 26a0b7e, tujuh container/volume/env lain dipertahankan. Email reset nyata tiba di inbox Gmail pemilik: ronde browser 02:53:25Z (tautan HTTPS fragment dari email asli membuka form dan reset berhasil) dan ronde Android 03:10:10Z (kode dari email dipakai di aplikasi 03:20:24Z; masuk dengan sandi baru mencapai Beranda 03:22:55Z). Verifikasi 4/4 PASS per ronde (bearer lama 401, sandi lama 422, sandi baru 200, pemakaian ulang kode 422; ronde A 03:01:35Z, final B 03:29:00Z). Penerimaan helper awal 03:01:47Z dicatat transport-only dan tergantikan. Bukti layar aman: screenshot reset-inbox-browser-success-26a0b7e.png dan reset-b-beranda-26a0b7e.png pada suplemen; rangkuman penerimaan di INBOX_ACCEPTANCE.md.

### P5 — §9 Arsitektur teknis (baris 84, kalimat terakhir)

- Alasan: kalimat "masih membutuhkan konfigurasi pengirim nyata" sudah tidak berlaku.
- Bukti terkini: fakta 1–2. Blocker: tidak ada.

> **Lama:** Worker mengirim email reset lewat SMTP — bagian inilah yang masih membutuhkan konfigurasi pengirim nyata.

> **Baru:** Worker mengirim email reset lewat SMTP — konfigurasi pengirim nyata selesai pada 13 Oktober 2026 di staging (Resend, domain terverifikasi milik sendiri, key sending-only khusus RT05; hanya container api dan worker-default).

### P6 — §14 tabel, baris "Kode akses akun (backend+mobile)", kolom Status (baris 132)

- Alasan: frasa "Inbox belum terbukti" sudah terlampaui.
- Bukti terkini: fakta 3–4. Blocker: tidak ada.

> **Lama:** Terimplementasi; CI, API staging, dan Android diuji. Inbox belum terbukti.

> **Baru:** Terimplementasi; CI, API staging, dan Android diuji. Inbox terbukti 13 Oktober 2026 (browser dan Android, verifikasi 4/4 per ronde).

### P7 — §14 tabel, baris "Reset password sampai inbox", kolom Status dan Bukti (baris 144–145)

- Alasan: baris ini adalah inti gate M06 dan masih bertuliskan Blocked dengan kondisi 12 Oktober.
- Bukti terkini: fakta 1–6 lengkap. Blocker: tidak ada.

> **Lama (Status):** Blocked
>
> **Lama (Bukti):** Akun pengirim RT05 valid belum tersedia. Resend existing ditolak HTTP401 pada pemeriksaan metadata; akun Chrome tidak cocok dengan inbox uji. Helper penerimaan inbox siap, belum dijalankan.

> **Baru (Status):** PASS
>
> **Baru (Bukti):** Pengirim valid disiapkan pemilik 13 Oktober 2026: domain Resend terverifikasi milik sendiri dengan API key sending-only khusus RT05; hanya api dan worker-default pada source 26a0b7e, container/volume/env lain dipertahankan. Email tiba di inbox Gmail pemilik: ronde browser 02:53:25Z dan ronde Android 03:10:10Z. Reset berhasil lewat tautan browser dari email asli dan lewat kode di aplikasi Android (03:20:24Z), lalu masuk dengan sandi baru mencapai Beranda (03:22:55Z). Verifikasi 4/4 PASS per ronde (bearer lama 401, sandi lama 422, sandi baru 200, reuse 422; ronde A 03:01:35Z, final B 03:29:00Z). Log: 4× ResetPasswordNotification DONE, 0 FAIL/exception, tanpa kebocoran token. Inbox uji awal hanya untuk uji transport (penerimaan helper 03:01:47Z tergantikan); default terpilih Gmail pemilik yang terhubung.

### P8 — §15 Blocker dan langkah berikutnya (baris 156–159, empat butir)

- Alasan: seluruh langkah penutup gate email selesai; daftar harus mencerminkan bahwa M06 lulus dan sisanya adalah pekerjaan berikutnya.
- Bukti terkini: fakta 1–6. Blocker: rotasi credential insiden historis (M16) tetap open; M07 belum diimplementasikan.

> **Lama:**
> 1. Rollout staging dan runtime smoke sudah lulus pada source 26a0b7e. Pertahankan checkpoint ini; tidak perlu deploy ulang untuk menutup gate email.
> 2. Siapkan pengirim SMTP sesuai SMTP_OPERATOR.md, lalu buktikan email reset tiba di inbox uji.
> 3. Registrasi, approval/penolakan, login dan cookie/CSRF sudah diuji di staging. Setelah pengirim valid tersedia, buktikan reset dari email inbox melalui browser dan Android.
> 4. Rotasi credential insiden historis (M16) tetap open.

> **Baru:**
> 1. M06 LULUS 13 Oktober 2026: rollout staging dan runtime smoke pada source 26a0b7e, konfigurasi SMTP oleh pemilik (tanpa mengubah kode), dan bukti inbox dua ronde dengan verifikasi 4/4 per ronde — checkpoint ini dipertahankan.
> 2. M07 (pendataan dan histori hunian) menjadi pekerjaan berikutnya; belum diimplementasikan dan buktinya akan menyusul terpisah.
> 3. Rotasi credential insiden historis (M16) tetap open.

### P9 — Paragraf status penutup dokumen (baris 213)

- Alasan: paragraf penutup adalah checkpoint resmi dokumen dan masih menyatakan email belum terbukti.
- Bukti terkini: fakta 1–6. Blocker: tidak ada untuk M06.

> **Lama:** Status: M06 belum ditutup karena email reset belum terbukti sampai inbox. M07 baru dibuka sesudah gate M06 lengkap. Bukti uji dan pembatasan di dokumen ini berlaku untuk data sintetis staging.

> **Baru:** Checkpoint 13 Oktober 2026: M06 LULUS. Email reset terbukti sampai inbox Gmail pemilik pada ronde browser (02:53:25Z) dan ronde Android (03:10:10Z); reset lewat tautan email asli di browser dan lewat kode di aplikasi Android berhasil, masuk dengan sandi baru tercapai, dan verifikasi keamanan 4/4 PASS per ronde (final B 03:29:00Z). M07 menjadi pekerjaan berikutnya dan belum diimplementasikan. Bukti uji dan pembatasan di dokumen ini berlaku untuk data sintetis staging.

### P10 — Lampiran L3: keterangan dan penjelasan gambar (baris 175–176)

- Alasan: gambar L3 dirender ulang dengan status TERBUKTI, sehingga keterangan lama yang menyebut "masih diblokir" harus sejalan; konteks historis tetap diakui.
- Bukti terkini: fakta 8; PNG baru tetap 1900 × 1946. Blocker: tidak ada.

> **Lama (keterangan):** Gambar L3. Sequence diagram lupa dan reset kata sandi; pengiriman SMTP ke inbox masih diblokir.
>
> **Lama (penjelasan):** Token reset disimpan dalam transaksi. Email baru diantrekan setelah transaksi commit, sehingga gangguan email tidak menghapus data. Jawaban server selalu sama (202), terdaftar atau tidak, agar daftar email warga tidak bocor. Kotak bergaris putus-putus menandai bagian yang belum terbukti. SMTP staging belum dikonfigurasi; penerimaan email reset di inbox belum dibuktikan. Langkah penggantian kata sandi (10 sampai 13) sudah lulus tes otomatis dan cek staging. Kode untuk cek itu dibuat di server, bukan diambil dari inbox.

> **Baru (keterangan):** Gambar L3. Sequence diagram lupa dan reset kata sandi; pengiriman SMTP ke inbox terbukti 13 Oktober 2026.
>
> **Baru (penjelasan):** Token reset disimpan dalam transaksi. Email baru diantrekan setelah transaksi commit, sehingga gangguan email tidak menghapus data. Jawaban server selalu sama (202), terdaftar atau tidak, agar daftar email warga tidak bocor. Sejak 13 Oktober 2026 seluruh langkah terbukti di staging 26a0b7e: pengirim SMTP valid (hanya container api dan worker-default yang dikonfigurasi), email tiba di inbox pada ronde browser 02:53:25Z dan ronde Android 03:10:10Z, lalu reset dan masuk dengan kata sandi baru berhasil. Verifikasi 4/4 per ronde: sesi lama 401, kata sandi lama 422, kata sandi baru 200, kode yang sama 422 saat dipakai ulang. Pada ronde Android kode diminta dari aplikasi terlebih dahulu, lalu kode baru dari email itulah yang dipakai; koreksi urutan uji ini bukan cacat kode. (Versi 12 Oktober gambar ini menandai pengiriman inbox sebagai belum terbukti — disimpan sebagai histori.)

## Penambahan konten baru (bukan penggantian)

### A1 — Dua screenshot aman baru pada suplemen

- Alasan: bukti sukses browser dan Android dari email asli belum punya gambar; kedua screenshot sudah direview aman (tanpa alamat/kode/secret).
- Bukti terkini: `docs/evidence/m06/screenshots/reset-inbox-browser-success-26a0b7e.png` dan `docs/evidence/m06/screenshots/reset-b-beranda-26a0b7e.png` (keduanya sudah ada di repo).
- Usulan sisipan: dua blok baru pada bagian suplemen (setelah blok "Bukti Android: akun tambahan menunggu"), sehingga 8 screenshot existing tetap utuh dan total menjadi 10:

> **Bukti browser: reset dari email inbox berhasil**
>
> Tautan HTTPS fragment dari email reset asli membuka form reset dan penggantian sandi berhasil; fragment tidak tersisa di address bar dan tidak ada kode/alamat pada gambar. Verifikasi keamanan 4/4 PASS (bearer lama 401, sandi lama 422, sandi baru 200, pemakaian ulang kode 422). Diambil pada staging 26a0b7e, 13 Oktober 2026.
>
> Gambar: reset-inbox-browser-success-26a0b7e.png

> **Bukti Android: masuk ke Beranda sesudah reset dari email**
>
> Kode reset diminta dari aplikasi (03:09:56Z), email tiba di inbox (03:10:10Z), kode baru dari email dipakai di aplikasi (03:20:24Z), lalu masuk dengan kata sandi baru mencapai Beranda (03:22:55Z). Gambar tidak memuat email, kode, atau kata sandi; seluruh identitas adalah data uji sintetis. Verifikasi keamanan 4/4 PASS (final 03:29:00Z).
>
> Gambar: reset-b-beranda-26a0b7e.png

### A2 — Penggantian gambar L3 (bukan penambahan)

- Ganti gambar L3 inline pada Doc dengan `03-sequence-reset-kata-sandi.png` versi 13 Oktober 2026; kanvas **1900 × 1946 persis sama**, sehingga replaceImage (CENTER_CROP) mempertahankan bingkai/aspect tanpa distorsi. PNG ukuran penuh di folder Drive yang sama juga diganti (timpa, bukan duplikat). Empat gambar suplemen lain tidak berubah (byte-identik).
- Terapkan bersama pasangan P10 agar keterangan/penjelasan sejalan dengan gambar baru.

## Yang sengaja tidak diubah

- 10 diagram/gambar lain dan 8 screenshot existing — semuanya dipertahankan byte-identik; hanya L3 yang dirender ulang.
- Dokumen akademik Tugas 03/04 (read-only) dan seluruh tabel rekonsiliasi bagian 13.
- Baris §14 lain (website, emulator, rollout, Stitch, insiden credential) karena kondisinya tidak berubah; baris M07 di §14 cukup diperbarui statusnya dari "Terkunci" menjadi "Pekerjaan berikutnya; belum diimplementasikan" (mengikuti P1/P8).
- Catatan historis 12 Oktober (termasuk keterangan DIBLOKIR versi lama) tetap diakui sebagai histori lewat kalimat penutup P10, bukan dihapus tanpa jejak.

## Handoff publikasi (root memiliki connector dan Git)

1. Terapkan pasangan P1–P10 pada Doc native `18yoO1-BY7-ylFyujpkDrpp_NkPQOwq-9-mTRPK_-KlM` dan sisipan A1 + penggantian A2 pada folder Drive existing yang sama — tanpa membuat file/folder duplikat.
2. Unggah dua screenshot A1 sebagai gambar inline; timpa PNG L3 inline dan PNG ukuran penuhnya.
3. Readback isi Doc setelah update dan catat di PROGRESS.md.
4. Bukti implementasi M07 akan tiba pada tugas terpisah; dokumen ini sengaja tidak memuat klaim M07 apa pun selain "pekerjaan berikutnya, belum diimplementasikan".
