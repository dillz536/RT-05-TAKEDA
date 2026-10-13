<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histori tautan akun ↔ warga/keluarga (M07). selesai_at NULL = aktif. is_utama = akun utama
 * keluarga (hak ubah data keluarga). Rumah diturunkan dari penghunian aktif keluarga.
 */
class TautanAkun extends Model
{
    protected $table = 'tautan_akun';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'is_utama' => 'boolean',
            'mulai_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    public function akun(): BelongsTo
    {
        return $this->belongsTo(Akun::class, 'akun_id');
    }

    public function warga(): BelongsTo
    {
        return $this->belongsTo(Warga::class, 'warga_id');
    }

    public function keluarga(): BelongsTo
    {
        return $this->belongsTo(Keluarga::class, 'keluarga_id');
    }
}
