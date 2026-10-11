# KIMI Independent Acceptance Review — M03 (Redis, worker, scheduler, outbox)

- Reviewer: Kimi (independent review; bukan implementator)
- Baseline: branch `codex/m03-async-recovery` @ `c644b84` (= `origin/main`, PR #14 merged). Branch belum memuat commit recovery baru — audit ini menilai kondisi existing sesuai tugas.
- Tanggal: 11 Oktober 2026 (lanjutan sesi setelah mati listrik)
- Acuan gate: docs/ROADMAP.md M03, docs/ASYNC_JOBS.md, docs/TESTING.md (Worker/scheduler), ADR05/ADR06.
- Batas review: baca-saja atas kode/dokumen. Tidak ada implementasi, tidak ada edit di luar file ini. Tidak ada klaim "passed" dari kode saja — status gate mengikuti bukti tes yang benar-benar ada.

## Ringkasan eksekutif

Implementasi M03 saat ini bersifat **sinkron**: `rt05:outbox-dispatch` (scheduler tiap menit) memanggil `Notifications::dispatch()` langsung dalam proses scheduler — tidak ada Laravel Job, tidak ada publish ke Redis queue, dan worker `queue:work` di compose tidak pernah menerima pekerjaan apa pun. Idempotency konsumen (UNIQUE event+recipient, replay aman) sudah dibuktikan tes, tetapi seluruh gate recovery yang mensyaratkan worker/Redis nyata (kill/restart, AOF restart, queue-loss) **belum dapat dinilai** karena arsitektur Redis-worker belum ada. Konfigurasi juga menyimpang dari ASYNC_JOBS (retry_after 90 vs 180, after_commit false, satu Redis untuk cache+queue+session, tanpa AUTH). **M03 belum memenuhi gate; jangan ditandai passed.**

## Matrix gate M03

| # | Gate (ROADMAP/ASYNC_JOBS) | Status | Bukti / catatan |
| --- | --- | --- | --- |
| G1 | Duplicate event → satu inbox | **PASS (terbatas)** | `ServicesTest::test_status_change_notifies_owner_once_even_if_replayed` (replay lewat reset `completed_at`/`dispatch_lease_until`, assert 1 notifikasi/penerima); UNIQUE(`event_id`,`penerima_id`) di migration `..._000400_..._notifikasi...:73`; `insertOrIgnore` di `Notifications::send`. Lulus di CI PR #12. |
| G2 | Kill worker setelah domain commit → restart → satu inbox, pembayaran tetap satu | **MISSING** | Tidak ada worker/queue job yang bisa di-kill; dispatch sinkron di scheduler. Tidak ada tes kill/restart. |
| G3 | Stale dispatched replay (published tanpa completion → replay) | **PARTIAL** | Mekanisme lease ada (`dispatch_lease_until` +5 menit, SKIP LOCKED — `Notifications::dispatch`), dan replay diuji manual-reset (G1). Namun `published_at` diisi **tanpa publish nyata ke Redis**, sehingga skenario "crash sesudah mark, Redis kehilangan job" (ASYNC_JOBS §outbox) tidak punya padanan implementasi. |
| G4 | AOF restart → pending job pulih | **MISSING** | `appendonly yes` ada di `infra/compose.production.yml:93`, tetapi tidak ada job yang pernah masuk Redis; tidak ada tes restart persistence (lokal/CI). WSL/Docker lokal sengaja tidak dipasang (R06) — tes ini memerlukan CI/service container. |
| G5 | Queue-loss recovery (simulasi queue hilang → outbox replay) | **MISSING** | Tanpa Redis queue, "queue loss" tidak bermakna; replay outbox via scheduler rerun ada, tapi bukan skenario gate. |
| G6 | DB rollback tidak meninggalkan event | **PASS (struktural)** | Outbox ditulis dalam transaksi domain yang sama (`Outbox::record` dipanggil di dalam `DB::transaction` domain, mis. `routes/console.php` pengingat agenda; `Dues::recordPayment`). Rollback otomatis menarik event. Tes penolakan parsial (`FinanceTest::test_partial_or_over_payment_rejected_without_partial_write`) mengassert 0 pembayaran/saldo, namun **belum mengassert `outbox_events` kosong** — tambahan assert disarankan. |
| G7 | No public Redis | **PASS + catatan** | Service `redis` di compose.production tanpa `ports:`; hanya `gateway` terekspos; VPS override hanya menempel gateway ke Traefik. **Catatan:** tidak ada `requirepass`/ACL — `REDIS_PASSWORD=null` di `.env.example`, tidak ada password di env compose. ASYNC_JOBS mewajibkan "ACL/password per env". Private network memitigasi, tapi gate keamanan belum penuh. |

Skor: 2 pass, 1 pass struktural, 1 partial, 3 missing. **Gate M03: NOT MET.**

## Temuan konfigurasi (deviasi dari ASYNC_JOBS.md)

| ID | Severity | Temuan | Lokasi | Acuan |
| --- | --- | --- | --- | --- |
| K01 | high | Dispatcher outbox sinkron di scheduler; tidak ada `app/Jobs/*`, tidak ada dispatch ke Redis; `published_at` diisi tanpa publish | `routes/console.php` (`rt05:outbox-dispatch`), `app/Domain/Community/Notifications.php:25-65` | ASYNC_JOBS §Transactional outbox langkah 2–4 |
| K02 | high | Satu Redis dipakai queue+cache+session dengan `noeviction`+AOF; spec memisahkan redis-queue (noeviction/AOF) dan redis-cache (allkeys-lru, TTL) | `infra/compose.production.yml:32-38,90-100` | ASYNC_JOBS §Redis; ADR06 "Antrean dan cache terpisah" |
| K03 | medium | `retry_after` default 90; spec 180 (> timeout worker maks 120) | `rest-api/config/queue.php:71` | ASYNC_JOBS §Worker awal |
| K04 | medium | `after_commit: false` pada koneksi redis; spec `after_commit=true` | `rest-api/config/queue.php:73` | ASYNC_JOBS §Worker awal |
| K05 | medium | Command worker tidak sesuai: `--sleep=3 --tries=3` tanpa `--queue=notifications,default` dan tanpa `--timeout=60`; tidak ada worker-media/worker-exports; spec tries=5, sleep=2, backoff 10/30/120/300 | `infra/compose.production.yml:55` | ASYNC_JOBS contoh command M03 |
| K06 | medium | `compose.local.yml` tidak memiliki Redis sama sekali; scope M03 mencakup "Compose lokal dan CLI smoke event sintetis" | `infra/compose.local.yml` | ROADMAP M03 scope |
| K07 | low | `REDIS_PREFIX` default `rt05prod_`; spec prefix `takeda:ENV:` | `infra/compose.production.yml:35` | ASYNC_JOBS §Redis |
| K08 | low | Jadwal `rt05:pengingat-iuran` 08:00; spec 09:00 tanggal 5 & 20 | `routes/console.php` (Schedule) | ASYNC_JOBS §Jadwal |
| K09 | low | `rt05:token-rotate` weekly unconditional; spec "tiap jam, rotasi hanya bila umur token ≥7 hari" (perilaku ekuivalen mingguan, tetapi menyimpang dari mekanisme ensure) | `routes/console.php` | ASYNC_JOBS §Jadwal |
| K10 | low | Tidak ada Redis AUTH/ACL per env | `infra/compose.production.yml:90-100`, `rest-api/.env.example:52` | ASYNC_JOBS §Redis |
| K11 | info | Belum ada heartbeat/metrics/alert (oldest outbox age, worker heartbeat, dll.) | — | ASYNC_JOBS §Kegagalan |

## Cek privacy/finance (diminta tidak terganggu)

- Payload outbox hanya ID/jenis/slot — tanpa PII; `iuran.pengingat` membawa daftar `penerima` berupa ID akun (bukan identitas). Sesuai "Payload hanya ID/jenis".
- Anonimitas pelapor terjaga: tes mengassert nama pelapor tidak muncul di teks notifikasi (`ServicesTest`), dan pesan ke pengurus generik.
- Keuangan: seluruh 17 skenario TESTING.md iuran/kas memiliki padanan tes di `FinanceTest` (idempotency replay/409, alokasi ganda ditolak DB 23505, pembalikan sekali, koreksi atomik, ledger immutable, CSV injection). Snapshot tarif dan catch-up ada. Tidak ada regresi yang terlihat dari audit statis.
- Guard DB tes: `phpunit.xml:29-30` memaksa `rt05_test` di 127.0.0.1:54329 lokal/CI; tidak ada SQLite; tidak menyentuh Neon. Sesuai batas "DB tests hanya *_test".

## Yang sudah benar (diakui)

- Schema outbox lengkap (lease, attempts, published/completed, last_error_code aman) + index pending; bug nyata presisi `timestamptz(0)` ditemukan dan diperbaiki (migration `..._000600_...`).
- SKIP LOCKED + lease membuat multi-dispatcher aman; backoff error terbatas (`min(60, 2^n) menit`); error disimpan sebagai kelas, tanpa PII.
- Jadwal memakai `withoutOverlapping`/`onOneServer`; timezone Asia/Jakarta untuk jadwal domain.

## Rekomendasi ke coordinator (bukan implementasi oleh reviewer)

1. Implementasi Redis-worker nyata (Claude backend): Job per event (DeliverNotification dkk.), dispatcher publish ID event ke Redis, `published_at` hanya setelah enqueue sukses, consumer load dari Postgres + completed_at; config K03–K05; worker default/media/exports.
2. Pisahkan redis-queue/redis-cache atau putuskan eksplisit single-instance dengan ADR (Grok infra): AOF everysec, noeviction untuk queue; AUTH per env; tambahkan Redis ke compose.local; prefix `takeda:ENV:`.
3. Tes gate G2/G4/G5 di CI dengan service container Redis+Postgres (kill/restart, AOF restart, flush-simulasi → replay), plus assert outbox kosong pada tes rollback (G6).
4. Setelah file implementasi tersedia di branch, reviewer memvalidasi ulang matrix ini terhadap bukti tes aktual — tanpa itu M03 tetap NOT MET.

## Status review

**IN PROGRESS — menunggu implementasi final M03 (Claude backend + Grok infra) untuk validasi ulang.** Reviewer tidak menandai gate dari kode saja; keputusan checkpoint/scope berikutnya ada di coordinator.

---

## Review inkremental — implementasi async muncul (11 Oktober 2026, dispatch 2)

Working tree kini memuat implementasi Claude (backend) yang belum di-commit. Review statis di bawah; **semua gate tetap PENDING sampai hasil tes aktual ada**.

### Perubahan yang direview (kepemilikan Claude)

| File | Penilaian statis |
| --- | --- |
| `app/Domain/Operations/OutboxDispatcher.php` | Sesuai kontrak: klaim SKIP LOCKED + lease 120 dtk; `published_at` hanya setelah `pushOn` sukses; lease dilepas setelah publish; enqueue gagal → event tetap pending + backoff pangkat dua (maks 15 mnt) + kode error aman (hanya nama kelas, komentar eksplisit soal DSN di pesan exception). Stale replay: `published_at < now()-stale_seconds` (900 dtk > jendela retry job ~460 dtk + timeout — masuk akal). |
| `app/Jobs/ProcessOutboxEvent.php` | Consumer memuat ulang dari Postgres, `lockForUpdate`, no-op bila completed/failed; inbox+`completed_at` atomik dalam satu transaksi; `tries=5`, `timeout=60`, backoff 10/30/120/300 sesuai ASYNC_JOBS; `failed()` memarkir `failed_at` (tidak replay otomatis) — sesuai "failure parking". |
| `app/Console/Commands/OutboxRetry.php` | Retry manual per event ID, menolak non-UUID, tidak menyentuh event completed. Sesuai "jangan replay seluruh antrean". |
| `app/Console/Commands/AsyncSmoke.php` | Ditolak pada `APP_ENV=production`; `--with-inbox` digandakan guard `*_test` host lokal (sama dengan `tests/bootstrap.php`); password acak tidak dicetak; output hanya event_id/state. |
| `app/Console/Commands/HeartbeatCheck.php` + `Support/Heartbeat.php` | Heartbeat worker via listener `Looping` (throttle 30 dtk; komentar benar soal return false menghentikan worker), scheduler via `Schedule::call` tiap menit; store `coordination` di instance queue (noeviction); nilai hanya timestamp; check command exit 0/1/2. |
| `config/queue.php` | `retry_after` 180 ✓, `after_commit: true` ✓, koneksi `queue` ✓, failed jobs `database-uuids` ke Postgres ✓ (K03/K04 teratasi). |
| `config/database.php` | Dua instance Redis: `queue` (db 0) dan `cache` (db 1) dengan host/port/password per-instance via `REDIS_QUEUE_*`/`REDIS_CACHE_*`; prefix `takeda:<env>:` ✓ (K07 teratasi); timeout gagal-cepat 2/5 dtk. |
| `config/cache.php`, `config/session.php` | Cache/sesi/rate-limit di instance cache; lock (`withoutOverlapping`, `Cache::lock`) di instance queue — pemisahan sesuai kontrak M03. |
| `migration 2026_10_12_000100_outbox_consumer_failure.php` | Additive `failed_at` + index pending diperbarui; kompatibel aplikasi lama. |
| `Notifications::handle` | `default => throw UnknownOutboxEvent` — jenis tak dikenal diparkir lewat jalur gagal consumer, bukan diselesaikan diam-diam ✓. |

### Blocker penerimaan saat ini (file/line)

| ID | Severity | Bukti |
| --- | --- | --- |
| B01 | **high — regresi build/tes** | `Notifications::dispatch()` dihapus tetapi masih dipanggil di `rest-api/tests/Feature/ServicesTest.php:93,95,152` dan `rest-api/app/Console/Commands/DemoSeed.php:88` → fatal "undefined method"; CI api.yml akan merah. Milik Claude (rest-api); reviewer tidak mengedit. |
| B02 | high — bukti gate belum ada | Belum ada tes/drill M03: `rest-api/tests` tidak berubah; gate kill/restart, AOF restart, queue-loss, enqueue/consumer terpisah belum punya bukti eksekusi (CI). |
| B03 | high — infra belum berubah | `infra/compose.production.yml:49-55,90-100` masih satu service `redis` (cache+queue+session, tanpa `requirepass`) dan worker `--sleep=3 --tries=3` tanpa `--queue`/`--timeout`; pemisahan instance, worker media/exports, dan healthcheck heartbeat belum ada. Milik Grok; belum muncul di working tree. |

### Catatan minor (bukan blocker)

- `QUEUE_CONNECTION=sync` di `phpunit.xml` membuat dispatcher mengeksekusi job inline pada tes — dapat dipakai tes lama, tetapi gate "enqueue dan consumer terpisah" memerlukan tes/drill dengan Redis nyata (CI).
- Job `ProcessOutboxEvent` tidak memeriksa `available_at` saat konsumsi — aman karena hanya dispatcher yang mengantrekan, tetapi dicatat bila nanti ada jalur enqueue lain.

### Verdict sementara

Desain async sesuai kontrak M03 (COORDINATION.md §Kontrak integrasi). **Gate tetap NOT MET / PENDING** sampai: B01 diperbaiki, tes+drill Redis nyata lulus di CI (B02), dan infra pemisahan Redis/worker hadir (B03). Reviewer menunggu konfirmasi coordinator bahwa kedua handoff selesai, lalu menilai bukti aktual.

---

## Review inkremental 2 — handoff Claude (tes) + Grok (infra) muncul (11 Oktober 2026)

Kedua handoff kini ada di working tree (belum di-commit; Git milik Codex). Review statis + verifikasi lokal yang bisa dijalankan tanpa Docker/Postgres.

### Status blocker sebelumnya

| ID | Status | Bukti |
| --- | --- | --- |
| B01 | **TERATASI (statis)** | `ServicesTest.php` kini memakai `OutboxDispatcher::dispatch()` (replay test juga membersihkan `published_at` — sesuai semantik baru); `DemoSeed.php:88` beralih ke `OutboxDispatcher`. Tidak ada referensi `Notifications::dispatch` tersisa. |
| B02 | **TERATASI SEBAGIAN** | `tests/Feature/AsyncOutboxTest.php` (10 tes) + `HeartbeatTest.php` ditambahkan — lihat cakupan di bawah. Namun drill worker proses nyata vs Redis nyata belum di-wire di CI. |
| B03 | **TERATASI (statis)** | `compose.production.yml`: service `redis` (queue, AOF everysec, noeviction, 256mb, volume `redis-data` dipertahankan sesuai kontrak) + `redis-cache` (tanpa persistensi, allkeys-lru, 128mb); tiga worker (`--queue=notifications,default/media/exports`, `--sleep=2 --tries=5 --timeout=60/120`, `stop_grace_period: 180s`, healthcheck `rt05:heartbeat-check` per komponen); scheduler ikut heartbeat; `compose.local.yml` mendapat redis+redis-cache tanpa port host; `deploy-vps.sh` mengangkat nama service baru. |

### Cakupan tes baru vs gate COORDINATION.md

| Gate | Cakupan | Level bukti |
| --- | --- | --- |
| Enqueue/consumer terpisah | `test_dispatcher_publishes_after_enqueue_and_only_consumer_completes`: setelah dispatch inbox=0 dan completed_at null; setelah `handle()` inbox=1 | Unit (Queue::fake) — membuktikan semantik, bukan proses worker nyata |
| Duplikat → satu inbox | `test_duplicate_delivery_creates_one_inbox_per_recipient` (handle 2x + completion hilang) | Unit |
| Lease expired/reclaim | `test_expired_lease_is_reclaimed_and_active_lease_is_skipped` | Unit |
| Stale published replay | `test_stale_published_event_is_replayed_but_recent_one_is_not` (900 dtk window) | Unit |
| Enqueue gagal (Redis down) | `test_enqueue_failure_keeps_event_and_domain_row_durable_with_safe_code` — termasuk assert pesan berisi DSN/password TIDAK bocor ke `last_error_code`, domain row tetap ada, recovery setelah Redis pulih | Unit (koneksi `broken` disuntik) |
| Parking + retry manual | `test_consumer_failure_rolls_back_then_parks_and_manual_retry_recovers` (inbox parsial rollback, `failed_at`, dispatcher skip parked, `rt05:outbox-retry` UUID valid/invalid) | Unit |
| Unknown type diparkir | `test_unknown_event_type_is_parked_not_silently_completed` | Unit (sync driver) |
| Rollback domain | `test_domain_rollback_leaves_no_outbox_event` — assert eksplisit `outbox_events` tidak bertambah setelah rollback | Unit (menutup catatan G6 review awal) |
| Smoke guards | `test_async_smoke_refuses_non_test_targets` (Redis non-lokal / DB bukan *_test ditolak), `test_async_smoke_prepare_and_verify_without_leaking_identity` (output tanpa email) | Unit |
| Heartbeat | `HeartbeatTest`: missing → exit 1, beat → ok, stale >max-age → exit 1 | Unit (store array) |
| Jadwal dispatcher | `test_outbox_dispatch_is_scheduled_every_minute_without_overlap` | Unit |
| AOF restart | `ci-redis.sh`: LPUSH job sintetis → `docker restart` → entry tetap ada; assert appendonly/everysec/noeviction; loopback-only; hapus kunci antrean berprefix dengan penolakan di luar prefix; tanpa FLUSHALL/FLUSHDB | **Redis nyata di CI** |
| Kill/restart worker (proses) | **BELUM ADA** — tidak ada step CI yang menjalankan `queue:work` nyata, mematikannya, lalu membuktikan event tertunda selesai setelah hidup kembali | — |
| Queue loss end-to-end | **BELUM ADA** — ci-redis.sh membuktikan penghapusan kunci aman, tetapi tidak ada drill app-level: dispatch → hapus antrean → stale → replay → completed | — |

### Verifikasi lokal yang reviewer jalankan (bukan bukti CI)

| Perintah | Hasil |
| --- | --- |
| `node infra/scripts/check-m03-compose.mjs` | OK (kontrak statis: worker×3, pemisahan Redis, heartbeat, tanpa `ports:` di production, tanpa FLUSHALL/FLUSHDB) |
| `bash -n` ci-redis.sh, redis-queue/cache/health.sh, deploy-vps.sh | syntax OK |
| `php -l` 11 file PHP baru/berubah | No syntax errors (semua) |
| `node infra/scripts/check-repo.mjs` | OK: 465 berkas, tanpa secret/pola terlarang |

PHPUnit/Postgres/Redis nyata tidak dapat dijalankan reviewer di lokal (R06: tanpa Docker/WSL); bukti akhir harus dari CI GitHub setelah Codex commit/push.

### Catatan residual (bukan blocker gate, untuk keputusan coordinator)

- N01: `REDIS_PASSWORD`/`REDIS_CACHE_PASSWORD` opsional; kosong → `--protected-mode no` tanpa AUTH (hanya jaringan privat Compose). ASYNC_JOBS meminta "ACL/password per env" — production disarankan mengisi password; keputusan dicatat di env example.
- N02: `REDIS_PREFIX` default di compose production tetap `rt05prod_` (dapat ditimpa env), sementara default aplikasi `takeda:<env>:`. Tidak merusak isolasi, tetapi dua konvensi prefix berbeda.
- N03: drill end-to-end worker (async-smoke skenario prepare→dispatch→kill/restart→verify) terdokumentasi di docblock `AsyncSmoke` tetapi belum punya langkah CI yang menjalankannya. Tanpa langkah ini, gate "kill/restart worker" dan "queue loss → replay" hanya terbukti pada level unit/infra, bukan integrasi proses nyata.

### Verdict review 2

B01/B03 teratasi secara statis; B02 teratasi sebagian (unit komprehensif + drill Redis infra nyata; drill proses worker end-to-end belum). **Seluruh gate tetap PENDING sampai hasil CI aktual (php artisan test hijau termasuk AsyncOutboxTest/HeartbeatTest, ci-redis.sh hijau, infra workflow hijau) tercatat.** Reviewer menunggu rebinding task final dari coordinator untuk penilaian bukti eksekusi.

### Catatan kontinuitas (11 Oktober 2026, menjawab coordinator msg balasan review 2)

- Coordinator mengonfirmasi: Grok sedang menangani N01/N02 dan drill worker nyata N03; Claude menambahkan tes durabilitas pembayaran offline. **Review final belum dimulai** — menunggu perubahan tersebut + hasil CI aktual.
- Scope pemilik terbaru: sekuensial sampai M05 saja, bertahap; review final wajib memakai hasil CI nyata dan mencatat selisih dari rencana; tanpa ekspansi M06+.
- Sejak review 2 sudah dinilai tambahan: `rest-api/.env.example` (dokumentasi instance queue/cache, prefix `takeda:<APP_ENV>:`, guard lokal sync) dan `rest-api/README.md` (alur M03 akurat) — konsisten, tanpa temuan baru. Catatan dokumentasi kecil: README menyebut "failed_jobs/log hanya memuat kelas + SQLSTATE"; perlu dipastikan di CI bahwa kolom `exception` `failed_jobs` bawaan Laravel tidak membocorkan DSN (payload job sendiri hanya event ID — aman).
- Dispatch ctx_500582c69d6b dicabut runtime saat reviewer meminta konfirmasi (capability revoked); tidak ada worker_done dari konteks itu. Review final menunggu dispatch baru.

---

## Review inkremental 3 — tindak lanjut N01–N03 + tes durabilitas (11 Oktober 2026)

Perubahan baru sejak review 2 dinilai. Dispatch ctx_fd5300f8fc3d dicabut runtime sejak awal (heartbeat pertama sudah "capability revoked"), sehingga temuan ini disampaikan lewat file; tidak ada pesan orchestration yang mungkin.

### Teratasi

| Item | Bukti |
| --- | --- |
| N01 (Redis AUTH) | `compose.production.yml` kini mewajibkan `REDIS_PASSWORD`/`REDIS_CACHE_PASSWORD` via `${VAR:?...}` untuk staging/production; `infra.yml` mengisi nilai CI untuk `compose config`. |
| N02 (prefix) | Default compose kini `takeda:${APP_ENV}:`; prefix lama eksplisit tetap dihormati (komentar). |
| Kebocoran exception | `app/Exceptions/OutboxConsumerFailed.php` membungkus exception consumer menjadi kelas+SQLSTATE saja (tanpa SQL/binding — komentar eksplisit soal ID pelapor anonim); `ProcessOutboxEvent::handle` melempar bungkusan ini sehingga `failed_jobs.exception`/log aman. Menutup catatan README review 2. |
| Fail-fast unknown type | `middleware()` `FailOnException([UnknownOutboxEvent::class])` — jenis tak dikenal langsung diparkir tanpa 5 retry. |
| Durabilitas pembayaran offline | `AsyncOutboxTest::test_offline_payment_stays_single_through_queue_outage_retry_and_redelivery`: bayar → antrean mati (enqueue gagal, event pending, ledger tetap 1) → replay idempotency (tanpa posting kedua) → antrean pulih → delivery ganda → inbox tetap 1 dan keuangan tidak berubah. Sangat sesuai TESTING.md skenario 10/12. |
| N03 (drill worker nyata) | `infra/scripts/m03-recovery-ci.sh`: worker `queue:work` proses nyata vs Redis loopback + Postgres *_test; menguji heartbeat worker, kill/restart (verify tanpa worker WAJIB gagal lalu sukses setelah hidup), duplikasi LPUSH mentah → satu inbox, restart AOF (job+sentinel bertahan → inbox), queue-loss (hapus hanya kunci notifications, sentinel tetap, replay stale 5 dtk → inbox), heartbeat scheduler via `schedule:work`. Guard: pola `*_test` pada 127.0.0.1/localhost/postgres + penolakan eksplisit host Neon; tanpa FLUSHALL; syntax `bash -n` OK. |

### Blocker baru (ditemukan reviewer)

| ID | Severity | Bukti |
| --- | --- | --- |
| B04 | **high — CI infra akan merah** | `infra/scripts/check-m03-compose.mjs:66` menolak pola `6379:6379` di compose.local, tetapi `infra/compose.local.yml:31-32,48-49` kini memetakan `127.0.0.1:6379:6379` dan `127.0.0.1:6380:6379` (loopback — masuk akal untuk dev lokal). Diverifikasi reviewer: `node infra/scripts/check-m03-compose.mjs` → exit 1 "compose local mempublikasikan 6379". Kontrak statis dan compose bertentangan; salah satunya harus disesuaikan (mis. regex mengizinkan loopback). Kedua file milik Grok. |
| B05 | **high — drill tidak menghasilkan bukti CI** | `m03-recovery-ci.sh` tidak dipanggil workflow mana pun (`grep` .github/workflows hanya menemukan `ci-redis.sh` di api.yml:47). Tanpa langkah CI yang menjalankannya, gate kill/restart, duplikat, AOF app-level, dan queue-loss tetap tanpa bukti eksekusi. |

### Catatan minor

- N04: header `compose.local.yml` masih menyatakan "Redis antrean/cache tidak memetakan port host" — sudah tidak benar setelah penambahan mapping loopback.

### Verdict review 3

N01/N02/N03 (sebagai script) teratasi; tes durabilitas keuangan-async kuat. **Gate M03 tetap PENDING**: B04 harus diperbaiki (CI infra saat ini pasti merah), B05 perlu wiring drill ke workflow, lalu seluruh gate dinilai dari hasil CI aktual setelah Codex commit/push.
