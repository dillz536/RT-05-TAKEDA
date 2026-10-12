<?php

namespace App\Domain\Identity;

use App\Models\Akun;
use App\Models\TokenRegistrasi;
use App\Support\Audit;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Token registrasi RT 6 digit (BR03/BR04): acak kriptografis, string agar nol di depan tetap,
 * satu token aktif, rotasi mencabut token lama. Disimpan sebagai ciphertext (lihat/salin
 * pengurus) + HMAC digest (verifikasi). Bukan OTP login, bukan API token, tidak memberi hak admin.
 *
 * Penguncian: rotasi diserialkan advisory lock rotasi, lalu mengantre pada advisory lock
 * registrasi yang sama dengan Registration (antrean FIFO PostgreSQL), lalu mengunci baris aktif
 * FOR UPDATE; registrasi membaca token aktif FOR SHARE. Urutannya deterministik: registrasi yang
 * sudah memegang lock selesai lebih dulu dengan token lama; registrasi yang datang setelah rotasi
 * mengantre menunggu rotasi commit dan ditolak generik karena token lama sudah dicabut.
 * Kode baru tidak pernah sama dengan kode token yang dicabut/terakhir.
 */
final class RegistrationToken
{
    /** Umur token sebelum rotasi terjadwal; berlaku_sampai memberi sedikit kelonggaran. */
    public const ROTATE_AFTER_DAYS = 7;

    public const VALID_DAYS = 8;

    /** Seam tes: pembangkit kode (default random_int kriptografis). */
    private static ?Closure $generator = null;

    public static function generateCodesUsing(?Closure $generator): void
    {
        self::$generator = $generator;
    }

    public static function digest(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    public static function active(): ?TokenRegistrasi
    {
        return self::activeQuery()->first();
    }

    /** Rotasi: cabut token aktif lalu buat token baru, atomik. Mengembalikan kode plaintext. */
    public static function rotate(?Akun $actor, string $reason = 'manual'): string
    {
        return DB::transaction(function () use ($actor, $reason) {
            self::lockRotation();

            return self::rotateLocked($actor, $reason);
        });
    }

    /** Token aktif untuk lihat/salin pengurus; dibuat sekali bila belum ada (GET concurrent aman). */
    public static function ensureActive(?Akun $actor): TokenRegistrasi
    {
        return DB::transaction(function () use ($actor) {
            self::lockRotation();
            if (! self::active()) {
                self::rotateLocked($actor, 'otomatis: belum ada token aktif');
            }

            return self::active();
        });
    }

    /**
     * Rotasi mingguan yang tahan scheduler terlewat: rotasi bila tidak ada token aktif yang
     * berlaku atau token aktif sudah berumur ROTATE_AFTER_DAYS. Mengembalikan true bila dirotasi.
     */
    public static function rotateIfDue(string $reason = 'terjadwal mingguan'): bool
    {
        return DB::transaction(function () use ($reason) {
            self::lockRotation();
            $token = self::active();
            if ($token && $token->created_at->gt(now()->subDays(self::ROTATE_AFTER_DAYS))) {
                return false;
            }
            self::rotateLocked(null, $reason);

            return true;
        });
    }

    /**
     * Mencocokkan kode terhadap token aktif. Mengembalikan token atau null (pesan generik di pemanggil).
     * Di dalam transaksi registrasi, baris token dikunci FOR SHARE sampai commit agar rotasi menunggu.
     */
    public static function verify(string $code): ?TokenRegistrasi
    {
        if (! preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $query = self::activeQuery();
        if (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }
        $token = $query->first();

        return $token && hash_equals($token->kode_digest, self::digest($code)) ? $token : null;
    }

    public static function reveal(TokenRegistrasi $token): string
    {
        return Crypt::decryptString($token->kode_terenkripsi);
    }

    private static function activeQuery()
    {
        return TokenRegistrasi::where('aktif', true)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhere('berlaku_sampai', '>', now()));
    }

    private static function lockRotation(): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext('rt05:token-registrasi'))");
        // Lock registrasi yang sama dengan Registration::lockAndVerify (tidak sebaliknya: registrasi
        // tidak pernah mengambil lock rotasi, jadi tidak ada deadlock).
        DB::select("SELECT pg_advisory_xact_lock(hashtext('rt05:registrasi'))");
    }

    private static function rotateLocked(?Akun $actor, string $reason): string
    {
        // Menunggu registrasi yang memegang FOR SHARE pada token lama selesai.
        DB::table('token_registrasi')->where('aktif', true)->lockForUpdate()->get(['id']);
        // Kode token aktif yang dicabut dan token terakhir (mis. sudah kedaluwarsa) tidak dipakai ulang.
        $previous = DB::table('token_registrasi')->where('aktif', true)
            ->orWhere('id', DB::table('token_registrasi')->select('id')->orderByDesc('created_at')->limit(1))
            ->pluck('kode_digest')->all();
        DB::table('token_registrasi')->where('aktif', true)->update(['aktif' => false, 'dicabut_at' => now()]);

        $attempts = 0;
        do {
            if (++$attempts > 50) {
                // Mustahil dengan random_int; batas ini hanya mencegah loop tanpa akhir (rollback).
                throw new RuntimeException('Kode token registrasi baru tidak dapat dibuat.');
            }
            $code = self::$generator ? (string) (self::$generator)() : str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        } while (! preg_match('/^\d{6}$/', $code) || in_array(self::digest($code), $previous, true));
        $token = TokenRegistrasi::create([
            'dibuat_oleh_id' => $actor?->id,
            'kode_terenkripsi' => Crypt::encryptString($code),
            'kode_digest' => self::digest($code),
            'aktif' => true,
            'berlaku_sampai' => now()->addDays(self::VALID_DAYS),
        ]);

        // Audit tanpa nilai token.
        Audit::record($actor, 'token_registrasi.dirotasi', 'token_registrasi', $token->id, ['alasan' => $reason]);

        return $code;
    }
}
