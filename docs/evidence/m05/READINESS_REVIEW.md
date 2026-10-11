# M05 — tinjauan kesiapan (baca-saja)

Tanggal: 11 Oktober 2026. Penulis: Claude (sesi backend yang sama), atas dispatch coordinator selama gate live M04 belum selesai. Branch saat audit: `codex/m04-staging-recovery` @ 75dee91.

**Status: M05 belum lulus dan tidak ditandai lulus oleh dokumen ini.** Dokumen ini hanya audit sumber. Tidak ada kode mobile yang diubah, tidak ada `flutter build`/Gradle/`flutter test` yang dijalankan, dan tidak ada emulator/HP yang dipakai. Tidak ada akses Neon, data warga, atau secret.

Sumber yang dibaca: README, PRD, BUSINESS_RULES, ARCHITECTURE, ROADMAP (M05), PROGRESS, MOBILE, API, SECURITY, TESTING, CAPSTONE_ALIGNMENT. Kode yang diaudit: `mobile/lib/**`, `mobile/test/app_test.dart`, `mobile/pubspec.yaml`/`pubspec.lock`, `mobile/android/**`, `.github/workflows/mobile.yml`.

## 1. Klasifikasi bukti

| Jenis | Isi | Berlaku untuk HEAD? |
| --- | --- | --- |
| **Historis lokal** (sesi 3, 10 Okt) | `flutter analyze` bersih, `flutter test` 13 lulus di PC pemilik | Isi `mobile/` identik dengan 00cd078 (`git diff 00cd078 HEAD -- mobile .github/workflows/mobile.yml` kosong). Hasil tidak dijalankan ulang di audit ini |
| **Historis CI** | Run Mobile verification sukses: 38068116945 (push main 00cd078) dan 38099378306 (workflow_dispatch @53a8b10, `API_BASE_URL=https://takeda-staging.morriz.tech`). Keduanya analyze + test + APK debug | Sumber sama, toolchain dipin (Flutter 3.47.7, `pubspec.lock` di-commit). Belum ada run CI baru untuk branch M05 |
| **Historis emulator** (sesi 4) | Pixel_8a → API lokal → Neon dev: login warga/pengurus, iuran, layanan anonim, catat iuran. Bukti: `docs/evidence/mobile/01–08` | Ini bukti fitur M06–M12 lewat API lokal, **bukan** bukti gate M05 terhadap staging |
| **Historis staging** (sesi 6) | APK staging terpasang, layar login tampil (`09-login-staging.png`) | Login atau request terautentikasi ke staging **belum** dibuktikan |
| **Baru, audit ini** | Audit sumber (bagian 2–3) | Tanpa eksekusi |
| **Live HP fisik** | Belum pernah | — |

## 2. Matriks fondasi M05 (ROADMAP M05 + MOBILE.md)

| Butir | Temuan di kode | Status |
| --- | --- | --- |
| Scaffold, pin Flutter stable, Android | Flutter 3.47.7 dipin di workflow; Dart `^3.13.5`; lockfile ada. Package `tech.morriz.takeda` (`android/app/build.gradle.kts:19`) | ada; komentar TODO template tersisa (`:18`, `:34`), `pubspec.yaml:2` "A new Flutter project." |
| Brand (navy/kuning/sky, font, logo gapura) | Warna sesuai website (`lib/app/theme.dart:7-10`). Font Bricolage/Public Sans diambil runtime dari Google (`theme.dart:34-35,51`), padahal website self-host (fontsource). Logo `GapuraMark` adalah gambar tangan CustomPaint (`lib/core/widgets.dart:155-192`), bukan aset `website/public/images/logo/rt05-mark*.svg`. **Ikon launcher masih logo Flutter bawaan** (`android/app/src/main/res/mipmap-*/ic_launcher.png`) | gap |
| Router go_router | **Tidak ada.** `go_router` tidak ada di `pubspec.yaml`. Navigasi = `home:` bersyarat sesi (`lib/main.dart:37-48`) + 13 `Navigator.push` imperatif + `IndexedStack` | gap vs MOBILE.md |
| Riverpod | `flutter_riverpod` 3; `sessionProvider` Notifier; `FutureProvider.autoDispose` per data (`lib/features/warga/providers.dart`) | ada |
| Dio/DTO/error | `Api` Dio dengan bearer interceptor, 401 → `expire()`, envelope error → `ApiException` (`lib/core/api.dart:26-53`), timeout = "hasil belum diketahui", POST tanpa auto-retry. DTO ringan `lib/core/models.dart` | ada; 429 Retry-After/503 tidak dibedakan (minor) |
| Secure storage | Token di `FlutterSecureStorage` (`lib/core/session.dart:21-22`); logout/401 menghapus token | ada, dengan **bug restore** (gap H2) |
| Flavor dev/staging/prod + HTTPS | Hanya `--dart-define` `API_BASE_URL`/`FLAVOR` (`lib/app/config.dart:6-7`). Default `dev` + `http://10.0.2.2:8077`. `isProduction` tidak dipakai (`:11`). CI selalu `FLAVOR=dev` termasuk APK staging (`mobile.yml:44`). Tidak ada validasi skema. HTTPS dijaga hanya oleh network security config: main tanpa cleartext, debug hanya 10.0.2.2/127.0.0.1 | sebagian; gap |
| Health smoke + timeout | `Api.health()` (`lib/core/api.dart:105-113`) tidak dipanggil di mana pun; hanya connectTimeout 8 detik tanpa receiveTimeout. Splash tidak memeriksa ketersediaan server | gap |
| Komponen loading/empty/error/form | `AsyncView`, `ErrorState`, `EmptyState`, `BusyButton` anti ketuk ganda, `StatusChip` teks + ikon (`lib/core/widgets.dart`) | ada, ada tes widget |
| Fake fixtures test-only | Tidak ada fake adapter; tes memakai objek langsung. Tidak ada jalur fallback fake di production | memenuhi secara trivial |
| Text scaling dasar | Tidak ada tes text scaler. Beberapa tinggi/ukuran font fixed (`theme.dart:87-88`). `MaterialApp` tanpa `localizationsDelegates`/`locale` (`main.dart:44`) sehingga date/time picker admin (`pengurus_pages.dart:501,682,684`) berbahasa Inggris | gap |
| CI analyze/test/debug APK | `mobile.yml`: analyze, test, APK debug + sha256 artifact. `dart format ... \|\| true` (`mobile.yml:40`) tidak menegakkan format | ada; minor |
| No DSN/secret di APK | `config.dart` hanya URL publik. `local.properties`, `GeneratedPluginRegistrant`, `.flutter-plugins-dependencies` di-ignore (diverifikasi `git check-ignore`) | ada |
| Gate: request nyata HP/emulator ke staging | Hanya layar login staging | **belum** |

## 3. Gap dan langkah minimal

Prioritas: H = menghalangi gate M05, M = sebaiknya ditutup di M05, L = catat atau jadwalkan.

| ID | Sev | Gap (path:line) | Langkah minimal | Uji bermakna |
| --- | --- | --- | --- | --- |
| H1 | H | Gate "request nyata HP/emulator ke staging" belum terbukti; hanya screenshot login | Setelah M04 lulus: APK staging dari CI. Login akun demo staging diketik pemilik sendiri. `/auth/me` dan satu GET (mis. `/dues/my`) terlihat. Logout → 401 tertangani | Screenshot + request_id dari log API staging; tanpa credential di bukti |
| H2 | H | `SessionController.restore` menghapus token pada **semua** error, termasuk offline/timeout/503 (`lib/core/session.dart:36-37`): pengguna ter-logout hanya karena sinyal buruk | Hapus token hanya untuk `ApiException` 401 (atau 403 akun nonaktif). Error jaringan → state "tidak dapat terhubung" + coba lagi, token tetap | Unit test `SessionController` dengan storage dan `fetchMe` palsu: 401 → token dihapus; `NETWORK_ERROR`/503 → token tetap |
| H3 | H | Flavor tidak tervalidasi: APK staging berlabel `dev` (`mobile.yml:44`); `http://` bisa masuk ke flavor non-dev (`config.dart:6-7`) | Input workflow `flavor` (dev/staging/prod) mengisi `FLAVOR`. `AppConfig.validate()` saat start: selain dev wajib `https://` dan URL tidak kosong, kalau tidak tampilkan layar konfigurasi salah. Badge "STAGING"/"DEV" di app bar non-prod. Opsional `productFlavors` + `applicationIdSuffix` agar dev/staging bisa terpasang berdampingan | Unit test `AppConfig` (https wajib di staging/prod); widget test badge; workflow staging menolak URL http |
| M1 | M | go_router tidak dipakai (rancangan MOBILE.md); M10 butuh deep link push → route | Tambah `go_router` (pin versi) dengan route tingkat atas `/splash`, `/login`, `/register`, `/forgot`, `/pending`, `/home`, `/notifications`, plus `redirect` dari `sessionProvider` (`refreshListenable`). Halaman detail M06+ boleh tetap `Navigator.push` dulu, jadi perilaku fitur tidak berubah. Alternatif: ADR yang menunda go_router ke M10, tetapi itu menggeser keputusan rancangan dan harus disetujui pemilik/coordinator | Widget test redirect: tanpa token → login; pending → pending; aktif warga/pengurus → shell yang benar; 401 → login + banner sesi berakhir |
| M2 | M | Health tidak dipakai; tanpa receiveTimeout (`lib/core/api.dart:105-113`) | Splash: `health()` dengan connect+receive timeout (mis. 8/8 detik) paralel restore sesi. Gagal → layar "server tidak tersedia" + coba lagi, tanpa menghapus sesi | Widget test splash dengan Dio adapter palsu: timeout → pesan + tombol coba lagi |
| M3 | M | Ikon launcher bawaan Flutter; logo gapura digambar ulang (`widgets.dart:155-192`) | Ikon launcher dan logo in-app dari aset resmi `website/public/images/logo/rt05-mark*.svg` (render PNG adaptive icon atau `flutter_svg`). Jangan mendesain logo baru | Screenshot launcher + splash emulator; tes golden opsional |
| M4 | M | Font Google diambil runtime (`theme.dart:34-35,51`): offline jatuh ke font sistem, ada request ke Google dari APK warga, berbeda dengan website self-host | Bundel TTF Bricolage Grotesque & Public Sans (OFL) di `assets/fonts`, `GoogleFonts.config.allowRuntimeFetching = false` atau ganti ke `fontFamily` aset | Tes: theme memakai family aset; tidak ada HTTP font saat tes |
| M5 | M | Text scaling belum diuji; tanpa lokalisasi Material Indonesia (`main.dart:44`) | Tambah `flutter_localizations` + `locale: id_ID`. Widget test login dan shell (warga/pengurus dengan data palsu) pada `TextScaler.linear(1.3)` dan `2.0` tanpa overflow exception | `tester.takeException()` null di skala 1.3/2.0; date picker berlabel Indonesia |
| L1 | L | Rilis ditandatangani debug key (`build.gradle.kts:34-36`), komentar TODO template | Biarkan untuk debug M05. Signing rilis = M15; tandai jelas di README agar APK release tidak dibagikan | — |
| L2 | L | `android:allowBackup` default true (`AndroidManifest.xml:3`); restore backup dapat membawa data aplikasi tanpa kunci keystore | `android:allowBackup="false"` atau `dataExtractionRules` yang mengecualikan secure storage | Cek manifest hasil merge di CI |
| L3 | L | Metadata: `pubspec.yaml:2` deskripsi template; `AppConfig.version` 1.0.0 (`config.dart:9`) ≠ `pubspec.yaml:4` 0.1.0+1 | Ambil versi dari satu sumber (pubspec / `--build-name`) | — |
| L4 | L | `dart format \|\| true` (`mobile.yml:40`) | Hapus `\|\| true` setelah format kode dirapikan sekali | CI format gagal bila tidak rapi |
| L5 | L | 429/503 tidak dibedakan dari error umum (`lib/core/api.dart:26-53`) | Map 429 → pesan "terlalu sering, coba lagi" (+ `Retry-After` bila ada); 503 → "layanan sementara tidak tersedia" | Unit test `ApiException.from` 429/503 |
| L6 | L (M06) | Keluar dari halaman pending tidak memanggil `/auth/logout` (`lib/features/auth/auth_pages.dart:325`), sehingga token server tidak dicabut | Catat untuk M06; tidak diubah di M05 | — |
| L7 | L | Warna teks sekunder sedikit berbeda dari website (`theme.dart:11-12` vs `website/resources/css/app.css:7`) | Samakan token `ink`/`muted` dengan CSS website | — |

## 4. Urutan kerja M05 yang disarankan (setelah coordinator membuka M05)

1. H2, H3, M2: sesi, flavor/HTTPS, health. Ketiganya fondasi kecil di `lib/core`/`lib/app` dengan unit/widget test.
2. M1: go_router minimal dengan redirect sesi. Halaman fitur M06–M12 dan perilaku API-nya tidak diubah.
3. M3, M4, M5: aset brand resmi, font terbundel, lokalisasi + uji text scaling.
4. CI: input flavor, format ditegakkan, `flutter analyze` + `flutter test` + APK debug dev dan staging (artifact + sha256). Build hanya di CI, bukan Gradle lokal (ADR19).
5. Live (H1): emulator Pixel_8a dan, bila tersedia, HP fisik ke `https://takeda-staging.morriz.tech` setelah gate M04. Bukti: screenshot dan request_id, tanpa credential atau data warga.

Larangan selama M05: jangan mengubah alur bisnis M06–M12 (registrasi, iuran, laporan, keuangan). Jangan menambah fake data ke build non-test. Jangan memasukkan secret ke `--dart-define`. Jangan menjalankan tes/factory terhadap Neon.

## 5. Bukti yang dibutuhkan untuk menyatakan gate M05

- CI Mobile verification pada commit M05: analyze bersih, test (termasuk tes baru H2/H3/M1/M2/M5) lulus, artifact APK dev+staging dengan sha256.
- Emulator/HP: APK staging → health OK → login → `/auth/me` → satu data warga sintetis → logout. Tambah skenario offline/timeout yang tidak menghapus sesi.
- Pemeriksaan APK: tidak ada DSN/secret (string scan artifact), cleartext ditolak di staging/prod, label flavor benar.
- Text scaling 1.3/2.0 tanpa overflow pada login dan shell.

## 6. Pemeriksaan yang dijalankan untuk dokumen ini

| Perintah | Hasil |
| --- | --- |
| `git diff --stat 00cd078 HEAD -- mobile .github/workflows/mobile.yml` | kosong (mobile tidak berubah sejak merge PR #12) |
| `gh run list --workflow mobile.yml --limit 6` (baca) | 6 run terakhir sukses; terbaru 38099378306 (11 Okt, dispatch staging URL) |
| `git check-ignore -v` local.properties / GeneratedPluginRegistrant / .flutter-plugins-dependencies | ketiganya di-ignore |
| Grep `health()`, `isProduction`, `textScale`, `locale`, `Navigator.push`, `GoogleFonts` | dasar temuan di atas |
| Melihat `mipmap-xxxhdpi/ic_launcher.png` | logo Flutter bawaan |
| `node infra/scripts/check-repo.mjs` (setelah dokumen ini ditulis) | OK: 484 berkas dilacak, tanpa secret/tautan rusak |

Tidak dijalankan: `flutter analyze`, `flutter test`, `flutter build`, Gradle, emulator, HP. Alasannya: instruksi audit sumber saja dan hemat SSD.
