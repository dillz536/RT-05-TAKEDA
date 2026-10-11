# M05 — hasil CI dan artifact

11 Oktober 2026. Source `13de24e230b44dd78b382260013108b5daf1d84a`,
branch `codex/m05-mobile-foundation`, [PR17](https://github.com/morrispes5/RT-05-TAKEDA/pull/17).

## Run source

| Pemeriksaan | Hasil aktual |
| --- | --- |
| [Mobile verification38135976078](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38135976078) | SUCCESS: plan6s, verify1m13s, APK dev4m33s, staging4m43s |
| Format | 25 files, 0 changed; tanpa pemaksa sukses |
| Analyzer | No issues found |
| Unit/widget | Log job verify pada11:34:25UTC: 77 tests passed |
| Guard konfigurasi | Self-test SUCCESS sebelum plan; PR menghasilkan dev lokal dan staging HTTPS |
| [Infra38135976074](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38135976074) | SUCCESS, 2m10s; bukan redeploy VPS |
| [Hygiene38135976098](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38135976098) | SUCCESS, 7s |
| Vercel dan Preview Comments | Keduanya PASS; website source/preview tidak diubah |

Mobile job plan selesai11:33:14UTC, verify11:34:30, APK dev11:39:06,
APK staging11:39:16. Toolchain Flutter3.47.7 stable, Dart3.13.5 dan Java17.
Seluruh delapan checks PR pada source ini PASS. Review CI Claude dan review
mobile Grok PASS. [Review final Kimi](KIMI_ACCEPTANCE_REVIEW.md) PASS setelah
memeriksa bukti dan screenshot emulator. Reviewer tidak mengklaim menjalankan
CI/APK sendiri; CI/download/emulator dijalankan Codex.

## Artifact

GitHub menghasilkan `rt05-takeda-debug-dev` dan `rt05-takeda-debug-staging`,
masing-masing APK bernama sesuai flavor dan berkas `.sha256`. Metadata
artifact tersedia/nonexpired; ukuran ZIP dev92.534.096 byte dan
staging92.533.359 byte. Unduhan ke folder temp lokal selesai.
Checksum dan artifact kini diverifikasi setelah unduhan selesai:

| APK | Byte | SHA256 |
| --- | --- | --- |
| dev | 173.402.788 | e7dd376b7707bab60683e41f8a19ff644ef9be0a395509750b3d921babea986c |
| staging | 173.402.780 | 88c2b29aba14acb1a111c3907d036795cf1c116f7812ad5fc99062568f3a176b |

Keduanya cocok dengan `.sha256` artifact. ZIP APK keduanya memuat dua SVG yang
byte-identik dengan website dan delapan TTF; scan seluruh member untuk pola
PostgreSQL/Redis DSN, password Neon `npg_...` dan `APP_KEY=base64:` menemukan0.
Scan ini tidak membuktikan ketiadaan setiap jenis secret; review sumber dan
check-repo melengkapinya. Nilai temuan tidak dicetak.

`aapt2 dump badging/xmltree`: package `tech.morriz.takeda`, versionName0.1.0,
versionCode1, minSDK24/targetSDK36, label RT05 TAKEDA, adaptive icon resmi,
debuggable=true, allowBackup=false, network config base cleartextfalse dan
pengecualian debug hanya10.0.2.2/127.0.0.1. `adb install -r` awal gagal
UPDATE_INCOMPATIBLE karena debug signing key berubah. Aplikasi uji lama
diverifikasi di layar login kosong; hanya paket uji itu dihapus lalu APK staging
terverifikasi dipasang: Success. Install/launch, health HTTPS nyata,
offline/retry pada proses yang sama, ikon dan visual skala teks1,3/2,0 telah
diperiksa. Bukti dan batas pengujian guest ada di [LIVE_RESULTS.md](LIVE_RESULTS.md).
Review acceptance final Kimi menerima bukti ini; status gate dicatat di
[PROGRESS.md](../../PROGRESS.md), bukan diputuskan dari CI saja.

APK memakai signing debug CI. Rilis production, signing key, autentikasi
akun nyata, SMTP, FCM dan UAT bisnis tidak diuji oleh run fondasi ini.
Peringatan maintenance action Node20/setup-java v4 dan perpindahan runner
Ubuntu tercantum pada run; tidak menyebabkan check gagal pada source ini.
