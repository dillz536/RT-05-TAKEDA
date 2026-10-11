# KIMI Independent Acceptance Review — M04 (staging dan bukti operasi VPS)

- Reviewer: Kimi (independent review; bukan implementator)
- Branch/baseline: `codex/m04-staging-recovery`; baseline PR #15 merged `27a3e5b`; handoff backend ter-commit `75dee91`.
- Tanggal: 11 Oktober 2026.
- Acuan gate: docs/ROADMAP.md M04 ("Gate live"), docs/COORDINATION.md (pembagian M04), docs/DEPLOYMENT.md.
- Batas review: baca-saja. Tidak ada edit di luar file ini, tidak ada Git mutation, tidak ada aksi server/Neon/DNS. Status gate mengikuti bukti aktual, bukan ketersediaan kode.

## Lingkup penilaian tahap ini

Deploy staging spesifik-env + auth helper, command berguard, image immutable same-SHA untuk api/web/worker, preservasi patch server yang belum di-commit, persistensi volume project yang sama, tanpa perubahan unrelated/production, tanpa kebocoran secret/log.

## Matrix gate M04 (ROADMAP gate live)

| # | Gate | Status | Bukti/catatan |
| --- | --- | --- | --- |
| L1 | HTTPS + TLS valid staging | PENDING | Preflight: proxy Coolify memegang 80/443; rollout baru belum terjadi |
| L2 | API routing + web routes dari luar VPS | PENDING | Menunggu deploy |
| L3 | Neon staging read/write sintetis | PENDING | `rt05:staging-smoke` siap, belum dijalankan di VPS |
| L4 | Worker/scheduler heartbeat di VPS | PENDING | `rt05:heartbeat-check` siap; belum ada bukti live |
| L5 | Restart persistence (Redis AOF, volume) | PENDING | Perlu redeploy dengan service redis/redis-cache baru; volume `redis-data` harus dipakai ulang (kontrak M03) |
| L6 | Tidak ada gangguan service lain (Coolify dsb.) | PENDING | Preflight menyatakan service lain berjalan; penilaian setelah rollout |
| L7 | Smoke dari luar VPS | PENDING | — |

**Semua gate live PENDING** sampai hasil CI commit ini dan laporan deploy Hostinger aktual ada.

## Review handoff Claude (ter-commit 75dee91)

`rt05:staging-smoke` (`rest-api/app/Console/Commands/StagingSmoke.php`) + `tests/Feature/StagingSmokeTest.php`:

| Aspek | Penilaian |
| --- | --- |
| Guard command | Kuat dan fail-closed: `APP_ENV === 'staging'` persis + `--confirm-staging` wajib, dievaluasi **sebelum** akses DB — tes membuktikan 0 query pada penolakan (query log). Argumen invalid exit 2 tanpa DB. |
| Tanpa efek samping | prepare menulis satu event `rt05.smoke` dengan `penerima: []` — tes mengassert jumlah akun/notifikasi/pembayaran/kas/tagihan tidak berubah; handler `rt05.smoke` dengan penerima kosong tidak membuat inbox. |
| Kebocoran | Stdout prepare hanya UUID; verify hanya `state/attempts/inbox/error_code=present\|none`; exception hanya nama kelas (komentar eksplisit soal DSN); tes mengassert output bebas `penerima`/`penanda`/`pgsql`/`postgres`. |
| Pemisahan dari guard CI | `rt05:async-smoke` (khusus `*_test` lokal) tidak diubah; hanya komentar di `Notifications.php` yang berubah pada file lama. |
| CLI saja | Tidak ada rute HTTP baru (pernyataan laporan; konsisten dengan tidak adanya perubahan `routes/`). |
| Kelemahan diketahui (diakui laporan) | Guard bergantung `APP_ENV` config; baris event sintetis tersisa di outbox staging (completed, tanpa data warga); verify bergantung scheduler+worker hidup. Diterima sebagai desain sadar, dicatat di laporan Claude. |

Verifikasi lokal reviewer: `php -l` kedua file bersih; `check-repo.mjs` OK (475 berkas, tanpa pola secret). **StagingSmokeTest belum punya hasil CI** — run API verification untuk `75dee91` (run 38113599864) masih berjalan saat review ini; penilaian tes menunggu hasilnya.

## Review preflight VPS (docs/evidence/m04/PREFLIGHT.md, Codex)

Konsisten dan relevan gate:

- Enam service staging existing sehat; Redis hanya internal; service Coolify/lain tidak disentuh.
- **Empat patch server belum di-commit dipertahankan** (`infra/compose.production.yml`, `compose.vps.yml`, `gateway.Caddyfile`, `website/bootstrap/app.php`) — deploy wajib memakai checkout release lain, bukan menimpa `/opt/rt05-takeda`. Ini prasyarat yang harus dibuktikan handoff Grok.
- **`REDIS_PASSWORD`/`REDIS_CACHE_PASSWORD` = missing di env staging**, sedangkan compose baru mewajibkan keduanya (`:?`). Rollout akan gagal `compose config` sebelum credential Redis staging diprovision. Ini blocker teknis yang sudah teridentifikasi dengan benar — provisioning credential baru khusus Redis staging diperlukan; tidak melonggarkan AUTH dan tidak menyentuh credential Neon/APP_KEY production (insiden sesi 5–6 tetap open).

## Blocker penerimaan saat ini

| ID | Severity | Bukti |
| --- | --- | --- |
| M04-B1 | high | Handoff infra Grok **belum ada di working tree/HEAD**: belum ada yang dapat direview untuk image immutable same-SHA (api/web/worker dari SHA yang sama), skrip deploy checkout-baru yang mempertahankan patch kotor server, maupun persistensi volume project yang sama (`redis-data`, `api-storage`, volume compose lain). `git status` pada `infra/` dan `.github/` kosong. |
| M04-B2 | high | Credential Redis staging belum diprovision (preflight: missing) — compose baru wajib nonempty; tanpa ini deploy gagal atau dilonggarkan (tidak boleh). |
| M04-B3 | medium | Hasil CI `75dee91` belum lengkap: API verification (38113599864) dan Infra images (38113599888) berjalan; hanya Repository checks success. StagingSmokeTest belum terbukti. |
| M04-B4 | medium | Seluruh gate live (L1–L7) menunggu deploy aktual + laporan Hostinger; tidak boleh diklaim dari kode. |

## Cek privacy/finance

- Smoke staging by-design tanpa akun/inbox/keuangan; payload hanya marker `staging-smoke`; tanpa PII.
- Tidak ada perubahan jalur keuangan/anonimitas pada diff M04 sejauh ini (satu-satunya perubahan kode lama: komentar handler).
- Tidak ada credential pada file baru (check-repo + inspeksi manual); PREFLIGHT hanya mencatat keberadaan/mode file env, tanpa nilai.

## Verdict checkpoint

Implementasi backend M04 (staging-smoke berguard) **dinilai baik secara statis**, tetapi **seluruh gate M04 PENDING**: menunggu handoff infra Grok (M04-B1), provisioning credential Redis staging (M04-B2), hasil CI `75dee91` (M04-B3), dan bukti deploy live Hostinger (M04-B4). Reviewer siap menilai handoff Grok dan bukti live segera setelah tersedia; tidak ada klaim keberhasilan dari kode saja.

---

## REVIEW FINAL M04 — setelah handoff Grok + bukti live (11 Oktober 2026)

Catatan provenance Codex: audit tambahan ini tiba setelah PR16 merged melalui
konteks task M04 yang sudah settled; Orca menolak completion `inactive_dispatch`.
Isinya disimpan sebagai audit postmerge atas dokumen/sumber, bukan completion
orchestration baru atau bukti reviewer menjalankan operasi VPS. Gate M04 telah
diterima melalui review Grok yang tercatat sebelum merge; tidak ada redeploy.

Checkpoint di atas sudah stale; bagian ini menggantikannya. M04 telah dieksekusi ke VPS dan di-merge sebagai PR #16 (`3a8be79`); staging aktif menjalankan SHA `12b4642`.

### Penutupan blocker checkpoint

| ID | Status | Bukti |
| --- | --- | --- |
| M04-B1 (infra Grok belum ada) | **CLOSED** | `infra/scripts/deploy-staging-immutable.sh`, `staging-metadata.sh`, `staging-runtime-smoke.sh`, `prepare-staging-redis-auth.py` (+`test_staging_redis_auth.py`), `staging-metadata.sh`, dan pin `image: rt05-api/web:${RT05_IMAGE_TAG:...}` di compose — direview penuh, lihat penilaian di bawah. |
| M04-B2 (credential Redis staging missing) | **CLOSED** | `prepare-staging-redis-auth.py` benar-benar dijalankan di console: "Provisioned staging keys: REDIS_PASSWORD, REDIS_CACHE_PASSWORD; mode 600; existing other values preserved" (LIVE_RESULTS.md); tanpa nilai dicetak. |
| M04-B3 (CI 75dee91 belum lengkap) | **CLOSED** | CI_RESULTS.md: API run 38113599864 SUCCESS **93 tes/766 assertion** termasuk 5 StagingSmokeTest; lima check SUCCESS pada 75dee91, dan lagi pada 03b4ac6, 62a863f, 12b4642, 9cad5de. Reviewer memverifikasi independen via `gh run list`: ketiga workflow success pada 12b4642 dan 9cad5de. |
| M04-B4 (gate live) | **CLOSED oleh bukti coordinator** | LIVE_RESULTS.md mencatat rollout aktual + smoke PASS; reviewer tidak menyaksikan langsung tetapi menilai bukti terdokumentasi lengkap, spesifik (PID, SHA256 artefak, marker), dan konsisten dengan desain script yang direview. |

### Penilaian script infra Grok (checklist tugas)

| Item | Penilaian |
| --- | --- |
| Guard khusus staging | `deploy-staging-immutable.sh`: menolak argumen "production"; `require_identity` APP_ENV=staging, RT05_ENV staging/stg (alias existing), RT05_HOST persis host staging; env wajib ada, mode 600, bukan symlink; menolak Redis terpublikasi. Smoke script mengulangi guard yang sama. |
| Immutable same-SHA api/web/workers | Build hanya `api web` dengan `RT05_IMAGE_TAG=<sha>`; `assert_staging_images` membuktikan keenam service memakai tag SHA sama dan worker/scheduler memakai **image ID** yang sama dengan api; `up --no-build`, `run --pull never` (koreksi live Compose 5.6.0 didokumentasikan). |
| Preservasi pohon legacy kotor | Rilis di `/opt/rt05-releases/<sha>` via bare clone + worktree detach; `/opt/rt05-takeda` tidak pernah di-fetch/ditimpa/dijadikan rilis; worktree kotor → ditolak, bukan di-reset; SHA wajib ancestor `main` atau branch M04 coordinator (anti fork/PR arbitrer). |
| Volume project sama | `list_staging_volumes`/`assert_staging_volumes`: tiga volume existing (`redis-data`, `api-storage`, `apk-downloads`) diverifikasi Name+CreatedAt tidak berubah dan ter-mount pada service yang benar; rollback tidak menghapus volume. |
| Unrelated StartedAt tidak berubah | `list_unrelated`/`assert_unrelated` membandingkan ID/name/StartedAt seluruh container non-project sebelum/sesudah; LIVE_RESULTS mencatat 13 container lain tetap (comparison=0). |
| Bounded checks | Semua loop berbatas: ready 120 dtk, heartbeat 180 dtk, PONG 120 dtk; smoke verify timeout 120. |
| AUTH provisioning fill-missing-only | Python: tanpa argumen/path, root+Linux saja, `O_NOFOLLOW`, mode 600 dipertahankan, hanya mengisi key kosong, replace atomik dengan deteksi perubahan konkuren, output hanya nama key; menolak key nonkanonik/duplikat; tidak pernah mencetak nilai. |
| Kompatibilitas rollback | `staging-previous-sha` dicatat dari image api berjalan; `rollback` men-deploy ulang SHA itu tanpa menghapus volume; pointer current/previous terdokumentasi di LIVE_RESULTS; rollback tidak dieksekusi (dicatat jujur). |

### Bukti live (dokumen coordinator, LIVE_RESULTS.md)

Rollout `12b4642`: migration additive DONE, grant DML ok; runtime smoke `PASS staging-runtime-smoke` dua kali (62a863f dan 12b4642) — marker published sebelum restart Redis, sentinel AOF everysec bertahan, completed attempts=1 inbox=0 (tanpa replay); empat heartbeat ok; enam service satu tag SHA; tiga volume CreatedAt tetap; 13 container luar project tetap; DbSmoke pooled/direct verify-full dengan sisa rollback 0; GET eksternal TLS default: /health/live, /health/ready, /api/v1, /, /profil, /artikel, /dokumentasi 200; /api/v1/auth/me 401; rute tak dikenal 404; /profil memuat Agus Ferdiansyah (fakta RT benar). Branch Neon staging terpisah dari dev/production diverifikasi ulang lewat metadata connector.

### Catatan residual (bukan gate M04)

- R01: Rollback otomatis ke stack pra-M04 tidak tersedia (rollout pertama); image/tree legacy dipertahankan manual — dicatat di "Batas operasi".
- R02: DSN migrator hadir di container API — temuan hardening untuk M16, diakui LIVE_RESULTS.
- R03: Insiden credential sesi 5–6 tetap **open** (rotasi Neon/APP_KEY belum, pernah ditolak pengaman); menghalangi klaim production M16, bukan gate M04.
- R04: Reviewer tidak menyaksikan console Hostinger secara langsung; penerimaan live mengandalkan dokumen bukti coordinator + review Grok (pengganti saat reviewer limit) + konsistensi dengan script yang direview reviewer.

### Jawaban final reviewer M04

**Gate live M04 (HTTPS, routing, Neon read/write sintetis, heartbeat worker/scheduler, restart persistence AOF, tanpa gangguan service lain, smoke eksternal) terpenuhi berdasarkan bukti terdokumentasi yang konsisten dengan implementasi yang direview independen.** Tidak ada blocker penerimaan tersisa untuk M04. Rekomendasi: M04 **passed**; keterbatasan R01–R04 diteruskan ke PROGRESS. Staging menjalankan SHA `12b4642`; production/M05+ bukan cakupan review ini.
