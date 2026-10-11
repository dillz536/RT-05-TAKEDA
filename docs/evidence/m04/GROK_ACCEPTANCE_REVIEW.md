# Penerimaan independen M04 — Grok

Tanggal: 11 Oktober 2026. Reviewer: Grok, menggantikan Kimi pada penerimaan M04 karena kuota provider Kimi (403, batas 5 jam). Sesi Kimi tidak ditutup dan berkas [KIMI_ACCEPTANCE_REVIEW.md](KIMI_ACCEPTANCE_REVIEW.md) tidak diedit. Review ini baca-saja. Tidak ada deploy, perubahan secret, commit, atau pindah branch.

Dasar pohon: branch `codex/m04-staging-recovery`, HEAD `12b46420049d2fb24e5ea231b2488c901f4ce007`, PR #16. Dibaca: AGENTS, [ROADMAP.md](../../ROADMAP.md) bagian M04, [PROGRESS.md](../../PROGRESS.md), [CAPSTONE_ALIGNMENT.md](../../CAPSTONE_ALIGNMENT.md), [CI_RESULTS.md](CI_RESULTS.md), [CLAUDE_INFRA_REVIEW.md](CLAUDE_INFRA_REVIEW.md), [LIVE_RESULTS.md](LIVE_RESULTS.md), [PREFLIGHT.md](PREFLIGHT.md), serta skrip deploy/smoke pada HEAD ini. Angka live disalin dari bukti coordinator, bukan dari inspeksi VPS sesi ini.

## Verdict

**Gate fungsional live M04 terpenuhi pada rilis `12b4642`.** HTTPS, rute web, routing API, Neon read/write, heartbeat, persistensi restart terkontrol, dan tidak adanya gangguan service lain sudah tertulis. Ini bukan kesiapan production. Rotasi password `rt05_owner` dan APP_KEY dari insiden sesi 5–6 tetap blocked pada pemilik. Rollback ke SHA sebelumnya tersedia dan tidak dijalankan.

Paragraf awal [LIVE_RESULTS.md](LIVE_RESULTS.md) masih berkata rollout `12b4642` belum PASS. Bagian “Rollout dan smoke final 12b4642” yang lebih bawah adalah catatan penutup dan mengalahkan kalimat itu.

## Bukti penutup yang dipakai

- Skrip blob `12b46420049d2fb24e5ea231b2488c901f4ce007`, SHA256 `ba4170d04a4bcc195b60ea14039c72376784e8bbad3537c824d4d509dc2a19b1`, cocok dengan blob Git lokal dan remote.
- Deploy PID 2889456 selesai (job Done). `current-sha` adalah `12b4642`. `previous` adalah `62a863fd000582b740f992a3daa46bd67ae89f13`.
- Smoke PID 2895206 mencetak `PASS staging-runtime-smoke`. Event `01a12a97-a934-73b8-b2cc-c5fffdb0f952`: published attempts=1, inbox=0, error_code=none sebelum restart; sentinel AOF bertahan; completed dengan attempts yang sama dan inbox=0.
- Heartbeat smoke itu: worker-default 1s, media 3s, exports 4s, scheduler 42s.
- Image API, web, tiga worker, dan scheduler memakai tag SHA yang sama.
- Tiga volume mempertahankan CreatedAt. Perbandingan unrelated deploy awal 62 dengan baseline deploy 12 kosong (`comparison=0`). Assertion deploy 12 dan smoke 12 lulus. Service staging tidak membuka port host.
- Empat patch routing pada pohon legacy `/opt/rt05-takeda` masih termodifikasi pada path yang sama.
- DbSmoke pooled: PostgreSQL 17.11, role `rt05_app`, `application_name=rt05-api`, endpoint pooled, verify-full, tulis dan baca ok, sisa rollback 0, DDL ditolak.
- DbSmoke direct: `rt05_owner` / `rt05-migrations`, verify-full, rollback 0. DDL pada role owner tidak dianggap bukti role minimal.
- Host env pooled dan direct cocok dengan compute staging `ep-autumn-breeze-b3v12sri`. Bukan host dev atau production. Branch staging `br-holy-bread-b300uftg` terpisah dari dev `br-crimson-art-b31mz59n` dan production `br-quiet-band-b3ba230a`.
- Headroom rollout final: Ubuntu 24.04.5, kernel 6.8.0-139, Docker 29.8.2, Compose 5.6.0, disk 96G dengan 18G terpakai dan 79G tersedia, MemAvailable 5619 MiB.
- GET dari PC, TLS bawaan, tanpa bypass:

| Rute | Hasil |
| --- | --- |
| `/health/live` | 200, status ok |
| `/health/ready` | 200, database ok |
| `/api/v1` | 200 |
| `/`, `/profil`, `/artikel`, `/dokumentasi` | 200; profil memuat Agus Ferdiansyah; tanpa URL aset HTTP host staging |
| `/api/v1/auth/me` tanpa token | 401 |
| rute API yang tidak ada | 404 |
| `/api/v1/profil` | 404 yang diharapkan. Konten publik M13 belum dibuka. `/profil` website berfungsi |

Rilis `62a863f` juga lulus smoke, tetapi percobaan skripnya berhenti di `run --no-build` dan dilanjutkan manual. `12b4642` adalah jalur skrip yang selesai.

## Gate ROADMAP

| Gate | Keputusan |
| --- | --- |
| HTTPS | Lulus. Beberapa rute 200 dengan TLS bawaan. |
| Routing API dari luar VPS | Lulus. `/api/v1` 200, tanpa token 401, rute asing 404. |
| Rute web dari luar VPS | Lulus. Empat halaman 200, nama ketua tampil, aset host tidak HTTP. |
| Neon read/write terisolasi | Lulus untuk staging. Pooled dan direct rollback 0, DDL runtime ditolak, host cocok dengan compute staging, branch terpisah dari dev dan production. |
| Heartbeat worker/scheduler | Lulus pada smoke `12b4642`. |
| Persistensi restart | Lulus untuk restart terkontrol. Sentinel AOF dan attempts=1 tertulis untuk event final. Bukan drill kill -9; drill itu tetap milik CI M03. |
| Tidak mengganggu service lain | Lulus pada jendela yang dibandingkan. `comparison=0`, lalu assertion deploy dan smoke lulus. |
| Smoke dari luar VPS | Lulus untuk rute di tabel. |
| Dokumentasi runtime, port, versi, headroom, rollback | Lulus sebagai dokumentasi. Port host staging tidak dibuka. Rollback ke `62a863f` tersedia dan tidak dieksekusi. Kembali ke image legacy tanpa AUTH tidak tersedia. |
| Data warga nyata dan domain production | Tidak dilakukan. Sesuai batas M04. |

## Yang sengaja tidak disebut lulus

- Production, M16, dan rotasi credential historis. Pengaman pernah menolak penulisan secret store. Tidak dicoba lewat jalur lain.
- Latihan rollback yang benar-benar dijalankan.
- Least privilege DSN: container API masih menerima URL direct/owner. Itu catatan hardening M16 di bukti, bukan gagal staging.
- Push, SMTP, cloud storage, dan selisih perencanaan di bawah.
- M05. Tidak ada implementasi baru di review ini.
- `/api/v1/profil` 404 bukan cacat rute website.

## Kode pada HEAD yang sama

Cukup untuk mengikat bukti, bukan audit ulang seluruh infra.

- Migrasi one-shot memakai `run --rm --no-deps --pull never`. Tidak ada `run --no-build`.
- Workflow infra menolak `run --no-build` pada baris aktif.
- Identitas staging tetap `APP_ENV=staging`, host `takeda-staging.morriz.tech`, `RT05_ENV` hanya `staging` atau `stg`, project `rt05-staging`.
- SHA harus leluhur `main` atau branch M04 ini.
- Skrip deploy yang dibaca tidak memuat `FLUSH`, `down -v`, atau `volume rm`.

## CI

Angka dari [CI_RESULTS.md](CI_RESULTS.md). Sesi ini tidak membuka GitHub Actions lagi. `12b4642`: Infra run 38115505350, API 38115505380, Hygiene 38115505386, Vercel, dan Preview Comments SUCCESS. CI membuktikan opsi CLI dan build. Bukti VPS di atas terpisah dari CI.

## Selisih Drive dan perencanaan

[CAPSTONE_ALIGNMENT.md](../../CAPSTONE_ALIGNMENT.md) melaporkan selisih secara eksplisit, bukan sebagai sesuatu yang sudah diseragamkan. Tabelnya memuat lokasi administrasi, persetujuan registrasi utama, masa token, DECIMAL(14,2) versus bigint rupiah, `warga_id` yang boleh null, nama tabel operasi di luar 31 tabel ERD, foto di volume bukan cloud, push yang belum ada, salinan Logbook 3 yang terbaca masih kosong, dan dua aplikasi Laravel. M04 tidak menutup selisih itu. Staging tetap memakai volume `api-storage`. Dokumen juga menulis bahwa selisih tersebut bukan izin membuka M06–M16.

## Batas pemeriksaan lokal

`node infra/scripts/check-repo.mjs`: `OK: 487 berkas dilacak; tanpa file terlarang, pola secret, .git bersarang, atau tautan relatif rusak; vercel.json setara.` Docker lokal tidak dijalankan. Tidak ada rahasia yang dibaca atau ditulis.
