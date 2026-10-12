<?php

namespace App\Domain\Identity;

use Closure;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Aturan kata sandi baru (registrasi utama/tambahan, reset, ganti, bootstrap operator):
 * minimal 8 karakter berisi huruf dan angka, maksimal 72 byte UTF-8, tanpa karakter NUL.
 *
 * Batas byte mengikuti bcrypt. PHP 8.4.13 (diamati): password_hash bcrypt memotong diam-diam
 * di byte ke-72, dan karakter NUL melempar ValueError yang dibungkus Laravel menjadi
 * RuntimeException (500). config/hashing.php memasang limit 72 sehingga hasher menolak, bukan
 * memotong, bila validasi ini terlewat. Huruf/angka ASCII = 1 byte; huruf beraksen 2 byte;
 * emoji 4 byte. Klien boleh memakai batas byte yang sama (UTF-8) agar pesan konsisten.
 */
final class PasswordPolicy
{
    public const MAX_BYTES = 72;

    public const MESSAGE_BYTES = 'Kata sandi maksimal 72 byte. Huruf dan angka biasa dihitung 1 byte; huruf beraksen atau emoji dihitung lebih.';

    public const MESSAGE_NUL = 'Kata sandi berisi karakter yang tidak didukung.';

    /** Aturan validasi untuk kata sandi baru (tanpa required/confirmed milik pemanggil). */
    public static function rules(): array
    {
        return ['string', self::byteLimit(), PasswordRule::min(8)->letters()->numbers()];
    }

    public static function byteLimit(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }
            if (str_contains($value, "\0")) {
                $fail(self::MESSAGE_NUL);
            } elseif (strlen($value) > self::MAX_BYTES) {
                $fail(self::MESSAGE_BYTES);
            }
        };
    }
}
