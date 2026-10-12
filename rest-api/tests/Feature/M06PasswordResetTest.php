<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\Akun;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\Browser;
use Tests\Support\Fixtures;
use Tests\Support\OpenApi;
use Tests\TestCase;

/**
 * M06 lupa/reset kata sandi: respons generik, email diantrekan after_commit dengan tautan HTTPS
 * (token di fragment) dan kode Android, token kedaluwarsa/sekali pakai, pencabutan akses,
 * form browser GET /reset-password dengan CSRF, tanpa token di log.
 * Catatan: mailer array/fake membuktikan isi dan antrean, bukan pengiriman SMTP sampai inbox.
 */
class M06PasswordResetTest extends TestCase
{
    use Browser, DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBrowser();
        config(['app.url' => 'https://takeda-staging.morriz.tech']);
    }

    private function reset(string $email, string $token, string $password = 'BaruSekali9')
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/reset-password', ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password]);
    }

    public function test_forgot_response_is_identical_for_known_unknown_and_inactive_emails(): void
    {
        Notification::fake();
        $this->warga('41');
        $off = $this->warga('42');
        $off->forceFill(['status' => 'nonaktif'])->save();

        $bodies = [];
        foreach (['uji41@contoh.test', 'UJI41@Contoh.TEST ', 'tidakada@contoh.test', 'uji42@contoh.test'] as $email) {
            $res = $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertStatus(202);
            $this->assertSame([], OpenApi::errors($res->json(), 'MessageEnvelope'));
            $bodies[] = $res->json('data');
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $bodies)));
        $this->assertSame(['message' => 'Jika email terdaftar, permintaan reset kata sandi telah diterima dan diantrekan.'], $bodies[0]);
        Notification::assertSentTo(Akun::where('email', 'uji41@contoh.test')->first(), ResetPasswordNotification::class);
        Notification::assertNotSentTo($off, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    public function test_reset_notification_is_queued_after_commit_encrypted_on_default_queue(): void
    {
        $notification = new ResetPasswordNotification('kode-uji');

        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $notification);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);
        $this->assertSame('default', $notification->queue);

        Queue::fake();
        $this->warga('43');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'uji43@contoh.test'])->assertStatus(202);
        Queue::assertPushedOn('default', SendQueuedNotifications::class, fn ($job) => $job->notification instanceof ResetPasswordNotification);
    }

    public function test_email_is_not_sent_when_the_reset_transaction_rolls_back(): void
    {
        // Transport array nyata (Mail::fake mengabaikan email notifikasi mentah).
        $sent = fn () => count($this->app['mailer']->getSymfonyTransport()->messages());
        $akun = $this->warga('44');

        try {
            DB::transaction(function () use ($akun) {
                Password::broker('akun')->sendResetLink(['email' => $akun->email]);
                throw new RuntimeException('rollback setelah token dibuat');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $sent());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $akun->email)->count());

        // Kontrol positif: transaksi yang commit mengirim tepat satu email.
        DB::transaction(fn () => Password::broker('akun')->sendResetLink(['email' => $akun->email]));
        $this->assertSame(1, $sent());
    }

    public function test_email_contains_https_fragment_link_and_copyable_code_only(): void
    {
        Notification::fake();
        $akun = $this->warga('45');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $akun->email])->assertStatus(202);

        Notification::assertSentTo($akun, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($akun) {
            $mail = $n->toMail($akun);
            $url = $mail->actionUrl;
            $this->assertStringStartsWith('https://takeda-staging.morriz.tech/reset-password#token=', $url);
            $this->assertStringNotContainsString('?', $url, 'Token/email tidak boleh di query string (masuk log).');
            $this->assertStringContainsString('email='.rawurlencode($akun->email), $url);
            $this->assertContains($n->token, $mail->introLines + $mail->outroLines, 'Kode dapat disalin ke Android.');
            $this->assertSame(64, strlen($n->token));

            return true;
        });
    }

    public function test_full_mail_rendering_is_sent_once_through_the_queue(): void
    {
        $akun = $this->warga('46');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $akun->email])->assertStatus(202);

        $messages = $this->app['mailer']->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $html = $messages[0]->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('https://takeda-staging.morriz.tech/reset-password#token=', $html);
        $this->assertStringContainsString('Reset kata sandi RT05 TAKEDA', $messages[0]->getOriginalMessage()->getSubject());
    }

    public function test_reset_succeeds_once_revokes_access_and_is_audited_without_secret(): void
    {
        $akun = $this->warga('47');
        $bearer = $akun->createToken('hp')->plainTextToken;
        $versi = $akun->versi_sesi;
        $token = Password::broker('akun')->createToken($akun);

        $res = $this->reset('UJI47@contoh.test', $token)->assertOk();
        $this->assertSame([], OpenApi::errors($res->json(), 'MessageEnvelope'));

        $akun->refresh();
        $this->assertSame(0, $akun->tokens()->count());
        $this->assertGreaterThan($versi, $akun->versi_sesi);
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/mobile-login', ['email' => $akun->email, 'password' => 'BaruSekali9', 'device_name' => 'hp'])->assertOk();
        $audit = DB::table('audit_logs')->where('action', 'akun.reset_password')->where('entity_id', $akun->id)->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($token, (string) $audit->safe_diff);

        // Pakai ulang ditolak.
        $this->reset($akun->email, $token, 'LainLagi99')->assertStatus(422)->assertJsonPath('error.fields.token.0', 'Tautan reset tidak valid atau kedaluwarsa.');
    }

    public function test_expired_wrong_or_inactive_reset_is_rejected_generically(): void
    {
        $akun = $this->warga('48');
        $token = Password::broker('akun')->createToken($akun);

        $this->reset($akun->email, str_repeat('a', 64))->assertStatus(422);
        $this->reset('lain@contoh.test', $token)->assertStatus(422);

        $this->travel(61)->minutes();
        $this->reset($akun->email, $token)->assertStatus(422);
        $this->travelBack();

        $token = Password::broker('akun')->createToken($akun);
        $akun->forceFill(['status' => 'nonaktif'])->save();
        $this->reset($akun->email, $token)->assertStatus(422);
        $this->assertTrue(password_verify('Rahasia123', $akun->refresh()->password_hash));
    }

    public function test_password_change_invalidates_outstanding_reset_links(): void
    {
        $akun = $this->warga('49');
        $token = Password::broker('akun')->createToken($akun);

        $this->as($akun)->patchJson('/api/v1/auth/password', ['current_password' => 'Rahasia123', 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertOk();

        $this->reset($akun->email, $token, 'LainLagi99')->assertStatus(422);
    }

    public function test_tokens_never_reach_application_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $akun = $this->warga('50');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $akun->email])->assertStatus(202);
        $token = Password::broker('akun')->createToken($akun);
        $this->reset($akun->email, 'salah')->assertStatus(422);
        $this->reset($akun->email, $token)->assertOk();

        foreach ($logged as $line) {
            $this->assertStringNotContainsString($token, $line);
        }
    }

    public function test_log_transport_is_refused_in_every_environment_and_http_url_outside_local(): void
    {
        $akun = $this->warga('51');
        $n = new ResetPasswordNotification('kode-rahasia');
        $env = $this->app['env'];
        try {
            foreach (['local', 'testing', 'staging', 'production'] as $environment) {
                $this->app['env'] = $environment;
                config(['app.url' => 'https://takeda-staging.morriz.tech']);
                // log langsung, failover default (smtp lalu log), roundrobin berisi log, mailer tak dikenal.
                config(['mail.mailers.rr-log' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'log']]]);
                foreach (['log', 'failover', 'rr-log', 'tidak-ada'] as $mailer) {
                    config(['mail.default' => $mailer]);
                    $this->assertFalse($n->shouldSend($akun, 'mail'), "$environment/$mailer tidak boleh mengirim token lewat log.");
                }
                config(['mail.default' => 'smtp']);
                $this->assertTrue($n->shouldSend($akun, 'mail'), "$environment/smtp https");
                config(['app.url' => 'http://takeda.invalid']);
                $this->assertSame(in_array($environment, ['local', 'testing'], true), $n->shouldSend($akun, 'mail'), "$environment/smtp http");
            }
        } finally {
            $this->app['env'] = $env;
        }
    }

    public function test_local_development_with_log_mailer_never_writes_the_reset_token_to_logs(): void
    {
        // Seperti .env lokal yang terhubung Neon dev: APP_ENV=local, MAIL_MAILER=log, APP_URL http.
        $env = $this->app['env'];
        $this->app['env'] = 'local';
        config(['mail.default' => 'log', 'app.url' => 'http://127.0.0.1:8000']);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->level.' '.$e->message.' '.json_encode($e->context);
        });
        try {
            // Kontrol positif: transport log memang menulis isi email ke log yang dipantau.
            $this->app['mail.manager']->mailer('log')->raw('kontrol-positif-isi-email', fn ($m) => $m->to('kontrol@contoh.test')->subject('Kontrol'));
            $this->assertNotEmpty(array_filter($logged, fn ($l) => str_contains($l, 'kontrol-positif-isi-email')));
            $logged = [];

            $akun = $this->warga('54');
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $akun->email])->assertStatus(202)
                ->assertJsonPath('data.message', AuthController::FORGOT_MESSAGE);
        } finally {
            $this->app['env'] = $env;
        }

        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $akun->email)->count(), 'Transaksi tetap commit.');
        foreach ($logged as $line) {
            // Token broker = HMAC-SHA256 hex 64 karakter.
            $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $line);
            $this->assertStringNotContainsString('reset-password#', $line);
            $this->assertStringNotContainsString($akun->email, $line);
        }
        // Peringatan berasal dari job notifikasi (antrean sync) setelah token dibuat, tanpa isi email.
        $this->assertNotEmpty(array_filter($logged, fn ($l) => str_contains($l, 'Email reset tidak dikirim')));
    }

    public function test_browser_form_is_no_store_no_referrer_and_ignores_query_secrets(): void
    {
        $res = $this->browser('GET', '/reset-password?token=rahasia-di-query&email=uji@contoh.test')->assertOk();

        $this->assertStringContainsString('text/html', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $res->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $res->headers->get('X-Frame-Options'));
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'nonce-[A-Za-z0-9]{32}'/", $csp);
        $html = $res->getContent();
        $this->assertStringNotContainsString('rahasia-di-query', $html);
        $this->assertStringNotContainsString('uji@contoh.test', $html);
        $this->assertStringContainsString('location.hash', $html);
        $this->assertStringContainsString('action="/api/v1/auth/reset-password"', $html);
        $this->assertStringContainsString("img-src 'self'", $csp);
        $this->assertStringContainsString("font-src 'self'", $csp);
    }

    public function test_reset_page_uses_official_gapura_logo_and_licensed_website_fonts(): void
    {
        $html = $this->browser('GET', '/reset-password')->assertOk()->getContent();

        $this->assertStringContainsString('<img src="/auth-assets/rt05-mark.svg" width="44" height="44" alt="">', $html);
        $this->assertStringContainsString('RT 05 Takeda<small>Taman Kedaung · RW 07</small>', $html);
        $this->assertStringContainsString("font-family:'Public Sans Variable'", $html);
        $this->assertStringContainsString("font-family:'Bricolage Grotesque Variable'", $html);
        foreach (['--navy:#123e65', '--ink:#183b50', '--sky:#eef5fa', '--yellow:#f2cd5a'] as $token) {
            $this->assertStringContainsString($token, $html, 'Palet website.');
        }
        // Label dan pesan error terhubung ke input (aksesibilitas).
        foreach (['email', 'token', 'password', 'password_confirmation'] as $field) {
            $this->assertStringContainsString("<label for=\"$field\">", $html);
            $this->assertMatchesRegularExpression("/id=\"$field\"[^>]*aria-describedby=\"[^\"]*$field-error\"/", $html);
            $this->assertStringContainsString("id=\"$field-error\" data-field=\"$field\"", $html);
        }

        // Aset disalin byte-identik dari sumber kanonik website/ dengan lisensi OFL.
        $sources = [
            'rt05-mark.svg' => 'website/public/images/logo/rt05-mark.svg',
            'public-sans-latin-wght.woff2' => 'website/preview/build/assets/public-sans-latin-wght-normal-DdeTHZLK.woff2',
            'bricolage-grotesque-latin-wght.woff2' => 'website/preview/build/assets/bricolage-grotesque-latin-wght-normal-DLoelf7F.woff2',
        ];
        foreach ($sources as $file => $source) {
            $copy = public_path("auth-assets/$file");
            $this->assertFileExists($copy);
            $canonical = base_path("../$source");
            if (is_file($canonical)) {
                $this->assertSame(hash_file('sha256', $canonical), hash_file('sha256', $copy), $file);
            }
        }
        foreach (['OFL-public-sans.txt' => 'Public Sans Project Authors', 'OFL-bricolage-grotesque.txt' => 'Bricolage Grotesque Project Authors'] as $file => $holder) {
            $license = (string) file_get_contents(public_path("auth-assets/$file"));
            $this->assertStringContainsString('SIL OPEN FONT LICENSE Version 1.1', $license);
            $this->assertStringContainsString($holder, $license);
        }
    }

    public function test_browser_form_submission_requires_csrf_and_resets_password(): void
    {
        $akun = $this->warga('52');
        $html = $this->browser('GET', '/reset-password')->assertOk()->getContent();
        preg_match('/name="_token" value="([^"]+)"/', $html, $m);
        $csrf = $m[1];
        $token = Password::broker('akun')->createToken($akun);
        $body = ['email' => $akun->email, 'token' => $token, 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'];

        // Fetch dari halaman (Origin sendiri) tanpa token CSRF ditolak, token tetap belum terpakai.
        $this->browser('POST', '/api/v1/auth/reset-password', $body, csrf: false)->assertStatus(419);
        $this->browser('POST', '/api/v1/auth/reset-password', $body, ['X-CSRF-TOKEN' => 'salah'], csrf: false)->assertStatus(419);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $akun->email)->count());

        $this->browser('POST', '/api/v1/auth/reset-password', $body, ['X-CSRF-TOKEN' => $csrf], csrf: false)->assertOk();
        $this->assertTrue(password_verify('BaruSekali9', $akun->refresh()->password_hash));
    }

    public function test_android_reset_with_copied_code_needs_no_browser_session(): void
    {
        $akun = $this->warga('53');
        $token = Password::broker('akun')->createToken($akun);

        $this->reset($akun->email, $token)->assertOk();
        $this->assertTrue(password_verify('BaruSekali9', $akun->refresh()->password_hash));
    }
}
