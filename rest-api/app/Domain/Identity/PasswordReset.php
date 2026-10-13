<?php

namespace App\Domain\Identity;

use App\Models\Akun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Token reset dibuat di dalam transaksi pemanggil; email dikirim/diantrekan sesudah commit.
 * Kegagalan transport atau antrean (SMTP menolak, Redis mati, antrean sync) tidak membatalkan
 * rekaman yang sudah commit dan dicatat tanpa token/email (AGENTS: kegagalan email tidak boleh
 * menghapus transaksi bisnis). Pengguna dapat meminta reset lagi setelah transport pulih.
 */
final class PasswordReset
{
    /** Token baru, atau null bila token terakhir terlalu baru (throttle broker, auth.passwords.akun.throttle). */
    public static function createToken(Akun $akun): ?string
    {
        $broker = Password::broker('akun');
        if ($broker->getRepository()->recentlyCreatedToken($akun)) {
            return null;
        }

        return $broker->createToken($akun);
    }

    /** Panggil di luar transaksi. True bila notifikasi berhasil diantrekan/dikirim. */
    public static function notify(Akun $akun, string $token): bool
    {
        try {
            $akun->sendPasswordResetNotification($token);

            return true;
        } catch (Throwable $e) {
            Log::warning('Email reset gagal diantrekan/dikirim; data yang sudah commit tetap tersimpan.', ['exception' => $e::class]);

            return false;
        }
    }
}
