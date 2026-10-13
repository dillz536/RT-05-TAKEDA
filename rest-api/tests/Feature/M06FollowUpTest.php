<?php

namespace Tests\Feature;

use App\Domain\Identity\PasswordPolicy;
use App\Domain\Identity\RegistrationToken;
use App\Models\Akun;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use InvalidArgumentException;
use Tests\Support\Fixtures;
use Tests\Support\OpenApi;
use Tests\TestCase;

/**
 * Perbaikan lanjutan M06: batas byte bcrypt untuk kata sandi baru (422, bukan 500 atau
 * pemotongan diam-diam), penolakan akun tambahan tetap dapat dibaca pemohon dengan akses
 * terbatas, akun nonaktif/diarsipkan ditolak global, dan rotasi tidak memakai ulang kode lama.
 */
class M06FollowUpTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    protected function tearDown(): void
    {
        RegistrationToken::generateCodesUsing(null);
        putenv('RT05_ADMIN_PASSWORD');
        parent::tearDown();
    }

    /** Kata sandi valid (huruf + angka) dengan panjang byte UTF-8 tepat $bytes. */
    private static function ascii(int $bytes): string
    {
        return 'a1'.str_repeat('b', $bytes - 2);
    }

    /** 'é' = 2 byte UTF-8; ditambah '1' agar berisi angka. */
    private static function accented(int $chars): string
    {
        return str_repeat('é', $chars - 1).'1';
    }

    public function test_php_bcrypt_behaviour_and_hasher_limit(): void
    {
        // PHP 8.4 (diamati): bcrypt memotong diam-diam setelah 72 byte dan NUL melempar ValueError.
        $truncated = password_hash(str_repeat('a', 72).'X', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertTrue(password_verify(str_repeat('a', 72).'Y', $truncated), 'Byte setelah ke-72 diabaikan bcrypt PHP.');
        try {
            password_hash("ab\0cd1234", PASSWORD_BCRYPT, ['cost' => 4]);
            $this->fail('NUL harus ditolak password_hash.');
        } catch (\ValueError) {
        }

        // Hasher aplikasi menolak, bukan memotong.
        $this->assertSame(72, config('hashing.bcrypt.limit'));
        Hash::make(self::ascii(72));
        $this->expectException(InvalidArgumentException::class);
        Hash::make(self::ascii(73));
    }

    public function test_new_password_byte_bound_returns_422_on_every_endpoint(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->freshToken();
        // Batas dihitung dalam byte UTF-8, bukan karakter: 37 karakter beraksen sudah 73 byte.
        $emoji = fn (int $n) => str_repeat("\u{1F600}", $n).'a1';
        $cases = [
            [self::ascii(72), true], [self::ascii(73), false],
            [self::accented(36), true], [self::accented(37), false],
            [$emoji(17), true], [$emoji(18), false],
            ["Rahasia\u{0000}123", false],
        ];
        $this->assertSame([71, 73, 70, 74], [strlen(self::accented(36)), strlen(self::accented(37)), strlen($emoji(17)), strlen($emoji(18))]);
        $this->assertSame([37, 20], [mb_strlen(self::accented(37)), mb_strlen($emoji(18))]);
        foreach ($cases as $n => [$password, $ok]) {
            $register = $this->postJson('/api/v1/auth/register', [
                'email' => "batas$n@contoh.test", 'password' => $password, 'password_confirmation' => $password,
                'registration_token' => $this->tokenCode, 'device_name' => 'hp', 'nama_kepala' => "Uji Batas $n",
                'blok' => 'B', 'jalan' => 'Jl. Batas', 'nomor' => (string) ($n + 1), 'jenis_hunian' => 'pemilik', 'anggota' => [],
            ]);
            $additional = $this->postJson('/api/v1/auth/additional-register', [
                'email' => "tambah$n@contoh.test", 'password' => $password, 'password_confirmation' => $password,
                'registration_token' => $this->tokenCode, 'device_name' => 'hp', 'nama' => "Uji Tambah $n", 'hubungan_keluarga' => 'Anak',
                'blok' => 'B', 'jalan' => 'Jl. Batas', 'nomor' => '1',
            ]);
            $label = bin2hex(substr($password, 0, 6)).'/'.strlen($password);
            if ($ok) {
                $register->assertCreated();
                $additional->assertStatus(202);
                $this->assertTrue(Hash::check($password, Akun::where('email', "batas$n@contoh.test")->value('password_hash')), $label);
            } else {
                foreach ([$register, $additional] as $res) {
                    $res->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
                    $this->assertContains($res->json('error.fields.password.0'), [PasswordPolicy::MESSAGE_BYTES, PasswordPolicy::MESSAGE_NUL], $label);
                }
                $this->assertFalse(Akun::where('email', "batas$n@contoh.test")->exists());
            }
        }

        // Reset dan ganti kata sandi memakai aturan yang sama; kata sandi lama tetap berlaku.
        $akun = $this->warga('70');
        foreach ([self::ascii(73), self::accented(37), "Rahasia\u{0000}123"] as $bad) {
            $token = Password::broker('akun')->createToken($akun);
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/auth/reset-password', ['email' => $akun->email, 'token' => $token, 'password' => $bad, 'password_confirmation' => $bad])
                ->assertStatus(422)->assertJsonPath('error.fields.password.0', fn ($m) => in_array($m, [PasswordPolicy::MESSAGE_BYTES, PasswordPolicy::MESSAGE_NUL], true));
            $this->as($akun)->patchJson('/api/v1/auth/password', ['current_password' => 'Rahasia123', 'password' => $bad, 'password_confirmation' => $bad])
                ->assertStatus(422)->assertJsonPath('error.fields.password.0', fn ($m) => in_array($m, [PasswordPolicy::MESSAGE_BYTES, PasswordPolicy::MESSAGE_NUL], true));
        }
        $this->assertTrue(Hash::check('Rahasia123', $akun->refresh()->password_hash));
        $this->as($akun)->patchJson('/api/v1/auth/password', ['current_password' => 'Rahasia123', 'password' => self::ascii(72), 'password_confirmation' => self::ascii(72)])->assertOk();
        $this->assertTrue(Hash::check(self::ascii(72), $akun->refresh()->password_hash));
        // Login: kata sandi 72 byte + sufiks tidak diterima (bcrypt sendiri mengabaikan byte ke-73 dst.),
        // input NUL/panjang ditolak generik tanpa 500, kata sandi tepat 72 byte diterima.
        $this->assertTrue(password_verify(self::ascii(72).'sufiks', $akun->password_hash), 'Kontrol: bcrypt PHP memotong.');
        foreach ([self::ascii(72).'sufiks', self::ascii(150), "a\u{0000}b"] as $wrong) {
            $this->postJson('/api/v1/auth/mobile-login', ['email' => $akun->email, 'password' => $wrong, 'device_name' => 'hp'])
                ->assertStatus(422)->assertJsonPath('error.fields.email.0', 'Email atau kata sandi salah.');
        }
        $this->postJson('/api/v1/auth/mobile-login', ['email' => $akun->email, 'password' => self::ascii(72), 'device_name' => 'hp'])->assertOk();
    }

    public function test_operator_password_over_72_bytes_is_rejected_before_hashing(): void
    {
        putenv('RT05_ADMIN_PASSWORD='.self::ascii(73));
        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => 'panjang@contoh.test', '--no-interaction' => true])
            ->expectsOutputToContain('maksimal 72 byte')->assertExitCode(2);
        $this->assertFalse(Akun::where('email', 'panjang@contoh.test')->exists());

        putenv('RT05_ADMIN_PASSWORD='.self::ascii(72));
        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => 'pas@contoh.test', '--no-interaction' => true])->assertSuccessful();
        $this->assertTrue(Hash::check(self::ascii(72), Akun::where('email', 'pas@contoh.test')->value('password_hash')));
    }

    public function test_rejected_additional_account_stays_pending_and_can_sign_in_to_read_the_reason(): void
    {
        $this->warga('71');
        $admin = $this->pengurus();
        $issued = $this->postJson('/api/v1/auth/additional-register', [
            'email' => 'anak71@contoh.test', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
            'registration_token' => $this->tokenCode, 'device_name' => 'hp lama', 'nama' => 'Uji Anak', 'hubungan_keluarga' => 'Anak',
            'blok' => 'Z', 'jalan' => 'Jl. Uji', 'nomor' => '71',
        ])->assertStatus(202)->json('data.token');
        $akun = Akun::where('email', 'anak71@contoh.test')->firstOrFail();
        $versi = $akun->versi_sesi;

        $this->as($admin)->postJson("/api/v1/admin/additional-account-requests/{$akun->permohonan->id}/reject", ['alasan' => 'Data hubungan keluarga belum sesuai.'])->assertOk();

        $akun->refresh();
        $this->assertSame(['tambahan', 'menunggu', null], [$akun->jenis, $akun->status, $akun->warga_id]);
        $this->assertSame('ditolak', $akun->permohonan->status);
        $this->assertSame(0, $akun->tokens()->count(), 'Bearer lama dicabut.');
        $this->assertGreaterThan($versi, $akun->versi_sesi);
        $this->app['auth']->forgetGuards();
        $this->withToken($issued)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Login ulang menerbitkan bearer terbatas; status dan alasan dapat dibaca.
        $this->app['auth']->forgetGuards();
        $login = $this->withToken('')->postJson('/api/v1/auth/mobile-login', ['email' => 'anak71@contoh.test', 'password' => 'Rahasia123', 'device_name' => 'hp baru'])->assertOk();
        $this->assertSame([], OpenApi::errors($login->json(), 'MobileAuthEnvelope'));
        $login->assertJsonPath('data.akun.status', 'menunggu');
        $bearer = $login->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->getJson('/api/v1/auth/additional-account-status')->assertOk()
            ->assertJsonPath('data.status_akun', 'menunggu')
            ->assertJsonPath('data.permohonan.status', 'ditolak')
            ->assertJsonPath('data.permohonan.catatan', 'Data hubungan keluarga belum sesuai.');
        $this->withToken($bearer)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.status', 'menunggu');
        // Tanpa layanan warga.
        foreach (['/api/v1/household', '/api/v1/complaints', '/api/v1/aspirations'] as $uri) {
            $this->withToken($bearer)->getJson($uri)->assertForbidden();
        }
        $this->withToken($bearer)->postJson('/api/v1/auth/logout-all', ['password' => 'Rahasia123'])->assertForbidden();
        // Tidak dapat disetujui setelah ditolak.
        $this->as($admin)->postJson("/api/v1/admin/additional-account-requests/{$akun->permohonan->id}/approve", [])->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_inactive_and_archived_accounts_are_denied_everywhere(): void
    {
        Notification::fake();
        foreach (['nonaktif' => '72', 'diarsipkan' => '73'] as $status => $nomor) {
            $akun = $this->warga($nomor);
            $bearer = $akun->createToken('hp')->plainTextToken;
            $akun->forceFill(['status' => $status])->save();

            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/auth/mobile-login', ['email' => $akun->email, 'password' => 'Rahasia123', 'device_name' => 'hp'])
                ->assertStatus(422)->assertJsonPath('error.fields.email.0', 'Email atau kata sandi salah.');
            foreach ([['GET', '/api/v1/auth/me'], ['GET', '/api/v1/auth/additional-account-status'], ['GET', '/api/v1/household'], ['POST', '/api/v1/auth/logout']] as [$method, $uri]) {
                $this->app['auth']->forgetGuards();
                $res = $this->withToken($bearer)->json($method, $uri);
                // Pertama 403 (token dicabut saat itu juga), selanjutnya 401 karena token sudah tidak ada.
                $this->assertContains($res->getStatusCode(), [401, 403], "$status $method $uri");
            }
            $this->assertSame(0, $akun->tokens()->count(), $status);
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $akun->email])->assertStatus(202);
            Notification::assertNotSentTo($akun, ResetPasswordNotification::class);
        }

        // Pengurus nonaktif/diarsipkan juga tidak dapat memakai bearer admin.
        $admin = $this->pengurus('arsip@contoh.test');
        $bearer = $admin->createToken('hp')->plainTextToken;
        $admin->forceFill(['status' => 'diarsipkan'])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_rotation_never_reissues_the_previous_code(): void
    {
        $old = RegistrationToken::rotate(null, 'tes');
        $calls = 0;
        RegistrationToken::generateCodesUsing(function () use (&$calls, $old) {
            return ++$calls <= 2 ? $old : '000417';
        });
        $this->assertSame('000417', RegistrationToken::rotate(null, 'tes'));
        $this->assertSame(3, $calls, 'Kode yang sama dengan token yang dicabut dibuang.');

        // Token terakhir sudah kedaluwarsa (tidak ada token aktif): kodenya juga tidak dipakai ulang.
        DB::table('token_registrasi')->where('aktif', true)->update(['berlaku_sampai' => now()->subMinute()]);
        $calls = 0;
        RegistrationToken::generateCodesUsing(function () use (&$calls) {
            return ++$calls === 1 ? '000417' : '995501';
        });
        $this->assertNull(RegistrationToken::active());
        $this->assertTrue(RegistrationToken::rotateIfDue());
        $this->assertSame('995501', RegistrationToken::reveal(RegistrationToken::active()));
    }
}
