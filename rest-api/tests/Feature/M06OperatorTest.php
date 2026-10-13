<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * M06 bootstrap operator rt05:pengurus: tanpa password bawaan, password tidak pernah dicetak,
 * grant/revoke mencabut token dan sesi, tidak mencabut pengurus aktif terakhir, semua diaudit.
 */
class M06OperatorTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    protected function tearDown(): void
    {
        putenv('RT05_ADMIN_PASSWORD');
        parent::tearDown();
    }

    private function audits(string $id): array
    {
        return DB::table('audit_logs')->where('entity_id', $id)->orderBy('created_at')->get(['actor_id', 'action', 'safe_diff'])->map(fn ($a) => (array) $a)->all();
    }

    public function test_bootstrap_from_env_password_is_audited_and_never_printed(): void
    {
        putenv('RT05_ADMIN_PASSWORD=OperatorKuat77');

        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => ' Admin@Contoh.TEST ', '--nama' => 'Uji Admin', '--no-interaction' => true])
            ->doesntExpectOutputToContain('OperatorKuat77')->assertSuccessful();

        $akun = Akun::where('email', 'admin@contoh.test')->firstOrFail();
        $this->assertSame(['pengurus', 'aktif', 'utama'], [$akun->peran, $akun->status, $akun->jenis]);
        $this->postJson('/api/v1/auth/mobile-login', ['email' => 'admin@contoh.test', 'password' => 'OperatorKuat77', 'device_name' => 'hp'])->assertOk()->assertJsonPath('data.akun.peran', 'pengurus');
        $audit = $this->audits($akun->id)[0];
        $this->assertNull($audit['actor_id']);
        $this->assertSame('akun.pengurus_dibuat_operator', $audit['action']);
        $this->assertStringNotContainsString('OperatorKuat77', $audit['safe_diff']);
        $this->assertStringContainsString('"password": "env"', $audit['safe_diff']);
    }

    public function test_bootstrap_without_password_queues_reset_email_and_prints_no_secret(): void
    {
        Notification::fake();

        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => 'baru@contoh.test', '--no-interaction' => true])
            ->expectsOutputToContain('Email reset diantrekan')->assertSuccessful();

        $akun = Akun::where('email', 'baru@contoh.test')->firstOrFail();
        Notification::assertSentTo($akun, ResetPasswordNotification::class);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'baru@contoh.test')->count());
        $this->assertStringContainsString('email_reset', $this->audits($akun->id)[0]['safe_diff']);
    }

    public function test_bootstrap_rejects_weak_password_duplicate_and_invalid_input(): void
    {
        putenv('RT05_ADMIN_PASSWORD=pendek');
        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => 'lemah@contoh.test', '--no-interaction' => true])->assertExitCode(2);
        $this->assertFalse(Akun::where('email', 'lemah@contoh.test')->exists());

        putenv('RT05_ADMIN_PASSWORD=OperatorKuat77');
        $this->warga('61');
        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => 'uji61@contoh.test', '--no-interaction' => true])->assertFailed();
        $this->assertSame('warga', Akun::where('email', 'uji61@contoh.test')->value('peran'));

        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => 'bukan-email', '--no-interaction' => true])->assertExitCode(2);
        $this->artisan('rt05:pengurus', ['aksi' => 'hapus', 'email' => 'x@contoh.test', '--no-interaction' => true])->assertExitCode(2);
    }

    public function test_interactive_bootstrap_uses_hidden_prompt(): void
    {
        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => 'prompt@contoh.test'])
            ->expectsQuestion('Password pengurus (tidak ditampilkan)', 'PromptKuat88')
            ->expectsQuestion('Ulangi password', 'PromptKuat88')
            ->doesntExpectOutputToContain('PromptKuat88')
            ->assertSuccessful();

        $this->assertTrue(password_verify('PromptKuat88', Akun::where('email', 'prompt@contoh.test')->value('password_hash')));
    }

    public function test_grant_revokes_old_tokens_and_sessions_and_requires_active_account(): void
    {
        $warga = $this->warga('62');
        $token = $warga->createToken('hp')->plainTextToken;
        $versi = $warga->versi_sesi;

        $this->artisan('rt05:pengurus', ['aksi' => 'grant', 'email' => 'uji62@contoh.test'])->assertSuccessful();

        $warga->refresh();
        $this->assertSame('pengurus', $warga->peran);
        $this->assertSame(0, $warga->tokens()->count());
        $this->assertGreaterThan($versi, $warga->versi_sesi);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame('akun.peran_pengurus_operator', last($this->audits($warga->id))['action']);

        // Idempoten: grant ulang tidak menulis audit baru.
        $count = count($this->audits($warga->id));
        $this->artisan('rt05:pengurus', ['aksi' => 'grant', 'email' => 'uji62@contoh.test'])->expectsOutputToContain('tidak ada perubahan')->assertSuccessful();
        $this->assertCount($count, $this->audits($warga->id));

        $off = $this->warga('63');
        $off->forceFill(['status' => 'nonaktif'])->save();
        $this->artisan('rt05:pengurus', ['aksi' => 'grant', 'email' => 'uji63@contoh.test'])->assertFailed();
        $this->assertSame('warga', $off->refresh()->peran);
        $this->artisan('rt05:pengurus', ['aksi' => 'grant', 'email' => 'tidakada@contoh.test'])->assertFailed();
    }

    public function test_revoke_never_removes_the_last_active_admin(): void
    {
        $only = $this->pengurus('satu@contoh.test');

        $this->artisan('rt05:pengurus', ['aksi' => 'revoke', 'email' => 'satu@contoh.test'])->expectsOutputToContain('terakhir')->assertFailed();
        $this->assertSame('pengurus', $only->refresh()->peran);

        $second = $this->pengurus('dua@contoh.test');
        $token = $second->createToken('hp')->plainTextToken;
        $this->artisan('rt05:pengurus', ['aksi' => 'revoke', 'email' => 'dua@contoh.test'])->assertSuccessful();

        $this->assertSame('warga', $second->refresh()->peran);
        $this->withToken($token)->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
        $this->assertSame('akun.peran_warga_operator', last($this->audits($second->id))['action']);
    }

    public function test_approve_and_reject_advance_session_generation(): void
    {
        $this->warga('65');
        $admin = $this->pengurus();
        foreach (['anak65a@contoh.test', 'anak65b@contoh.test'] as $email) {
            $this->postJson('/api/v1/auth/additional-register', [
                'email' => $email, 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
                'registration_token' => $this->tokenCode, 'device_name' => 'hp', 'nama' => 'Uji Anak', 'hubungan_keluarga' => 'Anak',
                'blok' => 'Z', 'jalan' => 'Jl. Uji', 'nomor' => '65',
            ])->assertStatus(202);
        }
        [$a, $b] = [Akun::where('email', 'anak65a@contoh.test')->first(), Akun::where('email', 'anak65b@contoh.test')->first()];

        $this->as($admin)->postJson("/api/v1/admin/additional-account-requests/{$a->permohonan->id}/approve", [])->assertOk();
        $this->as($admin)->postJson("/api/v1/admin/additional-account-requests/{$b->permohonan->id}/reject", ['alasan' => 'Uji'])->assertOk();

        $this->assertGreaterThan($a->versi_sesi, $a->refresh()->versi_sesi);
        $this->assertGreaterThan($b->versi_sesi, $b->refresh()->versi_sesi);
        $this->assertSame(['aktif', 'menunggu'], [$a->status, $b->status]);
        $this->assertSame(0, $b->tokens()->count());
    }

    public function test_api_never_grants_admin(): void
    {
        $admin = $this->pengurus();
        $warga = $this->warga('64');

        $this->as($admin)->patchJson("/api/v1/admin/accounts/{$warga->id}", ['status' => 'aktif', 'alasan' => 'Uji', 'peran' => 'pengurus'])->assertOk();
        $this->assertSame('warga', $warga->refresh()->peran);
    }
}
