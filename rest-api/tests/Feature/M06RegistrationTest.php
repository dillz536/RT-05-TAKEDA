<?php

namespace Tests\Feature;

use App\Domain\Identity\RegistrationToken;
use App\Models\Akun;
use App\Models\Rumah;
use App\Models\TokenRegistrasi;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;
use Tests\Support\Fixtures;
use Tests\Support\OpenApi;
use Tests\TestCase;

/**
 * M06 registrasi utama dan token RT: atomik termasuk bearer, rumah existing ditolak tanpa
 * auto-merge, konflik constraint generik, token invalid/dicabut/kedaluwarsa/leading-zero,
 * limiter kegagalan token per jam, rotasi mingguan tahan scheduler terlewat.
 */
class M06RegistrationTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    /** Tabel yang ditulis registrasi utama; semuanya harus kembali ke jumlah awal saat gagal. */
    private const TABLES = ['akun', 'rumah', 'keluarga', 'warga', 'penghunian', 'permohonan_akun', 'preferensi_notifikasi', 'audit_logs', 'outbox_events', 'personal_access_tokens'];

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'm06@contoh.test', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
            'registration_token' => $this->tokenCode, 'device_name' => 'hp uji', 'nama_kepala' => 'Uji Enam',
            'whatsapp' => '081200000006', 'blok' => 'M', 'jalan' => 'Jl. Melati', 'nomor' => '6', 'jenis_hunian' => 'kontrak',
            'anggota' => [['nama' => 'Uji Pasangan', 'hubungan_keluarga' => 'Istri'], ['nama' => 'Uji Anak', 'hubungan_keluarga' => 'Anak']],
        ], $overrides);
    }

    private function counts(): array
    {
        return array_combine(self::TABLES, array_map(fn ($t) => DB::table($t)->count(), self::TABLES));
    }

    private function useToken(string $code): void
    {
        $this->freshToken();
        DB::table('token_registrasi')->where('aktif', true)->update(['kode_terenkripsi' => Crypt::encryptString($code), 'kode_digest' => RegistrationToken::digest($code)]);
        $this->tokenCode = $code;
    }

    public function test_registration_payload_matches_contract_and_creates_all_records_with_device_token(): void
    {
        $this->freshToken();
        $before = $this->counts();

        $res = $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->assertSame([], OpenApi::errors($res->json(), 'MobileAuthEnvelope'));
        $res->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.akun.status', 'aktif')->assertJsonPath('data.akun.peran', 'warga');
        $after = $this->counts();
        foreach (['akun' => 1, 'rumah' => 1, 'keluarga' => 1, 'warga' => 3, 'penghunian' => 1, 'permohonan_akun' => 1, 'preferensi_notifikasi' => 1, 'personal_access_tokens' => 1] as $table => $delta) {
            $this->assertSame($before[$table] + $delta, $after[$table], $table);
        }
        $akun = Akun::where('email', 'm06@contoh.test')->first();
        $this->assertSame('belum_verifikasi', $akun->keluarga()->status);
        $this->assertSame('kontrak', $akun->keluarga()->penghunianAktif->jenis_hunian);
        $this->assertSame('081200000006', $akun->warga->whatsapp);
        // Bearer yang dikembalikan benar-benar milik akun baru dan berlaku 30 hari.
        $this->app['auth']->forgetGuards();
        $this->withToken($res->json('data.token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'm06@contoh.test');
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), strtotime($res->json('data.expires_at')), 5);
    }

    public function test_device_token_failure_rolls_back_every_registration_record(): void
    {
        $this->freshToken();
        $before = $this->counts();
        PersonalAccessToken::creating(fn () => throw new RuntimeException('simulasi kegagalan penerbitan token'));

        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertSame($before, $this->counts());
        $this->assertFalse(Rumah::where('alamat_key', 'm|melati|6')->exists());
    }

    public function test_additional_registration_also_rolls_back_when_device_token_fails(): void
    {
        $this->freshToken();
        $before = $this->counts();
        PersonalAccessToken::creating(fn () => throw new RuntimeException('simulasi'));

        $this->postJson('/api/v1/auth/additional-register', [
            'email' => 'tambahan@contoh.test', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
            'registration_token' => $this->tokenCode, 'device_name' => 'hp', 'nama' => 'Uji Tambahan', 'hubungan_keluarga' => 'Anak',
            'jalan' => 'Jl. Melati', 'nomor' => '6',
        ])->assertStatus(500);

        $this->assertSame($before, $this->counts());
    }

    public function test_existing_house_without_family_is_rejected_without_merge(): void
    {
        $this->freshToken();
        // Rumah kosong yang dibuat pengurus, tanpa penghunian aktif.
        Rumah::create(['kode_rumah' => 'M-6', 'blok' => 'M', 'jalan' => 'Melati', 'nomor' => '6', 'alamat_key' => 'm|melati|6', 'tagihan_aktif' => true]);
        $before = $this->counts();

        $this->postJson('/api/v1/auth/register', $this->payload(['jalan' => 'JALAN  melati', 'nomor' => 'no. 6']))
            ->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_ALREADY_REGISTERED');

        $this->assertSame($before, $this->counts());
        $this->assertSame(0, DB::table('penghunian')->join('rumah', 'rumah.id', '=', 'penghunian.rumah_id')->where('alamat_key', 'm|melati|6')->count());
    }

    public function test_archived_house_is_rejected_too(): void
    {
        $this->freshToken();
        Rumah::create(['kode_rumah' => 'M-6', 'blok' => 'M', 'jalan' => 'Melati', 'nomor' => '6', 'alamat_key' => 'm|melati|6', 'archived_at' => now()]);

        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_ALREADY_REGISTERED');
    }

    public function test_unique_violation_from_another_writer_becomes_generic_conflict_and_rolls_back(): void
    {
        $this->freshToken();
        $before = $this->counts();
        // Penulis lain menyisipkan rumah beralamat sama tepat sebelum insert registrasi.
        Rumah::creating(function (Rumah $rumah) {
            if ($rumah->kode_rumah !== 'X-LAIN') {
                DB::table('rumah')->insert(['kode_rumah' => 'X-LAIN', 'jalan' => 'Melati', 'nomor' => '6', 'alamat_key' => $rumah->alamat_key]);
            }
        });

        $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(409)->assertJsonPath('error.code', 'HOUSE_ALREADY_REGISTERED');

        $this->assertSame($before, $this->counts());
    }

    public function test_email_unique_violation_from_another_writer_stays_generic(): void
    {
        $this->freshToken();
        Akun::creating(function (Akun $akun) {
            if ($akun->nama !== 'Penulis Lain') {
                DB::table('akun')->insert(['nama' => 'Penulis Lain', 'email' => $akun->email, 'password_hash' => 'x']);
            }
        });

        $res = $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(422)->assertJsonPath('error.code', 'REGISTRATION_REJECTED');
        $this->assertStringNotContainsString('m06@contoh.test', $res->getContent());
        $this->assertStringNotContainsString('akun_email', $res->getContent());
    }

    public function test_leading_zero_token_works_end_to_end_and_shortened_value_is_rejected(): void
    {
        $this->useToken('004217');

        $this->postJson('/api/v1/auth/register', $this->payload(['registration_token' => '4217']))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->postJson('/api/v1/auth/register', $this->payload(['registration_token' => 4217]))->assertStatus(422);
        $this->postJson('/api/v1/auth/register', $this->payload(['registration_token' => '004217']))->assertCreated();
    }

    public function test_revoked_and_expired_tokens_are_rejected_generically(): void
    {
        $old = $this->freshToken();
        $this->freshToken();
        $this->postJson('/api/v1/auth/register', $this->payload(['registration_token' => $old === $this->tokenCode ? '______' : $old]))
            ->assertStatus(422)->assertJsonPath('error.code', 'REGISTRATION_TOKEN_INVALID');

        DB::table('token_registrasi')->where('aktif', true)->update(['berlaku_sampai' => now()->subMinute()]);
        $res = $this->postJson('/api/v1/auth/register', $this->payload())->assertStatus(422)->assertJsonPath('error.code', 'REGISTRATION_TOKEN_INVALID');
        $this->assertStringNotContainsString('kedaluwarsa', $res->json('error.message'));
    }

    public function test_token_failures_are_limited_per_ip_per_hour(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['rt05.auth.token_failures_per_hour' => 3]);
        $this->freshToken();
        $wrong = $this->tokenCode === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->postJson('/api/v1/auth/register', $this->payload(['registration_token' => $wrong]))
                ->assertStatus(422)->assertJsonPath('error.code', 'REGISTRATION_TOKEN_INVALID');
        }
        // Setelah batas, token benar pun ditolak (utama dan tambahan) sampai jendela satu jam lewat.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->postJson('/api/v1/auth/additional-register', [
            'email' => 'tambahan@contoh.test', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
            'registration_token' => $this->tokenCode, 'device_name' => 'hp', 'nama' => 'Uji Tambahan', 'hubungan_keluarga' => 'Anak',
            'jalan' => 'Jl. Melati', 'nomor' => '6',
        ])->assertStatus(429);
        $this->assertFalse(Akun::where('email', 'm06@contoh.test')->exists());

        // IP lain tidak terdampak.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.2'])->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->travel(61)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1'])->postJson('/api/v1/auth/register', $this->payload(['email' => 'lain@contoh.test', 'nomor' => '66']))->assertCreated();
    }

    public function test_successful_registrations_do_not_consume_the_failure_budget(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['rt05.auth.token_failures_per_hour' => 1]);
        $this->freshToken();

        for ($i = 1; $i <= 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.1.1'])
                ->postJson('/api/v1/auth/register', $this->payload(['email' => "ok$i@contoh.test", 'nomor' => "6$i"]))->assertCreated();
        }
    }

    public function test_weekly_rotation_is_hourly_catch_up_and_never_prints_the_token(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'rt05:token-rotate'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--if-due', $event->command);
        $this->assertSame('5 * * * *', $event->expression);

        DB::table('token_registrasi')->update(['aktif' => false]);
        $this->artisan('rt05:token-rotate --if-due')->expectsOutputToContain('dirotasi (jatuh tempo)')->assertSuccessful();
        $first = RegistrationToken::active();

        $this->travel(6)->days();
        $this->artisan('rt05:token-rotate --if-due')->expectsOutputToContain('belum jatuh tempo')->assertSuccessful();
        $this->assertSame($first->id, RegistrationToken::active()->id);

        $this->travel(1)->days();
        $code = RegistrationToken::reveal($first);
        $this->artisan('rt05:token-rotate --if-due')->doesntExpectOutputToContain($code)->assertSuccessful();
        $this->assertNotSame($first->id, RegistrationToken::active()->id);
        $this->assertFalse(TokenRegistrasi::find($first->id)->aktif);
        $this->assertNotNull(TokenRegistrasi::find($first->id)->dicabut_at);
        $this->assertSame(1, TokenRegistrasi::where('aktif', true)->count());
    }

    public function test_missed_schedule_catches_up_after_expiry(): void
    {
        $this->freshToken();
        $this->travel(20)->days();

        $this->assertNull(RegistrationToken::active());
        $this->assertTrue(RegistrationToken::rotateIfDue());
        $this->assertNotNull(RegistrationToken::active());
    }

    public function test_admin_token_view_creates_once_is_no_store_and_audit_has_no_plaintext(): void
    {
        DB::table('token_registrasi')->update(['aktif' => false]);
        $pengurus = $this->pengurus();

        $first = $this->as($pengurus)->getJson('/api/v1/admin/registration-token')->assertOk();
        $second = $this->as($pengurus)->getJson('/api/v1/admin/registration-token')->assertOk();

        $this->assertSame($first->json('data.kode'), $second->json('data.kode'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $first->json('data.kode'));
        $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
        $this->assertSame(1, TokenRegistrasi::where('aktif', true)->count());
        $audit = DB::table('audit_logs')->where('action', 'like', 'token_registrasi.%')->pluck('safe_diff')->implode(' ');
        $this->assertStringNotContainsString($first->json('data.kode'), $audit);

        $rotated = $this->as($pengurus)->postJson('/api/v1/admin/registration-token/rotate')->assertOk();
        $this->assertNotSame($first->json('data.kode'), $rotated->json('data.kode'));
        $this->assertNull(RegistrationToken::verify($first->json('data.kode')));
    }
}
