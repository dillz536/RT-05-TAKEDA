# M05 — fondasi mobile (Claude)

Tanggal: 11 Oktober 2026. Branch bersama `codex/m05-mobile-foundation` (dari 3a8be79, setelah PR #16). Owner: Claude, untuk `mobile/**` dan file ini. Claude tidak melakukan commit, push, perubahan VPS, build Gradle/APK lokal, atau pemakaian emulator. Tidak ada akses ke Neon atau secret.

**Status: implementasi dan tes lokal selesai; gate M05 belum lulus.** Gate masih menunggu dua hal yang dijalankan root: run CI pada commit integrasi (format, analyze, test, APK debug dev dan staging) dan health request nyata dari emulator/HP ke staging. `docs/evidence/m05/READINESS_REVIEW.md` bersifat historis. H1 dalam dokumen itu (login sebagai prasyarat) terlalu luas; untuk M05 cukup health request publik.

## Ringkasan per butir dispatch

### 1. Sesi (`lib/core/session.dart`)

- `TokenStorage` (implementasi aplikasi: `SecureTokenStorage` = flutter_secure_storage) dan `fetchMeProvider` menjadi seam injeksi. Tes mengganti keduanya; kode aplikasi tidak memuat implementasi palsu.
- `restore()` menghapus token **hanya** bila `/auth/me` menjawab 401. Offline, timeout, 5xx, 403, dan error lain mempertahankan token lalu mengisi `SessionState.unavailable`. Pada status ini akun bernilai null, sehingga data akun tidak pernah ditampilkan sebagai sah.
- Splash menampilkan pesan, tombol "Coba lagi", dan "Keluar dari perangkat ini" (hapus token lokal).
- Hasil async yang basi tidak dapat menimpa sesi terbaru:
  - `_epoch` naik pada restore, signIn, signOut, dan expire. Hasil restore lama, termasuk 401 lama, diabaikan.
  - Operasi storage diserialkan, sehingga `delete` milik logout tidak bisa mendahului `write` login baru.
  - Interceptor Api hanya meng-`expire` bila token request yang mendapat 401 sama dengan token sesi aktif (`expire(token:)`).
- `ApiException.from`: 5xx tanpa envelope (gateway/proxy) menjadi `SERVICE_UNAVAILABLE`, bukan `UNKNOWN`.
- Logout tetap mengikuti perilaku M06 yang ada. Akun memanggil `/auth/logout` lalu signOut; Pending hanya signOut lokal (L6 di readiness, tidak diubah).

### 2. Konfigurasi (`lib/app/config.dart`, `lib/app/startup.dart`, `lib/main.dart`)

- `AppConfig.parse(flavor, apiBaseUrl)` memvalidasi aturan berikut:
  - Flavor harus persis `dev`, `staging`, atau `prod`. Nilai lain, kosong, atau beda huruf ditolak.
  - URL harus absolut dengan host, origin root, tanpa userinfo, query, fragment, path, atau spasi.
  - staging/prod wajib `https`. dev boleh `https` ke host mana pun, tetapi `http` hanya ke `10.0.2.2`/`127.0.0.1`, sama dengan network security config debug.
- Pesan error tidak menyalin nilai URL, sehingga kredensial yang keliru dimasukkan tidak ikut tampil.
- `main()`: config tidak valid menjalankan `ConfigErrorApp` (layar aman, tanpa ProviderScope maupun request jaringan). Config valid di-override ke `appConfigProvider`.
- Badge `Banner` DEV (merah) atau STAGING (kuning/navy) di sudut kiri atas; prod tanpa badge.
- `AppConfig.version` (1.0.0, tidak sinkron dengan pubspec 0.1.0+1) dihapus karena tidak dipakai. Versi sekarang satu sumber: `pubspec.yaml` → versionName/versionCode Android.
- Default tanpa dart-define tetap dev + `http://10.0.2.2:8077`.
- Kontrak ini dikirim ke coordinator untuk Grok (msg_bb9e03be4090). `infra/scripts/check-mobile-build-config.mjs` milik Grok yang sekarang ada di working tree memakai aturan yang sama: daftar flavor, host HTTP dev, dan origin root.

### 3. Health, bootstrap, router (`lib/core/health.dart`, `lib/app/router.dart`, `lib/app/shell.dart`)

- `HealthClient.live()`:
  - `GET {API_BASE_URL}/health/live` tanpa Authorization, dengan connect/send/receive timeout 8 detik dan batas total 16 detik untuk server yang menggantung.
  - Lulus hanya bila HTTP 200 dan body JSON `{"status":"ok"}`.
  - Kegagalan dipetakan ke `timeout`, `offline`, `server` (non-200), atau `malformed`.
  - `request_id` diambil dari header `X-Request-Id` hanya bila berbentuk UUID; nilai lain dibuang.
- `HealthController` dijalankan paralel dengan `restore()` oleh `bootstrap()` saat start dan pada "Coba lagi". Hasil lama diabaikan (`_run`). Nonproduction mencetak `RT05 health ok request_id=…` ke log (logcat).
- `ServerInfo` (nonproduction saja) di login dan splash menampilkan flavor, host, dan `request_id` health terakhir. Root dapat mencocokkannya dengan log API staging sebagai bukti emulator.
- go_router 18.0.2, rute tingkat atas `/splash`, `/login`, `/register` (`?jenis=tambahan`), `/forgot`, `/pending`, `/home`, `/notifications`. Guard murni `sessionRedirect()`:
  - Health belum OK, sesi sedang dipulihkan, atau sesi tidak tersedia → splash.
  - Tamu → hanya rute tamu.
  - Akun pending → hanya `/pending`.
  - Akun aktif → home/notifikasi; rute tamu, pending, atau asing → `/home`.
- `refreshListenable` dipicu oleh perubahan session/health.
- Halaman detail fitur M06+ tetap `Navigator.push`. `HomeShell` dipindah apa adanya dari `main.dart` ke `lib/app/shell.dart`; perbedaannya:
  - Notifikasi dibuka lewat `context.push('/notifications')`.
  - Shell tidak membangun tab bila sesi tidak sah. Ini **bug yang ditemukan tes**: saat 401, go_router berpindah satu frame lebih lambat sehingga `akun!` di WargaHomePage melempar null-check.
- Login memakai `context.push` ke `/register` dan `/forgot`. Setelah daftar, `popUntil` manual dihapus karena redirect sesi yang membawa akun ke `/home` atau `/pending`.
- Aturan bisnis M06+ (login, registrasi, iuran, laporan, keuangan, endpoint, payload) tidak berubah.

### 4. Brand

| Bagian | Isi |
| --- | --- |
| Logo in-app | `mobile/assets/brand/rt05-mark.svg` dan `rt05-mark-terang.svg` disalin byte-identik dari `website/public/images/logo/` (dicek `cmp` dan oleh tes). `GapuraMark` sekarang `SvgPicture.asset` (flutter_svg 2.3.0); CustomPaint gambar tangan dihapus. `light: true` memakai varian terang |
| Ikon launcher | `mobile/tool/launcher_icon.py` mengonversi setiap elemen SVG resmi (rect/rx, path garis lurus, circle, clipPath, stroke linejoin round) dengan koordinat dan warna yang sama. Hasilnya `drawable/ic_launcher_foreground.xml` (VectorDrawable, adaptive icon API 26+, latar putih seperti header website, logo diskalakan 0,875 ke zona aman 66dp) dan PNG legacy `mipmap-*/ic_launcher.png` (API 24–25, Pillow 12.3.0, supersampling 8×). Dua kali jalan memberi sha256 identik. `aapt2 compile` (build-tools 36.1.0) menerima keempat resource. Logo Flutter bawaan sudah diganti; tidak ada logo baru yang digambar |
| Font | TTF statis resmi di `mobile/assets/fonts/` + lisensi OFL. Tidak ada `google_fonts` dan tidak ada unduhan font saat runtime (dicek tes). Detail sumber di tabel berikut |
| Token warna | `Brand` disamakan dengan `:root` di `website/resources/css/app.css`: ink `#183b50`, muted `#536a79`, line `#d8e3e9`, plus `skyDeep #d9eaf6`. navy/yellow/sky sudah sama sebelumnya. Tes membaca CSS dan mencocokkan setiap token |
| Lokalisasi | `flutter_localizations`, locale tunggal `id_ID`, `GlobalMaterialLocalizations.delegates`. Date/time picker admin kini berbahasa Indonesia ("Batal", "Pilih tanggal") |

Sumber dan sha256 font:

| Berkas | Sumber | sha256 |
| --- | --- | --- |
| PublicSans-Regular/Medium/SemiBold/Bold/ExtraBold.ttf + `OFL-PublicSans.txt` | github.com/uswds/public-sans tag v2.001 (commit c7923167a592d941646f99fb7b5fba17aa7d69e1), `fonts/ttf/` | Regular b577e9bc…88379, Bold bddd96a0…63a6 |
| BricolageGrotesque-SemiBold/Bold/ExtraBold.ttf + `OFL-BricolageGrotesque.txt` | github.com/ateliertriay/bricolage commit 84745e5b96261ae5f8c6c856e262fe78d1d6efdd (commit upstream yang tercatat di METADATA.pb google/fonts), `fonts/ttf/` | Bold f83cb3f1…689b |

Ukuran berkas cocok dengan GitHub contents API, dan setiap berkas diawali magic TrueType `00010000`. Bobot yang dibundel mengikuti bobot yang dipakai kode: body 400–800, display 600–800.

### 5. Tes dan perubahan layout untuk skala teks

Total **75 tes lulus**: 13 lama (tidak diubah selain format) dan 62 baru.

| Berkas | Jumlah | Isi |
| --- | --- | --- |
| `test/app_test.dart` | 13 | Tes lama |
| `test/config_test.dart` | 7 | HTTPS/flavor/URL, termasuk kredensial yang tidak tersalin ke pesan, dan default dev |
| `test/health_test.dart` | 8 | Request nyata lewat Dio tanpa token dengan timeout terpasang; request_id UUID atau dibuang; malformed (status salah/array/HTML); 503/500/404; offline; timeout Dio; server menggantung dibatasi waktu total; gagal lalu coba lagi berhasil |
| `test/session_test.dart` | 14 | 401 menghapus token; offline/timeout/503/403 mempertahankan token tanpa akun; tanpa token tidak memanggil `/auth/me`; coba lagi; restore basi vs logout; 401 basi vs login baru; logout lalu login cepat; interceptor 401 token aktif vs token lama; 503 HTML → `SERVICE_UNAVAILABLE` tanpa logout |
| `test/router_test.dart` | 14 | 6 tabel guard + 8 alur aplikasi utuh (router, bootstrap, Api, dan storage memori dengan transport HTTP palsu): tamu → login + request_id; pending; shell warga; shell pengurus; rute notifikasi; 401 saat dipakai → login + banner dan token dihapus; offline → splash, coba lagi → beranda dengan token tetap; `/auth/me` 503 → tanpa data akun, Keluar menghapus token |
| `test/brand_test.dart` | 11 | SVG byte-identik (disk + bundle) ×2; GapuraMark memakai aset resmi; ikon launcher memuat semua warna SVG + adaptive + 5 PNG; FontManifest + TTF termuat + OFL; tema/font/token CSS; tanpa google_fonts; badge STAGING + lokalisasi id; badge DEV; prod tanpa badge/diagnostik; layar config tidak valid |
| `test/text_scale_test.dart` | 8 | Login pada skala 1,3/2,0 × lebar 360/320; shell warga dan pengurus pada skala 1,3/2,0 lebar 360, mengetuk kelima tab (overflow = exception tes) |

Tes skala teks menemukan overflow nyata. Perbaikannya hanya layout, tanpa perubahan data/aturan:

| Lokasi | Perubahan |
| --- | --- |
| `pengurus_pages.dart` ringkasan admin | GridView rasio tetap 1,7 diganti dua baris `_TileRow` yang tingginya mengikuti isi |
| `pengurus_pages.dart` daftar rumah | Chip status verifikasi dipindah dari trailing ke bawah subjudul (trailing memakan seluruh lebar tile pada skala 2,0) |
| `warga_pages.dart` `_AgendaTile` | Badge tanggal di leading memakai `FittedBox(scaleDown)` karena ListTile membatasi tinggi leading 56 |
| `core/widgets.dart` `StatusChip` | Ikon + teks dalam `Wrap`, dan skala teks label dibatasi 1,5×. Status tetap terbaca lewat warna, ikon, dan teks; isi lain tetap mengikuti skala sistem |
| `app/shell.dart` | Label NavigationBar dibatasi 1,3× agar lima tab muat; judul AppBar `Flexible` + ellipsis |
| `auth_pages.dart` header | Kolom judul `Expanded` |

Font tes Flutter (glyph persegi) lebih lebar daripada Public Sans, sehingga hasil ini konservatif. Bukti visual di HP/emulator tetap perlu dilihat root.

## Paket (diverifikasi dari sumber primer)

| Paket | Versi | Dasar |
| --- | --- | --- |
| go_router | ^18.0.2 → 18.0.2 | pub.dev API: latest 18.0.2 (28 Sep 2026), butuh Flutter ≥3.44/Dart ^3.12. 18.0.0 bermigrasi ke material_ui/cupertino_ui, yang sudah ada di lockfile sebagai transitive SDK. API `GoRouter(redirect, refreshListenable)`, `GoRouterState.uri` diperiksa di source pub cache |
| flutter_svg | ^2.3.0 → 2.3.0 | pub.dev API: latest 2.3.0, Flutter ≥3.35. `SvgPicture.asset(..., semanticsLabel)` dan `SvgAssetLoader` diperiksa di source |
| flutter_localizations | SDK | Kini dependency langsung; intl ^0.20.3 cocok dengan pin SDK |
| google_fonts 9.0.0 | dihapus | Diganti font terbundel |

Diff `pubspec.lock`: tambah flutter_svg, go_router, path_parsing, petitparser, vector_graphics, vector_graphics_codec, vector_graphics_compiler, xml; hapus google_fonts; flutter_localizations menjadi direct. Tidak ada paket lain yang di-upgrade.

## Perintah yang dijalankan (lokal Windows, Flutter 3.47.7 / Dart 3.13.5, cwd `mobile/`)

| Perintah | Hasil |
| --- | --- |
| `flutter pub get` | Changed 10 dependencies (lihat atas) |
| `dart format lib test` (sekali, `formatter.page_width: 160` di `analysis_options.yaml`) | 21 berkas diformat. `dart format --output=none --set-exit-if-changed lib test` → 0 changed |
| `flutter analyze` | No issues found |
| `flutter test` | 75 lulus, 0 gagal (reporter JSON: app 13, brand 11, config 7, health 8, router 14, session 14, text_scale 8) |
| `python tool/launcher_icon.py` ×2 + sha256 | Deterministik (5 PNG + XML identik) |
| `aapt2 compile` foreground/adaptive/color/PNG | OK |
| `node infra/scripts/check-repo.mjs` (root) | OK, 519 berkas dilacak |
| `git diff --check` | Bersih |

Format pertama kali mengubah tata letak baris di berkas lama (sebelumnya tidak pernah diformat; CI lama `|| true`). Lebar 160 dipilih karena paling dekat dengan gaya yang ada; perbedaannya diuji:

| Lebar | Baris berubah di lib |
| --- | --- |
| 80 | 5.313 |
| 120 | 4.111 |
| 160 | 3.635 |

Perbandingan tanpa whitespace dan koma akhir antara HEAD dan hasil kerja: `providers.dart`, `format.dart`, `models.dart`, dan `app_test.dart` hanya berubah format. `warga_pages.dart`, `pengurus_pages.dart`, dan `auth_pages.dart` hanya berisi perubahan layout/router di atas, ditambah kurung kurawal untuk lint `curly_braces_in_flow_control_structures` yang muncul setelah format memecah baris.

Sengaja **tidak** dijalankan: Gradle, `flutter build apk` (ADR19, CI yang membangun APK), emulator/HP, serta request ke staging atau Neon.

## Berkas

Baru:

- `mobile/lib/app/{router,shell,startup}.dart`, `mobile/lib/core/health.dart`
- `mobile/assets/brand/rt05-mark{,-terang}.svg`, `mobile/assets/fonts/` (8 TTF + 2 OFL)
- `mobile/tool/launcher_icon.py`
- `mobile/android/app/src/main/res/{drawable/ic_launcher_foreground.xml, mipmap-anydpi-v26/ic_launcher.xml, values/ic_launcher_background.xml}`
- `mobile/test/{config,health,session,router,brand,text_scale}_test.dart`, `mobile/test/support/{fakes,app_harness}.dart`

Diubah:

- `mobile/pubspec.yaml`, `mobile/pubspec.lock`, `mobile/analysis_options.yaml`, `mobile/README.md`
- `mobile/lib/main.dart`, `mobile/lib/app/{config,theme}.dart`, `mobile/lib/core/{api,session,widgets}.dart`
- `mobile/lib/features/auth/auth_pages.dart`, `mobile/lib/features/pengurus/pengurus_pages.dart`, `mobile/lib/features/warga/warga_pages.dart` (layout/router)
- `mobile/lib/core/{format,models}.dart`, `mobile/lib/features/warga/providers.dart`, `mobile/test/app_test.dart` (format saja)
- `mobile/android/app/src/main/res/mipmap-*/ic_launcher.png` (5)
- `docs/evidence/m05/CLAUDE_FOUNDATION_RESULT.md` (berkas ini)

Tidak disentuh: `.github/workflows/mobile.yml`, `infra/scripts/check-mobile-build-config.mjs`, docs bersama, `website/`, `rest-api/`, Git.

## Sisa gate dan keterbatasan

- **Root:** run CI Mobile verification pada commit integrasi (format ketat, analyze, test, APK debug dev+staging + sha256), lalu APK staging di emulator/HP. Bukti health nyata: `ServerInfo` menampilkan `staging • takeda-staging.morriz.tech • health ok • <request_id>`; logcat `RT05 health ok request_id=…`; cocokkan `request_id` dengan log API staging. Skenario mode pesawat → splash "Coba lagi" tanpa logout. Screenshot launcher icon, badge STAGING, dan text scaling sistem 1,3/2,0. String scan APK tanpa DSN/secret.
- Login/`/auth/me` staging tetap bukan prasyarat M05 dan belum dibuktikan. Gate M06+ tidak diklaim.
- Belum ditangani (di luar dispatch): `android:allowBackup` default (L2), signing rilis (M15), logout server dari halaman pending (L6, M06), dan ikon monokrom Android 13 (tidak dibuat agar tidak menggambar ulang logo).
- Tes skala teks hanya mencakup tab utama shell, bukan setiap sub-tab atau halaman detail. Chip status dan label navigasi sengaja dibatasi skalanya (1,5× dan 1,3×).
- Handoff: kepemilikan berkas di atas diserahkan ke Codex untuk integrasi dan commit.

## Integrasi setelah handoff — Codex

Laporan Claude di atas adalah hasil 75 tes sebelum integrasi. Codex menyamakan
validasi Dart/guard CI untuk port1..65535, bentuk origin mentah, whitespace/control,
backslash, empty userinfo/query/fragment dan path normalisasi; dua tes config
ditambah. Manifest `allowBackup=false` kini diterapkan, sehingga catatan L2 di
atas tidak lagi berarti flag masih default. Workflow menjalankan self-test guard
di job plan memakai Node22. Guard dev juga menolak alias numerik localhost.
Validasi Codex lokal: analyzer No issues found, 77/77 tes lulus, format config
0 changed, self-test guard dan check-repo lulus. CI/APK/emulator masih pending
pada checkpoint ini; signing dan pending-page server logout tetap di luar M05.
