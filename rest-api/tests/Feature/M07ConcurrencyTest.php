<?php

namespace Tests\Feature;

use App\Domain\Residents\Accounts;
use App\Domain\Residents\Households;
use App\Domain\Residents\Links;
use App\Exceptions\DomainConflict;
use App\Models\Akun;
use App\Models\KeanggotaanKeluarga;
use App\Models\Keluarga;
use App\Models\Penghunian;
use App\Models\PermohonanAkun;
use App\Models\Rumah;
use App\Models\TautanAkun;
use App\Models\Warga;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedData;
use Tests\Support\Concurrent;
use Tests\Support\Fixtures;
use Tests\TestCase;
use Throwable;

/**
 * M07 concurrency dengan proses PHP independen (stack HTTP penuh, koneksi Postgres masing-masing)
 * yang dilepas bersamaan oleh barrier advisory lock: hunian ganda satu rumah, akun utama ganda,
 * persetujuan ganda, edit keluarga dengan versi sama, dan pemindahan anggota ganda. Ditambah
 * interleaving deterministik: worker lolos pemeriksaan controller lalu menunggu lock keluarga
 * yang dipegang PHPUnit, sementara PHPUnit mencabut haknya (pindah utama, arsip akun, pindah
 * anggota) dan commit. Data commit dibersihkan di tearDown.
 */
class M07ConcurrencyTest extends TestCase
{
    use CommittedData, Concurrent, Fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markCommittedData();
    }

    protected function tearDown(): void
    {
        $this->purgeCommittedData();
        parent::tearDown();
    }

    private function http(string $method, string $uri, string $bearer, array $body, int $i): array
    {
        return ['kind' => 'http', 'method' => $method, 'uri' => $uri, 'bearer' => $bearer, 'body' => $body, 'ip' => "10.7.0.$i", 'label' => "m07-$i"];
    }

    private function bearer(Akun $akun): string
    {
        return $akun->createToken('race')->plainTextToken;
    }

    public function test_two_families_cannot_occupy_one_house_concurrently(): void
    {
        $admin = $this->pengurus();
        $token = $this->bearer($admin);
        $rumah = Rumah::create(['kode_rumah' => 'RAS-1', 'jalan' => 'Jl. Ras', 'nomor' => '1', 'alamat_key' => 'ras|ras|1']);
        $families = [];
        for ($i = 0; $i < 4; $i++) {
            $families[] = Households::create($admin, ['nama_kepala' => "Uji Ras $i"])->id;
        }

        $results = $this->runConcurrently(array_map(fn ($k, $i) => $this->http('POST', '/api/v1/admin/occupancies', $token,
            ['rumah_id' => $rumah->id, 'keluarga_id' => $k, 'jenis_hunian' => 'kontrak'], $i), $families, array_keys($families)));

        $this->assertSame(['201' => 1, '409:HOUSE_OCCUPIED' => 3], $this->tally($results));
        $this->assertSame(1, Penghunian::where('rumah_id', $rumah->id)->whereNull('selesai_tanggal')->count());
        $winner = Penghunian::where('rumah_id', $rumah->id)->value('keluarga_id');
        // Versi keluarga yang kalah tidak berubah (rollback penuh).
        foreach ($families as $k) {
            $this->assertSame($k === $winner ? 2 : 1, Keluarga::find($k)->versi, $k);
        }
    }

    public function test_concurrent_primary_transfers_leave_exactly_one_primary(): void
    {
        $admin = $this->pengurus();
        $token = $this->bearer($admin);
        $utama = $this->warga('51');
        $a = $this->tambahan('51', 'ras-a@contoh.test', $admin);
        $b = $this->tambahan('51', 'ras-b@contoh.test', $admin);
        $k = $utama->keluarga()->fresh();

        $results = $this->runConcurrently([
            $this->http('POST', "/api/v1/admin/families/{$k->id}/primary-account", $token, ['akun_id' => $a->id, 'versi' => $k->versi, 'alasan' => 'ras'], 1),
            $this->http('POST', "/api/v1/admin/families/{$k->id}/primary-account", $token, ['akun_id' => $b->id, 'versi' => $k->versi, 'alasan' => 'ras'], 2),
        ]);

        $this->assertSame(['200' => 1, '409:VERSION_CONFLICT' => 1], $this->tally($results));
        $this->assertSame(1, TautanAkun::where('keluarga_id', $k->id)->whereNull('selesai_at')->where('is_utama', true)->count());
        $this->assertSame(3, TautanAkun::where('keluarga_id', $k->id)->whereNull('selesai_at')->count());
        $this->assertSame(1, Akun::whereIn('id', [$utama->id, $a->id, $b->id])->where('jenis', 'utama')->count());
    }

    public function test_concurrent_approvals_of_one_request_link_once(): void
    {
        $tokens = [$this->bearer($this->pengurus('p1@contoh.test')), $this->bearer($this->pengurus('p2@contoh.test')), $this->bearer($this->pengurus('p3@contoh.test'))];
        $this->warga('52');
        $pemohon = $this->pemohon('52', 'ras-tambahan@contoh.test');
        $id = $pemohon->permohonan->id;

        $results = $this->runConcurrently(array_map(fn ($t, $i) => $this->http('POST', "/api/v1/admin/additional-account-requests/$id/approve", $t, [], $i), $tokens, [1, 2, 3]));

        $this->assertSame(['200' => 1, '409:REQUEST_ALREADY_PROCESSED' => 2], $this->tally($results));
        $this->assertSame(1, TautanAkun::where('akun_id', $pemohon->id)->count());
        $this->assertSame('disetujui', PermohonanAkun::find($id)->status);
        $this->assertSame('aktif', Akun::find($pemohon->id)->status);
    }

    public function test_same_version_household_edits_accept_one_and_conflict_the_rest(): void
    {
        $utama = $this->warga('53');
        $token = $this->bearer($utama);
        $versi = $utama->keluarga()->versi;

        $results = $this->runConcurrently(array_map(fn ($i) => $this->http('PATCH', '/api/v1/household', $token,
            ['versi' => $versi, 'whatsapp' => "08123456780$i"], $i), [1, 2, 3]));

        $this->assertSame(['200' => 1, '409:VERSION_CONFLICT' => 2], $this->tally($results));
        $this->assertSame($versi + 1, Keluarga::find($utama->keluarga()->id)->versi);
    }

    public function test_concurrent_moves_of_one_member_keep_one_active_membership(): void
    {
        $admin = $this->pengurus();
        $token = $this->bearer($admin);
        $asal = $this->warga('54');
        $tambahan = $this->tambahan('54', 'ras-pindah@contoh.test', $admin);
        $tujuan = [$this->warga('55')->keluarga()->id, $this->warga('56')->keluarga()->id];
        $versi = $asal->keluarga()->fresh()->versi;

        $results = $this->runConcurrently(array_map(fn ($k, $i) => $this->http('POST', "/api/v1/admin/residents/{$tambahan->warga_id}/move", $token,
            ['keluarga_id' => $k, 'hubungan_keluarga' => 'Saudara', 'alasan' => 'ras', 'versi' => $versi], $i), $tujuan, [1, 2]));

        $this->assertSame(['200' => 1, '409:CONFLICT' => 1], $this->tally($results));
        $this->assertSame(1, KeanggotaanKeluarga::where('warga_id', $tambahan->warga_id)->whereNull('selesai_at')->count());
        $this->assertSame(1, TautanAkun::where('akun_id', $tambahan->id)->whereNull('selesai_at')->count());
        $active = TautanAkun::where('akun_id', $tambahan->id)->whereNull('selesai_at')->value('keluarga_id');
        $this->assertContains($active, $tujuan);
        $this->assertSame($active, Warga::find($tambahan->warga_id)->keluarga_id);
        $this->assertNotSame($asal->keluarga()->id, $active);
    }

    /**
     * PHPUnit memegang lock baris keluarga; worker menjalankan request (lolos controller, lalu
     * menunggu lock di domain); PHPUnit menjalankan $revoke dalam transaksi yang sama lalu commit.
     */
    private function raceAgainstFamilyLock(string $keluargaId, array $spec, Closure $revoke): array
    {
        DB::beginTransaction();
        try {
            Keluarga::whereKey($keluargaId)->lockForUpdate()->first();
            $worker = $this->spawnWorker($spec);
            $this->awaitRowLockWait($spec['label'], [$worker]);
            $revoke();
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->workerResult($worker);
    }

    private function outcome(array $result): array
    {
        return ['status' => $result['status'], 'code' => $result['code']];
    }

    public function test_former_primary_write_waiting_on_lock_is_denied_after_primary_transfer(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('61');
        $b = $this->tambahan('61', 'ras-utama-b@contoh.test', $admin);
        $k = Keluarga::find($utama->keluarga()->id);
        $wa = Warga::where('keluarga_id', $k->id)->where('is_kepala', true)->value('whatsapp');

        // Versi yang dikirim = versi SETELAH pindah utama: hanya cek hak di bawah lock yang menolak.
        $result = $this->raceAgainstFamilyLock($k->id, $this->http('PATCH', '/api/v1/household', $this->bearer($utama),
            ['versi' => $k->versi + 1, 'whatsapp' => '081299990001'], 1),
            fn () => Households::transferPrimary($admin, $k->id, $b->id, $k->versi, 'uji ras'));

        $this->assertSame(['status' => 403, 'code' => 'PRIMARY_ACCOUNT_REQUIRED'], $this->outcome($result));
        $this->assertSame($k->versi + 1, Keluarga::find($k->id)->versi, 'hanya pindah utama yang menaikkan versi');
        $this->assertSame($wa, Warga::where('keluarga_id', $k->id)->where('is_kepala', true)->value('whatsapp'));
        $this->assertSame($b->id, TautanAkun::where('keluarga_id', $k->id)->where('is_utama', true)->whereNull('selesai_at')->value('akun_id'));
    }

    public function test_primary_write_waiting_on_lock_is_denied_after_its_account_is_archived(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('62');
        $k = Keluarga::find($utama->keluarga()->id);
        $anggota = KeanggotaanKeluarga::where('keluarga_id', $k->id)->whereNull('selesai_at')->count();

        $result = $this->raceAgainstFamilyLock($k->id, $this->http('POST', '/api/v1/household/members', $this->bearer($utama),
            ['nama' => 'Uji Ras Arsip', 'hubungan_keluarga' => 'Anak', 'versi' => $k->versi + 1], 2),
            fn () => Accounts::archive($admin, $utama->id, 'uji ras'));

        $this->assertSame(['status' => 403, 'code' => 'FORBIDDEN'], $this->outcome($result));
        $this->assertSame($anggota, KeanggotaanKeluarga::where('keluarga_id', $k->id)->whereNull('selesai_at')->count());
        $this->assertSame(0, Warga::where('nama', 'Uji Ras Arsip')->count());
        $this->assertSame($k->versi + 1, Keluarga::find($k->id)->versi);
    }

    public function test_primary_write_waiting_on_lock_is_denied_after_its_member_moves_family(): void
    {
        $admin = $this->pengurus();
        $this->warga('63');
        $b = $this->tambahan('63', 'ras-pindah-utama@contoh.test', $admin);
        $asal = Keluarga::find($b->keluarga()->id);
        Households::transferPrimary($admin, $asal->id, $b->id, $asal->versi, 'persiapan');
        $asal->refresh();
        $tujuan = $this->warga('64')->keluarga()->id;
        $anggota = KeanggotaanKeluarga::where('keluarga_id', $asal->id)->whereNull('selesai_at')->count();

        $result = $this->raceAgainstFamilyLock($asal->id, $this->http('POST', '/api/v1/household/members', $this->bearer($b),
            ['nama' => 'Uji Ras Pindah', 'hubungan_keluarga' => 'Anak', 'versi' => $asal->versi + 1], 3),
            fn () => Households::moveMember($admin, $b->warga_id, $tujuan, 'Saudara', 'uji ras', $asal->versi));

        $this->assertSame(['status' => 409, 'code' => 'CONFLICT'], $this->outcome($result));
        // Akun ikut anggota sebagai tambahan di keluarga tujuan; keluarga asal tanpa anggota baru.
        $link = TautanAkun::where('akun_id', $b->id)->whereNull('selesai_at')->sole();
        $this->assertSame([$tujuan, false], [$link->keluarga_id, $link->is_utama]);
        $this->assertSame($anggota - 1, KeanggotaanKeluarga::where('keluarga_id', $asal->id)->whereNull('selesai_at')->count());
        $this->assertSame(0, Warga::where('nama', 'Uji Ras Pindah')->count());
    }

    public function test_account_archive_with_stale_family_returns_conflict_without_changes(): void
    {
        $admin = $this->pengurus();
        $this->warga('65');
        $c = $this->tambahan('65', 'ras-arsip-basi@contoh.test', $admin);
        $asal = Keluarga::find($c->keluarga()->id);
        $tujuan = $this->warga('66')->keluarga()->id;

        // Worker membaca keluarga asal dari tautan lalu menunggu lock; tautan pindah sebelum lock didapat.
        $result = $this->raceAgainstFamilyLock($asal->id, $this->http('POST', "/api/v1/admin/accounts/{$c->id}/archive", $this->bearer($admin),
            ['alasan' => 'uji ras'], 4),
            fn () => Households::moveMember($admin, $c->warga_id, $tujuan, 'Saudara', 'uji ras', $asal->versi));

        $this->assertSame(['status' => 409, 'code' => 'CONFLICT'], $this->outcome($result));
        $this->assertSame('aktif', Akun::find($c->id)->status);
        $this->assertSame($tujuan, TautanAkun::where('akun_id', $c->id)->whereNull('selesai_at')->value('keluarga_id'));
        // Pengulangan oleh client berhasil memakai keluarga terbaru.
        $this->as($admin)->postJson("/api/v1/admin/accounts/{$c->id}/archive", ['alasan' => 'ulang'])->assertOk();
        $this->assertSame(0, TautanAkun::where('akun_id', $c->id)->whereNull('selesai_at')->count());
    }

    public function test_admin_member_edit_racing_a_move_returns_conflict_not_not_found(): void
    {
        $admin = $this->pengurus();
        $asal = $this->warga('67')->keluarga()->fresh();
        $tujuan = $this->warga('68')->keluarga()->id;
        $anak = Warga::where('keluarga_id', $asal->id)->where('is_kepala', false)->value('id');

        $result = $this->raceAgainstFamilyLock($asal->id, $this->http('PATCH', "/api/v1/admin/residents/$anak", $this->bearer($admin),
            ['nama' => 'Uji Ras Ubah', 'versi' => $asal->versi + 1], 5),
            fn () => Households::moveMember($admin, $anak, $tujuan, 'Saudara', 'uji ras', $asal->versi));

        $this->assertSame(['status' => 409, 'code' => 'CONFLICT'], $this->outcome($result));
        $this->assertNotSame('Uji Ras Ubah', Warga::find($anak)->nama);
    }

    /** Deadlock/serialization yang lolos menjadi 409 CONFLICT dan transaksi di-rollback, termasuk bersarang. */
    public function test_deadlock_and_serialization_failures_become_conflict(): void
    {
        $raise = fn (string $state) => fn () => DB::statement("DO \$\$ BEGIN RAISE EXCEPTION 'uji' USING ERRCODE = '$state'; END \$\$");
        foreach (['40P01', '40001'] as $state) {
            foreach (['tunggal' => $raise($state), 'bersarang' => fn () => Links::transaction($raise($state))] as $mode => $work) {
                try {
                    Links::transaction(function () use ($work) {
                        Rumah::create(['kode_rumah' => 'DL-1', 'jalan' => 'Jl. Dl', 'nomor' => '1', 'alamat_key' => 'dl|dl|1']);
                        $work();
                    });
                    $this->fail("SQLSTATE $state ($mode) harus dipetakan.");
                } catch (DomainConflict $e) {
                    $this->assertSame([409, 'CONFLICT'], [$e->status, $e->errorCode], "$state $mode");
                }
                $this->assertSame(0, DB::transactionLevel(), "$state $mode");
                $this->assertSame(0, Rumah::where('kode_rumah', 'DL-1')->count(), "rollback $state $mode");
            }
        }
        // Error database lain tidak disamarkan menjadi 409.
        $this->expectException(QueryException::class);
        Links::transaction($raise('23514'));
    }
}
