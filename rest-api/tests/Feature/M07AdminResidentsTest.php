<?php

namespace Tests\Feature;

use App\Domain\Finance\Dues;
use App\Domain\Residents\Households;
use App\Models\Akun;
use App\Models\AuditLog;
use App\Models\KeanggotaanKeluarga;
use App\Models\Keluarga;
use App\Models\PemilikRumah;
use App\Models\Penghunian;
use App\Models\Rumah;
use App\Models\TagihanIuran;
use App\Models\TautanAkun;
use App\Models\Warga;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\Fixtures;
use Tests\Support\OpenApi;
use Tests\TestCase;

/**
 * M07 administrasi pengurus: rumah/keluarga/anggota/akun/hunian/permohonan dengan histori,
 * audit, versi 409, pencabutan akses, tanpa mass-assignment peran, tanpa hapus permanen, dan
 * tagihan tetap per rumah (tidak berpindah, tidak dibebaskan otomatis, tidak ganda per akun).
 */
class M07AdminResidentsTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    private function audited(string $action, string $entityId): void
    {
        $this->assertTrue(AuditLog::where('action', $action)->where('entity_id', $entityId)->exists(), "audit $action");
    }

    public function test_admin_routes_reject_residents_and_all_admins_have_equal_rights(): void
    {
        $warga = $this->warga('1');
        $this->as($warga)->getJson('/api/v1/admin/families')->assertForbidden();
        $this->postJson('/api/v1/admin/occupancies', [])->assertForbidden();

        // Data dibuat pengurus A dapat dikelola pengurus B (setara).
        $a = $this->pengurus('a@contoh.test');
        $b = $this->pengurus('b@contoh.test');
        $id = $this->as($a)->postJson('/api/v1/admin/families', ['nama_kepala' => 'Uji Admin'])->assertCreated()->json('data.id');
        $this->as($b)->postJson("/api/v1/admin/families/$id/verify")->assertStatus(422);
        $this->postJson("/api/v1/admin/families/$id/verify", ['versi' => 1])->assertOk()->assertJsonPath('data.status', 'terverifikasi');
    }

    public function test_family_create_with_house_detail_head_change_and_verify(): void
    {
        $admin = $this->pengurus();
        $rumah = $this->as($admin)->postJson('/api/v1/admin/houses', ['blok' => 'Q', 'jalan' => 'Jl. Uji', 'nomor' => '7'])->assertCreated()->json('data');
        $this->assertSame(1, $rumah['versi']);

        $res = $this->postJson('/api/v1/admin/families', [
            'nama_kepala' => 'Uji Kepala Q', 'anggota' => [['nama' => 'Uji Istri Q', 'hubungan_keluarga' => 'Istri']],
            'rumah_id' => $rumah['id'], 'jenis_hunian' => 'kontrak',
        ])->assertCreated();
        $this->assertSame([], OpenApi::errors($res->json(), 'FamilyDetailEnvelope'));
        $id = $res->json('data.id');
        $this->assertSame('kontrak', $res->json('data.jenis_hunian'));
        $this->assertCount(2, $res->json('data.riwayat_keanggotaan'));
        $this->assertSame([], $res->json('data.akun'));
        $this->audited('keluarga.dibuat', $id);
        $this->audited('penghunian.dimulai', $res->json('data.penghunian.0.id'));

        $istri = collect($res->json('data.anggota'))->firstWhere('is_kepala', false)['id'];
        $versi = $this->getJson("/api/v1/admin/families/$id")->json('data.versi');
        $this->patchJson("/api/v1/admin/families/$id", ['versi' => $versi - 1, 'kepala_warga_id' => $istri])->assertStatus(409);
        $this->patchJson("/api/v1/admin/families/$id", ['versi' => $versi, 'kepala_warga_id' => $istri])->assertOk();
        $this->assertTrue(Warga::find($istri)->is_kepala);
        $this->assertSame(1, Warga::where('keluarga_id', $id)->where('is_kepala', true)->count());
        $this->audited('keluarga.ganti_kepala', $id);

        // Rumah yang sudah dihuni tidak dapat dipakai keluarga lain secara atomik (tanpa keluarga setengah jadi).
        $before = Keluarga::count();
        $this->postJson('/api/v1/admin/families', ['nama_kepala' => 'Uji Lain', 'rumah_id' => $rumah['id'], 'jenis_hunian' => 'pemilik'])
            ->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_OCCUPIED');
        $this->assertSame($before, Keluarga::count());
    }

    public function test_family_archive_requires_no_occupancy_and_no_linked_accounts(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('1');
        $k = $utama->keluarga();
        $occupancy = Penghunian::where('keluarga_id', $k->id)->sole();

        $this->as($admin)->postJson("/api/v1/admin/families/{$k->id}/archive", ['alasan' => 'Pindah'])->assertStatus(409)->assertJsonPath('error.code', 'FAMILY_OCCUPYING');
        $this->postJson("/api/v1/admin/occupancies/{$occupancy->id}/end", ['alasan' => 'Pindah keluar RT'])->assertOk()->assertJsonPath('data.aktif', false);
        $this->postJson("/api/v1/admin/families/{$k->id}/archive", ['alasan' => 'Pindah'])->assertStatus(409)->assertJsonPath('error.code', 'FAMILY_HAS_ACCOUNTS');
        $this->postJson("/api/v1/admin/accounts/{$utama->id}/archive", ['alasan' => 'Pindah keluar RT'])->assertOk();
        $this->postJson("/api/v1/admin/families/{$k->id}/archive", ['alasan' => 'Pindah'])->assertNoContent();

        $this->assertNotNull($k->fresh()->archived_at);
        $this->assertSame(0, KeanggotaanKeluarga::where('keluarga_id', $k->id)->whereNull('selesai_at')->count());
        $this->assertSame(2, KeanggotaanKeluarga::where('keluarga_id', $k->id)->where('alasan_selesai', 'diarsipkan')->count());
        $this->assertSame(1, Penghunian::where('keluarga_id', $k->id)->count(), 'histori hunian tetap');
        $this->getJson("/api/v1/admin/families/{$k->id}")->assertOk()->assertJsonCount(1, 'data.akun');
        $this->audited('keluarga.diarsipkan', $k->id);
    }

    public function test_resident_update_end_and_move_preserve_history_and_move_account_link(): void
    {
        $admin = $this->pengurus();
        $a = $this->warga('1');
        $b = $this->warga('2');
        $tambahan = $this->tambahan('1', 'anak@contoh.test', $admin);
        $wargaTambahan = $tambahan->warga_id;
        $anakA = KeanggotaanKeluarga::where('keluarga_id', $a->keluarga()->id)->whereNull('selesai_at')
            ->whereHas('warga', fn ($q) => $q->where('nama', 'Uji Anak 1'))->value('warga_id');

        $this->as($admin)->patchJson("/api/v1/admin/residents/$anakA", ['nama' => 'Uji Anak Satu', 'versi' => 999])->assertStatus(409);
        $this->patchJson("/api/v1/admin/residents/$anakA", ['nama' => 'Uji Anak Satu'])->assertStatus(422);
        $versiA = $a->keluarga()->fresh()->versi;
        $this->patchJson("/api/v1/admin/residents/$anakA", ['nama' => 'Uji Anak Satu', 'versi' => $versiA])->assertOk()
            ->assertJsonPath('data.nama', 'Uji Anak Satu')->assertJsonPath('data.versi_keluarga', $versiA + 1);
        $versiA++;

        $kepalaA = $a->warga_id;
        $this->postJson("/api/v1/admin/residents/$kepalaA/end", ['status' => 'pindah', 'alasan' => 'x', 'versi' => $versiA])->assertStatus(409)->assertJsonPath('error.code', 'HEAD_OF_FAMILY');
        $this->postJson("/api/v1/admin/residents/$wargaTambahan/end", ['status' => 'pindah', 'alasan' => 'x', 'versi' => $versiA])->assertStatus(409)->assertJsonPath('error.code', 'RESIDENT_HAS_ACCOUNT');
        $this->postJson("/api/v1/admin/residents/$kepalaA/move", ['keluarga_id' => $b->keluarga()->id, 'hubungan_keluarga' => 'Saudara', 'alasan' => 'x', 'versi' => $versiA])
            ->assertStatus(409)->assertJsonPath('error.code', 'HEAD_OF_FAMILY');

        // Pindah anggota berakun: keanggotaan + tautan ditutup lalu dibuka di keluarga tujuan.
        $versiB = $b->keluarga()->versi;
        $this->postJson("/api/v1/admin/residents/$wargaTambahan/move", ['keluarga_id' => $b->keluarga()->id, 'hubungan_keluarga' => 'Menantu', 'alasan' => 'Menikah', 'versi' => $versiA - 1])
            ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $res = $this->postJson("/api/v1/admin/residents/$wargaTambahan/move", ['keluarga_id' => $b->keluarga()->id, 'hubungan_keluarga' => 'Menantu', 'alasan' => 'Menikah', 'versi' => $versiA])
            ->assertOk()->assertJsonPath('data.keluarga_id', $b->keluarga()->id);
        $this->assertCount(2, $res->json('data.riwayat_keanggotaan'));
        $this->assertSame('pindah_keluarga', KeanggotaanKeluarga::where('warga_id', $wargaTambahan)->whereNotNull('selesai_at')->value('alasan_selesai'));
        $links = TautanAkun::where('akun_id', $tambahan->id)->orderBy('created_at')->get();
        $this->assertCount(2, $links);
        $this->assertSame('pindah_keluarga', $links[0]->alasan_selesai);
        $this->assertSame($a->keluarga()->id, $links[0]->keluarga_id);
        $this->assertSame($b->keluarga()->id, $links[1]->keluarga_id);
        $this->assertFalse($links[1]->is_utama);
        $this->assertSame($b->keluarga()->id, Warga::find($wargaTambahan)->keluarga_id, 'pointer lama ikut');
        $this->assertSame($versiB + 1, $b->keluarga()->fresh()->versi);
        $this->as(Akun::find($tambahan->id))->getJson('/api/v1/household')->assertOk()->assertJsonPath('data.id', $b->keluarga()->id);
        $this->audited('warga.pindah_keluarga', $wargaTambahan);

        $this->as($admin)->postJson("/api/v1/admin/residents/$anakA/end", ['status' => 'meninggal', 'alasan' => 'Data RT', 'versi' => $a->keluarga()->fresh()->versi])->assertOk()
            ->assertJsonPath('data.status', 'meninggal')->assertJsonPath('data.keluarga_id', null);
        $this->assertNotNull(Warga::find($anakA)->archived_at);
        $this->assertEquals(['keluarga_id' => $a->keluarga()->id, 'status' => 'meninggal', 'alasan' => 'Data RT'],
            AuditLog::where('action', 'warga.diarsipkan')->where('entity_id', $anakA)->sole()->safe_diff, 'alasan pengurus tersimpan di audit');
        $this->postJson("/api/v1/admin/residents/$anakA/end", ['status' => 'pindah', 'alasan' => 'x', 'versi' => 1])->assertNotFound();
    }

    public function test_family_mutations_recheck_actor_inside_the_lock(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('1');
        $k = $utama->keluarga();
        $versi = $k->fresh()->versi;

        // Operasi khusus pengurus menolak aktor non-pengurus walau dipanggil langsung (bukan lewat middleware).
        foreach ([
            fn () => Households::verify($utama, $k->id, $versi),
            fn () => Households::archive($utama, $k->id, 'x'),
            fn () => Households::changeHead($utama, $k->id, Warga::where('keluarga_id', $k->id)->where('is_kepala', false)->value('id'), $versi),
        ] as $call) {
            try {
                $call();
                $this->fail('Aktor non-pengurus harus ditolak.');
            } catch (AuthorizationException) {
            }
        }
        // Pengurus yang dinonaktifkan setelah request diautentikasi tidak dapat menulis.
        Akun::whereKey($admin->id)->update(['status' => 'nonaktif']);
        $this->expectException(AuthorizationException::class);
        Households::verify($admin, $k->id, $versi);
    }

    public function test_primary_transfer_keeps_history_and_one_primary(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('1');
        $tambahan = $this->tambahan('1', 'anak@contoh.test', $admin);
        $k = $utama->keluarga();

        $this->as($admin)->postJson("/api/v1/admin/families/{$k->id}/primary-account", ['akun_id' => $tambahan->id, 'versi' => $k->fresh()->versi - 1, 'alasan' => 'x'])->assertStatus(409);
        $this->postJson("/api/v1/admin/families/{$k->id}/primary-account", ['akun_id' => $utama->id, 'versi' => $k->fresh()->versi, 'alasan' => 'x'])->assertStatus(422);
        $res = $this->postJson("/api/v1/admin/families/{$k->id}/primary-account", ['akun_id' => $tambahan->id, 'versi' => $k->fresh()->versi, 'alasan' => 'Kepala keluarga sakit'])->assertOk();
        $this->assertSame([], OpenApi::errors($res->json(), 'FamilyDetailEnvelope'));

        $this->assertSame(1, TautanAkun::where('keluarga_id', $k->id)->whereNull('selesai_at')->where('is_utama', true)->count());
        $this->assertSame($tambahan->id, TautanAkun::where('keluarga_id', $k->id)->whereNull('selesai_at')->where('is_utama', true)->value('akun_id'));
        $this->assertSame(2, TautanAkun::where('keluarga_id', $k->id)->where('alasan_selesai', 'pergantian_utama')->count());
        $this->assertSame(['tambahan', 'utama'], [Akun::find($utama->id)->jenis, Akun::find($tambahan->id)->jenis]);

        $this->as(Akun::find($utama->id))->patchJson('/api/v1/household', ['versi' => $k->fresh()->versi, 'whatsapp' => '081234567890'])->assertForbidden();
        $this->as(Akun::find($tambahan->id))->patchJson('/api/v1/household', ['versi' => $k->fresh()->versi, 'whatsapp' => '081234567890'])->assertOk();
    }

    public function test_account_detail_status_archive_and_no_role_assignment(): void
    {
        $admin = $this->pengurus();
        $other = $this->pengurus('lain@contoh.test');
        $warga = $this->warga('1');
        $token = $warga->createToken('hp')->plainTextToken;

        $this->as($admin)->getJson("/api/v1/admin/accounts/{$warga->id}")->assertOk()
            ->assertJsonPath('data.tautan.0.is_utama', true)->assertJsonPath('data.permohonan.jenis', 'utama')
            ->assertJsonMissingPath('data.password_hash');
        // Peran tidak pernah diterima.
        $this->patchJson("/api/v1/admin/accounts/{$warga->id}", ['status' => 'aktif', 'alasan' => 'x', 'peran' => 'pengurus'])->assertOk();
        $this->assertSame('warga', Akun::find($warga->id)->peran);

        $this->postJson("/api/v1/admin/accounts/{$admin->id}/archive", ['alasan' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'SELF_STATUS_CHANGE');
        $this->postJson("/api/v1/admin/accounts/{$other->id}/archive", ['alasan' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'ADMIN_ARCHIVE_OPERATOR_ONLY');
        $pending = $this->pemohon('1', 'tunggu@contoh.test');
        $this->postJson("/api/v1/admin/accounts/{$pending->id}/archive", ['alasan' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_PENDING');

        $versi = $warga->keluarga()->versi;
        $this->postJson("/api/v1/admin/accounts/{$warga->id}/archive", ['alasan' => 'Pindah'])->assertOk()
            ->assertJsonPath('data.status', 'diarsipkan')->assertJsonPath('data.keluarga_id', null);
        $this->assertSame(0, $warga->tokens()->count(), 'bearer dicabut');
        $this->assertSame('akun_diarsipkan', TautanAkun::where('akun_id', $warga->id)->value('alasan_selesai'));
        $this->assertSame($versi + 1, Keluarga::find(TautanAkun::where('akun_id', $warga->id)->value('keluarga_id'))->versi);
        $this->assertNotNull(Akun::find($warga->id)->warga_id, 'pointer histori tetap');
        $this->audited('akun.diarsipkan', $warga->id);

        $this->postJson("/api/v1/admin/accounts/{$warga->id}/archive", ['alasan' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_ARCHIVED');
        $this->patchJson("/api/v1/admin/accounts/{$warga->id}", ['status' => 'aktif', 'alasan' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_ARCHIVED');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/mobile-login', ['email' => $warga->email, 'password' => 'Rahasia123', 'device_name' => 'hp'])->assertStatus(422)->assertJsonMissingPath('data.token');
    }

    public function test_approval_links_existing_member_or_creates_one_and_house_must_be_occupied(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('1');
        $k = $utama->keluarga();
        $anak = KeanggotaanKeluarga::where('keluarga_id', $k->id)->whereNull('selesai_at')->whereHas('warga', fn ($q) => $q->where('is_kepala', false))->value('warga_id');
        $versi = $k->versi;

        $p1 = $this->pemohon('1', 'anak@contoh.test');
        $this->as($admin)->postJson("/api/v1/admin/additional-account-requests/{$p1->permohonan->id}/approve", ['warga_id' => $utama->warga_id])
            ->assertStatus(409)->assertJsonPath('error.code', 'RESIDENT_HAS_ACCOUNT');
        $this->assertSame('menunggu', $p1->fresh()->status, 'rollback penuh');
        $this->postJson("/api/v1/admin/additional-account-requests/{$p1->permohonan->id}/approve", ['warga_id' => $this->warga('2')->warga_id])->assertNotFound();
        $this->postJson("/api/v1/admin/additional-account-requests/{$p1->permohonan->id}/approve", ['warga_id' => $anak])->assertOk()->assertJsonPath('data.status', 'disetujui');
        $this->assertSame($anak, Akun::find($p1->id)->warga_id);
        $this->assertSame(3 - 1, KeanggotaanKeluarga::where('keluarga_id', $k->id)->whereNull('selesai_at')->count(), 'tidak ada warga ganda');
        $this->assertSame($versi + 1, $k->fresh()->versi);
        $this->postJson("/api/v1/admin/additional-account-requests/{$p1->permohonan->id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_ALREADY_PROCESSED');

        // Rumah tanpa keluarga aktif.
        $rumah = Rumah::create(['kode_rumah' => 'Z-KOSONG', 'jalan' => 'Jl. Kosong', 'nomor' => '1', 'alamat_key' => 'z|kosong|1']);
        $p2 = $this->pemohon('1', 'kedua@contoh.test');
        $this->postJson("/api/v1/admin/additional-account-requests/{$p2->permohonan->id}/approve", ['rumah_id' => $rumah->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'HOUSE_NOT_OCCUPIED');
        $this->assertSame(0, TautanAkun::where('akun_id', $p2->id)->count());
    }

    public function test_occupancy_begin_end_move_keep_charges_on_house_without_waiver(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('1');
        $this->tambahan('1', 'anak@contoh.test', $admin);
        $this->tambahan('1', 'cucu@contoh.test', $admin);
        $rumahLama = $utama->rumah();
        $kosong = $this->as($admin)->postJson('/api/v1/admin/houses', ['blok' => 'Q', 'jalan' => 'Jl. Baru', 'nomor' => '2', 'tagihan_aktif' => true])->json('data');

        // Satu rumah dengan tiga akun aktif tetap satu unit tagihan.
        $period = Dues::period(CarbonImmutable::now('Asia/Jakarta')->format('Y-m'));
        Dues::generate($period, $admin);
        $this->assertSame(1, TagihanIuran::where('rumah_id', $rumahLama->id)->count());
        $this->assertSame(3, TautanAkun::where('keluarga_id', $utama->keluarga()->id)->whereNull('selesai_at')->count());
        $chargeLama = TagihanIuran::where('rumah_id', $rumahLama->id)->sole();

        $occ = Penghunian::where('keluarga_id', $utama->keluarga()->id)->whereNull('selesai_tanggal')->sole();
        $this->postJson("/api/v1/admin/occupancies/{$occ->id}/move", ['rumah_id' => $rumahLama->id, 'alasan' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'SAME_HOUSE');
        $this->postJson("/api/v1/admin/occupancies/{$occ->id}/move", ['rumah_id' => $kosong['id'], 'tanggal' => now('Asia/Jakarta')->addDay()->toDateString(), 'alasan' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'DATE_IN_FUTURE');
        $new = $this->postJson("/api/v1/admin/occupancies/{$occ->id}/move", ['rumah_id' => $kosong['id'], 'alasan' => 'Pindah blok'])->assertCreated()->json('data');
        $this->assertTrue($new['aktif']);
        $this->assertNotNull($occ->fresh()->selesai_tanggal);
        $this->assertSame($kosong['id'], Akun::find($utama->id)->rumah()->id, 'rumah akun mengikuti keluarga');

        // Tagihan/tunggakan tetap di rumah lama; rumah lama kosong tidak dibebaskan otomatis.
        $this->assertSame($rumahLama->id, $chargeLama->fresh()->rumah_id);
        $this->assertSame('belum_bayar', $chargeLama->fresh()->status);
        $this->assertTrue($rumahLama->fresh()->tagihan_aktif);
        $this->getJson("/api/v1/admin/houses/{$rumahLama->id}")->assertOk()->assertJsonPath('keluarga', null);

        // Rumah lama dapat dihuni keluarga lain mulai hari yang sama (tanpa tumpang tindih).
        $fam = $this->postJson('/api/v1/admin/families', ['nama_kepala' => 'Uji Penyewa', 'rumah_id' => $rumahLama->id, 'jenis_hunian' => 'kontrak'])->assertCreated();
        $this->assertSame(2, Penghunian::where('rumah_id', $rumahLama->id)->count());
        $famId = $fam->json('data.id');
        $this->postJson('/api/v1/admin/occupancies', ['rumah_id' => $kosong['id'], 'keluarga_id' => $famId, 'jenis_hunian' => 'kontrak'])
            ->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_OCCUPIED');
        $fam2 = $this->postJson('/api/v1/admin/families', ['nama_kepala' => 'Uji Tanpa Rumah'])->json('data.id');
        $this->postJson('/api/v1/admin/occupancies', ['rumah_id' => $rumahLama->id, 'keluarga_id' => $fam2, 'jenis_hunian' => 'kontrak'])
            ->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_OCCUPIED');
        $this->postJson("/api/v1/admin/occupancies/{$occ->id}/end", ['alasan' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'OCCUPANCY_ENDED');
        $this->postJson('/api/v1/admin/occupancies', ['rumah_id' => $kosong['id'], 'keluarga_id' => $fam2, 'jenis_hunian' => 'pemilik', 'mulai_tanggal' => '2020-01-01'])
            ->assertStatus(409);

        // Penghunian rumah baru dimulai hari ini: tanggal mulai sebelum akhir hunian lama di rumah itu ditolak.
        $penyewa = Penghunian::where('keluarga_id', $famId)->sole();
        $this->postJson("/api/v1/admin/occupancies/{$penyewa->id}/end", ['alasan' => 'Kontrak habis'])->assertOk();
        $this->postJson('/api/v1/admin/occupancies', ['rumah_id' => $rumahLama->id, 'keluarga_id' => $fam2, 'jenis_hunian' => 'pemilik', 'mulai_tanggal' => '2020-01-01'])
            ->assertStatus(422)->assertJsonPath('error.code', 'DATE_OVERLAP');
        $this->postJson('/api/v1/admin/occupancies', ['rumah_id' => $rumahLama->id, 'keluarga_id' => $fam2, 'jenis_hunian' => 'pemilik'])->assertCreated();
        $this->assertSame(1, TagihanIuran::where('rumah_id', $rumahLama->id)->count(), 'hunian tidak membuat/menghapus tagihan');
    }

    public function test_house_update_and_archive_keep_code_history_and_charges(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('1');
        $this->warga('2');
        $rumah = $utama->rumah();
        Dues::generate(Dues::period(CarbonImmutable::now('Asia/Jakarta')->format('Y-m')), $admin);

        $this->as($admin)->patchJson("/api/v1/admin/houses/{$rumah->id}", ['nomor' => '2', 'alasan' => 'Koreksi'])->assertStatus(422);
        $this->patchJson("/api/v1/admin/houses/{$rumah->id}", ['nomor' => '2', 'alasan' => 'Koreksi', 'versi' => 1])->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_EXISTS');
        $this->patchJson("/api/v1/admin/houses/{$rumah->id}", ['jalan' => 'Jalan Uji Raya', 'alasan' => 'Koreksi', 'versi' => 99])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->patchJson("/api/v1/admin/houses/{$rumah->id}", ['jalan' => 'Jalan Uji Raya', 'alasan' => 'Koreksi', 'versi' => 1, 'kode_rumah' => 'HACK'])->assertOk()
            ->assertJsonPath('data.kode_rumah', $rumah->kode_rumah)->assertJsonPath('data.versi', 2);
        $this->audited('rumah.diubah', $rumah->id);

        $this->postJson("/api/v1/admin/houses/{$rumah->id}/archive", ['alasan' => 'Dibongkar'])->assertNoContent();
        $this->postJson("/api/v1/admin/houses/{$rumah->id}/archive", ['alasan' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_ARCHIVED');
        $this->assertSame(1, TagihanIuran::where('rumah_id', $rumah->id)->count());
        $this->assertNotNull(Penghunian::where('rumah_id', $rumah->id)->value('selesai_tanggal'));
        $this->audited('penghunian.diakhiri', Penghunian::where('rumah_id', $rumah->id)->value('id'));
        $this->patchJson("/api/v1/admin/houses/{$rumah->id}", ['jalan' => 'X', 'alasan' => 'x', 'versi' => 3])->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_ARCHIVED');
        $this->as(Akun::find($utama->id))->getJson('/api/v1/household')->assertOk()->assertJsonPath('data.rumah', null);
    }

    public function test_house_owner_is_admin_only_and_separate_from_occupant_head(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('1', ['jenis_hunian' => 'kontrak']);
        $rumah = $utama->rumah();

        $detail = $this->as($admin)->getJson("/api/v1/admin/houses/{$rumah->id}")->assertOk()->assertJsonPath('data.pemilik', null)->json('data');
        $occId = Penghunian::where('rumah_id', $rumah->id)->whereNull('selesai_tanggal')->value('id');
        $this->assertSame($occId, $detail['penghunian_aktif_id']);
        $this->assertSame($occId, $detail['riwayat_penghunian'][0]['id']);
        $this->assertSame($occId, $this->getJson('/api/v1/admin/houses')->json('data.0.keluarga.penghunian_id'));

        $this->patchJson("/api/v1/admin/houses/{$rumah->id}", ['pemilik' => ['nama' => 'Uji Pemilik'], 'alasan' => 'Data pemilik', 'versi' => 1])->assertOk();
        $owner = $this->getJson("/api/v1/admin/houses/{$rumah->id}")->assertJsonPath('data.pemilik.nama', 'Uji Pemilik')->json('data.pemilik.id');
        $this->patchJson("/api/v1/admin/houses/{$rumah->id}", ['pemilik' => ['nama' => 'Uji Pemilik Baru', 'whatsapp' => '081234567890'], 'alasan' => 'Koreksi', 'versi' => 2])->assertOk();
        $this->getJson("/api/v1/admin/houses/{$rumah->id}")->assertJsonPath('data.pemilik.id', $owner)->assertJsonPath('data.pemilik.nama', 'Uji Pemilik Baru');
        $this->assertSame('Uji Kepala 1', $this->getJson("/api/v1/admin/houses/{$rumah->id}")->json('data.keluarga.anggota.0.nama'), 'kepala penghuni tetap');
        $this->assertSame('kontrak', $this->getJson("/api/v1/admin/houses/{$rumah->id}")->json('data.keluarga.jenis_hunian'));

        // Warga tidak menerima data pemilik pada data keluarganya.
        $household = $this->as(Akun::find($utama->id))->getJson('/api/v1/household')->assertOk();
        $this->assertStringNotContainsString('Uji Pemilik', $household->getContent());
        $this->assertArrayNotHasKey('pemilik', $household->json('data.rumah'));

        $this->as($admin)->patchJson("/api/v1/admin/houses/{$rumah->id}", ['pemilik' => null, 'alasan' => 'Pemilik tidak diketahui', 'versi' => 3])->assertOk();
        $this->getJson("/api/v1/admin/houses/{$rumah->id}")->assertJsonPath('data.pemilik', null);
        $this->assertTrue(PemilikRumah::whereKey($owner)->exists(), 'tidak hard-delete');
    }

    public function test_families_list_and_resident_detail_shapes(): void
    {
        $admin = $this->pengurus();
        $utama = $this->warga('1');
        $res = $this->as($admin)->getJson('/api/v1/admin/families?q=Kepala 1')->assertOk();
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame('Uji Kepala 1', $res->json('data.0.kepala'));
        $this->assertSame(2, $res->json('data.0.jumlah_anggota'));
        $this->getJson('/api/v1/admin/families?status=salah')->assertStatus(422);

        $this->getJson("/api/v1/admin/residents/{$utama->warga_id}")->assertOk()
            ->assertJsonPath('data.akun.id', $utama->id)->assertJsonPath('data.akun.tautan_aktif', true)
            ->assertJsonPath('data.riwayat_keanggotaan.0.sumber', 'registrasi');
        $this->getJson('/api/v1/admin/residents/'.Str::uuid())->assertNotFound();
    }
}
