# M06 — review silang Flutter (Claude, read-only)

Tanggal: 12 Oktober 2026. Task `task_36ad989936b2`, dispatch `ctx_d201897c10c2`.

**Sumber yang direview: `8d0b3df8f73860b66277fed1494f196d47525d4a`** (HEAD `codex/m06-account-access`, pohon kerja bersih kecuali `.agents/`). Basis pembanding M05: `fb6e59f`.

Dibaca:

- `CONTRACT.md`, `docs/openapi.yaml` (auth, `NewPassword`), `API.md`, `GROK_FIXES.md`
- `mobile/lib/core/{session,api,widgets}.dart`
- `mobile/lib/features/auth/{account_access,auth_pages}.dart`
- `mobile/lib/features/warga/warga_pages.dart` (keamanan akun, ganti sandi)
- `mobile/lib/app/{router,startup}.dart`, provider data
- `mobile/test/{session_test,auth_access_test}.dart`
- `dio-5.11.1/lib/src/dio_mixin.dart`

Tidak ada berkas `mobile/`, backend, atau dokumen bersama yang diubah. Semua eksekusi berjalan di salinan `git archive 8d0b3df` di scratchpad sesi.

## Ringkasan

| # | Severity | Temuan |
| --- | --- | --- |
| F1 | **Medium (laten)** | Logout lama dapat mencabut token sesi **baru** di server: interceptor memasang token sesi saat `onRequest`, bukan token yang dicatat `revokeServerSession` |
| F2 | Low | Registrasi/tambahan yang sukses setelah pengguna menekan kembali: akun dibuat, bearer dibuang, tanpa umpan balik |
| F3 | Low | Halaman tunggu menampilkan subtitle "sedang ditinjau" juga untuk permohonan yang ditolak |
| F4 | Low | Logout yang dijawab 401 menampilkan banner "Sesi berakhir" |
| F5 | Info | Tombol "Salin kode" menyalin isi kolom yang diketik pengguna sendiri |
| F6 | Info | Cabang `nonaktif`/`diarsipkan` di `PendingPage._refresh` tidak terjangkau |

Tidak ada blocker. F1 layak diperbaiki sebelum penerimaan karena kontrak meminta logout aman terhadap hasil async lama, dan perbaikannya kecil serta sudah diuji (di bawah).

## F1 — Medium: header logout lama bisa memakai token sesi baru

**Berkas:**

- `mobile/lib/core/api.dart:69-71`: `onRequest` selalu menimpa `Authorization` dengan `sessionProvider.token` saat itu dan menyetel `sessionEpoch` saat itu.
- `mobile/lib/features/auth/account_access.dart:79-91`: `revokeServerSession` mencatat token dan epoch, lalu memanggil `api.post(path)` tanpa mematok header.

**Mekanisme:** Dio 5.11.1 menjalankan interceptor request lewat `Future<dynamic>(() => InterceptorState(requestOptions))` (`dio_mixin.dart:529`), yaitu task event-loop, bukan sinkron. Jadi token logout dibaca paling cepat satu putaran event loop setelah `revokeServerSession` mencatat `token`/`epoch`. Bila dalam jendela itu `signIn` baru selesai, request logout lama membawa `Bearer <token-baru>`. Server (`POST /auth/logout`) mencabut token baru tersebut.

Secara lokal, `signOutIfCurrent` benar tidak menghapus sesi baru (epoch berbeda). Namun bearer sesi baru sudah mati di server, sehingga request berikutnya 401 dan sesi baru berakhir. Hasil ini sama dengan yang dilarang kontrak ("old async result signing out new user/session"), hanya terjadi lewat server.

**Repro** (tes scratch `review_claude_test.dart` pada salinan `8d0b3df`; fake storage/adapter yang sama dengan `test/support/fakes.dart`, `revokeServerSession` asli lewat `WidgetRef`):

1. `restore()` dengan `token-lama`.
2. `revokeServerSession(ref, path: '/auth/logout')` dimulai, lalu langsung `signIn('token-baru', …)`.
3. Tunggu keduanya selesai.

Hasil pada `8d0b3df`: `REVIEW: header logout = Bearer token-baru; sesi lokal = token-baru; storage = token-baru`. Asersi "logout lama mencabut token lama" **gagal**.

**Keterjangkauan:** jendela hanya satu putaran event loop setelah request dibuat. Lewat UI saat ini, login baru hampir tidak mungkin selesai di jendela itu, karena layar login baru muncul setelah sesi dibersihkan. Karena itu severity medium-laten, bukan high. Risikonya naik bila kelak ada interceptor antre (`QueuedInterceptor`), refresh, atau logout otomatis.

**Perbaikan yang diusulkan** (sudah dicoba di salinan scratch; untuk Grok/pemilik `mobile/`):

```dart
// api.dart onRequest: jangan timpa header/epoch yang dipatok pemanggil.
if (token != null) options.headers.putIfAbsent('Authorization', () => 'Bearer $token');
options.extra.putIfAbsent('sessionEpoch', () => _ref.read(sessionProvider.notifier).epoch);

// api.dart post: parameter opsional bearer/sessionEpoch.
Future<dynamic> post(String path, {Object? data, String? idempotencyKey, String? bearer, int? sessionEpoch}) => _wrap(
  () => _dio.post(path, data: data, options: Options(
    headers: {'Idempotency-Key': ?idempotencyKey, if (bearer != null) 'Authorization': 'Bearer $bearer'},
    extra: {'sessionEpoch': ?sessionEpoch},
  )),
  (b) => b is Map ? b['data'] : null,
);

// account_access.dart revokeServerSession
await api.post(path, data: data, bearer: token, sessionEpoch: epoch);
```

Setelah patch:

- Repro menghasilkan `header logout = Bearer token-lama; sesi lokal = token-baru`.
- `flutter analyze`: No issues found.
- `flutter test`: 109/109 lulus (107 milik Grok + 2 tes review).

Tes regresi yang disarankan untuk repo adalah tes pertama di bagian Repro. Pola yang sama (patok token yang dicatat) layak dipakai juga untuk `PATCH /auth/password` bila kelak membutuhkan jaminan serupa. Saat ini ganti sandi hanya mempertahankan bearer request itu sendiri, jadi aman.

## F2 — Low: registrasi selesai setelah pengguna kembali

**Berkas:** `auth_pages.dart:296-298` (`_RegisterPageState._submit`: `if (!mounted) return; await _finishAuth(...)`) dan `_AuthScaffold(back: true)`. Tidak ada `PopScope` di `lib/`.

**Repro:** isi registrasi, tekan Daftar, lalu tekan tombol kembali AppBar/sistem sebelum respons tiba. Server membuat akun, rumah, dan bearer (201/202), tetapi halaman sudah unmounted sehingga bearer dibuang tanpa pesan. Bila pengguna mendaftar ulang, server menjawab `HOUSE_ALREADY_REGISTERED`/`REGISTRATION_REJECTED`, yang membingungkan. Login dengan sandi yang sama tetap berhasil.

**Saran:** `PopScope(canPop: !_busy)` di halaman daftar (dan lupa sandi), atau tetap memanggil `signIn` untuk registrasi yang sukses walau halaman sudah lepas. Sesi bersifat global, jadi aman.

## F3 — Low: teks halaman tunggu untuk permohonan ditolak

**Berkas:** `auth_pages.dart:528-530`. Subtitle tetap "Permohonan akun tambahan sedang ditinjau pengurus RT. Akses layanan terbuka setelah disetujui." walau kartu menampilkan chip "Ditolak" dan "Alasan: …".

**Repro:** akun tambahan ditolak dengan alasan, lalu login (backend kini mempertahankan `menunggu` + permohonan `ditolak`). Judul dan subtitle bertentangan dengan isi kartu.

**Saran:** judul/subtitle bergantung pada `permohonan.status`.

Paritas data sudah benar: alasan diambil dari `GET /auth/additional-account-status` `permohonan.catatan`, chip `ditolak`, tanpa akses layanan, router mengarahkan `menunggu` ke `/pending`.

## F4 — Low: logout yang dijawab 401 menampilkan "Sesi berakhir"

Bila token sudah dicabut di server (mis. logout-semua dari perangkat lain), interceptor `onError` memanggil `expire()` sebelum `revokeServerSession` menerima 401. `expire` menyetel `expired: true`, lalu `signOutIfCurrent` tidak melakukan apa-apa karena epoch sudah naik.

Tes scratch: `signedIn=false expired=true storage=null`. Hasil akhirnya benar (token lokal terhapus), hanya pengguna yang baru menekan Keluar melihat banner "Sesi berakhir. Silakan masuk kembali." Bisa diterima, atau dibedakan dengan menandai request logout agar interceptor tidak memanggil `expire`.

## F5, F6 — Info

- F5: `auth_pages.dart:592-598, 673-677`. "Salin kode" menyalin isi kolom kode yang diketik/ditempel pengguna sendiri, sehingga nilainya kecil. Tidak berbahaya.
- F6: `auth_pages.dart:497-500`. Untuk akun `nonaktif`/`diarsipkan`, `GET /auth/me` dijawab 403 oleh `EnsureAkun` sebelum body dikembalikan, jadi cabang pemeriksaan status tidak pernah berjalan. Pesan 403 server tetap tampil dan token lokal dipertahankan sesuai perilaku M05 yang dicatat Grok.

## Diperiksa dan tidak ada temuan

- **Paritas kata sandi** (`account_access.dart:26-33`) dengan `PasswordPolicy` backend:
  - NUL ditolak; batas byte `utf8.encode(v).length > 72`.
  - Minimal 8 `runes` (setara `mb_strlen`/aturan `min` Laravel).
  - Huruf `\p{L}` dan angka `\p{N}` (setara `letters()`/`numbers()`).
  - Pesan byte dan NUL identik dengan server.
  - Dipakai di daftar, tambahan, reset, dan ganti sandi. Login hanya wajib diisi; server menolak > 72 byte secara generik.
- **Request payload** sesuai OpenAPI/kontrak:
  - Registrasi: token string enam digit (digitsOnly, `maxLength` 6), `password_confirmation`, `device_name`, `blok`/`whatsapp` null bila kosong, `anggota` maksimal 15, baris setengah terisi ditolak klien. Batas panjang field sama dengan validasi server.
  - Field error server dipetakan per kunci, termasuk `anggota.N.*`.
- **Pesan lupa sandi:** `forgotQueuedMessage` identik dengan `AuthController::FORGOT_MESSAGE` backend saat ini. Klaim "dikirim/terkirim" diganti.
- **Status:** `additional-register` 202 tetap masuk sesi `menunggu` → `/pending`. Logout-all dengan sandi salah → 422 field ditampilkan, token lokal tetap.
- **Race sesi** (`session.dart`):
  - `signOutIfCurrent` memeriksa epoch dan token di dalam antrean storage, sehingga `signIn` yang mendahului antrean membatalkan hapus.
  - `expire` memakai epoch request, sehingga 401 sesi lama diabaikan, termasuk token string sama.
  - `updateAkun` memakai epoch.
  - Ganti sandi tidak menampilkan sukses atau menutup layar bila epoch/token berganti.
  - Satu-satunya celah adalah F1, pada pemasangan header, bukan pada state lokal.
- **Cache:** semua provider data `FutureProvider.autoDispose` dan dibuang saat router mengalihkan ke login. Tidak ada cache persisten (tidak ada SharedPreferences/Hive/sqflite). Akun tidak dipulihkan dari cache; `restore` selalu `GET /auth/me`.
- **Secure storage:** `SecureTokenStorage` (`flutter_secure_storage`, kunci `rt05_token`) tidak berubah sejak M05.
- **Regresi M05:** diff `session.dart` hanya menambah epoch/`signOutIfCurrent`. `restore` tetap menghapus hanya pada 401; offline/timeout/5xx/403 mempertahankan token. Splash `signOut` tetap tanpa syarat.
- **Offline, mounted, skala teks:** setiap `await` di halaman auth/keamanan diikuti `mounted`. Pesan offline/timeout/5xx tidak mengklaim pencabutan. `BusyButton`/`SectionCard` membungkus label (`Flexible`). Tes skala 1,3 dan 2,0 ada (`auth_access_test.dart:635`) dan lulus.

## Perintah dan hasil

SDK lokal: Flutter 3.47.7 di `C:\Users\USER\dev\flutter`. `pub get --offline` dari cache; tidak ada unduhan alat, tidak ada APK.

| Perintah (salinan scratch `git archive 8d0b3df mobile`) | Hasil |
| --- | --- |
| `flutter analyze` (asli) | No issues found |
| `flutter test` (asli) | **107/107 lulus**. `brand_test` membaca `../website/…`; berkas `website/public/images/logo` dan `website/resources/css` dari `8d0b3df` ikut diekstrak agar setara repo (tanpanya 3 tes brand gagal karena path, bukan kode) |
| `flutter test test/review_claude_test.dart` (asli) | F1 repro **gagal** (`Bearer token-baru`); F4 terkonfirmasi |
| `flutter analyze` + `flutter test` (dengan patch F1) | No issues; **109/109 lulus**; repro F1 lulus (`Bearer token-lama`) |

## Batas

- Review statis + tes Dart/widget di VM. Tidak ada emulator, APK, atau server nyata. F1 dibuktikan dengan adapter palsu Dio yang melewati interceptor asli.
- Patch F1 hanya ada di scratchpad sesi dan tidak diterapkan ke `mobile/`.
- SMTP sampai inbox dan implementasi M07 baru tetap blocked atau di luar lingkup.
