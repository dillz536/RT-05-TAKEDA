# M07 — rencana penerimaan LIVE sintetis (staging)

Status 13 Oktober 2026: **dijalankan untuk `979634f`, 77/77 PASS** — lihat
[DEPLOY_RESULT.md](DEPLOY_RESULT.md) dan [LIVE_RESULTS.md](LIVE_RESULTS.md). Script: `infra/scripts/m07-stage-acceptance.mjs` (Node 22,
`fetch` bawaan, tanpa dependency). Validasi persiapan hanya `node --check` dan
`node infra/scripts/check-repo.mjs`. Sumber backend: 220 tes lulus
([CLAUDE_RESULT.md](CLAUDE_RESULT.md)).

## 1. Gerbang sebelum eksekusi (root)

1. CI hijau untuk SHA kandidat `codex/m07-resident-data` (API, mobile, infra).
2. Deploy staging immutable SHA itu (job/runbook existing). Migration M07 additive berjalan
   lewat job migrasi existing. Jangan memakai seed, factory, atau tulis Neon langsung.
3. **Verifikasi SHA yang benar-benar berjalan lewat SSH**, read-only, di host staging yang sudah
   diotorisasi (host/kredensial tidak ditulis di sini):

   ```sh
   head -n 1 /opt/rt05-releases/staging-current-sha
   docker ps --filter label=com.docker.compose.project=rt05-staging \
     --filter label=com.docker.compose.service=api --format '{{.Image}}'
   ```

   Keduanya harus berakhir dengan SHA 40 hex yang sama. Nilai itu yang dikirim ke `--sha`.
   `infra/scripts/staging-runtime-smoke.sh` boleh dijalankan untuk memeriksa runtime/volume.
   API tidak mengekspos SHA, jadi script mencatat `--sha` sebagai SHA yang diverifikasi root.
4. Belum ada run M07 lain yang sedang berjalan dari IP yang sama, dan IP itu tidak memakai
   kuota registrasi dalam 10 menit terakhir (throttle 5 per 10 menit per IP).

## 2. Perintah

```powershell
# Rencana saja: validasi argumen, path, dan bentuk fixture; tanpa jaringan, tanpa menulis.
node infra/scripts/m07-stage-acceptance.mjs --sha <SHA40> `
  --fixture C:/Users/USER/.rt05/m06-stage-check/fixtures.json `
  --out C:/Users/USER/.rt05/m07-stage-check

# Eksekusi (hanya setelah gerbang 1).
node infra/scripts/m07-stage-acceptance.mjs --sha <SHA40> `
  --fixture C:/Users/USER/.rt05/m06-stage-check/fixtures.json `
  --out C:/Users/USER/.rt05/m07-stage-check --execute

# Lanjutkan setelah terputus: perintah yang sama (state dibaca dari --out).
# Langkah "tidak pasti" yang terbukti TIDAK terjadi (dicek di admin):
#   ... --execute --confirm-not-applied <stepId>
```

| Opsi | Arti |
| --- | --- |
| `--sha` | Wajib, 40 hex huruf kecil, SHA yang sudah diverifikasi SSH. State di `--out` terikat ke SHA ini; deployment baru memakai folder `--out` baru. |
| `--fixture` | Fixture privat M06, absolut, di luar Git. Hanya dibaca: `origin` harus staging dan `accounts.admin.email/password` harus ada. Nilai tidak pernah dicetak atau ditulis ulang. |
| `--out` | Folder privat absolut di luar Git dan terpisah dari folder fixture M06. |
| `--execute` | Tanpa ini, mode rencana. |
| `--confirm-not-applied <stepId>` | Membersihkan langkah `pending`/`failed` setelah operator memastikan tulisannya tidak terjadi. Boleh diulang. |

Exit code: `0` lulus, `1` kasus gagal, `2` berhenti karena tulis tidak pasti, `3` argumen/path/fixture.

## 3. Batas keamanan yang ditegakkan script

- Origin tetap `https://takeda-staging.morriz.tech`, path wajib `/api/v1`, `redirect: manual`
  (3xx ditolak). Tidak ada host lain, production, `/health`, SSH, atau Neon.
- Fixture M06 hanya dibaca, dan hanya akun admin yang dipakai (login biasa; tanpa logout-all,
  reset password, atau perubahan status). Akun/rumah M06 lain tidak disentuh.
- Aktor baru: `m07-<run>-{p1,a1,a2,a3}@example.test`, kata sandi acak (≥30 karakter huruf+angka),
  nama dan alamat bertanda `Sintetis M07 <RUN>` (`Jl. Sintetis M07 <RUN>`, blok `S7`).
  WhatsApp tidak diisi.
- Tidak ada DELETE data, cleanup, factory, SMTP, atau reset email. `DELETE /household/members`
  hanya mengarsipkan anggota sintetis (perilaku API). Di akhir hanya `POST /auth/logout`
  untuk bearer yang dibuat run ini.
- Throttle tidak dimatikan dan IP tidak dipalsukan: run butuh tepat 4 registrasi, jadi
  keempatnya boleh berurutan dalam satu jendela 10 menit (kuota 5 per IP); run yang di-resume
  menunggu bila sudah ada 4 registrasi run ini dalam 10 menit terakhir. Login maksimal 4 per
  menit per email. 429 diulang setelah `Retry-After` (maksimal 900 detik) karena throttle
  menolak sebelum handler. Run 979634f selesai dalam ±1 menit tanpa 429.
- Write yang tidak menerima respons (timeout/jaringan/502–504) **tidak diulang**: langkah tetap
  `pending`, exit 2. Saat resume, reconciler baca-saja dipakai bila ada (registrasi dicek
  dengan login; rumah dari kode; keluarga dari nama kepala; anggota dari nama; hunian dari
  detail rumah; approve/status/arsip akun dari detail akun). Tanpa reconciler, operator harus
  memeriksa admin lalu memakai `--confirm-not-applied`. Langkah yang mendapat respons tetapi
  gagal assertion ditandai `failed` dan tidak dilanjutkan otomatis. `--confirm-not-applied`
  hanya dipakai bila admin membuktikan tulisannya tidak terjadi (respons 2xx = sudah terjadi).
- Ringkasan publik dipindai sebelum ditulis: kata sandi, email, token, kode registrasi, `@`,
  dan pola bearer Sanctum menggagalkan penulisan.

## 4. Data sintetis yang dibuat (tidak dihapus)

| Objek | Jumlah | Catatan |
| --- | --- | --- |
| Akun warga | 4 | p1 utama (diarsipkan di akhir), a1 & a3 tambahan disetujui (salah satu menjadi utama), a2 menunggu |
| Rumah | 4 | H1 dari registrasi; H2–H4 admin, kode `M07-<RUN>-{2,3,4}`, tagihan aktif mulai periode berjalan |
| Keluarga | 3 | K1 registrasi; K2 admin + hunian H2 + pemilik sintetis; K3 admin (hunian H3 → H4 → diakhiri) |
| Warga/anggota | ±10 | termasuk anggota diarsipkan (`pindah`) dan pindah keluarga |
| Tagihan | periode berjalan | `POST /admin/dues/generate {periode}` membuat tagihan untuk **semua** rumah staging aktif bertagihan (semuanya sintetis, termasuk rumah fixture M06). Idempotent, tanpa pembayaran. |

Efek lain: token registrasi dibaca lewat `GET /admin/registration-token` (bisa menerbitkan token
baru bila yang lama kedaluwarsa, sama seperti M06); notifikasi in-app "Akun disetujui"; audit log.

## 5. Matriks kasus gerbang

77 kasus wajib (`REQUIRED_CASES`). Ringkasan hanya `passed` bila semua fase selesai, tidak ada
kasus gagal, dan tidak ada kasus wajib yang hilang. Setiap kasus memeriksa status HTTP, envelope
(`data` + `meta.request_id`, atau `error.code` + `error.request_id`, atau 204 kosong), kode error,
lalu assertion domain.

| Persyaratan | Kasus |
| --- | --- |
| Registrasi utama menulis keanggotaan + tautan | `register-p1`, `C04`, `C05` (3 keanggotaan aktif `registrasi`, 1 tautan utama aktif milik akun) |
| Tambahan pending ditolak dari layanan/feed | `register-a2`, `C07` household, `C08` pengumuman, `C09` iuran: 403 `FORBIDDEN`; `C10` status sendiri `diajukan` |
| Approve anggota existing, baca saja | `register-a1`, `approve-a1` (`warga_id`), `C12`, `C13` (tanpa warga ganda), `a1-*-denied` 403 `PRIMARY_ACCOUNT_REQUIRED` |
| Edit utama: versi hilang/basi, anggota | `p1-patch-missing-versi` 422 `fields.versi`, `p1-patch-ok`, `p1-patch-stale` 409 `VERSION_CONFLICT`, `p1-member-add/update/end`, `p1-head-end-protected` 422 `MEMBER_PROTECTED`, `C20` |
| IDOR | `p1-idor-other-family` 404 `NOT_FOUND` |
| Admin keluarga | `family-k2` (+hunian atomik), `family-k3`, `k2-verify`, `k2-verify-stale` 409, `k2-member-add/update/end/move`, `C38` |
| Gagal atomik, rumah dihuni | `family-house-occupied` 409 `HOUSE_OCCUPIED`, `C31` keluarga tidak tersimpan, `C32` H2 tetap |
| Pemilik terpisah dan privat | `h2-owner`, `C42` pemilik ≠ kepala, `C43` warga 403, `C44` tanpa field `pemilik` di household |
| Dua target pindah utama bersamaan, versi sama | `transfer-race-winner` 200 + `transfer-race-loser` 409 (`VERSION_CONFLICT`/`PRIMARY_ACCOUNT_EXISTS`), `C53` tepat satu utama aktif via detail keluarga |
| Utama lama ditolak, utama baru menulis | `old-primary-denied` 403, `new-primary-writes` 200 |
| Hunian mulai/pindah/akhiri, rumah dihuni | `occ-k3-h3`, `occ-h3-occupied` 409, `C61`, `occ-move`, `occ-end`, `C82` |
| Iuran sintetis idempotent | `dues-generate`, `dues-generate-again` (`tagihan_baru` 0) |
| Satu rumah banyak akun satu tagihan | `C72` (≥3 tautan aktif, 1 tagihan, nominal = tarif berlaku) |
| Tagihan lama tidak pindah; rumah kosong tidak dibebaskan | `C73` → `C83` (id tagihan H3 sama), `C84`, `C85` (bulan berjalan `belum_bayar`, `tagihan_aktif` tetap) |
| Nonaktif/arsip mencabut token | `loser-deactivate`, `C91` 401, `C92` login 422, `loser-reactivate`, `C94`; `p1-archive`, `C96` 401, `C97` 422, `p1-archive-again` 409 `ACCOUNT_ARCHIVED` |
| Histori arsip tetap | `C99` (tautan p1 tertutup tetap ada, satu utama), `C100` (riwayat `pindah` dan `pindah_keluarga`) |

## 6. Output

| File (`--out`) | Isi | Publik? |
| --- | --- | --- |
| `m07-live-summary.json` | `{schema, sourceSha, run, status, phases, requiredCases, missingCases, cases[{case, routeTemplate, httpStatus, code, assertions[{name, result}], result}]}` tanpa token/kata sandi/email/WA/ID | Boleh disalin root ke `docs/evidence/m07/` setelah ditinjau |
| `m07-state.private.json` | kredensial aktor sintetis, ID, checkpoint langkah, waktu registrasi | Privat, jangan dikomit |
| `m07-android-fixtures.private.json` | aktor (email/kata sandi/akunId/peran uji) dan ID untuk uji Android | Privat, jangan dikomit atau ditampilkan di screenshot |

Bearer tidak pernah ditulis ke disk.

## 7. Keterbatasan dan fase berikutnya

- Script membuktikan API live, bukan UI. Fase berikutnya (pending): Android M07 dengan
  `m07-android-fixtures.private.json` (utama baru ubah, tambahan baca saja, pending terbatas,
  utama lama ditolak login), lalu bukti root di `LIVE_RESULTS` M07.
- Bila resume terjadi setelah `loser-deactivate`/`p1-archive` selesai tanpa kasus token
  tercatat, kasus `C91/C92/C96/C97` tidak dapat diulang dan ringkasan tetap `incomplete`;
  ulangi dengan folder `--out` baru (aktor baru).
- Tidak menguji CMS browser, Neon langsung, SMTP, production, atau data warga nyata (gate M16).
