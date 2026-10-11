# M04 — eksekusi staging

11 Oktober 2026, coordinator Codex, Chrome/Konsol Hostinger dengan otorisasi
pemilik. Status awal: **in_progress**, belum gate live PASS.

Target source rollout: `62a863fd000582b740f992a3daa46bd67ae89f13`.
Review koreksi Claude tidak menemukan blocker kode; lihat
[CLAUDE_INFRA_REVIEW.md](CLAUDE_INFRA_REVIEW.md). Preflight:
[PREFLIGHT.md](PREFLIGHT.md). CI: [CI_RESULTS.md](CI_RESULTS.md).

Helper staging didownload dari URL raw GitHub ber-SHA tersebut ke
`/root/rt05-m04-62a863f-auth.py`. Checksum console sama dengan sumber lokal:
`154c193228e1a6d3e59c64aecf263b60816482a2ad0c2664c91c22e833c0acd6`.
Ini bukti kesesuaian artefak, belum bukti provisioning atau rollout.

`python3 /root/rt05-m04-62a863f-auth.py` benar-benar dijalankan di console.
Hasil: `Provisioned staging keys: REDIS_PASSWORD, REDIS_CACHE_PASSWORD;
mode 600; existing other values preserved.` Tidak ada nilai secret dicetak.
Ini provisioning Redis staging, bukan rotasi Neon/APP_KEY production.

Deploy script didownload dari SHA yang sama; SHA256 console cocok dengan blob
Git dan sumber lokal (LF tanpa CR):
`11c06f68009932a962701090d1545761f66576fd176181d45369873c2508fbba`.
Sesudah lima CI check SUCCESS dan review koreksi, coordinator memulai script
staging lewat `nohup`, PID console `2213465`, log root privat dengan umask 077.
Target tetap project `rt05-staging`; pekerjaan dapat bertahan bila console
terputus. Hasil rollout belum dinyatakan sampai marker sukses/metadata/health
benar-benar diperiksa.

Percobaan pertama membangun kedua image SHA `62a863f` dan menaikkan Redis
AUTH/cache dengan volume existing, kemudian berhenti: Compose 5.6.0 menolak
`run --no-build` (exit 1). Belum ada migration/up API baru pada percobaan
ini; tidak menyatakan gate lulus. Opsi one-shot dibetulkan menjadi
`run --rm --no-deps --pull never` sesuai
[Docker Compose run](https://docs.docker.com/reference/cli/docker/compose/run/).
`--no-build` tetap dipakai hanya untuk `up`. CI kini memeriksa opsi CLI dan
menolak opsi yang salah pada perintah aktif; syntax-only tidak cukup.

Pemulihan memakai checkout/image `62a863f` yang sudah diuji, tanpa edit file
di release: coordinator menjalankan Compose one-shot dengan opsi benar lalu
`up -d --no-build --remove-orphans` hanya project `rt05-staging`.
Migration `2026_10_11_000700_outbox_consumer_failure` DONE (166.38 ms), grant
DML runtime berhasil; tidak menjalankan seed/factory/fresh/wipe. API/web,
scheduler dan tiga worker started; Redis antrean/cache healthy. Worker tunggal
legacy di project ini dihapus sebagai orphan. GET eksternal `/health/ready`
HTTP 200, `ssl_verify_result=0`. Smoke runtime berikutnya masih pending.

Smoke release `62a863f` kemudian benar-benar lulus (PID 2250390), log privat
`/root/rt05-m04-62a863f-smoke.log`: event sintetis
`01a12983-985f-70c1-ba71-19d2e2547421` published attempts=1 inbox=0 error_code=none
sebelum Redis antrean restart; sentinel AOF bertahan, lalu completed attempts=1
inbox=0 error_code=none. Tidak memerlukan replay. Empat heartbeat ok:
worker-default age=2s, media=9s, exports=27s, scheduler=15s.
Enam service bertag SHA yang sama; worker/scheduler memakai image ID API sama.
Tiga volume existing beserta CreatedAt tetap; 13 container di luar project
tetap ID/StartedAt. Marker final `PASS staging-runtime-smoke` terlihat di konsol.
DbSmoke direct: PostgreSQL 17.11, role rt05_owner, sslmode verify-full,
tulis+baca ok, sisa rollback 0. Bukti runtime pooled akan diambil dari bagian
awal log sebelum penutupan gate.

Checkpoint sebelum operasi final: rollout otomatis `12b4642` saat itu sedang
disiapkan, belum PASS. Hasil penutup actual ada di bagian berikut.

## Rollout dan smoke final 12b4642

Script final didownload dari blob `12b46420049d2fb24e5ea231b2488c901f4ce007`;
SHA256 lokal Git blob dan remote cocok:
`ba4170d04a4bcc195b60ea14039c72376784e8bbad3537c824d4d509dc2a19b1`.
`nohup bash /root/rt05-m04-12b4642-deploy.sh <SHA>` PID 2889456 benar-benar
selesai (job Done, marker Selesai staging SHA). Worktree release baru bersih,
current-sha ditulis setelah readiness/heartbeat/image/volume/unrelated gates.
Image API, web, tiga worker dan scheduler memakai tag SHA final yang sama.
Runtime smoke PID 2895206 pada SHA aktif final juga `PASS staging-runtime-smoke`:
completed attempts=1 inbox=0 error_code=none, worker-default/media/exports
age=1/3/4s, scheduler=42s. Tiga volume CreatedAt tidak berubah; 13 container
lain tetap ID/StartedAt, service staging tidak membuka port host.

DbSmoke pooled final: PostgreSQL 17.11, role rt05_app, application_name rt05-api,
endpoint pooled, sslmode verify-full, tulis+baca ok, sisa rollback 0, DDL denied.
Direct memakai rt05_owner/rt05-migrations, verify-full, rollback 0; tidak
menganggap pemeriksaan DDL owner sebagai role minimal. Tidak ada seed/factory.

GET dari PC di luar VPS dengan verifikasi TLS default (tanpa bypass):

| Rute | Hasil |
| --- | --- |
| /health/live | 200, status ok, request_id 880d4bad-e0be-4c64-b918-871fa99e56d5 |
| /health/ready | 200, status ready/database ok, request_id 297966f1-4a9f-466c-b5c3-4d960c00ed8e |
| /api/v1 | 200, request_id 72c108e8-776e-4ba6-b669-d0ce89dc618f |
| /, /profil, /artikel, /dokumentasi | 200; profil memuat Agus Ferdiansyah; beranda/profil tidak memuat URL aset HTTP host staging |
| /api/v1/auth/me tanpa token | 401, request_id e8115586-7bc6-4e4e-a479-229ef33901ed |
| rute API tidak ada | 404, request_id 6ed08054-7be6-4379-8da0-bc50723daf26 |
| /api/v1/profil | 404 expected, API konten publik M13 belum dibuka; /profil website berfungsi |

Metadata Neon connector dibaca ulang: branch staging `br-holy-bread-b300uftg`
terpisah dari dev `br-crimson-art-b31mz59n` dan production
`br-quiet-band-b3ba230a`; compute staging `ep-autumn-breeze-b3v12sri`, active.
Pemeriksaan host env hanya mencari substring host staging yang sudah diketahui,
bukan mencetak URL/credential. Hasil actual match: dua host staging pooled dan
direct persis compute yang ditemukan connector; tidak memakai dev/production.
Event final `01a12a97-a934-73b8-b2cc-c5fffdb0f952`: published attempts=1
inbox=0 error_code=none sebelum restart, sentinel AOF bertahan, kemudian
completed attempts=1 inbox=0 error_code=none; PASS. Diff bagian unrelated
ID/name/StartedAt dari log deploy awal62 sebelum mutasi dengan baseline deploy12
kosong (`comparison=0`); assertion deploy12/smoke12 kemudian juga lulus.
Preflight rollout final: Ubuntu 24.04.5/kernel 6.8.0-139, Docker 29.8.2,
Compose 5.6.0, disk 96G/18G used/79G available (19%), MemAvailable 5619 MiB.
Empat patch routing source legacy tetap M pada path yang sama. Berkas pointer
actual: current `12b46420049d2fb24e5ea231b2488c901f4ce007`, previous
`62a863fd000582b740f992a3daa46bd67ae89f13`. Rollback kompatibel tersedia
namun tidak dieksekusi; image legacy dan kedua worktree dipertahankan.

**Gate fungsional live M04 terpenuhi.** Review akhir Grok sebagai pengganti
Kimi yang terkena limit juga menerima gate ini pada
[GROK_ACCEPTANCE_REVIEW.md](GROK_ACCEPTANCE_REVIEW.md). Rotasi credential
DB/APP_KEY insiden historis masih terbuka dan pernah ditolak pengaman otomatis
(alasan secret store); tidak dicoba lewat jalur lain. Ini keterbatasan keamanan
staging/demo yang tetap dicatat, dan menghalangi klaim production siap M16.

Semua nilai credential tetap privat; catat hanya nama key/status, SHA/image
ID, volume, StartedAt, dan hasil smoke yang tersanitasi. Tidak ada tindakan
production/DNS/firewall atau perubahan service lain yang termasuk tahap ini.

## Batas operasi

Rollout pertama dari stack lama belum mempunyai target rollback otomatis
yang kompatibel. Image/tree legacy tetap dipertahankan; tidak rollback Redis
ke konfigurasi tanpa AUTH. Restart yang diuji M04 terkontrol, berbeda dari
crash/kill drill M03 di CI. Jika container lain restart sendiri selama jendela
tes, assertion StartedAt akan gagal dan perlu pemeriksaan sebab; tidak otomatis
membuktikan gangguan oleh deployment RT05.

Temuan existing tentang DSN migrator di container API dicatat reviewer untuk
hardening M16. Ini bukan bukti production siap atau rotasi credential selesai.
