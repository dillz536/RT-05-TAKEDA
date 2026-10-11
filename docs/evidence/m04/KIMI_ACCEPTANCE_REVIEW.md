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
