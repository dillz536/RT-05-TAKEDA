# M06 — penerimaan reset dari inbox nyata

13 Oktober 2026. **Gate M06 diterima coordinator: passed.** Runtime26a0b7e,
source aplikasi identik HEAD2dfe4ba yang seluruh CI-nya SUCCESS. Akun sintetis
staging; tidak menggunakan production/data warga.

| Jalur | Bukti aktual (UTC) | Hasil |
| --- | --- | --- |
| Browser A | Gmail INBOX02:53:25; tautan email asli di Chrome, fragment dihapus; submit sekali | Reset sukses; verifier03:01:35: bearer lama401, sandi lama422, sandi baru200, kode ulang422 |
| Android B | Aplikasi meminta kode03:09:56.910; Gmail INBOX03:10:10; form reset03:20:24.104; login03:22:55.348 | Beranda sintetis; verifier03:29:00.769 empat asersi yang sama PASS |

Tautan/kode dibaca dari email asli, bukan dibuat server untuk uji. Pengirim dan
penerima dicocokkan privat. Penerima, kode, password, bearer, key dan isi email
tidak ditulis dalam bukti publik ini.

Resend/domain terverifikasi dan key khusus Sending/domain. Claude hanya mengganti
MAIL_* env privat staging lalu recreate API/worker-default pada tag yang sama;
DB/APP_KEY/Redis identik. Backup/restore privat tersedia. Volume dan ID container
lain tetap; dua worker recycle alami per jam sesuai konfigurasi. Empat job email
DONE, nol FAIL/exception/pola token-fragment pada log yang diperiksa. Rincian:
[laporan Claude](CLAUDE_RESUMED_ACCEPTANCE.md).

Penerima lama tidak dapat diakses, sehingga coordinator memilih Gmail pemilik
yang terhubung sebagai default uji setelah kesempatan preferensi; akun/fixture
lama dipertahankan privat. Email A awal hanya transport. Email B awal dari helper
digantikan oleh permintaan aplikasi, tidak dianggap reset akhir. Regex nama job
helper diperbaiki dan diperiksa read-only tanpa email tambahan. Expiry/race
dibuktikan17cek mekanika staging dan suite API184tes/1679assertions.

- [Browser sesudah reset tautan email](screenshots/reset-inbox-browser-success-26a0b7e.png)
- [Android masuk dengan sandi baru](screenshots/reset-b-beranda-26a0b7e.png)
- [Laporan perangkat](GROK_RESUMED_ACCEPTANCE.md)

Screenshot ditinjau tanpa penerima, kode, sandi atau fragment. Nama/rumah uji
bukan fakta RT. M07 menjadi tahap berikutnya; gate production/data nyata dan
insiden rotasi historis tetap terpisah.
