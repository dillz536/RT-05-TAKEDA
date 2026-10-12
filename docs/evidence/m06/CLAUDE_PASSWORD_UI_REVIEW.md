# M06 — review UI aturan kata sandi (Claude, read-only)

Tanggal: 12 Oktober 2026. Task `task_b15ce2e10cb9`, dispatch `ctx_d24bbea2f25f`.

**Sumber yang direview:** `acd196195a8b966917955edbb9d2e91e92de8b57` (HEAD), dibandingkan dengan `15885cb`. Pohon kerja `mobile/` identik dengan `acd1961`.

Review hanya membaca kode. Tidak ada perubahan di `mobile/`, backend, atau Git, dan tes besar tidak diulang. Angka 116 tes, analyze bersih, dan temuan emulator berasal dari root/Grok.

| Berkas (blob `acd1961`) | SHA-256 |
| --- | --- |
| `mobile/lib/features/auth/account_access.dart` | `d6b95089558cf9919555c924ead4c895d89ad658bbb8de56b114a920d50ec48d` |
| `mobile/lib/features/auth/auth_pages.dart` | `4bcb6c905a474850bd77f6f3701ed17a5d37e8f2c463e667a76450a06ec5ee11` |
| `mobile/lib/features/warga/warga_pages.dart` | `d3e992cec5a5abeaebabaa0e85d9d0fbaab4c4fc6987e954688d6d6679cea550` |
| `mobile/test/auth_access_test.dart` | `062c1cb35e1e70842e0ef816269bf124e72ac08c2d22b3fa83c37f427ce72902` |

## Verdict: PASS (tanpa blocker)

**Helper** (`account_access.dart`). `passwordRuleDecoration` memakai:

- label `Text(maxLines: 3)`;
- `helperText` = `passwordRuleHelper` dengan `helperMaxLines: 8`;
- `errorMaxLines: 16`.

`passwordRuleHelper` sama persis dengan pesan lemah `validateNewPassword` ("Minimal 8 karakter, berisi huruf dan angka."). Pesan 72 byte dan NUL tidak diubah, sehingga paritas dengan `PasswordPolicy` server tetap terjaga.

**Tiga jalur sudah memakai helper:**

| Jalur | Berkas | Label |
| --- | --- | --- |
| Daftar (utama dan tambahan) | `auth_pages.dart` `_field(... passwordRule: true)` | "Kata sandi" |
| Reset | `auth_pages.dart` | "Kata sandi baru" |
| Ganti | `warga_pages.dart` | "Kata sandi baru" |

Error server (`_err('password')`) dan error validator sama-sama memakai `errorMaxLines` 16. Pada jalur daftar, `counterText: ''` tidak ikut, tetapi kolom sandi tidak memakai `maxLength`, jadi counter tidak muncul.

**Tes** (dua tes baru, lebar 320, skala teks 2.0: reset dan ganti sandi):

- `expectTextFullyVisible` memeriksa `RenderParagraph`: teks utuh dan `didExceedMaxLines == false`. Ini indikator yang tepat untuk ellipsis atau pemotongan baris.
- Setiap tes memeriksa label, helper, error lemah, dan error 72 byte, ditambah `takeException() == null`.
- Kasus error lemah sah walaupun teksnya sama dengan helper. InputDecorator menampilkan error menggantikan helper, sehingga `findsOneWidget` mengenai teks error.

## Catatan (Info, di luar delta, tidak diverifikasi)

- Jalur daftar tidak punya tes skala 2.0 sendiri. Jalur itu memakai helper yang sama, jadi risikonya rendah.
- Kolom "Ulangi kata sandi (baru)" (`auth_pages.dart:710`, `warga_pages.dart:1397`, dan `_field password2` di daftar) serta "Kata sandi saat ini" masih memakai `InputDecoration` biasa. `errorMaxLines` default (1 baris) dapat memotong error panjang pada skala 2.0, misalnya "Pengulangan kata sandi tidak sama." atau pesan server `current_password`. Ini tidak diuji di sini dan sebaiknya ikut diperiksa di emulator final root.

## PostgreSQL portabel sesi Claude

**Already stopped.** Instance scratchpad sesi (`…\scratchpad\pg\data`, `127.0.0.1:54329`) dicek dengan `pg_ctl status`: "no server running". Tidak ada `postmaster.pid`, dan `netstat` tidak menunjukkan listener `:54329`. Log terakhir instance itu: `database system is shut down` pukul 16:14:11 +07, dari `pg_ctl stop -m fast` pada tugas sebelumnya. Tidak ada service lain, dataset, atau Neon yang disentuh.

## Batas

Read-only; tidak menjalankan Flutter, emulator, atau PHPUnit. CI dan emulator final menjadi tanggung jawab root secara terpisah.
