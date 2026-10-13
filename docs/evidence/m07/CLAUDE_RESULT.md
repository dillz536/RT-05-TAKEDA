# M07 backend/database — hasil Claude

Tanggal: 13 Oktober 2026. Branch `codex/m07-resident-data`, basis `bc57b4f` (M06 merged/passed).
Lingkup: `rest-api/`, `docs/API.md`, `docs/openapi.yaml`, `docs/ERD.md`, `docs/DATABASE.md`,
dokumen ini dan [CONTRACT.md](CONTRACT.md). Tidak ada commit/push, deploy, akses Neon,
staging, SMTP, DB/APP_KEY, atau perubahan mobile/website/infra. File `mobile/`, `.agents/`
dan dokumen M06 Kimi yang berubah di working tree bukan milik pekerjaan ini dan tidak disentuh.

**Status: source dan tes lokal selesai; gate live M07 belum.** Root men-deploy SHA yang diuji
lalu menjalankan staging sintetis bersama Claude.

## Preflight

- `git status`: branch `codex/m07-resident-data`; perubahan pengguna `.agents/` dan dokumen
  M06 Kimi dipertahankan.
- PHP 8.4.13. Docker/psql tidak tersedia; Postgres **17.10** sementara (binari
  `@embedded-postgres/windows-x64` sesi M06) di scratchpad sesi ini, `127.0.0.1:54329`,
  database `rt05_test` dengan role `rt05_migrator`/`rt05_app` (salinan cluster tes M06).
- Baseline sebelum perubahan: `php artisan test` **184/184 lulus, 1679 assertion**.

## Yang dibangun

Schema additive `2026_10_13_000100_m07_keanggotaan_tautan_akun`:
`keanggotaan_keluarga`, `tautan_akun` (partial unique aktif per warga/akun/warga-akun, satu
akun utama aktif per keluarga, CHECK rentang/alasan/sumber), `rumah.versi`. Backfill
idempotent `LegacyBackfill` (juga `php artisan rt05:residents-backfill`) — `mulai_at` NULL,
akhir hanya dari `archived_at` tercatat, baris lama tidak diubah. Down hanya menghapus
tambahan M07.

Domain `app/Domain/Residents`: `Links` (histori + pointer lama + lock order + pemetaan
constraint → 409), `Households` (edit kepala, anggota tambah/ubah/akhiri/pindah, buat/ganti
kepala/verifikasi/arsip keluarga, pindah akun utama), `Occupancies` (mulai/akhiri/pindah
atomik, tanpa tumpang tindih, tanpa sentuh tagihan), `Accounts` (status, arsip final).
`Registration::primary/approve` kini menulis keanggotaan + tautan dalam transaksi yang sama;
approve dapat menautkan anggota existing (`warga_id`). Akses keluarga/rumah/hak ubah, pengingat
iuran akun utama, dan `auth/me` membaca **tautan aktif**, bukan pointer.

Endpoint, payload, versi, kode error: [CONTRACT.md](CONTRACT.md) dan `docs/openapi.yaml`
(semua route M07 `implemented`; `/admin/users/*` rancangan direkonsiliasi ke `/admin/accounts/*`).
Keputusan root yang diterapkan: `versi` wajib pada semua mutasi keluarga/anggota dan edit rumah,
`versi_keluarga` di respons anggota, ID hunian pada daftar/detail rumah dan detail keluarga,
pemilik rumah (`pemilik_rumah` existing) dikelola pengurus terpisah dari penghuni/kepala.

## Tes M07 (Postgres `rt05_test` nyata)

| Kelas | Isi | Hasil |
| --- | --- | --- |
| M07HouseholdAccessTest | utama ubah+409 versi basi+422 versi hilang; tambahan disetujui baca saja 403; menunggu/ditolak 403 pada household dan 9 feed; tanpa tautan 404 walau pointer lama ada; hak dari tautan bukan `akun.jenis`; IDOR anggota keluarga lain 404; kepala/anggota berakun dilindungi | 8 lulus |
| M07AdminResidentsTest | warga ditolak admin; pengurus setara; keluarga dibuat+hunian atomik (rollback bila rumah dihuni); ganti kepala; arsip keluarga 409 berurutan; anggota ubah/akhiri/pindah+tautan ikut; pindah akun utama; akun detail/arsip (bearer dicabut, final, pengurus/sendiri/pending ditolak, `peran` diabaikan); approve existing/baru/rumah kosong 422 dengan rollback; hunian mulai/akhiri/pindah, satu tagihan per rumah walau 3 akun, tagihan tetap di rumah lama, rumah kosong tidak dibebaskan, DATE_OVERLAP/FUTURE; edit/arsip rumah (kode tetap, versi, histori+tagihan tetap); pemilik admin-only dan ID hunian | 11 lulus |
| M07ConcurrencyTest | proses PHP independen, barrier advisory lock: 4 hunian satu rumah → 1×201+3×409 HOUSE_OCCUPIED; 2 pindah akun utama versi sama → 1×200+1×409, tepat satu utama; 3 pengurus approve permohonan sama → 1×200+2×409, satu tautan; 3 PATCH household versi sama → 1+2×409; 2 pindah anggota bersamaan → 1×200+1×409 CONFLICT, satu keanggotaan/tautan aktif | 5 lulus (diulang 3× berturut, lulus) |
| M07MigrationTest | backfill dari data bergaya M06 (anggota keluar, akun arsip, pending, tambahan) tanpa tanggal karangan dan tanpa mengubah pointer; idempotent; dua utama lama → hanya terawal; 8 constraint Postgres menolak (utama kedua, tautan/keanggotaan aktif kedua, rentang, alasan, FK restrict, versi) | 3 lulus |
| M07MigrationRollbackTest | data commit → `migrate:rollback --step=1` menghapus hanya tambahan M07 dan data M06 identik → `migrate` membackfill ulang; role runtime tetap DML | 1 lulus |

Uji mutasi (sementara, lalu dikembalikan): hapus cek versi pindah utama → concurrency gagal;
hapus `startLink` pada approve → access gagal; hak ubah dari `akun.jenis` → access gagal;
backfill `mulai_at = created_at` → migration gagal; matikan cek tumpang tindih → occupancy gagal.

Tes lama yang disesuaikan: `IdentityTest` IDOR anggota kini mengirim `versi` (wajib); purge
`CommittedData` mencakup dua tabel baru; `race-worker` menerima bearer; fixture akun tambahan.

## Perintah dan hasil

| Perintah (dari `rest-api/` kecuali disebut) | Hasil |
| --- | --- |
| `php artisan test` baseline | 184/184, 1679 assertion |
| `php artisan test` akhir | **212/212 lulus, 2075 assertion**, ±62 detik |
| `vendor/bin/phpunit --filter "M07ConcurrencyTest\|M07MigrationRollbackTest"` ×3 | 6/6 setiap kali |
| `php vendor/bin/pint --test` | passed |
| `npx.cmd --yes @redocly/cli@2 lint docs/openapi.yaml` (root) | valid, tanpa warning |
| `node infra/scripts/check-repo.mjs` (root) | `OK: 710 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak`; `git diff --check` bersih |

## File

Baru: `database/migrations/2026_10_13_000100_m07_keanggotaan_tautan_akun.php`,
`app/Domain/Residents/{Links,Households,Occupancies,Accounts,LegacyBackfill}.php`,
`app/Models/{KeanggotaanKeluarga,TautanAkun}.php`, `app/Console/Commands/ResidentsBackfill.php`,
`tests/Feature/M07{HouseholdAccess,AdminResidents,Concurrency,Migration,MigrationRollback}Test.php`.
Diubah: `app/Domain/Identity/Registration.php`, `app/Domain/Community/Notifications.php`,
`app/Http/Controllers/{HouseholdController,AuthController,Admin/ResidentsController}.php`,
`app/Http/Responses/Present.php`, `app/Models/{Akun,Keluarga,Warga,Rumah}.php`, `routes/api.php`,
`tests/Support/{CommittedData,Fixtures,race-worker}.php`, `tests/Feature/IdentityTest.php`;
`docs/{API,ERD,DATABASE}.md`, `docs/openapi.yaml`, `docs/evidence/m07/{CONTRACT,CLAUDE_RESULT}.md`.

## Keterbatasan dan untuk root

- Belum ada bukti staging/Neon/Android M07. Deploy migration additive memerlukan job migrasi
  existing (`composer migrate` via `pgsql_migrations`); backfill berjalan di dalam migration.
  Tidak ada seed/factory staging dari pekerjaan ini.
- Rollback aplikasi ke image M06 aman terhadap schema (kolom/tabel additive); bila image M06
  sempat menulis registrasi, jalankan `php artisan rt05:residents-backfill` setelah forward lagi.
- Race di endpoint admin anggota (`PATCH/end`) antara pembacaan keluarga dan lock dapat
  menghasilkan 404 bila anggota baru saja dipindah; pindah anggota sendiri sudah 409.
- Arsip rumah mematikan `tagihan_aktif` (perilaku M06 dipertahankan) tanpa menyentuh tagihan
  lama; rumah yang hanya kosong tidak dibebaskan.
- Tidak ada NIK/KTP, hapus permanen, pembayaran online, atau perubahan peran lewat API.
- CI GitHub belum dijalankan untuk SHA ini (milik root).
- Postgres scratchpad dihentikan setelah tes (RAM PC rendah).

## Review dan perbaikan sebelum rilis (13 Oktober 2026, putaran kedua)

Lingkup sama (`rest-api/`, API/OpenAPI/ERD/DATABASE, CONTRACT, dokumen ini). Tanpa git/commit,
mobile, infra, cloud, Neon, staging, atau secret. Postgres tes: salinan cluster tes sesi M07
sebelumnya di scratchpad sesi ini (`127.0.0.1:54329`, `rt05_test`), dihentikan setelah tes.

### Temuan dan perbaikan

| # | Temuan | Bukti | Perbaikan |
| --- | --- | --- | --- |
| 1 | `HouseholdController` memeriksa hak ubah dari relasi `tautanAktif` yang dimuat **sebelum** lock keluarga; domain tidak memeriksa ulang. Request akun utama yang menunggu lock ketika akun utama dipindah/akun diarsipkan/anggota dipindah tetap menulis bila `versi` yang dikirim = versi setelah perubahan. | Tes interleaving baru gagal tanpa perbaikan (mutasi: `assertEditor` dikosongkan → PATCH/POST household lolos 200/201). | `Links::assertEditor` dipanggil di setiap mutasi `Households` setelah lock keluarga: akun dibaca segar (`aktif`), pengurus, atau tautan aktif `is_utama` pada keluarga terkunci; operasi khusus pengurus memakai `pengurusOnly`. Tanpa lock baru (perubahan tautan selalu memegang lock keluarga, jadi baca READ COMMITTED setelah lock sudah memuat perubahan yang commit lebih dulu). |
| 2 | `Accounts::archive` mengunci keluarga → **akun** → tautan, sedangkan pindah utama/pindah anggota mengunci keluarga → **tautan** → akun. Bila tautan pindah keluarga di antara baca awal dan lock, arsip memegang akun lalu menunggu tautan milik keluarga lain: siklus dengan transaksi keluarga itu (deadlock → 500). | Analisis urutan lock. | Urutan diseragamkan keluarga → tautan → akun; tautan yang sudah pindah → 409 `CONFLICT` tanpa perubahan (ulang berhasil, diuji). `Links::transaction` kini memetakan 40P01/40001 (termasuk `DeadlockException` bersarang Laravel) ke 409 `CONFLICT` dengan rollback utuh dan log warning tanpa PII. `Links::activeLinkOf` yang tidak terpakai dihapus. |
| 3 | `LegacyBackfill` membuat tautan **aktif** untuk akun yang menunjuk warga sudah diarsipkan atau keluarga sudah diarsipkan, dan untuk akun `diarsipkan` tanpa `archived_at` / akun `menunggu` yang sudah menunjuk warga. | Tes baru gagal pada mutasi yang menghapus guard. | Konservatif: tautan aktif hanya bila akun `aktif`/`nonaktif` tanpa `archived_at`, warga & keluarga belum diarsipkan, dan ada keanggotaan aktif yang cocok. Yang berakhir → tautan ditutup pada tanggal tercatat paling awal; tanpa tanggal tercatat → tidak ditautkan (404, ditinjau pengurus). Warga aktif di keluarga arsip → keanggotaan ditutup pada `keluarga.archived_at`. Tetap idempotent dan tidak mengubah baris lama. |
| 4 | `POST /admin/residents/{id}/end` memvalidasi `alasan` tetapi domain hanya menerima status: alasan hilang dari audit. | Tes audit. | `endMember(..., ?string $alasan)`; audit `warga.diarsipkan` memuat `alasan` (status tetap di histori keanggotaan, CHECK alasan tidak diubah). |
| 5 | PATCH/end admin anggota yang dipindah bersamaan menghasilkan 404 (catatan keterbatasan putaran pertama). | Tes interleaving. | Jalur pengurus: anggota aktif di keluarga lain setelah lock → 409 `CONFLICT`; jalur akun utama tetap 404 (anti-IDOR). |
| 6 | Dokumen menyatakan "versi wajib pada semua mutasi keluarga" tanpa pengecualian, dan API.md masih menulis `versi` opsional untuk `/household/members`. | Kode: `versi` wajib di semua route household; arsip keluarga/hunian/approve/arsip akun tanpa `versi`. | API.md diperbaiki (wajib). CONTRACT §5 membedakan edit (versi wajib) vs transisi status yang disengaja tanpa `versi` (buat/arsip keluarga, hunian, approve, arsip akun, keluarga tujuan pindah) — diperiksa ulang di bawah row lock dan tetap menaikkan `keluarga.versi`. |
| 7 | Helper tes `awaitRowLockWait` mem-poll `pg_stat_activity` yang di-snapshot per transaksi; tes yang memegang lock di dalam transaksi melihat status basi. | Handshake gagal 60 detik. | `pg_stat_clear_snapshot()` setiap polling (`tests/Support/Concurrent.php`). |

Auth/reset/primary M06 tidak berubah perilakunya (seluruh tes M06 lulus).

### Tes baru

- `M07ConcurrencyTest` (proses PHP independen; PHPUnit memegang lock keluarga, worker lolos
  controller lalu menunggu lock, PHPUnit mencabut hak lalu commit):
  former primary PATCH vs pindah utama → 403 `PRIMARY_ACCOUNT_REQUIRED`, tanpa tulis;
  POST anggota vs arsip akun → 403 `FORBIDDEN`; POST anggota vs anggota/tautan dipindah keluarga
  → 409 `CONFLICT`; arsip akun dengan keluarga basi → 409 `CONFLICT` tanpa perubahan lalu ulang
  berhasil; PATCH admin anggota vs pindah → 409 (bukan 404); 40P01/40001 tunggal dan bersarang →
  409 + rollback, error lain tidak disamarkan.
- `M07AdminResidentsTest`: alasan akhir anggota di audit; operasi khusus pengurus menolak aktor
  non-pengurus/nonaktif di domain.
- `M07MigrationTest`: backfill tidak memberi tautan aktif ke warga/keluarga berakhir atau pointer
  tidak konsisten; tanggal dari kejadian tercatat; idempotent; household 404.

Uji mutasi (dikembalikan setelahnya): `assertEditor` dikosongkan → 3 tes interleaving gagal;
guard backfill dihapus → tes backfill gagal.

### Perintah dan hasil putaran kedua

| Perintah | Hasil |
| --- | --- |
| `php vendor/bin/pint --test` (rest-api) | passed |
| `php artisan test` (rest-api) | **220/220 lulus, 2125 assertion**, ±66 detik |
| `phpunit --filter "M07ConcurrencyTest\|M07MigrationRollbackTest"` ×3 | 12/12 setiap kali |
| `npx.cmd --yes @redocly/cli@2 lint docs/openapi.yaml` (root) | valid |
| `node infra/scripts/check-repo.mjs` (root) | `OK: 729 berkas dilacak; …`; `git diff --check -- rest-api docs` bersih |

### Risiko tersisa

- Arsip akun/status akun/hunian tidak memanggil `assertEditor` (khusus pengurus; middleware
  memeriksa per request). Pengurus yang dicabut operator tepat saat request berjalan dapat
  menyelesaikan satu aksi itu; tidak memengaruhi hak warga.
- Deadlock yang tetap terjadi kini 409 dan dilog warning; client harus mengulang.
- Belum ada bukti staging/Neon/Android untuk putaran ini; CI GitHub belum dijalankan.
