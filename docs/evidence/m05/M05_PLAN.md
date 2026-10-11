# M05 — rencana dan gate fondasi mobile

11 Oktober 2026. M04 fungsional diterima Grok dan PR #16 merged `3a8be79`
setelah lima cek sukses pada `9cad5de`: API38133898050, infra38133897989,
hygiene38133897951, Vercel dan Preview Comments. Staging tetap SHA `12b4642`.
Keterbatasan credential historis tetap terbuka; tidak menyatakan production siap.
Target terbaru pemilik sampai M05, bertahap. Tidak membuka implementasi M06+.

## Ownership

- Claude (sesi sama): `mobile/**`, laporan CLAUDE_FOUNDATION_RESULT.md.
- Grok (sesi sama): workflow mobile, guard konfigurasi build, laporan GROK_CI_RESULT.md.
- Codex: rencana, Git/integrasi, review hasil, CI, artifact dan emulator nyata.
- Kimi: sesi dipertahankan. Jika limit, tugas dialihkan kepada Grok sesuai
  instruksi terbaru pemilik, tanpa mengganti akun atau mengklaim review Kimi baru.

## Butir penerimaan

| Butir | Implementasi minimal | Bukti |
| --- | --- | --- |
| Runtime/identitas | Flutter3.47.7/Dart3.13.5, Java17 CI, package Android existing tech.morriz.takeda, versi dari pubspec | Runtime aktual, manifest APK CI, tanpa pergantian identitas aplikasi |
| State/komponen | Riverpod, DTO/error API existing; forms/loading/empty/error/retry reusable; fake adapter hanya dalam test | Analyze, unit/widget perilaku penting, review sumber |
| Sesi/offline | Secure token tidak hilang karena timeout/503/403; 401/logout menghapus; retry manual; hasil restore lama tidak menimpa sesi baru | Tes storage/error/race bermakna |
| Konfigurasi | dev/staging/prod nonsensitif; staging/prod wajib HTTPS; dev HTTP hanya host lokal yang disetujui; URL tanpa userinfo/query/fragment | Tes runtime dan guard CI menolak input tidak valid |
| Health | GET nyata /health/live, status JSON ok, timeout bounded, unavailable/retry | Tes respons/error/retry dan request dari emulator ke staging |
| Router | go_router top-level bootstrap/login/pending/home; redirect mengikuti sesi; detail existing dapat tetap Navigator | Tes guest/pending/aktif/expiry |
| Brand | Gapura aset resmi identik sumber website, navy/kuning/sky, font terbundel berlisensi; lokalisasi Indonesia | Source/aset dan screenshot emulator |
| Aksesibilitas | Login/shell pada layar ringkas dan text scaling1.3/2.0 | Widget test tanpa overflow |
| CI/APK | Format ditegakkan, analyze/test, debug dev dan staging APK/checksum | CI pada commit M05 dan artifact terpasang |
| Keamanan | Tidak ada DSN/credential/fake production atau bypass TLS; storage OS; business tetap di API | Review sumber/manifest/artifact dan check-repo |

Health request publik nyata cukup untuk gate integrasi fondasi M05. Login,
registrasi dan layanan bisnis M06+ tidak dijadikan prasyarat dan tidak diklaim
lulus. Audit [READINESS_REVIEW.md](READINESS_REVIEW.md) adalah temuan historis;
usulan H1 yang menuntut login/dues terlalu luas untuk tahap ini.

Build Gradle/APK dilakukan di CI sesuai ADR19; local Flutter analyze/test saja.
Debug APK untuk pengujian bukan rilis signed/UAT/production. Situs accepted
tidak diubah; penyimpangan Drive/planning tetap [CAPSTONE_ALIGNMENT.md](../../CAPSTONE_ALIGNMENT.md).

## Titik mulai

`git status` bersih sebelum branch M05. Flutter3.47.7 stable, Dart3.13.5
diverifikasi lokal; emulator-5554 online. APK staging lama berlabel flavor dev
adalah bukti historis dan akan diganti artifact baru. Tidak ada tes/factory
terhadap Neon. Kode dan tes baru belum ada pada titik penulisan rencana ini.

## Review rencana

Kimi kembali menghasilkan audit setelah pemilik menyebut kuota mungkin pulih.
Completion audit `task_ae3524560f81` / `ctx_820928d88366` diterima;
`worker-show` mengonfirmasi completed/succeeded meski receipt awal sempat
agent_prompt_stalled. Duplikat dari retry ditolak dan tidak dihitung review baru.
Laporan [KIMI_PLAN_REVIEW.md](KIMI_PLAN_REVIEW.md) PASS untuk kelengkapan rencana
saja. Tiga catatan implisit dijadikan butir eksplisit di atas; CI/artifact/emulator
tetap wajib dibuktikan. Audit tambahan M04 yang tiba lewat konteks lama bukan
alasan membuka kembali milestone atau redeploy.
