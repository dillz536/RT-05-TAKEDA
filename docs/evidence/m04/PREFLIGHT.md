# M04 — preflight staging

11 Oktober 2026, Codex memakai Chrome Hostinger/Konsol web dengan otorisasi
pemilik. Pemeriksaan awal bersifat baca saja; belum deploy kode M03/M04.

- Ubuntu 24.04.5 LTS, kernel 6.8.0-139-generic; login console berhasil.
- Ringkasan console: memori 30%, root disk 17.4% dari 95.82 GB, swap 0%.
  `free -m`: total 7940 MiB, available 5614 MiB; `df -h /`: 96G total,
  17G used, 80G available (18%). Docker 29.8.2; Compose 5.6.0.
- Enam service RT05 staging existing: gateway, web, api, worker lama,
  scheduler, redis. API/web/Redis healthy; Redis hanya 6379/tcp internal,
  tanpa port host. Proxy Coolify existing memegang 80/443.
- Service Coolify dan aplikasi lain masih berjalan; hanya staging RT05 yang
  menjadi target rollout. Tidak ada restart OS, upgrade OS, DNS, firewall,
  proxy baru atau penghentian service lain.
- `/opt/rt05-takeda` masih mempunyai empat patch belum di-commit:
  `infra/compose.production.yml`, `infra/compose.vps.yml`,
  `infra/docker/gateway.Caddyfile`, `website/bootstrap/app.php`.
  Semua dipertahankan; deployment harus memakai checkout release lain.
  `git rev-parse HEAD` melalui console mengembalikan
  `53a8b10c9156fa6dcbd57d623863efb238f39d64` sebelum rollout baru.
- Pemeriksaan keberadaan (tanpa nilai) pada env staging:
  `REDIS_PASSWORD=missing`, `REDIS_CACHE_PASSWORD=missing`. Compose baru
  mewajibkan keduanya. Provisioning credential baru khusus Redis staging
  diperlukan sebelum rollout; tidak melonggarkan AUTH dan tidak merotasi
  credential Neon/APP_KEY production yang sebelumnya diblokir.
  `stat -c %a` pada env staging mengembalikan 600.
- Percobaan SSH dengan key lokal VPS tidak diterima (publickey/password);
  tidak mencoba key milik layanan lain. Konsol Chrome dipakai sebagai akses.

PR #15 M03 merged `27a3e5b`, lima cek terakhir SUCCESS pada `ea4179e`.
Tahap aktif `codex/m04-staging-recovery`. Bukti live baru, SHA/image/volume,
smoke Neon, heartbeat dan restart ditambahkan setelah benar-benar dijalankan.
