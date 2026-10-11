# M03 — hasil eksekusi CI

Commit diuji: `0d678bb`, PR [#15](https://github.com/morrispes5/RT-05-TAKEDA/pull/15).
Tanggal: 11 Oktober 2026. Dibaca langsung dari status dan log Actions oleh Codex.
Seluruh data sintetis; PostgreSQL 17 dan Redis 7.4 berada di runner GitHub.

| Pemeriksaan | Hasil dan bukti |
| --- | --- |
| [API verification](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38112554974) | SUCCESS; 88 tes/727 assertion; Pint dan OpenAPI lulus |
| Redis queue/cache | ci-redis: policy, AOF restart dan hapus kunci prefix OK; sentinel/cache tidak terhapus |
| Commit lalu worker mati | Worker PHP dijeda sebelum enqueue; DB inbox=0 dan published belum completed diperiksa sebelum SIGKILL; worker baru → inbox=1/completed |
| Dua delivery | Job digandakan pada Redis; kedua delivery drain; inbox=1/completed |
| AOF everysec | Pending job dan sentinel bertahan setelah restart Redis; consumer → inbox=1/completed |
| Queue hilang → replay | Hanya empat kunci notifications CI dihapus; sentinel bertahan; replay stale → attempts=2, inbox=1/completed |
| Heartbeat proses | worker-default ok age=0s; schedule:work → scheduler ok age=3s |
| Runtime/migrator | rt05:db-smoke keduanya SUCCESS; tulis/baca, baris tersisa setelah rollback=0; runtime DDL ditolak |
| [Infra images](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38112554907) | SUCCESS; Compose auth/private ports, Bash syntax, build API/web dan smoke gateway |
| Smoke image tanpa DB | live 200; ready 503; /api/v1 valid; /profil memuat ketua yang benar; rute API tak dikenal 404 |
| [Repository hygiene](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38112554910) | SUCCESS |
| Vercel preview/comment checks | Keduanya SUCCESS; source website tidak berubah |

Drill berhasil pukul 04:46:03 UTC dengan keluaran
`m03-recovery: kill/restart, duplikat, AOF, queue-loss, heartbeat OK`.
Ini bukti gate M03 pada CI, bukan bukti deployment VPS/Neon staging baru.
Gangguan OS/server, batas memori dan restart persistence VPS tetap M04.
