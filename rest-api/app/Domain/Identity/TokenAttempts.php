<?php

namespace App\Domain\Identity;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Batas kegagalan token registrasi (docs/API.md: 10/jam per IP, konfigurabel). Token enam digit
 * berentropi terbatas, jadi percobaan salah dihitung terpisah dari throttle registrasi umum.
 * Kunci memakai hash IP, bukan email (tidak membuka email).
 *
 * Reservasi: setiap percobaan memesan satu slot SEBELUM token diverifikasi dan ditolak 429 bila
 * slot jendela ini habis; percobaan yang tidak gagal karena token mengembalikan slotnya (release),
 * sehingga registrasi sukses tidak memakai kuota.
 *
 * Tidak memakai RateLimiter::increment Laravel: increment itu menulis ulang penghitung menjadi
 * `amount` (put) bila add() gagal dan hasil INCR sama dengan `amount`. Pada penghitung baru atau
 * penghitung yang kembali ke nol setelah release, reservasi bersamaan dapat saling menimpa tanpa
 * batas. Di sini satu nilai {hits, reset_at} dibaca-ubah-tulis di bawah Cache::lock pada store
 * limiter yang sama (config cache.limiter, default store): Redis = lock SET NX di koneksi lock
 * (queue, noeviction), database = tabel lock (diuji lintas proses). Store file/array tidak dipakai
 * sebagai batas keras: file hanya add ber-flock satu host, array per proses. Nilai disimpan dengan
 * serialisasi store biasa (tanpa INCR mentah) dan kedaluwarsa pada reset_at; Retry-After = sisa
 * jendela.
 */
final class TokenAttempts
{
    private const DECAY_SECONDS = 3600;

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public static function reserve(Request $request): void
    {
        $key = self::key($request);
        $retryAfter = null;
        self::locked($key, function (Repository $store) use ($key, &$retryAfter) {
            $now = now()->getTimestamp();
            [$hits, $resetAt] = self::read($store, $key, $now);
            if ($hits >= self::max()) {
                $retryAfter = max(1, $resetAt - $now);

                return;
            }
            $store->put($key, ['hits' => $hits + 1, 'reset_at' => $resetAt], max(1, $resetAt - $now));
        });
        if ($retryAfter !== null) {
            throw self::tooMany($retryAfter);
        }
    }

    /** Mengembalikan slot percobaan yang tidak gagal karena token (sukses atau konflik lain). */
    public static function release(Request $request): void
    {
        $key = self::key($request);
        try {
            self::locked($key, function (Repository $store) use ($key) {
                $now = now()->getTimestamp();
                [$hits, $resetAt] = self::read($store, $key, $now);
                if ($hits > 0) {
                    $store->put($key, ['hits' => $hits - 1, 'reset_at' => $resetAt], max(1, $resetAt - $now));
                }
            });
        } catch (ThrottleRequestsException) {
            // Lock tidak didapat: slot tetap terpakai sampai jendela berakhir (fail closed).
        }
    }

    /** Jumlah slot terpakai pada jendela berjalan (tes/diagnostik). */
    public static function hits(Request $request): int
    {
        return self::read(self::store(), self::key($request), now()->getTimestamp())[0];
    }

    /** @return array{0:int, 1:int} [hits, reset_at]; nilai hilang/kedaluwarsa/tidak dikenal = jendela baru. */
    private static function read(Repository $store, string $key, int $now): array
    {
        $value = $store->get($key);
        if (is_array($value) && isset($value['hits'], $value['reset_at']) && (int) $value['reset_at'] > $now) {
            return [max(0, (int) $value['hits']), (int) $value['reset_at']];
        }

        return [0, $now + self::DECAY_SECONDS];
    }

    private static function locked(string $key, callable $callback): void
    {
        $store = self::store();
        try {
            $store->lock($key.':lock', self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, fn () => $callback($store));
        } catch (LockTimeoutException) {
            // Store lock sibuk/tidak tersedia: tolak (fail closed) daripada melewati batas.
            throw self::tooMany(self::LOCK_WAIT_SECONDS);
        }
    }

    private static function store(): Repository
    {
        return Cache::store(config('cache.limiter'));
    }

    private static function tooMany(int $retryAfter): ThrottleRequestsException
    {
        return new ThrottleRequestsException('Terlalu banyak percobaan token.', null, ['Retry-After' => $retryAfter]);
    }

    private static function max(): int
    {
        return max(1, (int) config('rt05.auth.token_failures_per_hour', 10));
    }

    private static function key(Request $request): string
    {
        return 'token-registrasi-gagal:'.hash('sha256', (string) $request->ip());
    }
}
