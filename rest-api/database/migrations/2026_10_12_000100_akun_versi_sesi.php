<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| M06: generasi sesi browser CMS. Sesi cookie menyimpan versi_sesi saat login; reset/ganti
| password, logout-all, perubahan status, dan grant/revoke peran operator menaikkan nilainya
| sehingga sesi lama ditolak pada request berikutnya, apa pun driver sesinya (Redis/array).
| Additive: kolom NOT NULL dengan default, aplikasi lama tetap berjalan.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE akun ADD COLUMN versi_sesi integer NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE akun ADD CONSTRAINT akun_versi_sesi_positif CHECK (versi_sesi >= 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE akun DROP COLUMN versi_sesi');
    }
};
