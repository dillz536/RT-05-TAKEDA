# Kontrak v1 M07 — rumah, keluarga, anggota, akun tambahan

Penulis: Claude (backend/database), branch `codex/m07-resident-data`, basis `bc57b4f`.
Status: kontrak dipublikasikan ke root sebelum integrasi mobile, lalu **diimplementasikan
dan diuji lokal** pada Postgres `rt05_test` (lihat [CLAUDE_RESULT.md](CLAUDE_RESULT.md)).
Belum ada deploy/staging M07; gate live menunggu root.

Prinsip: route dan nama field yang sudah ada sejak M06 **tidak diubah**. Semua
penambahan bersifat additive (field baru, route baru). Field JSON Indonesia (ADR17),
path Inggris. Envelope `{data, meta}` / `{error:{code,message,fields?,request_id}}`.

## 1. Inventaris sebelum M07 (main `bc57b4f`)

| Bagian | Kondisi |
| --- | --- |
| Tabel | `rumah`, `pemilik_rumah`, `keluarga(versi)`, `warga(keluarga_id NOT NULL, is_kepala)`, `penghunian(mulai/selesai_tanggal, satu aktif per rumah dan per keluarga)`, `akun(warga_id unique, jenis utama/tambahan)`, `permohonan_akun` |
| Riwayat | Penghunian punya rentang. Keanggotaan keluarga dan tautan akun **tidak** punya histori: hanya pointer `warga.keluarga_id` dan `akun.warga_id` |
| Implemented | `GET/PATCH /household`, `POST/PATCH/DELETE /household/members`, admin houses list/show/create/patch(tagihan)/archive, `families/{id}/verify`, `accounts` list/patch status, permohonan list/approve/reject, token |
| Planned di OpenAPI | `admin/families` GET/POST/PATCH, `admin/residents/{id}`, `admin/users/{id}` (+archive), `admin/occupancies` (+end) |
| Celah | Tanpa histori membership/link; move keluarga/anggota tidak ada; hak edit hanya `akun.jenis`; member mutation tanpa lock/versi; approve tanpa membership/link; archive akun tidak ada; status akun `diarsipkan` dapat diaktifkan ulang lewat PATCH |

## 2. Schema additive (migration `2026_10_13_000100_m07_keanggotaan_tautan_akun`)

`keanggotaan_keluarga` (family_memberships)

| Kolom | Catatan |
| --- | --- |
| id, keluarga_id FK, warga_id FK | RESTRICT, tanpa cascade |
| hubungan_keluarga | snapshot saat bergabung |
| mulai_at timestamptz NULL | NULL = tercatat sebelum M07 (tanggal tidak diketahui; tidak dikarang) |
| selesai_at timestamptz NULL, alasan_selesai | NULL = aktif. alasan: `pindah_keluarga`, `pindah`, `meninggal`, `diarsipkan` |
| sumber | `migrasi`, `registrasi`, `persetujuan`, `akun_utama`, `pengurus` |
| dicatat_oleh_id, diakhiri_oleh_id FK akun NULL | aktor |

Constraint: satu keanggotaan aktif per warga (partial unique `selesai_at IS NULL`);
`selesai_at >= mulai_at`; alasan wajib bila selesai.

`tautan_akun` (account_links): `akun_id, warga_id, keluarga_id, is_utama, mulai_at,
selesai_at, alasan_selesai, sumber, dicatat_oleh_id, diakhiri_oleh_id`.
Constraint: satu tautan aktif per akun; satu tautan aktif per warga; **satu akun
utama aktif per keluarga** (partial unique `keluarga_id WHERE is_utama AND selesai_at IS NULL`).
Karena penghunian aktif tetap satu per rumah dan satu per keluarga, ini sama dengan
satu akun utama aktif per rumah. Rumah tidak disimpan di tautan; rumah diturunkan
dari penghunian aktif keluarga (tidak perlu rotasi tautan ketika keluarga pindah rumah).

`rumah.versi integer default 1` untuk edit pengurus dengan 409.

Backfill (di migration, idempotent, juga `php artisan rt05:residents-backfill`):
setiap `warga` → satu keanggotaan (`mulai_at` NULL; bila `archived_at` terisi,
`selesai_at = archived_at` dan alasan dari status `pindah/meninggal`, lainnya `diarsipkan`).
Warga aktif di keluarga yang sudah diarsipkan → keanggotaan selesai pada `keluarga.archived_at`
(`diarsipkan`). Setiap `akun.warga_id` → paling banyak satu tautan (`mulai_at` NULL). Konservatif:
tautan **aktif** hanya bila akun `aktif`/`nonaktif` tanpa `archived_at`, warga dan keluarga belum
diarsipkan, dan warga punya keanggotaan aktif di keluarga yang sama; `is_utama` hanya untuk akun
`jenis='utama'` paling awal per keluarga. Selain itu tautan langsung ditutup pada tanggal tercatat
paling awal (`akun.archived_at` akun diarsipkan → `akun_diarsipkan`; `warga/keluarga.archived_at`
→ `diarsipkan`). Pointer tidak konsisten tanpa tanggal tercatat (akun `diarsipkan` tanpa
`archived_at`, akun `menunggu` yang sudah menunjuk warga, warga sudah bertaut aktif) **tidak
ditautkan**: akses keluarga 404 sampai pengurus meninjau. Tidak mengubah baris lama.
Down migration hanya menghapus dua tabel baru dan kolom `rumah.versi`.

## 3. Strategi pointer lama (kompatibel image M06)

- `warga.keluarga_id`, `warga.is_kepala`, `akun.warga_id`, `akun.jenis` tetap ada dan
  diperbarui **dalam transaksi yang sama** dengan histori: pointer = keanggotaan/tautan
  terakhir. Image lama yang membaca pointer tetap benar.
- Kode M07 memutuskan akses dari **tautan aktif** (bukan pointer): rumah/keluarga
  akun, hak edit (`is_utama`), penerima pengingat iuran.
- Bila image M06 sempat menulis registrasi setelah migration (rollback aplikasi),
  `rt05:residents-backfill` melengkapi histori tanpa menimpa baris yang ada.

## 4. Akses dan status

| Akun | /household GET | /household mutasi | Layanan warga |
| --- | --- | --- | --- |
| Aktif, tautan utama | 200 | 200/201/204 | ya |
| Aktif, tautan tambahan (disetujui) | 200 (baca saja) | **403 `PRIMARY_ACCOUNT_REQUIRED`** | ya |
| Aktif tanpa tautan aktif | **404 `HOUSEHOLD_NOT_LINKED`** | 404 | ya |
| Menunggu / ditolak | **403 `FORBIDDEN`** (hanya me/status/logout) | 403 | tidak |
| Nonaktif / diarsipkan | 403, bearer dicabut | 403 | tidak |
| Pengurus | 200 untuk keluarga tautannya; admin endpoint untuk semua | | |

Resource keluarga lain (anggota, dll.) → **404** (bukan 403). Versi basi → **409 `VERSION_CONFLICT`**.

Pemeriksaan controller hanya jalur cepat. Keputusan akhir ada di domain (`Links::assertEditor`)
**setelah** baris keluarga dikunci, dibaca segar dari database: akun masih `aktif`, lalu
pengurus, atau tautan aktif `is_utama` pada keluarga yang terkunci. Request yang sudah lolos
controller tetapi menunggu lock ketika hak dicabut: pindah akun utama → 403
`PRIMARY_ACCOUNT_REQUIRED`; akun dinonaktifkan/diarsipkan → 403 `FORBIDDEN`; anggota/tautan
dipindah ke keluarga lain → 409 `CONFLICT`. Operasi khusus pengurus memeriksa ulang peran
pengurus aktif dengan cara yang sama.
Semua pengurus setara; tidak ada field `peran` yang diterima API.

## 5. Endpoint warga (route lama, field tambahan)

| Method/path | Body | Hasil |
| --- | --- | --- |
| GET /household | – | `{id,status,versi,rumah,jenis_hunian,anggota[], dapat_ubah}` (+`dapat_ubah`) |
| PATCH /household | `versi` wajib, `whatsapp?`, `nama_kepala?` | 200 detail / 403 / 409 |
| POST /household/members | `nama, hubungan_keluarga, whatsapp?, versi` | 201 warga + `versi_keluarga` |
| PATCH /household/members/{id} | `nama?, hubungan_keluarga?, whatsapp?, versi` | 200 warga + `versi_keluarga` / 404 lain keluarga |
| DELETE /household/members/{id} | `versi`, `status?` pindah/meninggal (body JSON atau query) | 204 / 422 `MEMBER_PROTECTED` |

`versi` = `keluarga.versi` dan **wajib pada semua edit data keluarga/anggota** (keputusan root
13 Oktober): PATCH /household, tambah/ubah/akhiri anggota (warga dan admin), ganti kepala,
verifikasi, pindah akun utama, dan pindah anggota (versi keluarga asal). Tidak dikirim → 422
`VALIDATION_FAILED` (`fields.versi`), basi → 409 `VERSION_CONFLICT`. Pengecualian yang disengaja
(transisi status, bukan edit field): buat keluarga, arsip keluarga (alasan wajib, final), hunian
mulai/akhiri/pindah, approve permohonan, arsip akun, dan keluarga tujuan pada pindah anggota —
tidak menerima `versi`, diperiksa ulang di bawah row lock (409 bila state berubah) dan tetap
menaikkan `keluarga.versi` sehingga edit dengan versi lama ditolak. Setiap mutasi menaikkan versi; respons anggota memuat `versi_keluarga`
terbaru, GET /household dan detail admin memuat `versi`.

## 6. Endpoint pengurus

Rumah: `GET/POST /admin/houses`, `GET/PATCH /admin/houses/{id}`, `POST /admin/houses/{id}/archive`.
PATCH menerima `blok?, jalan?, nomor?, tagihan_aktif?, mulai_tagih?(YYYY-MM), pemilik?, alasan, versi`
(rumah.versi wajib). Alamat ganda → 409 `HOUSE_EXISTS`. `kode_rumah` tidak diubah.
`pemilik` = `{nama, whatsapp?, catatan?}` atau `null` (lepas tanpa hard-delete) memakai tabel
`pemilik_rumah` existing: pemilik terpisah dari keluarga penghuni/kepala dan `jenis_hunian`;
hanya muncul di `GET /admin/houses/{id}` (`pemilik`), tidak pernah di data warga. Tidak ada
fakta pemilik yang diisi otomatis.
GET detail rumah memuat `penghunian_aktif_id` dan `riwayat_penghunian[].id`; daftar rumah memuat
`keluarga.penghunian_id`; detail keluarga memuat `penghunian[].id` — ID ini target end/move.
Arsip: 409 `ALREADY_ARCHIVED`; mengakhiri penghunian aktif lewat layanan hunian;
tagihan/pembayaran/kas tidak dihapus.

Keluarga:

| Method/path | Body | Hasil |
| --- | --- | --- |
| GET /admin/families | `q?, status?, arsip?` | paginated ringkas |
| POST /admin/families | `nama_kepala, hubungan_kepala?, whatsapp?, anggota[]?, rumah_id?, jenis_hunian?, mulai_tanggal?` | 201 detail; hunian opsional dibuat atomik |
| GET /admin/families/{id} | – | detail + `penghunian[]`, `riwayat_keanggotaan[]`, `akun[]` (tautan aktif/histori) |
| PATCH /admin/families/{id} | `versi` wajib, `kepala_warga_id` | ganti kepala (anggota aktif) |
| POST /admin/families/{id}/verify | `versi` | 200 `{id,status,versi}`; arsip 409 `FAMILY_ARCHIVED` |
| POST /admin/families/{id}/archive | `alasan` | 204; 409 `FAMILY_OCCUPYING` / `FAMILY_HAS_ACCOUNTS` |
| POST /admin/families/{id}/members | `nama, hubungan_keluarga, whatsapp?, versi` | 201 detail anggota |
| POST /admin/families/{id}/primary-account | `akun_id, versi, alasan` | pindah akun utama ke akun tertaut lain di keluarga |

Anggota: `GET/PATCH /admin/residents/{id}` (`nama?, hubungan_keluarga?, whatsapp?, versi`; detail memuat
`keluarga_id`, `versi_keluarga`, `akun`, `riwayat_keanggotaan`),
`POST /admin/residents/{id}/end` (`status pindah|meninggal, alasan, versi`; status di histori
keanggotaan, `alasan` di audit `warga.diarsipkan`; 409 `HEAD_OF_FAMILY`, `RESIDENT_HAS_ACCOUNT`).
PATCH/end admin untuk anggota yang dipindah keluarga secara bersamaan → 409 `CONFLICT` (bukan 404), `POST /admin/residents/{id}/move` (`keluarga_id, hubungan_keluarga, alasan, versi`
= versi keluarga asal;
mengakhiri keanggotaan lama dan memulai yang baru; tautan akun ikut berpindah sebagai tambahan).

Akun (route M06 `/admin/accounts` dipertahankan; `/admin/users/*` planned diganti nama ini):
`GET /admin/accounts`, `GET /admin/accounts/{id}` (detail + riwayat tautan + permohonan),
`PATCH /admin/accounts/{id}` (`status aktif|nonaktif, alasan`; 409 `ACCOUNT_ARCHIVED`),
`POST /admin/accounts/{id}/archive` (`alasan`; mengakhiri tautan, mencabut bearer+sesi;
422 untuk akun sendiri/pengurus; 409 bila permohonan masih diajukan).

Hunian: `POST /admin/occupancies` (`rumah_id, keluarga_id, jenis_hunian pemilik|kontrak, mulai_tanggal?`),
`POST /admin/occupancies/{id}/end` (`selesai_tanggal?, alasan`),
`POST /admin/occupancies/{id}/move` (`rumah_id, jenis_hunian?, tanggal?, alasan`) → akhiri + mulai atomik.
409: `HOUSE_OCCUPIED`, `FAMILY_ALREADY_OCCUPYING`, `OCCUPANCY_ENDED`, `HOUSE_ARCHIVED`, `FAMILY_ARCHIVED`.
422: `DATE_OVERLAP` (tumpang tindih histori rumah/keluarga; akhir = mulai pada hari yang sama boleh),
`DATE_IN_FUTURE`, `SAME_HOUSE`. Respons begin/move 201, end 200: `{id, rumah, keluarga_id, jenis_hunian,
mulai_tanggal, selesai_tanggal, aktif}`.
Tagihan tetap di rumah lama; rumah baru/kosong tidak dibebaskan otomatis; tidak ada pembayaran online.

Permohonan: approve menerima `rumah_id?, catatan?, warga_id?` (tautkan ke anggota existing tanpa
akun, bila tidak dikirim membuat warga baru). Approve membuat keanggotaan + tautan (bukan utama)
dalam satu transaksi. 409 `REQUEST_ALREADY_PROCESSED`, `RESIDENT_HAS_ACCOUNT`; 422 `HOUSE_NOT_OCCUPIED`.

## 7. Concurrency

Urutan lock tetap: permohonan → rumah (id naik) → keluarga (id naik) → tautan → akun. Tautan hanya
diubah sambil memegang lock keluarganya. Arsip akun membaca keluarga tautan tanpa lock, mengunci
keluarga itu, lalu tautan, lalu akun (sama dengan pindah utama/pindah anggota); bila tautan
sudah pindah keluarga di antara keduanya → 409 `CONFLICT` tanpa perubahan, ulangi berhasil.
Deadlock (40P01) atau serialization failure (40001) yang tetap terjadi → 409 `CONFLICT`
(transaksi di-rollback, termasuk transaksi bersarang), bukan 500.
Pemindahan anggota bersamaan yang kalah → 409 `CONFLICT`; akun utama ganda → 409
(`VERSION_CONFLICT`, atau `PRIMARY_ACCOUNT_EXISTS` dari constraint). Constraint
Postgres partial unique menjadi pertahanan terakhir; pelanggaran dipetakan ke 409, bukan 500.
Diuji dengan proses PHP independen (`tests/Support/race-worker.php`), termasuk interleaving
deterministik: worker lolos controller lalu menunggu lock keluarga yang dipegang PHPUnit,
sementara PHPUnit memindah akun utama / mengarsipkan akun / memindah anggota lalu commit.

## 8. Keputusan root (13 Oktober)

1. `versi` wajib pada semua mutasi keluarga/anggota (diterapkan; APK staging diganti untuk M07).
2. `/admin/users/*` rancangan direkonsiliasi ke `/admin/accounts/*` existing di API.md/OpenAPI.
3. Pointer lama tetap dibaca/ditulis untuk kompatibilitas; tanggal lama tidak diketahui tetap NULL.
4. Pemilik rumah dikelola pengurus terpisah dari penghuni/kepala (tabel `pemilik_rumah` existing).
Hunian (begin/end/move), arsip keluarga/akun dan status akun memakai transisi status di bawah
row lock (409 bila state berubah), bukan field `versi`.
