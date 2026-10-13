<?php

namespace App\Console\Commands;

use App\Domain\Residents\LegacyBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Melengkapi histori keanggotaan/tautan M07 dari pointer lama, mis. setelah rollback aplikasi ke
 * image M06 yang sempat menulis registrasi tanpa histori. Idempotent; tidak mengubah baris ada.
 * Berjalan dengan role runtime (DML) dalam satu transaksi; tidak mencetak data warga.
 */
class ResidentsBackfill extends Command
{
    protected $signature = 'rt05:residents-backfill';

    protected $description = 'Lengkapi histori keanggotaan keluarga dan tautan akun M07 (idempotent)';

    public function handle(): int
    {
        $result = DB::transaction(fn () => LegacyBackfill::run());
        $this->info("Keanggotaan ditambahkan: {$result['keanggotaan']}; tautan ditambahkan: {$result['tautan']}.");

        return self::SUCCESS;
    }
}
