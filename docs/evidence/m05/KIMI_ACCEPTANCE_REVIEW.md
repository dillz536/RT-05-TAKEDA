# KIMI Acceptance Review — M05 (fondasi mobile)

- Reviewer: Kimi (independent; READ ONLY terhadap sumber). Tanggal: 11 Oktober 2026.
- Objek: branch `codex/m05-mobile-foundation` @ `13de24e` (PR17, sumber final frozen), PR #17.
- Acuan: ROADMAP.md M05, docs/MOBILE.md, docs/evidence/m05/M05_PLAN.md, CLAUDE_FOUNDATION_RESULT.md, GROK_CI_RESULT.md, CAPSTONE_ALIGNMENT.md, PROGRESS.md, BUSINESS_RULES/PRD.
- Riwayat preliminary kode/laporan dipertahankan di bawah. **Verdict FINAL di bagian akhir: PASS**, setelah bukti CI/artifact dan screenshot emulator aktual diterima pada dispatch `task_84918b406e92`.

## Cakupan butir penerimaan (M05_PLAN) vs bukti

| Butir | Bukti kode/laporan | Status |
| --- | --- | --- |
| Sesi/offline | `lib/core/session.dart`: 401 saja menghapus token; offline/timeout/5xx/403 → `unavailable` tanpa akun; epoch anti-race; storage diserialkan; 14 tes sesi | PASS kode; emulator offline/retry PENDING |
| Konfigurasi | `lib/app/config.dart` + `startup.dart`: flavor dev/staging/prod, origin root, HTTPS wajib staging/prod, HTTP dev hanya 10.0.2.2/127.0.0.1; ConfigErrorApp tanpa request; 7+2 tes | PASS kode; guard CI aktual PENDING |
| Health | `lib/core/health.dart`: GET /health/live tanpa token, timeout 8s/total 16s, status JSON ok, request_id UUID; 8 tes | PASS kode; request emulator nyata PENDING |
| Router | `lib/app/router.dart`: go_router top-level, guard sesi murni, detail tetap Navigator; 14 tes router | PASS kode |
| Brand | SVG gapura byte-identik website (diverifikasi reviewer: `cmp` kedua SVG identik); font OFL terbundel tanpa unduhan runtime; token warna disamakan CSS; ikon launcher dikonversi dari SVG resmi, deterministik; 11 tes brand | PASS kode; screenshot emulator PENDING |
| Aksesibilitas | 8 widget test skala 1,3/2,0 lebar 360/320; perbaikan layout murni tata letak (grid→baris, chip status pindah, FittedBox badge); label nav dibatasi 1,3×, chip status 1,5× | PASS kode; visual emulator PENDING |
| CI/APK | Workflow `mobile.yml`: job plan/verify/apk, format `--set-exit-if-changed` tanpa `|| true`, analyze, test, dua APK debug (dev/staging) + sha256; guard `check-mobile-build-config.mjs` dengan self-test dan kontrak URL selaras Dart (port 1..65535, normalisasi slash/port default, tolak whitespace/backslash/userinfo/query/fragment/path/alias numerik) | PASS desain/guard; run GitHub + sha256 artifact PENDING |
| Keamanan | Tanpa DSN/secret di `--dart-define`; `allowBackup=false` di manifest (diverifikasi reviewer); scan reviewer tidak menemukan credential di `mobile/lib`/manifest (hanya nama field form); pesan error tidak menyalin nilai URL | PASS kode; string-scan APK PENDING |

## Butir rencana yang sebelumnya implisit (dari KIMI_PLAN_REVIEW)

Ketiganya kini eksplisit: Riverpod dan versi aplikasi dari `pubspec.yaml` disebut di MOBILE.md yang diperbarui; pola reusable loading/empty/error ada di `core/widgets.dart` dan MOBILE.md "Perilaku UI". Catatan minor plan review ditutup.

## Batas bisnis dan keselamatan (diverifikasi reviewer)

- **Aturan bisnis tidak berubah**: perubahan `mobile/**` terbatas fondasi (config, session, router, health, brand, layout skala teks); endpoint/payload login/registrasi M06 historis tidak diubah; `rest-api/`, `website/`, schema/ERD tidak disentuh.
- **Selisih Drive tetap sesuai CAPSTONE_ALIGNMENT**: approval akun utama (Drive: semua pending vs BUSINESS_RULES: utama aktif unverified) tetap unresolved → M06, tidak direkonsiliasi diam-diam. Tipe nominal bigint vs DECIMAL(14,2) tercatat ADR17. Dua selisih baru yang dicatat root (detail UI skala teks, aset/komponen M05) adalah pilihan tata letak/aset, bukan perubahan aturan — sesuai penilaian reviewer.
- **Debug APK ≠ production**: build `--debug`, bukan signed release/UAT; M15/M16 tidak diklaim.
- **Credential historis tetap open**: rotasi secret insiden lama tetap blocked pada pemilik; tidak disentuh pekerjaan ini.
- Tidak ada tes/factory terhadap Neon; tidak ada perubahan VPS/DNS/production.

## Bukti yang masih PENDING (diminta ke coordinator)

1. Run ID + URL GitHub Actions "Mobile verification" pada `13de24e` dengan ketiga job (plan/verify/apk) SUCCESS.
2. Artifact `rt05-takeda-debug-dev` dan `rt05-takeda-debug-staging`: sha256 cocok dengan berkas `.sha256` di artifact.
3. APK staging terpasang di emulator/HP: bukti health nyata — ServerInfo `staging • takeda-staging.morriz.tech • health ok • <request_id>` dan/atau logcat `RT05 health ok request_id=…`, dicocokkan dengan log API staging.
4. Skenario mode pesawat → splash "Coba lagi" tanpa logout (token dipertahankan).
5. Screenshot: launcher icon, badge STAGING, text scaling sistem 1,3 dan 2,0 pada layar login/shell.
6. String scan APK staging: tanpa DSN/secret.

## Verdict preliminary

**PASS untuk implementasi kode dan desain CI sesuai butir rencana** (77 tes lokal lulus menurut laporan Claude+Codex, analyzer bersih, guard konfigurasi self-test, batas bisnis terjaga). **Gate akhir M05 tetap PENDING** menunggu keenam bukti aktual di atas; review ini tidak mengklaim milestone lulus dari kode saja.

---

## Verdict FINAL — gate M05 (dispatch task_84918b406e92, 11 Oktober 2026)

Keenam bukti PENDING di atas kini tiba dan telah diverifikasi reviewer terhadap artefak aktual:

### Bukti aktual yang diverifikasi

1. **CI GitHub**: [Mobile verification 38135976078](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38135976078) SUCCESS pada source `13de24e` — plan, verify (format 0 changed, analyzer bersih, **77 tes lulus**), APK dev 4m33s dan staging 4m43s. Infra 38135976074, hygiene 38135976098, Vercel dan Preview Comments juga PASS: delapan check PR17 hijau.
2. **Checksum artifact**: APK dev `e7dd376b…ea986c` dan staging `88c2b29a…f3a176b` cocok dengan berkas `.sha256` artifact (CI_RESULTS.md).
3. **Health emulator nyata**: APK staging terpasang di Pixel_8a (emulator-5554); screenshot `online.png` menampilkan ServerInfo `staging • takeda-staging.morriz.tech • health ok • 31bace65-6b48-4e1a-9ff6-258e9fb2d5f0` — UUID persis sama dengan tabel logcat LIVE_RESULTS (PID22925, 18:43:42 WIB). TLS nyata tanpa proxy/bypass.
4. **Offline → retry proses sama**: `offline.png` menunjukkan ikon mode pesawat di status bar, pesan "Tidak dapat terhubung ke server" dan tombol "Coba lagi" dengan footer `health gagal (offline)`; `retry.png` menunjukkan health ok dengan UUID baru `d71823ee-b78c-442f-a665-775eee609f4f` pada PID23103 yang sama, kembali ke login tanpa restart. Cocok dengan LIVE_RESULTS.
5. **Visual/brand/aksesibilitas**: `app-info.png` menampilkan ikon gapura resmi dan label RT05 TAKEDA dari APK terpasang; `scale13.png` terbaca pada skala 1,3; `scale20-top.png`/`scale20-bottom.png` pada skala 2,0 menampilkan semua isian/tombol (tombol akun tambahan membungkus dua baris sesuai catatan) dan ServerInfo UUID `94bbf2f2…` yang cocok dengan logcat PID23713. Tanpa overflow/crash pada logcat.
6. **String scan APK**: seluruh member ZIP kedua APK dipindai pola DSN PostgreSQL/Redis, password Neon `npg_…`, dan `APP_KEY=base64:` — 0 temuan. `aapt2` pada APK aktual: `allowBackup=false`, cleartext base false, pengecualian debug hanya 10.0.2.2/127.0.0.1. Melengkapi (bukan menggantikan) review sumber dan check-repo.

### Penilaian gate ROADMAP M05 — seluruhnya TERPENUHI dengan bukti aktual

| Gate | Verdict final | Bukti |
| --- | --- | --- |
| HP/emulator request nyata | PASS | GET /health/live HTTPS nyata dari emulator-5554, UUID response header tampil di UI dan logcat (5 skenario) |
| Tidak ada DSN/secrets | PASS | Scan ZIP 0 temuan + check-repo 532 berkas + tanpa secret di dart-define |
| Fake mode tidak ada production | PASS | Health staging nyata TLS; fake hanya local/test |
| HTTPS validation | PASS | Config Dart + guard CI selaras (self-test); aapt network config terverifikasi pada APK aktual |
| Responsive/text scaling dasar | PASS | 8 widget test CI + screenshot emulator skala 1,3/2,0 tanpa overflow/exception |
| Analyze/test lulus | PASS | Run 38135976078: analyzer bersih, 77/77 tes |

### Nuansa dan batas yang dicatat (bukan blocker)

- **Preservasi token** dibuktikan oleh 14 tes sesi dalam 77 tes CI; emulator berada dalam keadaan guest tanpa token, sehingga tidak ada klaim runtime terautentikasi. Gate M05 tidak mensyaratkan akun; login/registrasi M06 bukan prasyarat dan tidak diklaim.
- **UUID health** adalah bukti response nyata di aplikasi; tidak diklaim dikorelasikan dengan access log VPS, dan ROADMAP M05 tidak mensyaratkan logging tambahan.
- **Kosmetik**: banner diagonal STAGING beririsan dengan jam status bar emulator (terlihat pada screenshot); terdokumentasi di CAPSTONE_ALIGNMENT/LIVE_RESULTS, hanya nonprod, tidak menghalangi konten.
- **Bukan cakupan**: signed release/production (M15/M16), UAT bisnis, SMTP, FCM, akun/login nyata M06. Debug APK bukan rilis.
- **Insiden credential historis tetap open** (blocked pada pemilik); keberhasilan M05 tidak menyatakan production aman.

### Keputusan akhir

**PASS — gate M05 terpenuhi oleh bukti aktual.** Seluruh gate ROADMAP M05 dibuktikan oleh CI nyata, artifact terverifikasi checksum, dan drill emulator nyata; batas bisnis, privasi, dan rekonsiliasi Drive terjaga; tidak ada blocker tersisa pada scope M05. Tidak ada syarat M06/M15/M16 yang dicampur ke dalam penerimaan ini.
