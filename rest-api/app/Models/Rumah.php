<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Rumah extends Model
{
    protected $table = 'rumah';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'tagihan_aktif' => 'boolean',
            'versi' => 'integer',
            'mulai_tagih' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function pemilik(): BelongsTo
    {
        return $this->belongsTo(PemilikRumah::class, 'pemilik_id');
    }

    public function penghunian(): HasMany
    {
        return $this->hasMany(Penghunian::class, 'rumah_id');
    }

    public function tagihan(): HasMany
    {
        return $this->hasMany(TagihanIuran::class, 'rumah_id');
    }

    public function penghunianAktif(): HasOne
    {
        return $this->hasOne(Penghunian::class, 'rumah_id')->whereNull('selesai_tanggal');
    }

    public function alamat(): string
    {
        return trim(($this->blok ? 'Blok '.$this->blok.', ' : '').$this->jalan.' No. '.$this->nomor);
    }
}
