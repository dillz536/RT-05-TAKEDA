# M04 backend — rt05:staging-smoke (Claude)

Tanggal: 11 Oktober 2026. Branch bersama `codex/m04-staging-recovery` (dari ea4179e). Owner: Claude, untuk `rest-api/**` dan file ini. Claude tidak melakukan commit/push, deploy, atau perubahan pada server/env/Neon.

**Status: kode dan tes lokal siap, belum ada bukti live.** Tes database belum dijalankan karena PC ini tidak punya Postgres; tes itu dijalankan coordinator di CI. Command belum pernah dijalankan di staging. Gate M04 (Neon read/write, worker, heartbeat) tidak boleh diklaim sebelum command ini lulus di VPS staging.

## Command

`php artisan rt05:staging-smoke --confirm-staging --phase=prepare|verify [--event=<uuid>] [--timeout=120]`. CLI saja; tidak ada rute HTTP (`route:list` tidak memuat `staging`).

Urutan pemeriksaan (`app/Console/Commands/StagingSmoke.php`). Seluruh langkah 1–3 terjadi tanpa akses DB.

1. `APP_ENV` harus persis `staging`. Nilai lain ditolak dengan exit 1, termasuk `production`, `testing`, `local`, dan `Staging`.
2. `--confirm-staging` wajib. Tanpa flag itu, exit 1.
3. Validasi argumen: phase harus prepare atau verify; `--event` harus UUID; `--timeout` 0–600. Argumen salah memberi exit 2.
4. **prepare**: satu transaksi `Outbox::record('rt05.smoke', 'smoke', null, {penerima: [], penanda: 'staging-smoke'})`. Tidak membuat akun, inbox, atau data keuangan. Stdout hanya UUID event.
5. **verify**: hanya menerima event bertipe `rt05.smoke`. Command menunggu terbatas (polling 1 detik) sampai event completed atau failed. Output hanya `state=… attempts=… inbox=… error_code=present|none`. Exit 0 hanya bila `state=completed` dan `inbox=0`.
6. Exception dicetak sebagai nama kelas saja, tanpa pesan, DSN, atau payload.

Jalur yang dipakai ulang: `Outbox` → `rt05:outbox-dispatch` (scheduler) → Redis `notifications` → `ProcessOutboxEvent` → handler `rt05.smoke` yang sudah ada. Dengan penerima kosong, handler tidak menyisipkan inbox lalu menandai completed. `rt05:async-smoke` beserta guard `*_test`/Redis lokal tidak diubah. Satu-satunya perubahan pada file lama adalah komentar handler di `app/Domain/Community/Notifications.php`.

## Rencana pakai di staging (untuk coordinator/pemilik)

Jalankan di container `api` atau `worker` project `rt05-staging`:

```sh
ID=$(php artisan rt05:staging-smoke --confirm-staging --phase=prepare)
php artisan rt05:staging-smoke --confirm-staging --phase=verify --event="$ID" --timeout=180
php artisan rt05:heartbeat-check --component=scheduler --component=worker-default
```

Lulus berarti tiga hal: (a) tulis/baca Neon staging lewat role runtime, (b) scheduler mengklaim event dan push ke Redis queue, (c) worker menyelesaikan event. Skenario restart dapat diulang di antara prepare dan verify, misalnya restart worker atau Redis queue dengan AOF.

## Tes (lokal/CI `*_test`, tidak pernah ke Neon)

`tests/Feature/StagingSmokeTest.php` berisi 5 tes. Di dalam tes, nilai "staging" hanya mensimulasikan `app['env']`:

- `test_rejects_non_staging_environments_before_database_access`: production/testing/local/Staging memberi exit 1, query log pgsql 0, dan tidak ada event dibuat.
- `test_staging_requires_explicit_confirmation_before_database_access`: tanpa flag, exit 1 dan 0 query.
- `test_invalid_arguments_rejected_without_database_access`: phase, UUID, atau timeout yang salah memberi exit 2 dan 0 query.
- `test_synthetic_event_is_processed_without_accounts_inbox_or_finance`: prepare mencetak UUID saja dan payload sesuai. verify sebelum consumer memberi `state=pending` exit 1. Sesudah `OutboxDispatcher::dispatch()` (queue sync), hasilnya `state=completed attempts=1 inbox=0 error_code=none` exit 0. Output tidak memuat payload atau nama driver. Jumlah baris akun/notifikasi/pembayaran/kas/tagihan tidak berubah.
- `test_verify_accepts_only_rt05_smoke_events`: event bertipe lain atau UUID yang tidak ada memberi exit 1.

## Hasil aktual (lokal Windows, PHP 8.4.13, cwd rest-api)

| Perintah | Hasil |
| --- | --- |
| `php -l` StagingSmoke.php, StagingSmokeTest.php | tanpa error |
| `php vendor/bin/pint --test` | passed |
| `php artisan rt05:staging-smoke --phase=prepare --confirm-staging` (APP_ENV lokal) | exit 1 "hanya untuk APP_ENV=staging" |
| `APP_ENV=production … --phase=prepare --confirm-staging` | exit 1 |
| `APP_ENV=staging … --phase=prepare` (tanpa flag) | exit 1 "tambahkan --confirm-staging" |
| `APP_ENV=staging … --phase=bogus --confirm-staging` / `--phase=verify --event=x` | exit 2 / exit 2 (berhenti sebelum DB) |
| `RT05_SKIP_TEST_MIGRATIONS=1 php vendor/bin/phpunit tests/Feature/HeartbeatTest.php tests/Unit` (regresi tanpa DB) | 10 lulus, 50 assertion |
| `node infra/scripts/check-repo.mjs` (root), `git diff --check` | OK / bersih |

Sengaja **tidak** dijalankan: kombinasi `APP_ENV=staging` + `--confirm-staging` + phase valid di PC ini. Kombinasi itu akan menulis ke database yang ditunjuk `.env` lokal (Neon dev). StagingSmokeTest juga belum dijalankan dan menunggu CI.

## Keterbatasan

- Setiap prepare meninggalkan satu baris `outbox_events` sintetis (completed, payload `penanda=staging-smoke`) di Neon staging. Baris ini tidak memuat data warga dan tidak dibersihkan otomatis.
- Smoke tidak menguji inbox ke akun nyata, push, email, atau keuangan. Itu disengaja; jalur inbox sudah dibuktikan `rt05:async-smoke` di CI.
- Guard memakai `APP_ENV` dari config. Bila config cache staging basi atau `APP_ENV` salah isi, command menolak (fail closed), bukan menulis ke tempat lain.
- Hasil verify bergantung pada scheduler (dispatch tiap menit) dan worker yang hidup. Karena itu timeout default 120 detik; gunakan 180 bila deploy baru saja restart.
