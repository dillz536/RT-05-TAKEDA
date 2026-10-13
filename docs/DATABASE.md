# Database PostgreSQL / Neon

## Kepemilikan

rest-api/database/migrations adalah satu-satunya schema domain. Eloquent digunakan; tidak menambahkan Prisma/Drizzle/SQLite sebagai database bisnis kedua.

ERD.md merupakan rancangan baru v1 berdasarkan kebutuhan terverifikasi. ERD lama yang disebut dalam percakapan belum disediakan sebagai sumber lengkap; M02 harus merekonsiliasi jika file lama ditemukan, bukan menganggap jumlah tabel sebelumnya wajib.

## Lingkungan dan koneksi

| Lingkungan | Data | Endpoint |
| --- | --- | --- |
| local/CI | Sintetis | Postgres lokal terisolasi |
| dev | Sintetis | Neon dev atau branch schema-only |
| staging | Sintetis atau anonymized tervalidasi | Neon staging terpisah |
| production | Data RT operasional | Neon production |

Gunakan project/credential yang sudah ada bila disediakan. Jangan membuat project/branch berbayar atau menyalin PII production ke dev tanpa keputusan pemilik. Pilih region dekat VPS jika project baru memang diperlukan; jangan migrasi region project existing diam-diam.

Runtime API/worker: pooled connection. Migration, pg_dump/restore: direct connection. TLS wajib; SSL mode memverifikasi hostname/certificate jika runtime mendukung, CA bundle terpasang. Jangan menonaktifkan TLS agar koneksi berhasil.

Nama environment desain:

```dotenv
DB_CONNECTION=pgsql
DATABASE_URL=<pooled-url-dari-Neon>
DATABASE_URL_UNPOOLED=<direct-url-untuk-migration>
DB_SSLMODE=verify-full
DB_SSLROOTCERT=<path-ca-bundle-runtime>
```

Ini template, bukan credential. Laravel tidak otomatis membaca nama custom tersebut: M02 harus memetakan DATABASE_URL pada config/database.php connection pgsql dan DATABASE_URL_UNPOOLED pada connection pgsql_migrations. DB_SSLMODE/root cert dipetakan eksplisit. Migration runner memanggil connection migrations; jangan menimpa env runtime dengan direct URL.

Implementasi M02 (rest-api/config/database.php): kedua koneksi memakai `timezone=UTC`, `application_name` (rt05-api / rt05-migrations), `connect_timeout` dari DB_CONNECT_TIMEOUT, dan named prepares dimatikan untuk pooler (ADR14). Parameter query pada URL (Neon: `sslmode=require`) mengalahkan DB_SSLMODE, jadi URL staging/production harus memuat `sslmode=verify-full` (ADR15). `composer migrate` = `php artisan migrate --database=pgsql_migrations --force`. Hak runtime: `infra/sql/grant-runtime-role.sql` (psql) atau `php artisan rt05:db-grant-runtime-role <role>`.

Role runtime memiliki DML yang diperlukan, bukan superuser/DDL. Role migration terpisah. Website/mobile tidak menerima URL database. Credential direct hanya diberikan kepada migration/backup job/operator, bukan bundle frontend.

Isolasi runtime (perbaikan12 Oktober): Compose hanya memberikan
`DATABASE_URL_UNPOOLED` kepada job sekali jalan `migrate`. Konfigurasi PHP
tidak mendaftarkan `pgsql_migrations` tanpa URL direct eksplisit; tidak ada
fallback ke runtime maupun DB_* untuk koneksi migrasi yang tidak tersedia.
TLS runtime tetap wajib verify-full; koneksi migrasi diperiksa bila tersedia.
Smoke direct/grant dijalankan melalui job migrasi, bukan `exec api`.
Guard memeriksa JSON Compose yang sudah mengurai inheritance dan override,
tanpa mencetak URL. Username/password harus pada authority URL; override
credential via query ditolak karena Laravel mendahulukan opsi query.
Query TLS/channel_binding tetap diperbolehkan. Root `.dockerignore` mengecualikan env/cache konfigurasi
dan state privat sebelum COPY di setiap build stage.

## Tipe dan identitas

- UUID untuk domain ID publik; jangan jadikan UUID pengganti policy.
- Timestamp timestamptz UTC; display Asia/Jakarta.
- Period iuran date hari pertama bulan dengan check.
- Rupiah bigint integer; tidak float. JSON mengirim integer yang masih dalam batas aman client.
- Status string terbatas dengan check/enum aplikasi dan validasi transisi.
- Email lowercased dan unique normalized value.
- deleted/archived menggunakan archived_at dan archived_by untuk domain penting.
- Optimistic version integer untuk edit konten/status; concurrent stale mutation ditolak 409.

## Constraint wajib

| Data | Constraint/invariant |
| --- | --- |
| houses | unique normalized address key / kode rumah |
| occupancies | partial unique primary aktif per house; end ≥ start |
| account_links | satu primary aktif per house; satu link aktif per user (M07: `tautan_akun`, utama per keluarga + satu hunian aktif per keluarga/rumah) |
| family_memberships | M07 `keanggotaan_keluarga`: satu keanggotaan aktif per warga; rentang valid |
| registration_invites | hanya satu aktif; 6 digit dipertahankan sebagai string |
| dues_rates | unique effective_month; amount >0 |
| dues_charges | unique house_id, period; amount_due >0 |
| allocations | satu allocation aktif per charge; amount = charge penuh v1 |
| cash_transactions | satu entry posting per payment; reversal_of unique nonnull |
| idempotency_requests | unique actor_id, route_scope, key; request_hash mengikat payload |
| notifications | unique event_id, recipient_id, channel=inbox |
| outbox_events | stable event UUID; aggregate/version dedup jika diperlukan |
| media_assets | owner, visibility, processing state harus valid |

Constraint lintas baris seperti total allocations=payment dilindungi transaction/action dan meaningful tests; jangan mengklaim CHECK biasa dapat membaca tabel lain.

## Transaksi pembayaran

1. Validasi admin, rumah, periode, nominal, tanggal dan Idempotency-Key.
2. Reserve idempotency record dalam transaksi; key sama/payload lain →409.
3. Lock house dan charge dalam urutan stabil. Re-read charge unpaid.
4. Hitung total dari snapshot amount_due, bukan tarif terbaru atau client.
5. Insert payment, active allocations, cash entry, audit, outbox.
6. Mark charges paid dan complete idempotency response.
7. Commit; side effect dikirim oleh outbox.

Request kedua yang bersamaan untuk charge sama tidak boleh menghasilkan posting kedua; tangani constraint collision sebagai 409/replay, bukan 500 generik. Deadlock/serialization retry terbatas hanya jika transaksi belum commit.

## Koreksi dan kas

Koreksi menggunakan transaction yang lock payment asal, house, charges, dan cash asal. Buat reversal sekali, nonaktifkan allocation asal, buka charge, kemudian posting pengganti jika request koreksi menyertakannya. Setiap langkah gagal membatalkan keseluruhan. History immutable tetap ada.

Saldo computed dari ledger signed_amount atau view konsisten; tidak memiliki kolom saldo yang diedit manual. signed_amount cash income positif, expense negatif, reversal kebalikan entry asal. Opening balance terpisah dengan uniqueness scope.

## Penciptaan tagihan

Generator memastikan charge rumah/periode tersedia sesuai billing_enabled/start. Snapshot effective rate ≤period; rate tidak tersedia →error operasional, bukan nominal 0. Upsert tidak boleh overwrite snapshot charge existing.

Setiap bulan scheduler melakukan catch-up, bukan hanya tepat tengah malam. Generation berdasarkan period dan constraint house-period; aman setelah downtime.

## Index dan query

Index feed status/created_at, family-house active, charge house/period/status, cash posted_at/source, outbox status/available_at, media state/created_at. Semua list paginated. Gunakan eager loading untuk menghindari N+1.

Audit memakai JSON before/after yang direduksi; jangan memasukkan password/token/PII penuh. Audit bukan tempat menyalin seluruh form keluarga.

### Perubahan M07 — rumah, keluarga, akun

Tabel additive `keanggotaan_keluarga`, `tautan_akun`, kolom `rumah.versi`; detail di ERD.md.
Migration menjalankan backfill idempotent (`App\Domain\Residents\LegacyBackfill`): satu
keanggotaan per warga dan paling banyak satu tautan per `akun.warga_id`, `mulai_at` NULL (tidak
dikarang), akhir hanya dari `archived_at` warga/keluarga/akun yang tercatat, tanpa mengubah baris
lama. Konservatif: tautan aktif hanya untuk akun aktif/nonaktif dengan warga dan keluarga yang
belum diarsipkan serta keanggotaan aktif yang cocok; pointer ke warga/keluarga yang sudah
berakhir menjadi tautan tertutup, dan pointer tidak konsisten tanpa tanggal tercatat (akun
`diarsipkan` tanpa `archived_at`, akun `menunggu` yang menunjuk warga) tidak ditautkan. Setelah rollback
aplikasi ke image M06, `php artisan rt05:residents-backfill` melengkapi histori baru.
Down migration hanya menghapus tambahan M07.

Transaksi di `App\Domain\Residents\{Households,Occupancies,Accounts,Links}`; urutan lock
permohonan → rumah (id naik) → keluarga (id naik) → tautan → akun; tautan hanya diubah sambil
memegang lock keluarganya. Hak aktor diperiksa ulang setelah lock keluarga. Constraint unik
Postgres, deadlock (40P01) dan serialization failure (40001) dipetakan ke 409. Tagihan tetap per rumah: hunian mulai/akhiri/pindah tidak membuat, memindah, menghapus,
atau membebaskan tagihan; arsip rumah mematikan tagihan berikutnya tanpa menyentuh histori.

## Migration workflow

Additive migration →uji local→uji Neon staging direct→backup/check restore path→migration job single→deploy kompatibel→verifikasi. Destructive drop/rename setelah semua consumer berpindah pada release terpisah.

Production tidak migrate:fresh atau seed factory. Migration gagal menghentikan rollout. Rollback aplikasi tidak otomatis rollback schema/data; skema additive harus mendukung image sebelumnya.

## Backup dan restore

pg_dump/pg_restore memakai direct endpoint serta client kompatibel versi server. Backup terenkripsi di luar VPS, beserta media dan kunci/config recovery yang dikelola aman. Verify checksum dan restore ke isolated environment; jangan hanya memeriksa ukuran dump.

Kebijakan restore window Neon bergantung paket akun, diperiksa saat M16. Jangan menganggap free plan menjamin PITR/retensi tertentu.

## Batas Redis

Saldo, pembayaran, status charge, audit, notification inbox dan outbox tetap di Postgres. Cache boleh hilang tanpa kehilangan ledger. Queue replay boleh terjadi sehingga consumer wajib idempotent.
