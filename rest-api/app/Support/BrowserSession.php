<?php

namespace App\Support;

use App\Models\Akun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;

/**
 * Sesi cookie CMS pengurus (guard web, dipakai Sanctum untuk request stateful dari origin sendiri).
 * Sesi menyimpan generasi akun.versi_sesi dan waktu aktivitas terakhir; setiap request diperiksa
 * ulang terhadap database sehingga reset/ganti password, logout-all, perubahan status, dan
 * grant/revoke peran mengakhiri sesi lama walaupun record sesi di Redis masih ada.
 */
final class BrowserSession
{
    private const VERSI = 'rt05.versi_sesi';

    private const AKTIVITAS = 'rt05.aktivitas_at';

    public static function start(Request $request, Akun $akun): void
    {
        Auth::guard('web')->login($akun);
        // Cegah session fixation: ID sesi baru setelah login.
        $request->session()->regenerate();
        self::bind($request, $akun);
    }

    /** Ikat sesi saat ini ke generasi akun terbaru (mis. setelah ganti password dari sesi ini). */
    public static function bind(Request $request, Akun $akun): void
    {
        $request->session()->put(self::VERSI, (int) $akun->versi_sesi);
        $request->session()->put(self::AKTIVITAS, now()->getTimestamp());
    }

    /** True bila user request ini diautentikasi lewat cookie sesi, bukan bearer perangkat. */
    public static function isCookie(Akun $akun): bool
    {
        return $akun->currentAccessToken() instanceof TransientToken;
    }

    /** Sesi masih sah: generasi sama, belum idle melewati session.lifetime, pengurus aktif. */
    public static function valid(Request $request, Akun $akun): bool
    {
        $session = $request->session();
        $versi = $session->get(self::VERSI);
        $aktivitas = (int) $session->get(self::AKTIVITAS, 0);
        $idle = 60 * (int) config('session.lifetime', 120);

        if ($versi === null || (int) $versi !== (int) $akun->versi_sesi
            || now()->getTimestamp() - $aktivitas > $idle
            || ! $akun->isPengurus() || ! $akun->isAktif()) {
            return false;
        }
        $session->put(self::AKTIVITAS, now()->getTimestamp());

        return true;
    }

    public static function end(Request $request): void
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
