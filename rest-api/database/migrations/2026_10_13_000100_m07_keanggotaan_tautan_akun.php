<?php

use App\Domain\Residents\LegacyBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| M07 (additive): histori keanggotaan keluarga dan tautan akun dengan rentang waktu.
| Pointer lama (warga.keluarga_id, warga.is_kepala, akun.warga_id, akun.jenis) tetap ada dan
| disinkronkan dalam transaksi yang sama sehingga image M06 tetap membaca data yang benar.
|   keanggotaan_keluarga: satu keanggotaan aktif per warga.
|   tautan_akun: satu tautan aktif per akun dan per warga; satu akun utama aktif per keluarga
|   (= per rumah, karena penghunian aktif tetap satu per rumah dan satu per keluarga).
| mulai_at NULL = tercatat sebelum M07; tanggal lama tidak dikarang. Backfill hanya menambah baris.
| rumah.versi: optimistic version untuk edit pengurus.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keanggotaan_keluarga', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('keluarga_id')->constrained('keluarga')->restrictOnDelete();
            $table->foreignUuid('warga_id')->constrained('warga')->restrictOnDelete();
            $table->string('hubungan_keluarga', 40);
            $table->timestampTz('mulai_at')->nullable();
            $table->timestampTz('selesai_at')->nullable();
            $table->string('alasan_selesai', 30)->nullable();
            $table->string('sumber', 20);
            $table->foreignUuid('dicatat_oleh_id')->nullable()->constrained('akun')->restrictOnDelete();
            $table->foreignUuid('diakhiri_oleh_id')->nullable()->constrained('akun')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('warga_id');
        });
        DB::statement('ALTER TABLE keanggotaan_keluarga ADD CONSTRAINT keanggotaan_rentang_valid CHECK (selesai_at IS NULL OR mulai_at IS NULL OR selesai_at >= mulai_at)');
        DB::statement('ALTER TABLE keanggotaan_keluarga ADD CONSTRAINT keanggotaan_alasan_selesai CHECK ((selesai_at IS NULL) = (alasan_selesai IS NULL))');
        DB::statement("ALTER TABLE keanggotaan_keluarga ADD CONSTRAINT keanggotaan_alasan_check CHECK (alasan_selesai IN ('pindah_keluarga', 'pindah', 'meninggal', 'diarsipkan'))");
        DB::statement("ALTER TABLE keanggotaan_keluarga ADD CONSTRAINT keanggotaan_sumber_check CHECK (sumber IN ('migrasi', 'registrasi', 'persetujuan', 'akun_utama', 'pengurus'))");
        DB::statement('CREATE UNIQUE INDEX keanggotaan_satu_aktif_per_warga ON keanggotaan_keluarga (warga_id) WHERE selesai_at IS NULL');
        DB::statement('CREATE INDEX keanggotaan_aktif_per_keluarga ON keanggotaan_keluarga (keluarga_id) WHERE selesai_at IS NULL');

        Schema::create('tautan_akun', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('akun_id')->constrained('akun')->restrictOnDelete();
            $table->foreignUuid('warga_id')->constrained('warga')->restrictOnDelete();
            $table->foreignUuid('keluarga_id')->constrained('keluarga')->restrictOnDelete();
            $table->boolean('is_utama');
            $table->timestampTz('mulai_at')->nullable();
            $table->timestampTz('selesai_at')->nullable();
            $table->string('alasan_selesai', 30)->nullable();
            $table->string('sumber', 20);
            $table->foreignUuid('dicatat_oleh_id')->nullable()->constrained('akun')->restrictOnDelete();
            $table->foreignUuid('diakhiri_oleh_id')->nullable()->constrained('akun')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('akun_id');
        });
        DB::statement('ALTER TABLE tautan_akun ADD CONSTRAINT tautan_rentang_valid CHECK (selesai_at IS NULL OR mulai_at IS NULL OR selesai_at >= mulai_at)');
        DB::statement('ALTER TABLE tautan_akun ADD CONSTRAINT tautan_alasan_selesai CHECK ((selesai_at IS NULL) = (alasan_selesai IS NULL))');
        DB::statement("ALTER TABLE tautan_akun ADD CONSTRAINT tautan_alasan_check CHECK (alasan_selesai IN ('pindah_keluarga', 'pergantian_utama', 'akun_diarsipkan', 'diarsipkan'))");
        DB::statement("ALTER TABLE tautan_akun ADD CONSTRAINT tautan_sumber_check CHECK (sumber IN ('migrasi', 'registrasi', 'persetujuan', 'pengurus'))");
        DB::statement('CREATE UNIQUE INDEX tautan_satu_aktif_per_akun ON tautan_akun (akun_id) WHERE selesai_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX tautan_satu_aktif_per_warga ON tautan_akun (warga_id) WHERE selesai_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX tautan_satu_utama_per_keluarga ON tautan_akun (keluarga_id) WHERE is_utama AND selesai_at IS NULL');

        DB::statement('ALTER TABLE rumah ADD COLUMN versi integer NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE rumah ADD CONSTRAINT rumah_versi_positif CHECK (versi >= 1)');

        LegacyBackfill::run();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rumah DROP COLUMN IF EXISTS versi');
        Schema::dropIfExists('tautan_akun');
        Schema::dropIfExists('keanggotaan_keluarga');
    }
};
