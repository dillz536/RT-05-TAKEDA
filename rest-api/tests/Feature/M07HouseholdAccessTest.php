<?php

namespace Tests\Feature;

use App\Domain\Identity\Registration;
use App\Models\Akun;
use App\Models\KeanggotaanKeluarga;
use App\Models\TautanAkun;
use App\Models\Warga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\Fixtures;
use Tests\Support\OpenApi;
use Tests\TestCase;

/**
 * M07 akses data keluarga sendiri: akun utama ubah, akun tambahan disetujui hanya baca (403),
 * menunggu/ditolak tanpa feed/household (403), tanpa tautan 404, keluarga lain 404 (IDOR),
 * versi basi 409. Keputusan dari tautan aktif, bukan pointer akun.warga_id/jenis.
 */
class M07HouseholdAccessTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    private const FEEDS = ['/api/v1/household', '/api/v1/complaints', '/api/v1/aspirations', '/api/v1/agendas',
        '/api/v1/announcements', '/api/v1/dues/my', '/api/v1/dues/transparency', '/api/v1/cash/summary', '/api/v1/notifications'];

    public function test_registration_writes_membership_and_primary_link_history(): void
    {
        $akun = $this->warga('1');
        $keluargaId = $akun->keluarga()->id;

        $this->assertSame(2, KeanggotaanKeluarga::where('keluarga_id', $keluargaId)->whereNull('selesai_at')->where('sumber', 'registrasi')->whereNotNull('mulai_at')->count());
        $link = TautanAkun::where('akun_id', $akun->id)->sole();
        $this->assertTrue($link->is_utama);
        $this->assertNull($link->selesai_at);
        $this->assertSame($akun->warga_id, $link->warga_id);
        $this->assertSame($keluargaId, $link->keluarga_id);
    }

    public function test_primary_reads_and_edits_own_household_with_version(): void
    {
        $akun = $this->warga('1');
        $res = $this->as($akun)->getJson('/api/v1/household')->assertOk();
        $this->assertSame([], OpenApi::errors($res->json(), 'HouseholdEnvelope'));
        $this->assertTrue($res->json('data.dapat_ubah'));
        $versi = $res->json('data.versi');

        $this->patchJson('/api/v1/household', ['versi' => $versi, 'whatsapp' => '0812 3456 789'])->assertOk()
            ->assertJsonPath('data.versi', $versi + 1);
        // Versi basi ditolak, tidak menimpa diam-diam.
        $this->patchJson('/api/v1/household', ['versi' => $versi, 'whatsapp' => '0899 9999 999'])->assertStatus(409)
            ->assertJsonPath('error.code', 'VERSION_CONFLICT');
        $this->assertSame('0812 3456 789', Warga::where('keluarga_id', $res->json('data.id'))->where('is_kepala', true)->value('whatsapp'));

        // versi wajib pada semua mutasi anggota: hilang 422, basi 409.
        $this->postJson('/api/v1/household/members', ['nama' => 'Uji Cucu', 'hubungan_keluarga' => 'Cucu'])->assertStatus(422)->assertJsonValidationErrors('versi', 'error.fields');
        $member = $this->postJson('/api/v1/household/members', ['nama' => 'Uji Cucu', 'hubungan_keluarga' => 'Cucu', 'versi' => $versi + 1])
            ->assertCreated()->assertJsonPath('data.versi_keluarga', $versi + 2)->json('data');
        $this->assertSame(1, KeanggotaanKeluarga::where('warga_id', $member['id'])->whereNull('selesai_at')->where('sumber', 'akun_utama')->count());
        $this->patchJson("/api/v1/household/members/{$member['id']}", ['nama' => 'Uji Cucu Baru', 'versi' => $versi])->assertStatus(409);
        $this->patchJson("/api/v1/household/members/{$member['id']}", ['hubungan_keluarga' => 'Keponakan'])->assertStatus(422);
        $this->patchJson("/api/v1/household/members/{$member['id']}", ['hubungan_keluarga' => 'Keponakan', 'versi' => $versi + 2])->assertOk()
            ->assertJsonPath('data.versi_keluarga', $versi + 3);
        $this->assertSame('Keponakan', KeanggotaanKeluarga::where('warga_id', $member['id'])->whereNull('selesai_at')->value('hubungan_keluarga'));

        $this->deleteJson("/api/v1/household/members/{$member['id']}", ['status' => 'pindah'])->assertStatus(422);
        $this->deleteJson("/api/v1/household/members/{$member['id']}", ['status' => 'pindah', 'versi' => $versi + 2])->assertStatus(409);
        $this->deleteJson("/api/v1/household/members/{$member['id']}?status=pindah&versi=".($versi + 3))->assertNoContent();
        $ended = KeanggotaanKeluarga::where('warga_id', $member['id'])->sole();
        $this->assertNotNull($ended->selesai_at);
        $this->assertSame('pindah', $ended->alasan_selesai);
        $this->assertNotNull(Warga::find($member['id'])->archived_at);
        $this->assertCount(2, $this->getJson('/api/v1/household')->json('data.anggota'));
    }

    public function test_head_and_linked_members_are_protected_from_primary_end(): void
    {
        $akun = $this->warga('1');
        $this->tambahan('1', 'anak@contoh.test');
        $household = $this->as($akun)->getJson('/api/v1/household')->json('data');
        foreach ($household['anggota'] as $a) {
            if ($a['is_kepala'] || $a['punya_akun']) {
                $this->deleteJson("/api/v1/household/members/{$a['id']}", ['versi' => $household['versi']])->assertStatus(422)->assertJsonPath('error.code', 'MEMBER_PROTECTED');
            }
        }
    }

    public function test_approved_additional_account_reads_but_cannot_write(): void
    {
        $utama = $this->warga('1');
        $tambahan = $this->tambahan('1', 'anak@contoh.test');
        $link = TautanAkun::where('akun_id', $tambahan->id)->sole();
        $this->assertFalse($link->is_utama);
        $this->assertSame($utama->keluarga()->id, $link->keluarga_id);

        $res = $this->as($tambahan)->getJson('/api/v1/household')->assertOk();
        $this->assertFalse($res->json('data.dapat_ubah'));
        $this->assertSame($utama->keluarga()->id, $res->json('data.id'));
        $this->assertFalse($this->getJson('/api/v1/auth/me')->json('data.dapat_ubah_keluarga'));
        $memberId = collect($res->json('data.anggota'))->firstWhere('is_kepala', false)['id'];
        $versi = $res->json('data.versi');

        $this->patchJson('/api/v1/household', ['versi' => $versi, 'whatsapp' => '081234567890'])->assertForbidden()->assertJsonPath('error.code', 'PRIMARY_ACCOUNT_REQUIRED');
        $this->postJson('/api/v1/household/members', ['nama' => 'X', 'hubungan_keluarga' => 'Anak', 'versi' => $versi])->assertForbidden();
        $this->patchJson("/api/v1/household/members/$memberId", ['nama' => 'X', 'versi' => $versi])->assertForbidden();
        $this->deleteJson("/api/v1/household/members/$memberId", ['versi' => $versi])->assertForbidden();
        $this->assertSame($versi, $this->getJson('/api/v1/household')->json('data.versi'));
        // Layanan warga tetap tersedia.
        $this->getJson('/api/v1/dues/my')->assertOk();
        $this->getJson('/api/v1/complaints')->assertOk();
    }

    public function test_pending_and_rejected_accounts_get_no_household_or_feeds(): void
    {
        $this->warga('1');
        $pending = $this->pemohon('1', 'tunggu@contoh.test');
        $rejected = $this->pemohon('1', 'tolak@contoh.test');
        Registration::reject($rejected->permohonan, $this->pengurus(), 'Data tidak cocok');

        foreach ([$pending, $rejected->refresh()] as $akun) {
            $this->assertSame('menunggu', $akun->status);
            $this->assertSame(0, TautanAkun::where('akun_id', $akun->id)->count());
            $this->as($akun);
            foreach (self::FEEDS as $path) {
                $this->getJson($path)->assertForbidden();
            }
            $this->patchJson('/api/v1/household', ['versi' => 1])->assertForbidden();
            $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.keluarga_id', null)->assertJsonPath('data.rumah', null);
            $this->getJson('/api/v1/auth/additional-account-status')->assertOk();
        }
    }

    public function test_other_family_members_are_not_found_not_forbidden(): void
    {
        $a = $this->warga('1');
        $b = $this->warga('2');
        $bMember = $b->keluarga()->keanggotaanAktif()->with('warga')->get()->firstWhere('warga.is_kepala', false)->warga_id;

        $this->as($a);
        $versiA = $a->keluarga()->versi;
        $this->patchJson("/api/v1/household/members/$bMember", ['nama' => 'Bocor', 'versi' => $versiA])->assertNotFound();
        $this->deleteJson("/api/v1/household/members/$bMember", ['versi' => $versiA])->assertNotFound();
        $this->getJson('/api/v1/admin/residents/'.$bMember)->assertForbidden();
        $this->assertNotSame('Bocor', Warga::find($bMember)->nama);
        $this->assertNull(Warga::find($bMember)->archived_at);
    }

    public function test_account_without_active_link_gets_404_and_decision_ignores_legacy_pointer(): void
    {
        $akun = $this->warga('1');
        // Pointer lama masih menunjuk warga, tetapi tautan ditutup: akses ditentukan tautan aktif.
        TautanAkun::where('akun_id', $akun->id)->update(['selesai_at' => now(), 'alasan_selesai' => 'diarsipkan']);
        $this->assertNotNull($akun->fresh()->warga_id);

        $this->as($akun->fresh())->getJson('/api/v1/household')->assertNotFound()->assertJsonPath('error.code', 'HOUSEHOLD_NOT_LINKED');
        $this->getJson('/api/v1/dues/my')->assertNotFound();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.keluarga_id', null)->assertJsonPath('data.dapat_ubah_keluarga', false);
    }

    public function test_primary_flag_comes_from_link_not_legacy_jenis(): void
    {
        $akun = $this->warga('1');
        TautanAkun::where('akun_id', $akun->id)->update(['is_utama' => false]);
        $this->assertSame('utama', $akun->fresh()->jenis);

        $this->as(Akun::find($akun->id))->patchJson('/api/v1/household', ['versi' => 1, 'whatsapp' => '081234567890'])->assertForbidden();
    }
}
