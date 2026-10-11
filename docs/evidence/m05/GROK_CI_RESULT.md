# Konfigurasi CI mobile M05 — Grok

Tanggal: 11 Oktober 2026. Penulis: Grok. Branch `codex/m05-mobile-foundation`, HEAD `0309f3e4148bc3f1bc46661c4f7bc71c5119fdc7`. Kepemilikan sesi ini hanya [mobile.yml](../../../.github/workflows/mobile.yml), [check-mobile-build-config.mjs](../../../infra/scripts/check-mobile-build-config.mjs), dan dokumen ini. `mobile/**` tetap milik Claude. Git, dokumen bersama, dan emulator tetap milik Codex.

Susulan setelah tinjauan coordinator mengubah checker dan dokumen ini. Workflow tetap memanggil checker yang sama dan tidak diedit ulang. Tidak ada commit, pindah branch, deploy, VPS, `flutter pub get`, `dart format`, atau build Gradle/APK lokal. Larangan build lokal mengikuti [DECISIONS.md](../../DECISIONS.md) ADR19.

## Hasil

Workflow Mobile verification memisahkan rencana, pemeriksaan, dan build debug. Job `plan` menulis matriks. Job `verify` berjalan sekali: `flutter pub get`, `dart format --output=none --set-exit-if-changed lib test`, `flutter analyze`, dan `flutter test`. Job `apk` memakai Java 17 (Temurin) dan Flutter 3.47.7, lalu `flutter build apk --debug`.

Pada pull request dan push ke `main`, matriksnya persis dua APK debug:

- flavor `dev`, origin `http://10.0.2.2:8077`
- flavor `staging`, origin `https://takeda-staging.morriz.tech`

`workflow_dispatch` punya pilihan flavor `dev`, `staging`, atau `prod`, plus input `api_base_url` yang kosong memakai default flavor. Prod hanya ada pada dispatch eksplisit, dengan default `https://takeda.morriz.tech`. Build itu tetap `--debug`. Bukan APK rilis dan bukan klaim tanda tangan production.

Nilai yang masuk ke `--dart-define=API_BASE_URL` adalah origin yang sudah dinormalisasi, bukan string mentah. Garis miring akhir pada origin root diterima lalu dibuang. Port HTTPS yang sah, termasuk 443, diterima; port default 443 dan port default HTTP 80 dibuang, port lain yang sah tetap. Input tidak sah menggagalkan `plan` sebelum build. Perintah Flutter disusun sebagai argv dengan `shell: false`. `--dart-define=FLAVOR` mengikuti flavor matriks. Artifact debug bernama `rt05-takeda-debug-dev`, `rt05-takeda-debug-staging`, dan `rt05-takeda-debug-prod` hanya bila flavor itu memang dibangun, masing-masing dengan berkas sha256.

Default prod `https://takeda.morriz.tech` adalah origin nonsensitif untuk domain yang tertulis di [ROADMAP.md](../../ROADMAP.md) M16. Domain itu belum dideploy oleh pekerjaan ini. Build prod hanya muncul pada `workflow_dispatch` dan tetap `--debug`.

## Penyelarasan

Nama define tetap `FLAVOR` dan `API_BASE_URL`. Blob pada HEAD masih memakai field statis lama dengan kedua nama itu. Pohon kerja Claude yang belum di-commit memvalidasi nama yang sama, menerima garis miring akhir, dan `AppConfig.devHttpHosts` berisi `10.0.2.2` serta `127.0.0.1`. Berkas debug `mobile/android/app/src/debug/res/xml/network_security_config.xml` memuat host yang sama. `localhost` serta `[::1]` ditolak. Berkas `mobile/**` tidak diubah pada susulan ini.

Kontrak akhir checker, diperiksa pada string mentah sebelum `new URL`:

- Bentuk wajib `http://` atau `https://`, lalu authority, lalu paling banyak satu garis miring. `https:host` dan `https:/host` ditolak (`url-not-origin`).
- Garis miring akhir pada origin root diterima dan dinormalisasi hilang.
- Port eksplisit 1 sampai 65535 diterima. Port 443 pada HTTPS dan port 80 pada HTTP dinormalisasi hilang. Port 0 dan port di atas 65535 ditolak (`url-port`).
- Whitespace atau karakter kontrol di dalam nilai ditolak (`url-whitespace`). String kosong atau hanya whitespace ditolak (`url-empty`).
- Query atau fragment mentah ditolak, termasuk `?` dan `#` kosong (`url-query-or-fragment`).
- Userinfo ditolak, termasuk `@` dengan nama kosong (`url-userinfo`).
- Backslash ditolak (`url-backslash`).
- Path selain garis miring akhir ditolak, termasuk `/api` dan `/../`, walaupun parser WHATWG menormalisasinya menjadi origin (`url-not-origin`).
- HTTP hanya untuk flavor `dev` pada `10.0.2.2` atau `127.0.0.1` (`url-http-not-allowed`). HTTPS boleh pada setiap flavor.

## Perintah dan hasil

| Perintah | Hasil |
| --- | --- |
| `node infra/scripts/check-mobile-build-config.mjs --self-test` | exit 0, `self-test OK: pemeriksaan input build debug` |
| `--plan` event `pull_request` | exit 0, dua build dev lokal dan staging publik |
| `--plan` event `push` dengan flavor `prod` dan URL HTTP lain | exit 0, matriks yang sama; input itu diabaikan |
| `--plan` dispatch flavor `staging`, URL HTTP staging | exit 1, `konfigurasi build ditolak: url-http-not-allowed` |
| `--plan` dispatch flavor `nope` | exit 1, `flavor-invalid` |
| `--plan` dispatch flavor `dev`, HTTP `example.com` | exit 1, `url-http-not-allowed` |
| `--self-test` URL bernilai newline atau spasi di tengah | `url-whitespace` |
| `--plan` dispatch flavor `prod`, URL kosong | exit 0, satu build `https://takeda.morriz.tech` |
| `--plan` dispatch flavor `dev`, URL kosong | exit 0, satu build `http://10.0.2.2:8077` |
| `--plan` dispatch staging dengan garis miring akhir | exit 0, origin tanpa garis miring |
| `--plan` dispatch staging dengan `:443` | exit 0, origin tanpa port |
| `--plan` dispatch staging dengan `:8443/` | exit 0, origin `https://takeda-staging.morriz.tech:8443` |
| `--plan` dispatch staging port `0` dan `65536` | exit 1, `url-port` |
| `--plan` dispatch `https:host` tanpa dua garis miring | exit 1, `url-not-origin` |
| `--plan` dispatch `?` kosong dan `@` kosong | exit 1, `url-query-or-fragment` dan `url-userinfo`; pesan tidak menyalin URL |
| `node --check infra/scripts/check-mobile-build-config.mjs` | exit 0 |
| `--self-test` susulan | exit 0, `self-test OK: pemeriksaan input build debug` |
| Parser YAML | PyYAML, Ruby, yq, dan actionlint tidak terpasang. Paket npm `yaml` dipasang sementara di direktori temp, lalu dihapus. `YAML.parse` menerima workflow ini: job `plan`, `verify`, `apk`; pilihan flavor `dev,staging,prod`; langkah format tidak memuat pemaksa sukses |

Keluar `--plan` untuk pull request:

```json
[{"flavor":"dev","apiBaseUrl":"http://10.0.2.2:8077"},{"flavor":"staging","apiBaseUrl":"https://takeda-staging.morriz.tech"}]
```

`node infra/scripts/check-repo.mjs` dijalankan lagi setelah kontrak URL ini ditulis. Hitungan berkas mencakup `mobile/**` yang sedang diubah Claude, jadi angka itu bukan jumlah berkas susulan ini. Keluaran:

OK: 519 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.

## Tidak dijalankan

`flutter build`, `dart format`, `flutter pub get`, `flutter analyze`, `flutter test`, Gradle, `--build-debug`, workflow GitHub, unduh artifact, dan pemasangan emulator tidak dijalankan dari sesi ini.

## Serah terima

Setelah Claude menyerahkan `mobile/**`, Codex menjalankan Mobile verification, mengunduh `rt05-takeda-debug-dev` dan `rt05-takeda-debug-staging`, memeriksa sha256, lalu memasang APK debug staging pada emulator. APK itu ditandatangani debug, bukan rilis production. Pemeriksaan format adalah gate wajib; hasil source final25 file0changed ada di [CI_RESULTS.md](CI_RESULTS.md). Jika nama `FLAVOR` atau `API_BASE_URL` berubah, workflow perlu diselaraskan. Bukti request nyata emulator untuk [ROADMAP.md](../../ROADMAP.md) M05 ada di [LIVE_RESULTS.md](LIVE_RESULTS.md). [READINESS_REVIEW.md](READINESS_REVIEW.md) tidak diubah.

Dokumen acuan lain: [MOBILE.md](../../MOBILE.md), [TESTING.md](../../TESTING.md), [CAPSTONE_ALIGNMENT.md](../../CAPSTONE_ALIGNMENT.md). Rotasi kredensial dari insiden sebelumnya tetap di luar pekerjaan ini dan tetap blocked pada pemilik. M06 dan sesudahnya tidak dibuka.

## Integrasi setelah handoff — Codex

Job plan kini memakai Node22 dan menjalankan `--self-test` sebelum matriks.
Guard menerima scheme HTTPS case-insensitive seperti Dart, serta menolak alias
numerik host HTTP dev (`127.1`, `0x7f000001`) sebelum WHATWG normalisasi; hanya
host mentah allowlist Android yang sah. Self-test mencakup kasus ini dan lulus.
Config Dart menerima port1..65535 serta menolak bentuk mentah yang sama;
validasi lokal gabungan Codex: analyzer bersih, 77 tes Flutter lulus. Bukti
GitHub build dan emulator belum ada pada checkpoint ini.
