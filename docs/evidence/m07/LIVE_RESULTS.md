# M07 — hasil penerimaan LIVE API staging (sintetis)

**Status: PASSED — 77/77 kasus wajib, 327/327 assertion, 0 kasus hilang.**

| Item | Nilai |
| --- | --- |
| Source | `979634fb7ae847f6c743f0ae7e11ec575b1fe722` (PR #22), dideploy ke `rt05-staging` dan diverifikasi lewat SSH: `staging-current-sha` dan image `rt05-api`/`rt05-web` sama ([DEPLOY_RESULT.md](DEPLOY_RESULT.md)) |
| Target | `https://takeda-staging.morriz.tech/api/v1` saja (redirect ditolak) |
| Waktu | 13 Oktober 2026, 06:14:12–06:15:10 UTC, satu run tanpa resume |
| Helper | `infra/scripts/m07-stage-acceptance.mjs --execute` ([LIVE_PLAN.md](LIVE_PLAN.md)); exit 0 |
| Run | `Sintetis M07 E0C6B8` |
| Aktor | Admin fixture M06 (login biasa, logout perangkat ini saja) + 4 akun baru `@example.test` dengan kata sandi acak |

Perbaikan helper sebelum eksekusi: daftar `REQUIRED_CASES` kini dipecah dengan `/\s+/`
(mode rencana menghitung 77) dan jeda 125 detik diganti kuota sadar jendela (maksimal 4
registrasi per 10 menit dari run ini, 429 tetap mengikuti `Retry-After`). Throttle tidak
dimatikan; tidak ada 429 selama run.

## Ringkasan per fase

| Fase | Bukti utama |
| --- | --- |
| preflight | Info API v1, login admin pengurus aktif, token registrasi 6 digit dengan `Cache-Control: no-store` |
| primary-register | Registrasi utama 201: akun utama aktif, 3 keanggotaan aktif `registrasi`, 1 tautan utama aktif milik akun |
| additional-pending | Akun tambahan menunggu: household, pengumuman, dan iuran 403 `FORBIDDEN`; status sendiri `diajukan` |
| approve-existing-member | Approve dengan `warga_id` anggota existing: tanpa warga ganda, tambahan baca saja, tulis 403 `PRIMARY_ACCOUNT_REQUIRED` |
| primary-edit-versioning | Versi hilang 422 (`fields.versi`), edit 200 versi +1, versi basi 409, anggota tambah/ubah/akhiri, kepala 422 `MEMBER_PROTECTED` |
| admin-families | Rumah dengan kode tetap; keluarga + hunian atomik; rumah dihuni 409 tanpa keluarga setengah jadi; verifikasi + basi 409; anggota tambah/ubah/akhiri/pindah dengan histori; IDOR 404; pemilik terpisah, khusus admin, tidak tampil di household |
| primary-transfer | Dua pindah akun utama bersamaan dengan versi sama: satu 200, satu 409 `VERSION_CONFLICT`; tepat satu utama aktif menurut detail keluarga; utama lama 403, utama baru 200 |
| occupancy-begin | Hunian mulai 201; rumah dihuni 409 `HOUSE_OCCUPIED` tanpa perubahan histori |
| dues-generate | Generate periode berjalan 200, ulang `tagihan_baru = 0`; satu rumah dengan ≥3 akun aktif tetap satu tagihan, nominal = tarif berlaku |
| occupancy-move-end | Pindah dan akhiri hunian; tagihan lama tetap di rumah lama (ID sama, belum dibayar); rumah kosong tetap `tagihan_aktif` dan tidak dibebaskan |
| accounts | Nonaktif: bearer lama 401, login 422; aktif lagi; arsip utama lama: bearer 401, login 422, arsip ulang 409 `ACCOUNT_ARCHIVED`; histori tautan/keanggotaan tetap |

## Matriks kasus

Kolom assertion mencakup status, envelope (`data`+`meta.request_id` atau
`error.code`+`error.request_id`, 204 kosong), kode error, dan assertion domain.

| Kasus | Route | HTTP | Kode | Assertion | Hasil |
| --- | --- | --- | --- | --- | --- |
| `C00-api-info` | `GET /api/v1` | 200 | – | 4/4 | PASS |
| `C01-admin-login` | `POST /api/v1/auth/mobile-login` | 200 | – | 5/5 | PASS |
| `C02-admin-registration-token` | `GET /api/v1/admin/registration-token` | 200 | – | 5/5 | PASS |
| `register-p1` | `POST /api/v1/auth/register` | 201 | – | 7/7 | PASS |
| `C04-primary-household` | `GET /api/v1/household` | 200 | – | 8/8 | PASS |
| `C05-registration-history` | `GET /api/v1/admin/families/{id}` | 200 | – | 5/5 | PASS |
| `register-a2` | `POST /api/v1/auth/additional-register` | 202 | – | 4/4 | PASS |
| `C07-pending-household-denied` | `GET /api/v1/household` | 403 | `FORBIDDEN` | 4/4 | PASS |
| `C08-pending-feed-denied` | `GET /api/v1/announcements` | 403 | `FORBIDDEN` | 4/4 | PASS |
| `C09-pending-dues-denied` | `GET /api/v1/dues/my` | 403 | `FORBIDDEN` | 4/4 | PASS |
| `C10-pending-own-status` | `GET /api/v1/auth/additional-account-status` | 200 | – | 4/4 | PASS |
| `register-a1` | `POST /api/v1/auth/additional-register` | 202 | – | 4/4 | PASS |
| `approve-a1` | `POST /api/v1/admin/additional-account-requests/{id}/approve` | 200 | – | 4/4 | PASS |
| `C12-additional-profile` | `POST /api/v1/auth/mobile-login` | 200 | – | 4/4 | PASS |
| `C13-additional-household-read-only` | `GET /api/v1/household` | 200 | – | 6/6 | PASS |
| `a1-household-patch-denied` | `PATCH /api/v1/household` | 403 | `PRIMARY_ACCOUNT_REQUIRED` | 4/4 | PASS |
| `a1-member-add-denied` | `POST /api/v1/household/members` | 403 | `PRIMARY_ACCOUNT_REQUIRED` | 4/4 | PASS |
| `p1-patch-missing-versi` | `PATCH /api/v1/household` | 422 | `VALIDATION_FAILED` | 5/5 | PASS |
| `p1-patch-ok` | `PATCH /api/v1/household` | 200 | – | 5/5 | PASS |
| `p1-patch-stale` | `PATCH /api/v1/household` | 409 | `VERSION_CONFLICT` | 4/4 | PASS |
| `p1-member-add` | `POST /api/v1/household/members` | 201 | – | 4/4 | PASS |
| `p1-member-update` | `PATCH /api/v1/household/members/{id}` | 200 | – | 4/4 | PASS |
| `p1-head-end-protected` | `DELETE /api/v1/household/members/{id}` | 422 | `MEMBER_PROTECTED` | 4/4 | PASS |
| `p1-member-end` | `DELETE /api/v1/household/members/{id}` | 204 | – | 2/2 | PASS |
| `C20-ended-member-hidden` | `GET /api/v1/household` | 200 | – | 4/4 | PASS |
| `house-h2` | `POST /api/v1/admin/houses` | 201 | – | 4/4 | PASS |
| `house-h3` | `POST /api/v1/admin/houses` | 201 | – | 4/4 | PASS |
| `house-h4` | `POST /api/v1/admin/houses` | 201 | – | 4/4 | PASS |
| `family-k2` | `POST /api/v1/admin/families` | 201 | – | 5/5 | PASS |
| `family-house-occupied` | `POST /api/v1/admin/families` | 409 | `HOUSE_OCCUPIED` | 4/4 | PASS |
| `C31-no-half-family` | `GET /api/v1/admin/families` | 200 | – | 4/4 | PASS |
| `C32-house-still-k2` | `GET /api/v1/admin/houses/{id}` | 200 | – | 4/4 | PASS |
| `family-k3` | `POST /api/v1/admin/families` | 201 | – | 4/4 | PASS |
| `k2-verify` | `POST /api/v1/admin/families/{id}/verify` | 200 | – | 4/4 | PASS |
| `k2-verify-stale` | `POST /api/v1/admin/families/{id}/verify` | 409 | `VERSION_CONFLICT` | 4/4 | PASS |
| `k2-member-add` | `POST /api/v1/admin/families/{id}/members` | 201 | – | 4/4 | PASS |
| `k2-member-update` | `PATCH /api/v1/admin/residents/{id}` | 200 | – | 4/4 | PASS |
| `k2-member-end` | `POST /api/v1/admin/residents/{id}/end` | 200 | – | 4/4 | PASS |
| `C38-ended-history-retained` | `GET /api/v1/admin/residents/{id}` | 200 | – | 4/4 | PASS |
| `k2-member-move` | `POST /api/v1/admin/residents/{id}/move` | 200 | – | 4/4 | PASS |
| `p1-idor-other-family` | `PATCH /api/v1/household/members/{id}` | 404 | `NOT_FOUND` | 4/4 | PASS |
| `h2-owner` | `PATCH /api/v1/admin/houses/{id}` | 200 | – | 3/3 | PASS |
| `C42-owner-separate` | `GET /api/v1/admin/houses/{id}` | 200 | – | 4/4 | PASS |
| `C43-owner-admin-only` | `GET /api/v1/admin/houses/{id}` | 403 | `FORBIDDEN` | 4/4 | PASS |
| `C44-resident-view-without-owner` | `GET /api/v1/household` | 200 | – | 4/4 | PASS |
| `register-a3` | `POST /api/v1/auth/additional-register` | 202 | – | 4/4 | PASS |
| `approve-a3` | `POST /api/v1/admin/additional-account-requests/{id}/approve` | 200 | – | 4/4 | PASS |
| `C51-three-links-one-primary` | `GET /api/v1/admin/families/{id}` | 200 | – | 4/4 | PASS |
| `transfer-race-winner` | `POST /api/v1/admin/families/{id}/primary-account` | 200 | – | 4/4 | PASS |
| `transfer-race-loser` | `POST /api/v1/admin/families/{id}/primary-account` | 409 | `VERSION_CONFLICT` | 4/4 | PASS |
| `C53-exactly-one-active-primary` | `GET /api/v1/admin/families/{id}` | 200 | – | 6/6 | PASS |
| `old-primary-denied` | `PATCH /api/v1/household` | 403 | `PRIMARY_ACCOUNT_REQUIRED` | 4/4 | PASS |
| `new-primary-writes` | `PATCH /api/v1/household` | 200 | – | 4/4 | PASS |
| `occ-k3-h3` | `POST /api/v1/admin/occupancies` | 201 | – | 4/4 | PASS |
| `occ-h3-occupied` | `POST /api/v1/admin/occupancies` | 409 | `HOUSE_OCCUPIED` | 4/4 | PASS |
| `C61-h3-unchanged` | `GET /api/v1/admin/houses/{id}` | 200 | – | 4/4 | PASS |
| `dues-generate` | `POST /api/v1/admin/dues/generate` | 200 | – | 4/4 | PASS |
| `dues-generate-again` | `POST /api/v1/admin/dues/generate` | 200 | – | 4/4 | PASS |
| `C72-one-house-many-accounts-one-charge` | `GET /api/v1/admin/dues-charges` | 200 | – | 6/6 | PASS |
| `C73-h3-charge-before-move` | `GET /api/v1/admin/dues-charges` | 200 | – | 4/4 | PASS |
| `occ-move` | `POST /api/v1/admin/occupancies/{id}/move` | 201 | – | 4/4 | PASS |
| `occ-end` | `POST /api/v1/admin/occupancies/{id}/end` | 200 | – | 4/4 | PASS |
| `C82-h3-empty-billing-kept` | `GET /api/v1/admin/houses/{id}` | 200 | – | 4/4 | PASS |
| `C83-old-charge-stays` | `GET /api/v1/admin/dues-charges` | 200 | – | 4/4 | PASS |
| `C84-h4-charge-separate` | `GET /api/v1/admin/dues-charges` | 200 | – | 4/4 | PASS |
| `C85-empty-house-not-waived` | `GET /api/v1/admin/houses/{id}/dues` | 200 | – | 4/4 | PASS |
| `loser-deactivate` | `PATCH /api/v1/admin/accounts/{id}` | 200 | – | 4/4 | PASS |
| `C91-deactivated-token-revoked` | `GET /api/v1/auth/me` | 401 | `UNAUTHENTICATED` | 4/4 | PASS |
| `C92-deactivated-login-denied` | `POST /api/v1/auth/mobile-login` | 422 | `VALIDATION_FAILED` | 4/4 | PASS |
| `loser-reactivate` | `PATCH /api/v1/admin/accounts/{id}` | 200 | – | 4/4 | PASS |
| `C94-reactivated-login` | `POST /api/v1/auth/mobile-login` | 200 | – | 4/4 | PASS |
| `p1-archive` | `POST /api/v1/admin/accounts/{id}/archive` | 200 | – | 5/5 | PASS |
| `C96-archived-token-revoked` | `GET /api/v1/auth/me` | 401 | `UNAUTHENTICATED` | 4/4 | PASS |
| `C97-archived-login-denied` | `POST /api/v1/auth/mobile-login` | 422 | `VALIDATION_FAILED` | 4/4 | PASS |
| `p1-archive-again` | `POST /api/v1/admin/accounts/{id}/archive` | 409 | `ACCOUNT_ARCHIVED` | 4/4 | PASS |
| `C99-family-history-retained` | `GET /api/v1/admin/families/{id}` | 200 | – | 6/6 | PASS |
| `C100-k2-history-retained` | `GET /api/v1/admin/families/{id}` | 200 | – | 4/4 | PASS |

## Data sintetis dan efek samping

- Dibuat dan tidak dihapus: 4 akun (`@example.test`), 4 rumah (1 dari registrasi, 3 admin
  dengan kode `M07-E0C6B8-{2,3,4}`), 3 keluarga, anggota bertanda `Sintetis M07 E0C6B8`,
  satu permohonan tambahan tetap `diajukan`, satu pemilik rumah sintetis. Tanpa WhatsApp.
- `POST /admin/dues/generate` periode berjalan membuat tagihan untuk semua rumah staging aktif
  bertagihan (semua sintetis, termasuk rumah fixture M06); idempotent, tanpa pembayaran.
- Fixture M06 hanya dibaca; akun M06 selain admin tidak disentuh, admin tidak logout-all.
- Bearer yang dibuat run ditutup dengan `POST /auth/logout` (perangkat ini saja). Tidak ada
  reset password/SMTP, factory, atau tulis Neon langsung.

## Fixture privat untuk Android (struktur saja)

`C:/Users/USER/.rt05/m07-stage-check/m07-android-fixtures.private.json` (di luar Git, nilai tidak
dicetak). Kunci: `schema`, `origin`, `sourceSha`, `run`, `note`, `writtenAt`,
`actors.{p1,a1,a2,a3}.{email,password,akunId,peranUji}`, dan `ids.{keluarga1,rumah1,kepala1,
anak1,rumah2,rumah3,rumah4,keluarga2,keluarga3,primaryWinner,primaryLoser,occ3,chargeH3}`.
Peran uji akhir: `a1` akun utama aktif (boleh ubah keluarga), `a3` tambahan aktif (baca saja),
`a2` tambahan menunggu (akses terbatas), `p1` utama lama diarsipkan (login harus ditolak).
State lengkap (checkpoint/ID) ada di `m07-state.private.json`; ringkasan publik asli di
`m07-live-summary.json` pada folder yang sama. Izin file Windows mengikuti profil pengguna
(mode POSIX 600 tidak berlaku di NTFS).

## Keterbatasan

- Membuktikan API live staging, bukan UI. Android M07 (APK debug dari CI) dan pemeriksaan
  perangkat ditangani root dengan fixture di atas.
- Tidak menguji CMS browser, SMTP, Neon langsung, production, atau data warga nyata (gate M16).
- `tagihan_baru` pertama tidak dicatat sebagai angka di ringkasan; idempotensi dibuktikan oleh
  panggilan kedua = 0 dan satu tagihan per rumah.
