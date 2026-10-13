<?php

namespace App\Http\Middleware;

use App\Models\Akun;
use App\Support\BrowserSession;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Status dan peran akun diperiksa ulang pada setiap request (bukan dari cache APK).
 *   akun            → status aktif
 *   akun:menunggu   → aktif atau menunggu (endpoint terbatas akun tambahan pending)
 *   akun:pengurus   → aktif dan peran pengurus
 * Akun nonaktif/diarsipkan: token dicabut dan 403.
 * Sesi cookie CMS hanya untuk pengurus aktif dengan generasi sesi terbaru dan belum idle;
 * selain itu sesi diakhiri dan 401 (BrowserSession).
 */
class EnsureAkun
{
    public function handle(Request $request, Closure $next, string $mode = 'aktif'): Response
    {
        /** @var Akun|null $akun */
        $akun = $request->user();
        if (! $akun) {
            throw new AuthenticationException;
        }

        // Fail closed: user sesi tanpa sesi yang berjalan pada request ini juga ditolak.
        if (BrowserSession::isCookie($akun) && (! $request->hasSession() || ! BrowserSession::valid($request, $akun))) {
            BrowserSession::end($request);
            throw new AuthenticationException;
        }

        if (in_array($akun->status, ['nonaktif', 'diarsipkan'], true)) {
            $akun->tokens()->delete();
            throw new AuthorizationException('Akun tidak aktif.');
        }

        $allowed = $mode === 'menunggu' ? ['aktif', 'menunggu'] : ['aktif'];
        if (! in_array($akun->status, $allowed, true)) {
            throw new AuthorizationException('Akun menunggu persetujuan pengurus.');
        }

        if ($mode === 'pengurus' && ! $akun->isPengurus()) {
            throw new AuthorizationException('Khusus pengurus.');
        }

        return $next($request);
    }
}
