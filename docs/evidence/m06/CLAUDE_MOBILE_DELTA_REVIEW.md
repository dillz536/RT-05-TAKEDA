# M06 — review delta Flutter F1–F3 (Claude, read-only)

Tanggal: 12 Oktober 2026. Task `task_ed3ff09b820a`, dispatch `ctx_bb7a55f211bb`.

**Sumber:** pohon kerja saat dispatch, yaitu `HEAD 8d0b3df` ditambah koreksi Grok. Root kemudian meng-commit koreksi itu sebagai **`15885cbf2d08b3e0f2b66b32899e9e6857ff63d1`**. Keenam berkas `mobile/` di bawah identik byte dengan blob di commit itu (dicek `git show 15885cb:<berkas> | sha256sum`), jadi verdict ini berlaku untuk SHA final tersebut. Acuan temuan: [CLAUDE_MOBILE_REVIEW.md](CLAUDE_MOBILE_REVIEW.md) dan [GROK_REVIEW_FIXES.md](GROK_REVIEW_FIXES.md).

Review hanya membaca kode dan diff terhadap `8d0b3df`. Tidak ada berkas `mobile/` yang diubah. `flutter test`/`analyze`, PHPUnit, dan unduhan perkakas tidak dijalankan pada review ini; angka 114 tes lulus berasal dari laporan Grok.

## Fingerprint berkas yang direview (SHA-256, pohon kerja)

| Berkas | SHA-256 |
| --- | --- |
| `mobile/lib/core/api.dart` | `756879cec032808fcb3c8fd217cee48706ab65d0647da36f73c2eab1954d5df1` |
| `mobile/lib/features/auth/account_access.dart` | `9f982eb53ccda0b36090dad53ca45374a149fceeaf981618907a9ca55c1cbd44` |
| `mobile/lib/features/auth/auth_pages.dart` | `a62476c7a660dd617f3904dec2663cc368a07efc343842089f982cbb796e1914` |
| `mobile/test/logout_pin_test.dart` (baru) | `0b77aa22bd6b36d1aebc2ddc31c3c37ff2b1f1e4584ce40e5004ccf5e63e1040` |
| `mobile/test/auth_access_test.dart` | `92382a6938a2719d4344795d76f854349d4eead01eabc40560fc444a5516f545` |
| `mobile/lib/core/session.dart` (tidak berubah sejak `8d0b3df`) | `fb91d05c832425ba064ba5b5a449e1ab7b64ba94aefe2b30ad23e87b48c8d9cd` |
| `docs/evidence/m06/GROK_REVIEW_FIXES.md` | `cc41eea66248073322e851fb2965685763cd5f13cdcae34c5bf3d3f1d8fcbac7` |

Bila salah satu berkas berubah setelah fingerprint ini, verdict di bawah tidak berlaku untuk versi baru itu.

## Verdict

**F1, F2, dan F3 tertutup. Tidak ada blocker dan tidak ada temuan baru di atas Info.** F4–F6 tetap seperti keputusan Grok (diterima/Info).

### F1 — bearer dan epoch logout lama: tertutup

**`api.dart` `post`** menerima `bearer` dan `sessionEpoch` lalu memasangnya di `Options(headers: …, extra: …)` saat `_dio.post` dipanggil. Ini terjadi sebelum Dio menjadwalkan interceptor lewat `Future(() => InterceptorState…)`.

**`onRequest`** kini memakai `options.headers.putIfAbsent('Authorization', …)` dan `options.extra.putIfAbsent('sessionEpoch', …)`. Header dan epoch yang dipatok pemanggil tidak lagi ditimpa sesi terbaru. Header Dio memakai map case-insensitive, jadi kunci `Authorization` dari `Options` terdeteksi oleh `putIfAbsent`.

**`revokeServerSession`** mencatat `token`/`epoch`, lalu `api.post(path, data:, bearer: token, sessionEpoch: epoch)`. 401 memakai epoch yang dipatok, sehingga `expire` (dengan `epoch != _epoch`) dan `signOutIfCurrent` mengabaikan sesi yang sudah berganti. Ini juga berlaku untuk token string yang sama dengan epoch baru.

**Tes `logout_pin_test.dart`** mencakup tiga kasus: logout, logout-all dengan badan `password`, dan token sama + epoch baru + 401. Sesi diganti setelah `revokeServerSession` dipanggil. Tes menegaskan adapter belum menerima request logout sebelum `signIn` selesai, yang membuktikan urutan "sebelum onRequest", lalu memeriksa:

- header `Bearer <token lama>`;
- `extra.sessionEpoch` = epoch lama;
- sesi, storage, dan cache `duesMyProvider` tetap milik sesi baru, tanpa `delete` dan tanpa `expired`.

Ini setara dengan repro scratch saya (`Bearer token-baru` sebelum perbaikan).

### F2 — kembali saat daftar: tertutup

`_AuthScaffold` dibungkus `PopScope(canPop: !back || allowBack)`. Saat `allowBack` false, tombol AppBar diganti `IconButton` nonaktif (tooltip "Kembali", key `auth-back`). `RegisterPage` (utama dan tambahan) memakai `allowBack: !_busy`, sehingga kembali sistem dan tombol AppBar tertahan selama request. Setelah respons, kembali normal lagi.

`if (!mounted) return` sebelum `_finishAuth` tetap ada. Dengan halaman tidak dapat dilepas selama `_busy`, bearer registrasi yang sukses kini tersimpan.

Tes: utama dan tambahan × respons tertunda.

- Sukses: `canPop` false, `maybePop` diveto, token belum tersimpan; setelah respons, token tersimpan dan halaman beranda/tunggu tampil.
- 422: setelah respons, kembali bekerja ke login.

Tes lama yang mengharapkan token dibuang saat kembali sudah diganti sesuai perilaku baru.

### F3 — salinan permohonan ditolak: tertutup

`PendingPage` menurunkan `rejected` dan `alasan` dari `status.asData?.value`.

- Ditolak: judul "Permohonan ditolak", subjudul "Permohonan akun tambahan ditolak. Alasan: …".
- Menunggu: judul dan subjudul lama tetap.

Kartu tetap memuat chip Ditolak + alasan, dan alasan kosong ditampilkan `-`. Tes memeriksa judul, tidak adanya "sedang ditinjau", dan alasan muncul dua kali (subjudul dan kartu). Paritas backend sesuai: akun `menunggu` dengan `permohonan.status = ditolak` dan `catatan` dari `GET /auth/additional-account-status`.

## Catatan sisa (Info, bukan blocker)

1. **Bearer null.** Bila `revokeServerSession` dipanggil saat `token == null`, `post` tidak mematok header, dan interceptor dapat memasang token sesi yang muncul belakangan. Tombol logout hanya ada di layar bersesi (token tidak null), jadi jalur ini tidak terjangkau dari UI. Bila ingin tertutup penuh, `revokeServerSession` dapat langsung kembali ketika `token == null` sebelum request.
2. **Request lain tidak dipatok.** `get`/`patch`/`put`/`delete`, termasuk `PATCH /auth/password`, tetap membaca token saat `onRequest`. Grok mencatat ini. Halaman milik sesi lama dilepas router saat sesi berganti, sehingga risikonya praktis nol.
3. **Alasan tampil dua kali** (subjudul dan kartu). Ini kosmetik dan disengaja oleh tes. Selama loading/error, judul default "Menunggu persetujuan".
4. **F4** (banner "Sesi berakhir" pada logout 401 sesi aktif), **F5**, dan **F6** tidak diubah. Ini keputusan Grok yang dapat diterima; hasil lokal tetap benar.

## README backend (bagian lain tugas ini)

Baris Token RT di `rest-api/README.md` diperbarui. Teks "increment cache" diganti menjadi reservasi read-modify-write `{hits, reset_at}` di bawah `Cache::lock` pada store limiter bersama (Redis di staging, lock di koneksi queue, tanpa `RateLimiter::increment`), dengan `Retry-After` = sisa jendela. Tidak ada perubahan kode backend atau tes.

## Batas

- Read-only: tidak menjalankan Flutter, PHPUnit, emulator, atau APK. Perilaku runtime bersandar pada pembacaan kode dan tes. Hasil `flutter test` (114) berasal dari laporan Grok, tidak diulang.
- Review terbatas pada `Api.post`/interceptor, `revokeServerSession`, `PopScope` `RegisterPage`, teks `PendingPage`, serta tes `logout_pin_test`/`auth_access_test`.
- SMTP sampai inbox dan M07 tetap di luar lingkup.
