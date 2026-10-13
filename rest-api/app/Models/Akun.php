<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * ERD v1.1 tabel akun. peran: warga | pengurus (semua pengurus setara, BR07).
 * jenis: utama | tambahan. status: menunggu | aktif | nonaktif | diarsipkan.
 * Peran tidak pernah diisi dari request; grant pengurus hanya lewat command operator.
 */
class Akun extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasApiTokens, HasUuids, Notifiable;

    protected $table = 'akun';

    protected $guarded = ['id', 'peran'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $authPasswordName = 'password_hash';

    protected $hidden = ['password_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'email_verified_at' => 'datetime',
            'archived_at' => 'datetime',
            'versi_sesi' => 'integer',
        ];
    }

    /**
     * Mengakhiri sesi browser CMS lama: sesi cookie menyimpan versi_sesi saat login dan
     * ditolak bila nilainya berbeda (EnsureAkun). Mengembalikan generasi baru.
     */
    public function endBrowserSessions(): int
    {
        $versi = (int) DB::selectOne('UPDATE akun SET versi_sesi = versi_sesi + 1 WHERE id = ? RETURNING versi_sesi', [$this->id])->versi_sesi;
        $this->forceFill(['versi_sesi' => $versi])->syncOriginalAttribute('versi_sesi');

        return $versi;
    }

    /** Cabut semua bearer perangkat dan semua sesi browser (status/peran/reset/logout-all). */
    public function revokeAllAccess(): void
    {
        $this->tokens()->delete();
        $this->endBrowserSessions();
    }

    /** Email reset diantrekan after_commit; tautan HTTPS ke /reset-password dan kode untuk Android. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function warga(): BelongsTo
    {
        return $this->belongsTo(Warga::class, 'warga_id');
    }

    public function permohonan(): HasOne
    {
        return $this->hasOne(PermohonanAkun::class, 'akun_id');
    }

    public function isPengurus(): bool
    {
        return $this->peran === 'pengurus';
    }

    public function isAktif(): bool
    {
        return $this->status === 'aktif';
    }

    public function isUtama(): bool
    {
        return $this->jenis === 'utama';
    }

    /** Keluarga aktif akun ini (melalui warga), atau null bila belum terhubung. */
    public function keluarga(): ?Keluarga
    {
        return $this->warga?->keluarga;
    }

    /** Rumah dari penghunian aktif keluarga akun. */
    public function rumah(): ?Rumah
    {
        return $this->keluarga()?->penghunianAktif?->rumah;
    }
}
