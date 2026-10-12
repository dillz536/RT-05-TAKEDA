<?php

namespace App\Notifications;

use App\Models\Akun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Email reset kata sandi (M06). Diantrekan pada antrean default setelah commit; payload antrean
 * dienkripsi (ShouldBeEncrypted) agar token tidak tersimpan polos di Redis atau failed_jobs.
 *
 * Tautan HTTPS ke GET /reset-password milik API. Token dan email berada di fragment URL (#),
 * yang tidak dikirim browser ke server, sehingga tidak masuk log proxy/API. Email yang sama
 * memuat kode untuk ditempel di aplikasi Android (tanpa App Links terverifikasi).
 */
class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $token)
    {
        $this->onQueue('default');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Dievaluasi di job antrean sebelum transport dipanggil. Di SEMUA environment (termasuk
     * local yang dapat terhubung ke Neon dev): mailer yang memakai transport log, langsung atau
     * di dalam failover/roundrobin, akan menulis isi email (berisi token) ke log, sehingga email
     * tidak dikirim. Tautan wajib HTTPS kecuali local/testing (URL sintetis). Peringatan tanpa
     * token/email; respons lupa password tetap 202 generik dan transaksi tidak terpengaruh.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (self::mailerWritesLog((string) config('mail.default'))) {
            Log::warning('Email reset tidak dikirim: mailer bertransport log atau tidak dikenal; token tidak boleh masuk log. Konfigurasikan SMTP.');

            return false;
        }
        if (! app()->environment('local', 'testing') && ! str_starts_with((string) config('app.url'), 'https://')) {
            Log::warning('Email reset tidak dikirim: APP_URL harus HTTPS.');

            return false;
        }

        return true;
    }

    /** True bila mailer (atau salah satu mailer failover/roundrobin di dalamnya) bertransport log. */
    public static function mailerWritesLog(string $mailer, int $depth = 0): bool
    {
        $config = config("mail.mailers.$mailer");
        if (! is_array($config) || $depth > 5) {
            // Mailer tidak dikenal/terlalu bersarang: fail closed.
            return true;
        }
        $transport = $config['transport'] ?? null;
        if ($transport === 'log') {
            return true;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            foreach ((array) ($config['mailers'] ?? []) as $inner) {
                if (self::mailerWritesLog((string) $inner, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function toMail(Akun $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.akun.expire', 60);

        return (new MailMessage)
            ->subject('Reset kata sandi RT05 TAKEDA')
            ->greeting('Halo '.$notifiable->nama.',')
            ->line('Kami menerima permintaan reset kata sandi untuk akun RT05 TAKEDA Anda.')
            ->action('Atur kata sandi baru', self::url($this->token, $notifiable->email))
            ->line('Di aplikasi Android, buka "Lupa kata sandi", masukkan email Anda, lalu tempel kode berikut:')
            ->line($this->token)
            ->line("Tautan dan kode berlaku $minutes menit dan hanya dapat dipakai sekali.")
            ->line('Abaikan email ini jika Anda tidak meminta reset. Kata sandi Anda tidak berubah.')
            ->salutation('Pengurus RT 05 Taman Kedaung');
    }

    /** https://<APP_URL>/reset-password#token=...&email=... (fragment, bukan query). */
    public static function url(string $token, string $email): string
    {
        return rtrim((string) config('app.url'), '/').'/reset-password#'.http_build_query(['token' => $token, 'email' => $email], '', '&', PHP_QUERY_RFC3986);
    }
}
