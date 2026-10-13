<?php

namespace Tests\Feature;

use App\Domain\Residents\LegacyBackfill;
use App\Models\Akun;
use App\Models\KeanggotaanKeluarga;
use App\Models\TautanAkun;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * M07 schema: backfill konservatif dari pointer lama (tanpa tanggal karangan, tanpa mengubah
 * baris lama, idempotent) dan constraint Postgres nyata untuk histori aktif.
 * Rollback/re-apply migration ada di M07MigrationRollbackTest (data commit, DDL).
 */
class M07MigrationTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    /** Hapus histori M07 agar data menyerupai baris yang ditulis image M06. */
    private function forgetHistory(): void
    {
        DB::table('tautan_akun')->delete();
        DB::table('keanggotaan_keluarga')->delete();
    }

    private function pointers(): array
    {
        return [
            DB::table('warga')->orderBy('id')->get(['id', 'keluarga_id', 'hubungan_keluarga', 'is_kepala', 'status', 'archived_at'])->toArray(),
            DB::table('akun')->orderBy('id')->get(['id', 'warga_id', 'jenis', 'status', 'archived_at'])->toArray(),
        ];
    }

    public function test_backfill_preserves_legacy_associations_without_inventing_dates(): void
    {
        $utama = $this->warga('1');
        $tambahan = $this->tambahan('1', 'anak@contoh.test');
        $pending = $this->pemohon('1', 'tunggu@contoh.test');
        $this->warga('2');
        // Anggota yang sudah keluar sebelum M07 dan akun yang sudah diarsipkan.
        $keluar = DB::table('warga')->where('nama', 'Uji Anak 2')->value('id');
        DB::table('warga')->where('id', $keluar)->update(['archived_at' => '2026-10-01 03:00:00+00', 'status' => 'meninggal']);
        $arsip = $this->warga('3');
        DB::table('akun')->where('id', $arsip->id)->update(['status' => 'diarsipkan', 'archived_at' => '2026-10-02 04:00:00+00']);

        $this->forgetHistory();
        $before = $this->pointers();
        $result = LegacyBackfill::run();

        $this->assertSame(DB::table('warga')->count(), $result['keanggotaan']);
        $this->assertSame(4, $result['tautan']);
        $this->assertSame(0, KeanggotaanKeluarga::whereNotNull('mulai_at')->count(), 'tanggal mulai lama tidak dikarang');
        $this->assertSame(0, TautanAkun::whereNotNull('mulai_at')->count());
        $this->assertSame(0, KeanggotaanKeluarga::where('sumber', '!=', 'migrasi')->count());

        $ended = KeanggotaanKeluarga::where('warga_id', $keluar)->sole();
        $this->assertSame('meninggal', $ended->alasan_selesai);
        $this->assertSame('2026-10-01T03:00:00Z', $ended->selesai_at->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(DB::table('warga')->where('id', $keluar)->value('keluarga_id'), $ended->keluarga_id);

        $this->assertTrue(TautanAkun::where('akun_id', $utama->id)->sole()->is_utama);
        $this->assertFalse(TautanAkun::where('akun_id', $tambahan->id)->sole()->is_utama);
        $this->assertSame(0, TautanAkun::where('akun_id', $pending->id)->count(), 'pending tanpa tautan');
        $arsipLink = TautanAkun::where('akun_id', $arsip->id)->sole();
        $this->assertSame('akun_diarsipkan', $arsipLink->alasan_selesai);
        $this->assertFalse($arsipLink->is_utama);

        $this->assertEquals($before, $this->pointers(), 'baris lama tidak diubah');
        $this->assertSame(['keanggotaan' => 0, 'tautan' => 0], LegacyBackfill::run(), 'idempotent');

        // Setelah backfill, akses M07 berjalan dari tautan.
        $this->as(Akun::find($utama->id))->getJson('/api/v1/household')->assertOk()->assertJsonPath('data.dapat_ubah', true);
        $this->as(Akun::find($tambahan->id))->getJson('/api/v1/household')->assertOk()->assertJsonPath('data.dapat_ubah', false);
    }

    public function test_backfill_keeps_only_earliest_legacy_primary_per_family(): void
    {
        $utama = $this->warga('1');
        $tambahan = $this->tambahan('1', 'anak@contoh.test');
        $this->forgetHistory();
        // Data lama tidak konsisten: dua akun utama pada satu keluarga.
        DB::table('akun')->where('id', $tambahan->id)->update(['jenis' => 'utama', 'created_at' => now()->addMinute()]);

        LegacyBackfill::run();

        $this->assertSame([$utama->id], TautanAkun::where('is_utama', true)->pluck('akun_id')->all());
        $this->assertSame('utama', DB::table('akun')->where('id', $tambahan->id)->value('jenis'), 'akun.jenis tidak diubah');
    }

    public function test_backfill_never_grants_active_links_to_ended_or_inconsistent_pointers(): void
    {
        $sehat = $this->warga('1');
        $keluar = $this->tambahan('1', 'keluar@contoh.test');
        $arsipKeluarga = $this->warga('2');
        $arsipTanpaTanggal = $this->warga('3');
        $tungguBertaut = $this->tambahan('3', 'tunggu-bertaut@contoh.test');
        $this->forgetHistory();
        // Akun aktif yang warganya sudah keluar; akun di keluarga yang sudah diarsipkan (warga belum);
        // akun berstatus diarsipkan tanpa tanggal; akun menunggu yang sudah menunjuk warga.
        DB::table('warga')->where('id', $keluar->warga_id)->update(['archived_at' => '2026-09-01 01:00:00+00', 'status' => 'pindah']);
        $k2 = DB::table('warga')->where('id', $arsipKeluarga->warga_id)->value('keluarga_id');
        DB::table('keluarga')->where('id', $k2)->update(['archived_at' => '2026-09-02 02:00:00+00']);
        DB::table('akun')->where('id', $arsipTanpaTanggal->id)->update(['status' => 'diarsipkan', 'archived_at' => null]);
        DB::table('akun')->where('id', $tungguBertaut->id)->update(['status' => 'menunggu']);
        $before = $this->pointers();

        LegacyBackfill::run();

        $this->assertTrue(TautanAkun::where('akun_id', $sehat->id)->whereNull('selesai_at')->sole()->is_utama);

        $l = TautanAkun::where('akun_id', $keluar->id)->sole();
        $this->assertSame(['2026-09-01T01:00:00Z', 'diarsipkan', false], [$l->selesai_at->utc()->format('Y-m-d\TH:i:s\Z'), $l->alasan_selesai, $l->is_utama]);
        $this->assertSame('pindah', KeanggotaanKeluarga::where('warga_id', $keluar->warga_id)->sole()->alasan_selesai);

        $l = TautanAkun::where('akun_id', $arsipKeluarga->id)->sole();
        $this->assertSame(['2026-09-02T02:00:00Z', 'diarsipkan', false], [$l->selesai_at->utc()->format('Y-m-d\TH:i:s\Z'), $l->alasan_selesai, $l->is_utama]);
        $this->assertSame(0, KeanggotaanKeluarga::where('keluarga_id', $k2)->whereNull('selesai_at')->count(), 'keluarga arsip tanpa anggota aktif');

        $this->assertSame(0, TautanAkun::whereIn('akun_id', [$arsipTanpaTanggal->id, $tungguBertaut->id])->count(), 'tanpa tanggal tercatat: tidak ditautkan');
        $this->assertSame(0, TautanAkun::where('keluarga_id', DB::table('warga')->where('id', $arsipTanpaTanggal->warga_id)->value('keluarga_id'))->whereNull('selesai_at')->count());
        $this->assertEquals($before, $this->pointers(), 'baris lama tidak diubah');
        $this->assertSame(['keanggotaan' => 0, 'tautan' => 0], LegacyBackfill::run(), 'idempotent');

        $this->as(Akun::find($keluar->id))->getJson('/api/v1/household')->assertNotFound();
        $this->as(Akun::find($arsipKeluarga->id))->patchJson('/api/v1/household', ['versi' => 1, 'whatsapp' => '081234567890'])->assertNotFound();
    }

    public function test_postgres_constraints_guard_active_history(): void
    {
        $utama = $this->warga('1');
        $tambahan = $this->tambahan('1', 'anak@contoh.test');
        $link = TautanAkun::where('akun_id', $tambahan->id)->sole();
        $membership = KeanggotaanKeluarga::where('warga_id', $tambahan->warga_id)->sole();

        $cases = [
            'utama kedua' => [UniqueConstraintViolationException::class, fn () => DB::table('tautan_akun')->where('id', $link->id)->update(['is_utama' => true])],
            'tautan aktif kedua per akun' => [UniqueConstraintViolationException::class, fn () => DB::table('tautan_akun')->insert([
                'akun_id' => $tambahan->id, 'warga_id' => $utama->warga_id, 'keluarga_id' => $link->keluarga_id, 'is_utama' => false, 'sumber' => 'pengurus'])],
            'keanggotaan aktif kedua' => [UniqueConstraintViolationException::class, fn () => DB::table('keanggotaan_keluarga')->insert([
                'keluarga_id' => $membership->keluarga_id, 'warga_id' => $membership->warga_id, 'hubungan_keluarga' => 'Anak', 'sumber' => 'pengurus'])],
            'selesai sebelum mulai' => [QueryException::class, fn () => DB::table('keanggotaan_keluarga')->where('id', $membership->id)
                ->update(['selesai_at' => $membership->mulai_at->subDay(), 'alasan_selesai' => 'pindah'])],
            'selesai tanpa alasan' => [QueryException::class, fn () => DB::table('tautan_akun')->where('id', $link->id)->update(['selesai_at' => now()])],
            'alasan di luar daftar' => [QueryException::class, fn () => DB::table('tautan_akun')->where('id', $link->id)->update(['selesai_at' => now(), 'alasan_selesai' => 'dihapus'])],
            'hapus warga berhistori' => [QueryException::class, fn () => DB::table('warga')->where('id', $tambahan->warga_id)->delete()],
            'versi rumah nol' => [QueryException::class, fn () => DB::table('rumah')->update(['versi' => 0])],
        ];
        foreach ($cases as $name => [$exception, $write]) {
            try {
                DB::transaction($write);
                $this->fail("Constraint tidak menolak: $name");
            } catch (QueryException $e) {
                $this->assertInstanceOf($exception, $e, $name);
            }
        }
        // Rentang lama yang sudah ditutup tidak menghalangi tautan aktif baru.
        DB::transaction(function () use ($link, $tambahan) {
            DB::table('tautan_akun')->where('id', $link->id)->update(['selesai_at' => now(), 'alasan_selesai' => 'pindah_keluarga']);
            DB::table('tautan_akun')->insert(['akun_id' => $tambahan->id, 'warga_id' => $tambahan->warga_id, 'keluarga_id' => $link->keluarga_id, 'is_utama' => false, 'sumber' => 'pengurus']);
        });
        $this->assertSame(2, TautanAkun::where('akun_id', $tambahan->id)->count());
    }
}
