<?php

namespace Tests\Feature;

use App\Domain\Identity\RegistrationToken;
use App\Models\Akun;
use App\Models\PermohonanAkun;
use App\Models\TokenRegistrasi;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Identitas/keluarga (TESTING.md): token, registrasi, akun tambahan, status, peran. */
class IdentityTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'baru@contoh.test', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
            'registration_token' => $this->tokenCode, 'device_name' => 'tes', 'nama_kepala' => 'Uji Baru',
            'jalan' => 'Jl. Uji', 'nomor' => '99', 'blok' => 'Z', 'jenis_hunian' => 'pemilik',
            'anggota' => [['nama' => 'Uji Istri', 'hubungan_keluarga' => 'Istri']],
        ], $overrides);
    }

    public function test_token_is_six_digit_string_and_rotation_revokes_previous(): void
    {
        $first = RegistrationToken::rotate(null);
        $second = RegistrationToken::rotate(null);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $first);
        $this->assertSame(1, TokenRegistrasi::where('aktif', true)->count());
        $this->assertNull(RegistrationToken::verify($first === $second ? '______' : $first));
        $this->assertNotNull(RegistrationToken::verify($second));
        $this->assertStringNotContainsString($second, (string) TokenRegistrasi::where('aktif', true)->value('kode_terenkripsi'));
    }

    public function test_leading_zero_token_is_preserved(): void
    {
        $this->freshToken();
        $token = TokenRegistrasi::where('aktif', true)->first();
        DB::table('token_registrasi')->where('id', $token->id)->update([
            'kode_terenkripsi' => Crypt::encryptString('004217'),
            'kode_digest' => RegistrationToken::digest('004217'),
        ]);

        $this->assertNotNull(RegistrationToken::verify('004217'));
        $this->assertNull(RegistrationToken::verify('4217'));
    }

    public function test_primary_registration_creates_household_atomically(): void
    {
        $this->freshToken();
        $res = $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $res->assertJsonPath('data.akun.peran', 'warga')->assertJsonPath('data.akun.status', 'aktif');
        $akun = Akun::where('email', 'baru@contoh.test')->first();
        $this->assertSame(2, $akun->keluarga()->warga()->count());
        $this->assertNotNull($akun->rumah());
        $this->assertSame('belum_verifikasi', $akun->keluarga()->status);
    }

    public function test_invalid_token_rejected_without_partial_write(): void
    {
        $this->freshToken();
        $before = DB::table('rumah')->count();

        $this->postJson('/api/v1/auth/register', $this->payload(['registration_token' => $this->tokenCode === '000000' ? '111111' : '000000']))
            ->assertStatus(422)->assertJsonPath('error.code', 'REGISTRATION_TOKEN_INVALID');
        $this->assertSame($before, DB::table('rumah')->count());
        $this->assertFalse(Akun::where('email', 'baru@contoh.test')->exists());
    }

    public function test_role_cannot_be_mass_assigned_from_registration(): void
    {
        $this->freshToken();
        $this->postJson('/api/v1/auth/register', $this->payload(['peran' => 'pengurus', 'status' => 'aktif']))->assertCreated();

        $this->assertSame('warga', Akun::where('email', 'baru@contoh.test')->value('peran'));
    }

    public function test_same_house_written_differently_is_not_registered_twice(): void
    {
        $this->warga('5');

        $this->postJson('/api/v1/auth/register', $this->payload(['jalan' => 'jalan uji', 'nomor' => 'No. 5', 'email' => 'lain@contoh.test']))
            ->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_ALREADY_REGISTERED');
    }

    public function test_duplicate_email_gets_generic_message(): void
    {
        $this->warga('6');

        $res = $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'UJI6@contoh.test', 'nomor' => '77']))->assertStatus(422);
        $this->assertStringNotContainsString('sudah terdaftar', $res->json('error.message'));
    }

    public function test_additional_account_pending_until_approved(): void
    {
        $utama = $this->warga('8');
        $res = $this->postJson('/api/v1/auth/additional-register', [
            'email' => 'anak8@contoh.test', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
            'registration_token' => $this->tokenCode, 'device_name' => 'tes', 'nama' => 'Uji Anak Akun',
            'hubungan_keluarga' => 'Anak', 'blok' => 'Z', 'jalan' => 'Jl. Uji', 'nomor' => '8',
        ])->assertStatus(202);
        $token = $res->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/additional-account-status')->assertOk()->assertJsonPath('data.permohonan.status', 'diajukan');
        $this->withToken($token)->getJson('/api/v1/complaints')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/household')->assertForbidden();

        $pengurus = $this->pengurus();
        $permohonan = PermohonanAkun::where('status', 'diajukan')->first();
        $this->as($pengurus)->postJson("/api/v1/admin/additional-account-requests/{$permohonan->id}/approve", [])->assertOk();

        $anak = Akun::where('email', 'anak8@contoh.test')->first();
        $this->assertSame('aktif', $anak->status);
        $this->assertSame('warga', $anak->peran);
        $this->assertSame($utama->keluarga()->id, $anak->keluarga()->id);
        // Approval tidak memberi hak edit keluarga (hanya akun utama).
        $this->as($anak)->patchJson('/api/v1/household', ['versi' => 1, 'whatsapp' => '081234567890'])->assertForbidden();
    }

    public function test_inactive_account_with_valid_token_is_rejected_and_revoked(): void
    {
        $akun = $this->warga('9');
        $plain = $akun->createToken('hp')->plainTextToken;
        $akun->forceFill(['status' => 'nonaktif'])->save();

        $this->withToken($plain)->getJson('/api/v1/household')->assertForbidden();
        $this->assertSame(0, $akun->tokens()->count());
    }

    public function test_resident_cannot_reach_admin_or_token(): void
    {
        $warga = $this->warga('10');
        $this->as($warga)->getJson('/api/v1/admin/registration-token')->assertForbidden();
        $this->as($warga)->getJson('/api/v1/admin/houses')->assertForbidden();
    }

    public function test_login_and_logout_revokes_token(): void
    {
        $this->warga('11');
        $token = $this->postJson('/api/v1/auth/mobile-login', ['email' => 'uji11@contoh.test', 'password' => 'Rahasia123', 'device_name' => 'hp'])
            ->assertOk()->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_household_isolation_between_families(): void
    {
        $a = $this->warga('12');
        $b = $this->warga('13');
        $memberOfB = $b->keluarga()->warga()->where('is_kepala', false)->first();

        $this->as($a)->patchJson("/api/v1/household/members/{$memberOfB->id}", ['nama' => 'Diretas', 'versi' => 1])->assertNotFound();
        $this->assertNotSame('Diretas', $memberOfB->refresh()->nama);
    }

    public function test_household_update_rejects_stale_version(): void
    {
        $a = $this->warga('14');
        $this->as($a)->patchJson('/api/v1/household', ['versi' => 1, 'whatsapp' => '081234567890'])->assertOk();
        $this->as($a)->patchJson('/api/v1/household', ['versi' => 1, 'whatsapp' => '081234567891'])->assertStatus(409)->assertJsonPath('error.code', 'VERSION_CONFLICT');
    }
}
