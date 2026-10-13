<?php

namespace Tests\Feature;

use App\Models\Akun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedData;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Rollback dan re-apply migration M07 pada Postgres *_test dengan data commit (seperti image M06
 * yang tetap berjalan): down hanya menghapus tabel/kolom additive M07, data rumah/keluarga/akun
 * utuh; up mengisi ulang histori dari pointer lama. Schema selalu dipulihkan di finally.
 */
class M07MigrationRollbackTest extends TestCase
{
    use CommittedData, Fixtures;

    private const MIGRATION = '2026_10_13_000100_m07_keanggotaan_tautan_akun';

    protected function setUp(): void
    {
        parent::setUp();
        $this->markCommittedData();
    }

    protected function tearDown(): void
    {
        $this->migrate();
        $this->purgeCommittedData();
        parent::tearDown();
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]);
        DB::connection('pgsql_migrations')->disconnect();
    }

    private function snapshot(): array
    {
        return [
            DB::table('rumah')->orderBy('id')->get(['id', 'kode_rumah', 'alamat_key', 'tagihan_aktif'])->toArray(),
            DB::table('keluarga')->orderBy('id')->get(['id', 'status', 'versi'])->toArray(),
            DB::table('warga')->orderBy('id')->get(['id', 'keluarga_id', 'is_kepala', 'archived_at'])->toArray(),
            DB::table('akun')->orderBy('id')->get(['id', 'warga_id', 'jenis', 'status'])->toArray(),
            DB::table('penghunian')->orderBy('id')->get(['id', 'rumah_id', 'keluarga_id', 'mulai_tanggal', 'selesai_tanggal'])->toArray(),
        ];
    }

    public function test_rollback_drops_only_m07_additions_and_reapply_backfills(): void
    {
        $utama = $this->warga('71');
        $tambahan = $this->tambahan('71', 'rollback@contoh.test');
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'pgsql_migrations', '--step' => 1, '--force' => true]));
        DB::connection('pgsql_migrations')->disconnect();
        $this->assertFalse(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $this->assertFalse(Schema::hasTable('tautan_akun'));
        $this->assertFalse(Schema::hasTable('keanggotaan_keluarga'));
        $this->assertFalse(Schema::hasColumn('rumah', 'versi'));
        $this->assertEquals($before, $this->snapshot(), 'data M06 utuh setelah down');

        $this->migrate();
        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $keluargaId = DB::table('warga')->where('id', $utama->warga_id)->value('keluarga_id');
        $this->assertSame($keluargaId, DB::table('tautan_akun')->where('akun_id', $utama->id)->whereNull('selesai_at')->where('is_utama', true)->value('keluarga_id'));
        $this->assertFalse((bool) DB::table('tautan_akun')->where('akun_id', $tambahan->id)->value('is_utama'));
        $this->assertSame(0, DB::table('keanggotaan_keluarga')->whereNotNull('mulai_at')->count());
        $this->assertSame(3, DB::table('keanggotaan_keluarga')->where('keluarga_id', $keluargaId)->count());

        // Runtime role tetap memiliki DML pada tabel yang dibuat ulang (default privileges).
        $versi = DB::table('keluarga')->where('id', $keluargaId)->value('versi');
        $this->as(Akun::find($utama->id))->patchJson('/api/v1/household', ['versi' => $versi, 'whatsapp' => '081234567890'])->assertOk();
        $this->assertSame($versi + 1, DB::table('keluarga')->where('id', $keluargaId)->value('versi'));
    }
}
