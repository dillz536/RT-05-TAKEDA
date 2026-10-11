# M05 — tinjauan independen CI mobile (Claude)

Tanggal: 11 Oktober 2026. Commit `13de24e` (draft PR #17), branch `codex/m05-mobile-foundation`; pohon kerja bersih saat tinjauan. Tinjauan baca-saja. Satu-satunya perintah yang dijalankan adalah `node infra/scripts/check-mobile-build-config.mjs --self-test`: exit 0, `self-test OK: pemeriksaan input build debug`. Tidak ada edit sumber, `pub get`, format, Flutter/Gradle, tes, mutasi Git, maupun VPS.

**Hasil: PASS, tanpa blocker.** Bukti run GitHub Actions, unduhan artifact, dan emulator tetap milik root dan belum ditinjau di sini.

## Pemeriksaan

| Butir | Bukti (file:line) | Hasil |
| --- | --- | --- |
| Input dispatch tidak diinterpolasi ke shell | `.github/workflows/mobile.yml:44-48`: `inputs.flavor`/`inputs.api_base_url` hanya masuk `env:`; `run:` statis. `mobile.yml:94-97`: nilai matriks juga lewat `env:`. `matrix.build.flavor` hanya dipakai di `with:` artifact (`:100-103`), dan nilainya berasal dari plan yang membatasi flavor ke tiga nilai tetap | PASS |
| argv tanpa shell | `check-mobile-build-config.mjs:116-123` menyusun argv; `:280` `spawnSync('flutter', args, { shell: false })` | PASS |
| Secret dan izin | Tidak ada `secrets.` di workflow; `permissions: contents: read` (`mobile.yml:30-31`). Output plan memakai delimiter acak (`:132-133`); pesan error tidak menyalin URL (`:259`, `:272`; self-test `:231`) | PASS |
| Flavor tidak bergeser | Flavor tetap `dev/staging/prod` (`:11`, `mobile.yml:21-24`). PR/push selalu dev+staging dan mengabaikan input (`:92-101`, self-test `:206-211`). `--build-debug` memvalidasi ulang dan memastikan `FLAVOR` sesuai (`:270-279`). Dart menerima nama yang sama persis (`mobile/lib/app/config.dart:42-43`) | PASS |
| HTTPS untuk nondev; HTTP dev hanya host lokal persis | JS `:79-82` memakai host mentah sebelum normalisasi WHATWG, sehingga `127.1`/`0x7f000001`/`localhost`/`[::1]` ditolak (self-test `:159-168`). Dart `config.dart:67-72` dengan `devHttpHosts = {10.0.2.2, 127.0.0.1}` (`:29`). Network config debug memuat dua host yang sama (`mobile/android/app/src/debug/res/xml/network_security_config.xml:5-8`); main tanpa cleartext (`src/main/res/xml/network_security_config.xml:3`) | PASS |
| Normalisasi origin dan bentuk mentah | JS: bentuk mentah `^https?://authority/?$` (`:64-65`), backslash (`:56`), `@` (`:57`), `?`/`#` termasuk kosong (`:58`), port 1–65535 (`:24-31`, `:66-69`), path dan `/../` (`:65`, `:78`); hasil akhir `url.origin` (`:87`). Dart setara: `config.dart:46-66` (regex origin mentah, `@`, query/fragment, port 1–65535, whitespace/kontrol `:47`); tes `mobile/test/config_test.dart:71-95` | PASS |
| Format, analyze, dan tes ketat | `mobile.yml:67` format tanpa `\|\| true`; `:68` analyze; `:69` test. Jumlah 77 cocok secara statis: 75 tes pada handoff Claude ditambah 2 tes config baru root (`config_test.dart:71`, `:76`). Run aktual tidak diulang di sini | PASS (CI aktual milik root) |
| Toolchain | Java 17 Temurin (`mobile.yml:57-60`, `:82-85`); Flutter 3.47.7 stable (`:61-65`, `:86-90`); Node 22 di job plan (`:39-41`) | PASS |
| APK debug terpisah + checksum | Matriks per flavor (`mobile.yml:76-79`). Berkas `rt05-takeda-<flavor>-debug.apk` + `.sha256` (`check-mobile-build-config.mjs:285-294`). Artifact `rt05-takeda-debug-<flavor>` dengan `if-no-files-found: error` (`mobile.yml:98-105`) | PASS |
| Prod hanya dispatch manual, debug, bukan rilis | Prod hanya lewat `workflow_dispatch` (`:103-107`); argv selalu `--debug` dan `--release` ditolak (`:120`, `:276`; self-test `:235`). Workflow tidak punya langkah signing | PASS |
| allowBackup | `mobile/android/app/src/main/AndroidManifest.xml:4` `android:allowBackup="false"` | PASS |

## Catatan non-blocker

1. `check-mobile-build-config.mjs:60`: pola `^https?:` peka huruf, sedangkan `:59` dan `:64` tidak. Input seperti `HTTPS:host` ditolak dengan kode `url-scheme`, bukan `url-not-origin`. Hasilnya tetap ditolak; hanya label error yang berbeda.
2. Job `apk` (`mobile.yml:70-97`) tidak memakai `setup-node`, sehingga skrip berjalan dengan Node bawaan runner ubuntu-latest. Skrip hanya memakai API Node standar.
3. `GROK_CI_RESULT.md:77` masih menyebut langkah format "boleh gagal sampai sumber mobile diformat". Kondisi itu sudah usang karena sumber diformat dan CI kini ketat. Ini hanya catatan dokumen.

## Belum terbukti (milik root)

- Run Mobile verification di GitHub untuk `13de24e`/PR #17: format, analyze, 77 tes, dan dua artifact APK debug dengan sha256.
- Verifikasi sha256 artifact yang diunduh.
- Health request nyata dari emulator/HP ke staging (gate ROADMAP M05).
