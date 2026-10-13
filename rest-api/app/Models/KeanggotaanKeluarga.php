<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histori keanggotaan warga pada keluarga (M07). selesai_at NULL = aktif; mulai_at NULL = sebelum M07. */
class KeanggotaanKeluarga extends Model
{
    protected $table = 'keanggotaan_keluarga';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'mulai_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    public function keluarga(): BelongsTo
    {
        return $this->belongsTo(Keluarga::class, 'keluarga_id');
    }

    public function warga(): BelongsTo
    {
        return $this->belongsTo(Warga::class, 'warga_id');
    }
}
