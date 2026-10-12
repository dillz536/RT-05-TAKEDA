<?php

namespace App\Domain\Identity;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Batas kegagalan token registrasi (docs/API.md: 10/jam per IP, konfigurabel). Token enam digit
 * berentropi terbatas, jadi percobaan salah dihitung terpisah dari throttle registrasi umum dan
 * diperiksa sebelum token diverifikasi. Kunci memakai hash IP, bukan email (tidak membuka email).
 */
final class TokenAttempts
{
    private const DECAY_SECONDS = 3600;

    public static function ensureAllowed(Request $request): void
    {
        $key = self::key($request);
        if (RateLimiter::tooManyAttempts($key, self::max())) {
            throw new ThrottleRequestsException('Terlalu banyak percobaan token.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
    }

    public static function failed(Request $request): void
    {
        RateLimiter::hit(self::key($request), self::DECAY_SECONDS);
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
