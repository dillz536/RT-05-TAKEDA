<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Warga extends Model
{
    protected $table = 'warga';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'is_kepala' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function keluarga(): BelongsTo
    {
        return $this->belongsTo(Keluarga::class, 'keluarga_id');
    }

    /** Akun yang pointer-nya menunjuk warga ini (akun.warga_id unik; bisa sudah diarsipkan). */
    public function akun(): HasOne
    {
        return $this->hasOne(Akun::class, 'warga_id');
    }

    public function keanggotaan(): HasMany
    {
        return $this->hasMany(KeanggotaanKeluarga::class, 'warga_id');
    }

    public function keanggotaanAktif(): HasOne
    {
        return $this->hasOne(KeanggotaanKeluarga::class, 'warga_id')->whereNull('selesai_at');
    }

    public function tautanAktif(): HasOne
    {
        return $this->hasOne(TautanAkun::class, 'warga_id')->whereNull('selesai_at');
    }
}
