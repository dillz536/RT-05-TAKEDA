# M04 — bukti CI

11 Oktober 2026. PR [#16](https://github.com/morrispes5/RT-05-TAKEDA/pull/16),
commit `75dee911f2b95423367dfa9adf8feb201377a7ed` (backend probe dan dokumentasi).

| Pemeriksaan | Hasil | Bukti |
| --- | --- | --- |
| API verification | SUCCESS: 93 tes, 766 assertion; termasuk lima StagingSmokeTest | [run 38113599864](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38113599864) |
| Infra images | SUCCESS | [run 38113599888](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38113599888) |
| Repository checks | SUCCESS | [run 38113599875](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38113599875) |
| Vercel | SUCCESS | [deployment](https://vercel.com/morrizshkki/rt05takeda/EUB4AFPqYYwq3pjfhycGHXkAgAUa) |
| Vercel Preview Comments | SUCCESS | PR #16 checks |

API CI juga menjalankan drill Redis/worker M03, Pint, dan validasi kontrak.
Hasil ini membuktikan kode backend pada SHA tersebut; belum membuktikan rollout,
Neon staging, heartbeat VPS, atau persistence pada server target. Skrip infra M04
yang belum termasuk SHA ini harus diuji ulang setelah handoff.

## Integrasi infra `03b4ac6`

Lima check SUCCESS setelah rollout/smoke/helper/metadata ditambahkan:
[API 38114626260](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38114626260),
[Infra 38114626258](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38114626258),
[Hygiene 38114626266](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38114626266),
serta Vercel dan Preview Comments. Infra juga menjalankan empat pure helper
tests dan syntax checks. Alias staging existing `RT05_ENV=stg` ditemukan
review berikutnya; perubahan guard alias harus diuji pada commit berikutnya.
Gate VPS masih pending, bukan hasil CI.

## Koreksi review `62a863f`

Lima check SUCCESS pada SHA
`62a863fd000582b740f992a3daa46bd67ae89f13` sebelum rollout:
[API 38114916959](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38114916959),
[Infra 38114916898](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38114916898),
[Hygiene 38114916966](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38114916966),
Vercel dan Preview Comments. Infra lulus syntax, lima helper tests,
Compose/image build dan gateway smoke. Koreksi meliputi alias `stg`, trusted
ref ancestry, penolakan key guarded noncanonical, image ID dan sentinel TTL.
Review independen Claude menutup blocker kode; gate live tetap terpisah.

## Opsi Compose `12b4642`

Lima check SUCCESS setelah temuan live `compose run --no-build` dibetulkan:
[Infra 38115505350](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38115505350),
[API 38115505380](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38115505380),
[Hygiene 38115505386](https://github.com/morrispes5/RT-05-TAKEDA/actions/runs/38115505386),
Vercel dan Preview Comments. CLI help Compose memverifikasi `--no-deps` dan
`--pull`; script one-shot memakai image hasil build dengan `--pull never`.
Ini koreksi operasional skrip, tanpa perubahan source API/web dari `62a863f`.
