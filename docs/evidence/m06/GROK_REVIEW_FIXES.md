# M06 — koreksi review Flutter (Grok)

Tanggal: 12 Oktober 2026. Task `task_3b14402de68e`, dispatch `ctx_f070274d6534`.

**Sumber:** `8d0b3df8f73860b66277fed1494f196d47525d4a`. Acuan temuan: [CLAUDE_MOBILE_REVIEW.md](CLAUDE_MOBILE_REVIEW.md).

Perubahan hanya di `mobile/` dan berkas ini. Backend, dokumen bersama, Git, APK, dan unduhan perkakas tidak disentuh.

## Ringkasan

| # | Tindakan |
| --- | --- |
| F1 | Diperbaiki. Bearer dan epoch dipasang saat `post` dibuat; interceptor tidak menimpa nilai itu. |
| F2 | Diperbaiki. Daftar utama dan tambahan menolak kembali selama kirim, dan tidak memanggil masuk setelah lepas dari pohon. |
| F3 | Diperbaiki. Judul dan subjudul permohonan ditolak mengikuti status serta alasan. |
| F4 | Dibiarkan. Logout 401 pada sesi yang masih aktif tetap menandai sesi berakhir. |
| F5 | Info. Tombol salin kode menyalin isi kolom yang diketik pengguna. |
| F6 | Info. Cabang akun nonaktif/diarsipkan di refresh halaman tunggu tidak terjangkau. |

## F1 — bearer logout lama

`Api.post` menerima `bearer` dan `sessionEpoch` opsional dan memasangnya pada `Options` sebelum Dio menjadwalkan interceptor. `onRequest` memakai `putIfAbsent`, jadi header dan epoch yang sudah ada tidak diganti token sesi yang lebih baru. `revokeServerSession` mengirim token dan epoch yang dicatat sebelum request.

Regresi di `mobile/test/logout_pin_test.dart` mengganti sesi setelah `revokeServerSession` dipanggil dan sebelum `onRequest` (antrean timer Dio) berjalan. Tiga kasus:

- `POST /auth/logout` membawa `Bearer` token lama; sesi lokal, penyimpanan, dan cache `duesMyProvider` tetap pada sesi baru.
- `POST /auth/logout-all` sama, dengan badan `password`, jadi jalur ini tidak menggandakan logout biasa.
- Token string yang sama dengan epoch lebih baru: request menyimpan epoch lama. Respons 401 tidak menghapus sesi baru dan tidak mengosongkan cache.

Perilaku M05 yang sudah ada tetap: `signOutIfCurrent` dan `expire` mengabaikan hasil lama bila epoch atau token berbeda. `PATCH /auth/password` tidak dipatok dengan cara yang sama; ganti sandi hanya mempertahankan bearer request itu.

## F2 — kembali saat daftar

`RegisterPage` (utama dan tambahan) memakai `PopScope` dengan `canPop` mati selama `_busy`, dan tombol kembali AppBar dimatikan pada saat yang sama. `if (!mounted) return` sebelum `signIn` tetap ada, jadi respons yang datang setelah halaman lepas tidak masuk.

Tes: selama respons sukses tertunda, kembali sistem tidak meninggalkan halaman dan token belum tersimpan; setelah respons, bearer daftar tersimpan (beranda untuk akun utama, halaman tunggu untuk akun tambahan). Setelah respons 422 selesai dan halaman masih terbuka, tombol kembali bekerja dan kembali ke masuk. Tes daftar utama yang sebelumnya mengharapkan token dibuang bila kembali saat request berjalan kini mengharapkan sesi itu tersimpan.

## F3 — salinan permohonan ditolak

Bila `permohonan.status` adalah `ditolak`, judul menjadi "Permohonan ditolak" dan subjudul memuat alasan yang sama dengan kartu (`Alasan: …`, atau `-` bila catatan kosong). Status `menunggu` tetap "Menunggu persetujuan" dan kalimat sedang ditinjau. Tes router untuk akun menunggu tidak diubah.

## F4, F5, F6

F4: `expire` pada 401 request yang epoch-nya masih sesi aktif menandai `expired`, sehingga layar masuk menampilkan "Sesi berakhir. Silakan masuk kembali." Hasil lokalnya benar. Tidak diubah.

F5: "Salin kode" di lupa sandi menyalin kolom kode yang sedang diisi. Tidak diubah.

F6: cabang `nonaktif`/`diarsipkan` di `PendingPage._refresh` tidak tercapai karena `GET /auth/me` menjawab 403 lebih dulu dan token lokal tetap, sesuai M05. Tidak diubah.

## Perintah

Dari `mobile/`, Flutter 3.47.7 (`C:\Users\USER\dev\flutter`):

- `dart format` pada berkas yang diubah.
- `flutter analyze`: No issues found.
- `flutter test`: 114 lulus (107 tes yang sudah ada, ditambah 3 regresi F1 dan 4 tes kembali F2).

Dari akar repositori, setelah byte akhir berkas ini:

`OK: 636 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.`

## Batas

Tidak ada emulator, APK, Neon, atau deployment. Integrasi perangkat nyata tidak dibuktikan di sini.
