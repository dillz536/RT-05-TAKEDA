# M06 — koreksi reservasi kuota token (Claude)

Tanggal: 12 Oktober 2026. Task `task_f684bcfbae8b`, dispatch `ctx_660bb74c1bce`.

Lingkup: `rest-api/app/Domain/Identity/TokenAttempts.php`, tes/support terkait, dan laporan ini. Tidak ada commit, push, atau staging. Kontrak bersama, `mobile/`, dan `rest-api/README.md` tidak diubah.

**Hasil: 184/184 PHPUnit lulus (1679 assertion) pada PostgreSQL 17.10 lokal sementara (`rt05_test`). Pint passed. `check-repo` exit 0.** Tes baru menutup penghitung baru, penghitung nol setelah release, dan reservasi yang ditahan tepat sebelum menulis penghitung. Ketiganya gagal pada versi sebelumnya dan lulus sekarang.

## Masalah (temuan root, terkonfirmasi)

Versi sebelumnya (`CLAUDE_REVIEW_FIXES.md`) memakai `RateLimiter::increment`/`decrement` Laravel 13:

```php
$this->cache->add($key.':timer', …);
$added = $this->cache->add($key, 0, $decay);
$hits = (int) $this->cache->increment($key, $amount);
if (! $added && $hits == $amount) {
    $this->cache->put($key, $amount, $decay);   // menimpa tanpa syarat
}
```

Bila kunci sudah ada tetapi bernilai 0 (penghitung baru yang ditambahkan proses lain, atau penghitung yang kembali ke nol setelah release), proses yang mendapat `hits == 1` menulis ulang penghitung menjadi 1 *sesudah* INCR proses lain. Semua increment di antaranya hilang, dan jumlahnya tidak dibatasi. `decrement` memakai jalur yang sama dengan `amount = -1`.

Laporan sebelumnya menyebut "paling banyak +1". **Itu keliru**; batas tersebut tidak berlaku. Tes burst lama mengisi penghitung lebih dulu (preseed 1), sehingga kasus penghitung baru dan nol tidak teruji.

## Perbaikan

`TokenAttempts` tidak lagi memakai `RateLimiter`. Satu nilai `{hits, reset_at}` dibaca-ubah-tulis di bawah `Cache::lock` pada store yang sama dengan limiter (`Cache::store(config('cache.limiter'))`, yaitu default store; staging/production `CACHE_STORE=redis`).

- **`reserve`:** di bawah lock, baca nilai. Bila hilang, kedaluwarsa, atau bentuknya tidak dikenal, mulai jendela baru `[0, now + 3600]`. Bila `hits >= batas` → 429 dengan `Retry-After = reset_at − now` (minimal 1). Selain itu `put` `hits + 1` dengan TTL sampai `reset_at`. Jendela tidak diperpanjang oleh percobaan berikutnya.
- **`release`:** di bawah lock, `hits − 1` dengan minimum 0; `reset_at` tetap. Registrasi sukses dan konflik non-token tetap mengembalikan slotnya, jadi semantik kuota tidak berubah (`AuthController::registerWithToken` tidak diubah).
- **Lock:** `lock(key:lock, 10 s)->block(5 s)`.
  - Redis: `SET NX` di koneksi `lock_connection` (`queue`, noeviction + AOF; `config/cache.php`).
  - Database: tabel lock.
  - Lock tidak didapat → 429 `Retry-After: 5` (fail closed). Release yang gagal mendapat lock membiarkan slot terpakai sampai jendela berakhir.
- **Serialisasi:** nilai disimpan sebagai array lewat `put` biasa, dengan serialisasi store yang sama untuk Redis/database. Tidak ada INCR mentah, kunci `:timer` terpisah, atau `withoutSerializationOrCompression`.
- **Waktu:** memakai `now()` (Carbon), sehingga `travel()` di tes dan TTL store konsisten.
- **Diagnostik:** `TokenAttempts::hits()` membaca slot terpakai, untuk tes.

Tidak ada layanan, tabel production, atau dependency baru.

## Tes

Tes lintas proses (`M06ConcurrencyTest`):

- Worker memakai cache store `database` bersama, dengan tabel tes sementara `rt05_race_cache` dan `rt05_race_cache_locks` di `rt05_test` yang dihapus di `finally`. Batas diset 3.
- Worker baru `kind: reserve` (`race-worker.php`) memanggil `TokenAttempts::reserve` (opsional `release`) tanpa HTTP, sehingga throttle registrasi umum tidak memengaruhi hasil.
- Gerbang `gate_on_limiter_write` menahan proses pada event `WritingKey` penghitung, yaitu tepat sebelum `put`.

| Tes | Isi | Hasil kini | Versi `RateLimiter::increment` |
| --- | --- | --- | --- |
| `test_parallel_reservations_on_a_fresh_counter_grant_exactly_the_limit` | penghitung baru, 10 reservasi paralel | 3×200 + 7×429; `Retry-After` 3500–3600; `hits = 3` | **gagal**: 4×200 |
| `test_parallel_reserve_and_release_returns_the_counter_to_zero` | 10 reserve+release paralel, lalu 10 reservasi | 10×200, `hits = 0`, lalu tepat 3 | **gagal**: hanya 3 dari 10 reserve+release diterima (penghitung tidak kembali ke nol) |
| `test_reservation_paused_before_its_counter_write_cannot_erase_concurrent_reservations` | penghitung nol setelah release; reservasi pertama ditahan sebelum menulis; 9 lainnya berjalan; gerbang dilepas; burst kedua 10 | total diterima = 3 dari 20 | **gagal 3/3**: total 5 |
| `test_parallel_wrong_token_burst_on_a_fresh_counter_stops_exactly_at_the_limit` (HTTP) | 10 `POST /auth/register` token salah, satu IP, penghitung baru | 3×422 `REGISTRATION_TOKEN_INVALID` + 7×429, tanpa akun | tally lulus pada run mutasi, karena race tidak selalu muncul lewat HTTP |
| `test_parallel_wrong_token_burst_after_release_to_zero_stops_exactly_at_the_limit` (HTTP) | registrasi sukses (slot kembali, `hits = 0`), lalu 10 token salah | 3×422 + 7×429 | — |

Tes `M06ConcurrencyTest` lulus 3× berturut-turut (11 tes, ±27 detik).

Pada tes gerbang, versi tanpa lock menyelesaikan 9 proses lain sebelum gerbang dilepas. Versi berlock membuat mereka menunggu lock. Batas tunggu 3 detik hanya memengaruhi durasi; burst kedua mengisi sisa kuota, sehingga totalnya selalu tepat batas.

Tes in-process (`M06ReviewFixesTest::test_token_reservation_window_retry_after_and_release_to_zero`, store array, batas 2):

- Release ke 0 dan tidak pernah negatif.
- Reservasi ke-3 setelah 20 menit → 429 dengan `Retry-After` ≈ 40 menit (jendela tidak diperpanjang).
- Setelah jendela berakhir, `hits = 0` dan reservasi baru diterima.

Tes yang sudah ada tetap lulus: limiter per IP + 61 menit (`M06RegistrationTest`), registrasi sukses tidak memakai kuota, dan perilaku 429 envelope.

## Perintah dan hasil

| Perintah (dari `rest-api/`) | Hasil |
| --- | --- |
| `vendor/bin/phpunit --filter='wrong_token_burst\|reserv' tests/Feature/M06ConcurrencyTest.php` | 5/5 lulus |
| Mutasi: `TokenAttempts` versi `RateLimiter::increment` | tes gerbang gagal 3/3 (total 5); penghitung baru gagal (4×200); reserve+release gagal (3/10); berkas dipulihkan, `cmp` identik |
| `vendor/bin/phpunit tests/Feature/M06ConcurrencyTest.php` ×3 | 11/11 lulus setiap kali |
| `php vendor/bin/pint --test` | passed |
| `php artisan test` | **184/184 lulus, 1679 assertion** |
| `node infra/scripts/check-repo.mjs` (root) | `OK: 638 berkas dilacak …` (setelah laporan ini), exit 0 |

Setelah suite, `rt05_test` kosong dan tidak ada tabel `rt05_race*` tersisa. Postgres sementara di scratchpad, tanpa Neon.

## Batas yang akurat

- **Batas keras hanya dibuktikan untuk store bersama berlock.** Tes memakai store database Postgres (lock baris tabel) sebagai pengganti. Redis staging memakai `SET NX` untuk lock dan `GET`/`SET` untuk nilai; jalur kodenya sama, tetapi Redis tidak diuji di sini karena tidak ada Redis lokal.
- **Store file tidak diklaim sebagai batas keras.** Lock-nya `add` ber-flock satu host dan tidak diuji. Store array per proses, hanya untuk tes in-process.
- **Eviction Redis cache.** Bila instance cache (evictable) mengeluarkan kunci penghitung, jendela dimulai ulang dan kuota dapat bertambah. Lock berada di instance queue yang noeviction.
- **Lock TTL 10 detik.** Bila proses mati sambil memegang lock, reservasi lain menunggu sampai 5 detik lalu 429. Bila operasi di bawah lock melebihi 10 detik, lock kedaluwarsa (tidak realistis untuk satu get/put).
- **Throttle middleware Laravel lain** (`throttle:registrasi`, login, lupa password, reset) tetap memakai `RateLimiter` vendor: cek-lalu-hit, dengan race `put` yang sama. Batas kegagalan token dijamin oleh `TokenAttempts`, bukan throttle umum; throttle lain bukan batas keras di bawah burst.
- **`rest-api/README.md`** baris Token RT masih menyebut "increment cache". Teks itu di luar kepemilikan tugas ini; usul ganti menjadi "reservasi di bawah Cache::lock pada store limiter".

## Berkas

- `rest-api/app/Domain/Identity/TokenAttempts.php`
- `rest-api/tests/Feature/M06ConcurrencyTest.php` (helper store bersama, 2 tes HTTP, 3 tes limiter)
- `rest-api/tests/Feature/M06ReviewFixesTest.php` (tes jendela/Retry-After/release)
- `rest-api/tests/Support/race-worker.php` (worker `reserve`, gerbang `gate_on_limiter_write`)
- `rest-api/tests/Support/Concurrent.php` (dokumen kind)
- `docs/evidence/m06/CLAUDE_TOKEN_RESERVATION_FIX.md`
