<?php

namespace Tests\Feature;

use App\Domain\Identity\TokenAttempts;
use App\Models\Akun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Koreksi review backend M06 (GROK_BACKEND_REVIEW.md):
 * - bootstrap operator tanpa password eksplisit gagal SEBELUM membuat akun bila email reset pasti
 *   tidak terkirim; kegagalan SMTP sesudah commit tidak membatalkan akun;
 * - konfirmasi kata sandi saat ini (logout-all, ganti) memakai batas 72 byte/tanpa NUL yang sama
 *   dengan login, tetap menerima kata sandi lama yang lemah;
 * - submit reset memakai limiter sendiri, terpisah dari lupa password.
 */
class M06ReviewFixesTest extends TestCase
{
    use DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    private ?string $env = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = $this->app['env'];
        config(['app.url' => 'https://takeda-staging.morriz.tech']);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->env;
        putenv('RT05_ADMIN_PASSWORD');
        parent::tearDown();
    }

    /** Jalankan rt05:pengurus non-interaktif; kembalikan [exit, output]. */
    private function operator(string $email): array
    {
        $exit = Artisan::call('rt05:pengurus', ['aksi' => 'buat', 'email' => $email, '--no-interaction' => true]);

        return [$exit, Artisan::output()];
    }

    private function sentMails(): int
    {
        return count($this->app['mail.manager']->mailer('array')->getSymfonyTransport()->messages());
    }

    private static function ascii(int $bytes): string
    {
        return 'a1'.str_repeat('b', $bytes - 2);
    }

    public function test_bootstrap_without_password_fails_before_creating_an_admin_when_reset_mail_cannot_be_delivered(): void
    {
        config(['mail.mailers.rr-log' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'log']]]);
        $cases = [
            ['testing', 'log', 'https://takeda-staging.morriz.tech', 'transport log'],
            ['production', 'failover', 'https://takeda.morriz.tech', 'transport log'],
            ['staging', 'rr-log', 'https://takeda-staging.morriz.tech', 'transport log'],
            ['local', 'tidak-ada', 'http://127.0.0.1:8000', 'tidak dikenal'],
            ['staging', 'smtp', 'http://takeda-staging.morriz.tech', 'APP_URL harus HTTPS'],
            ['staging', 'array', 'https://takeda-staging.morriz.tech', 'mailer array'],
        ];
        foreach ($cases as $n => [$environment, $mailer, $url, $reason]) {
            $this->app['env'] = $environment;
            config(['mail.default' => $mailer, 'app.url' => $url]);
            $email = "blok$n@contoh.test";

            [$exit, $output] = $this->operator($email);

            $label = "$environment/$mailer";
            $this->assertSame(1, $exit, $label);
            $this->assertStringContainsString('Akun tidak dibuat', $output, $label);
            $this->assertStringContainsString($reason, $output, $label);
            $this->assertStringContainsString('RT05_ADMIN_PASSWORD', $output, $label);
            $this->assertFalse(Akun::where('email', $email)->exists(), $label);
            $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $email)->count(), $label);
        }
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'akun.pengurus_dibuat_operator')->count());
        $this->app['env'] = $this->env;
        $this->assertSame(0, $this->sentMails());
    }

    public function test_explicit_env_password_and_hidden_prompt_still_work_with_log_mailer(): void
    {
        config(['mail.default' => 'log']);

        putenv('RT05_ADMIN_PASSWORD=OperatorKuat77');
        [$exit, $output] = $this->operator('eksplisit@contoh.test');
        $this->assertSame(0, $exit, $output);
        $this->assertStringNotContainsString('OperatorKuat77', $output);
        $this->postJson('/api/v1/auth/mobile-login', ['email' => 'eksplisit@contoh.test', 'password' => 'OperatorKuat77', 'device_name' => 'hp'])
            ->assertOk()->assertJsonPath('data.akun.peran', 'pengurus');
        putenv('RT05_ADMIN_PASSWORD');

        $this->artisan('rt05:pengurus', ['aksi' => 'buat', 'email' => 'prompt@contoh.test'])
            ->expectsQuestion('Password pengurus (tidak ditampilkan)', 'PromptKuat88')
            ->expectsQuestion('Ulangi password', 'PromptKuat88')
            ->doesntExpectOutputToContain('PromptKuat88')
            ->assertSuccessful();
        $this->assertTrue(Hash::check('PromptKuat88', Akun::where('email', 'prompt@contoh.test')->value('password_hash')));
        $this->assertSame(0, DB::table('password_reset_tokens')->whereIn('email', ['eksplisit@contoh.test', 'prompt@contoh.test'])->count());
    }

    public function test_bootstrap_without_password_sends_reset_mail_through_array_transport_in_tests(): void
    {
        [$exit, $output] = $this->operator('array@contoh.test');

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Email reset diantrekan', $output);
        $this->assertSame(1, $this->sentMails());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'array@contoh.test')->count());
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $output, 'Token reset tidak dicetak.');
    }

    public function test_smtp_failure_after_commit_keeps_the_account_and_forgot_response(): void
    {
        // SMTP valid secara konfigurasi tetapi tidak dapat dihubungi (port discard lokal, ditolak).
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9, 'timeout' => 3, 'scheme' => 'smtp']]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        [$exit, $output] = $this->operator('smtpgagal@contoh.test');

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('Akun tetap tersimpan', $output);
        $akun = Akun::where('email', 'smtpgagal@contoh.test')->first();
        $this->assertNotNull($akun, 'Akun yang sudah commit tidak dibatalkan kegagalan SMTP.');
        $this->assertSame('pengurus', $akun->peran);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'akun.pengurus_dibuat_operator')->where('entity_id', $akun->id)->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'smtpgagal@contoh.test')->count());
        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $output);

        // Lupa password dengan SMTP gagal: tetap 202 generik, token tersimpan, log tanpa token/email.
        $warga = $this->warga('81');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $warga->email])->assertStatus(202);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $warga->email)->count());
        $this->assertNotEmpty(array_filter($logged, fn ($l) => str_contains($l, 'Email reset gagal diantrekan/dikirim')));
        foreach ($logged as $line) {
            $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $line);
            $this->assertStringNotContainsString($warga->email, $line);
        }
    }

    public function test_current_password_confirmation_rejects_72_byte_prefix_with_suffix_and_nul(): void
    {
        $akun = $this->warga('82', ['password' => self::ascii(72)]);
        $bearer = $akun->createToken('hp')->plainTextToken;
        $other = $akun->createToken('hp lain')->plainTextToken;
        $this->assertTrue(password_verify(self::ascii(72).'Z', $akun->password_hash), 'Kontrol: bcrypt PHP menerima sufiks.');

        foreach ([self::ascii(72).'Z', self::ascii(72)."\u{0000}"] as $wrong) {
            $this->app['auth']->forgetGuards();
            $this->withToken($bearer)->patchJson('/api/v1/auth/password', ['current_password' => $wrong, 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])
                ->assertStatus(422)->assertJsonValidationErrorFor('current_password', 'error.fields');
            $this->app['auth']->forgetGuards();
            $this->withToken($bearer)->postJson('/api/v1/auth/logout-all', ['password' => $wrong])
                ->assertStatus(422)->assertJsonValidationErrorFor('password', 'error.fields');
        }
        $this->assertSame(2, $akun->tokens()->count(), 'Tidak ada mutasi.');
        $this->assertTrue(Hash::check(self::ascii(72), $akun->refresh()->password_hash));

        // Nilai persis tetap berlaku.
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->patchJson('/api/v1/auth/password', ['current_password' => self::ascii(72), 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_old_weak_password_can_still_be_confirmed(): void
    {
        $akun = $this->warga('83');
        // Akun lama sebelum PasswordPolicy: kata sandi lemah langsung di hash.
        DB::table('akun')->where('id', $akun->id)->update(['password_hash' => Hash::make('lemah')]);
        $bearer = $akun->createToken('hp')->plainTextToken;

        $this->withToken($bearer)->patchJson('/api/v1/auth/password', ['current_password' => 'lemah', 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->postJson('/api/v1/auth/logout-all', ['password' => 'BaruSekali9'])->assertNoContent();
        $this->assertSame(0, $akun->tokens()->count());

        $weak = $this->warga('84');
        DB::table('akun')->where('id', $weak->id)->update(['password_hash' => Hash::make('lemah')]);
        $token = $weak->createToken('hp')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout-all', ['password' => 'lemah'])->assertNoContent();
    }

    public function test_token_reservation_window_retry_after_and_release_to_zero(): void
    {
        config(['rt05.auth.token_failures_per_hour' => 2]);
        $request = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.9.9.9']);

        TokenAttempts::reserve($request);
        TokenAttempts::release($request);
        $this->assertSame(0, TokenAttempts::hits($request), 'Release mengembalikan ke nol.');
        TokenAttempts::release($request);
        $this->assertSame(0, TokenAttempts::hits($request), 'Tidak pernah negatif.');

        TokenAttempts::reserve($request);
        $this->travel(20)->minutes();
        TokenAttempts::reserve($request);
        try {
            TokenAttempts::reserve($request);
            $this->fail('Reservasi ketiga harus 429.');
        } catch (ThrottleRequestsException $e) {
            // Jendela dihitung dari reservasi pertama: sisa ±40 menit, bukan diperpanjang.
            $this->assertEqualsWithDelta(40 * 60, $e->getHeaders()['Retry-After'], 2);
        }
        $this->assertSame(2, TokenAttempts::hits($request));

        $this->travel(41)->minutes();
        $this->assertSame(0, TokenAttempts::hits($request), 'Jendela berakhir.');
        TokenAttempts::reserve($request);
        $this->assertSame(1, TokenAttempts::hits($request));
    }

    public function test_reset_submissions_have_their_own_limiter_bucket(): void
    {
        $akun = $this->warga('85');
        $this->app['auth']->forgetGuards();
        // Tiga permintaan lupa password menghabiskan bucket lupa-password (3/15 menit).
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $akun->email])->assertStatus(202);
        }
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $akun->email])->assertStatus(429);

        // Submit reset tetap dapat dipakai: dua salah lalu token yang berlaku.
        $token = Password::broker('akun')->createToken($akun);
        foreach (['salah-1', 'salah-2'] as $bad) {
            $this->postJson('/api/v1/auth/reset-password', ['email' => $akun->email, 'token' => $bad, 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/reset-password', ['email' => $akun->email, 'token' => $token, 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertOk();

        // Bucket reset sendiri tetap dibatasi: 10 per 15 menit per IP + email.
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/v1/auth/reset-password', ['email' => $akun->email, 'token' => "x$i", 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/reset-password', ['email' => $akun->email, 'token' => 'x', 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertStatus(429);
    }
}
