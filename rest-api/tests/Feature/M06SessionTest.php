<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireCsrfToken;
use App\Models\Akun;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Password;
use Tests\Support\Browser;
use Tests\Support\Fixtures;
use Tests\Support\OpenApi;
use Tests\TestCase;

/**
 * M06 sesi cookie CMS + CSRF dan pencabutan sesi browser. Middleware CSRF nyata (tidak dilewati
 * saat unit test), sesi di handler bersama, cookie terenkripsi dibawa seperti browser.
 */
class M06SessionTest extends TestCase
{
    use Browser, DatabaseTransactions, Fixtures;

    protected array $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBrowser();
    }

    private function admin(string $email = 'cms@contoh.test'): Akun
    {
        return $this->pengurus($email);
    }

    private function bearer(Akun $akun, string $device = 'hp'): string
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        return $this->postJson('/api/v1/auth/mobile-login', ['email' => $akun->email, 'password' => 'Rahasia123', 'device_name' => $device])->assertOk()->json('data.token');
    }

    private function withBearer(string $token, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        return $this->withToken($token)->json($method, $uri, $data);
    }

    public function test_csrf_cookie_sets_separate_api_cookies(): void
    {
        $res = $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();

        $cookies = collect($res->headers->getCookies())->keyBy(fn ($c) => $c->getName());
        $this->assertTrue($cookies->has('rt05_api_session'));
        $this->assertTrue($cookies->has('rt05_api_xsrf'));
        $this->assertFalse($cookies->has('XSRF-TOKEN'), 'Tidak memakai nama cookie website.');
        $this->assertTrue($cookies['rt05_api_session']->isHttpOnly());
        $this->assertSame('lax', $cookies['rt05_api_session']->getSameSite());
        $this->assertFalse($cookies['rt05_api_xsrf']->isHttpOnly(), 'CMS membaca cookie CSRF untuk header X-XSRF-TOKEN.');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_web_login_requires_a_matching_csrf_token(): void
    {
        $this->admin();
        $this->browser('GET', '/sanctum/csrf-cookie');
        $login = ['email' => 'cms@contoh.test', 'password' => 'Rahasia123'];

        $this->browser('POST', '/api/v1/auth/web-login', $login, csrf: false)->assertStatus(419)->assertJsonPath('error.code', 'CSRF_EXPIRED');
        $this->browser('POST', '/api/v1/auth/web-login', $login, csrf: 'bukan-token')->assertStatus(419);
        // Sec-Fetch-Site same-origin saja tidak cukup (lebih ketat dari bawaan Laravel 13).
        $this->browser('POST', '/api/v1/auth/web-login', $login, ['Sec-Fetch-Site' => 'same-origin'], csrf: false)->assertStatus(419);
        // Cookie XSRF website (APP_KEY berbeda) ditolak.
        $website = (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encrypt('token-website', false);
        $this->browser('POST', '/api/v1/auth/web-login', $login, csrf: $website)->assertStatus(419);

        $before = $this->jars['a']['rt05_api_session'];
        $res = $this->browser('POST', '/api/v1/auth/web-login', $login)->assertOk();

        $this->assertSame([], OpenApi::errors($res->json(), 'AccountEnvelope'));
        $res->assertJsonPath('data.peran', 'pengurus')->assertJsonMissingPath('data.token');
        $this->assertNotSame($before, $this->jars['a']['rt05_api_session'], 'ID sesi diganti setelah login (anti session fixation).');
        $this->browser('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'cms@contoh.test');
    }

    public function test_web_login_is_generic_for_non_admin_inactive_or_wrong_password(): void
    {
        $this->warga('31');
        $off = $this->admin('off@contoh.test');
        $off->forceFill(['status' => 'nonaktif'])->save();
        $this->admin();

        foreach ([['uji31@contoh.test', 'Rahasia123'], ['off@contoh.test', 'Rahasia123'], ['cms@contoh.test', 'Salah12345'], ['tidakada@contoh.test', 'Rahasia123']] as [$email, $password]) {
            $res = $this->cmsLogin($email, $password)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
            $this->assertSame(['Email atau kata sandi salah.'], $res->json('error.fields.email'), $email);
            $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        }
    }

    public function test_cookie_session_is_only_honoured_from_the_cms_origin_and_mutations_need_csrf(): void
    {
        $this->admin();
        $this->cmsLogin('cms@contoh.test')->assertOk();

        $this->browser('GET', '/api/v1/admin/dashboard')->assertOk();
        // Tanpa Origin/Referer (mis. skrip lain) cookie tidak dipakai.
        $this->browser('GET', '/api/v1/auth/me', origin: null)->assertUnauthorized();
        // Origin asing: cookie diabaikan, bukan diterima.
        $this->browser('GET', '/api/v1/auth/me', origin: 'https://jahat.test')->assertUnauthorized();
        $this->browser('POST', '/api/v1/auth/logout', origin: 'https://jahat.test')->assertUnauthorized();
        // Mutation dengan cookie tanpa token CSRF ditolak dan sesi tetap hidup.
        $this->browser('POST', '/api/v1/auth/logout', csrf: false)->assertStatus(419);
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
    }

    public function test_mobile_bearer_needs_no_csrf_and_ignores_cookie_state(): void
    {
        $akun = $this->warga('32');
        $token = $this->bearer($akun);

        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertOk();
        $this->withBearer($token, 'POST', '/api/v1/auth/logout')->assertNoContent();
        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_cookie_logout_ends_only_that_browser_session(): void
    {
        $admin = $this->admin();
        $token = $this->bearer($admin);
        $this->cmsLogin('cms@contoh.test', jar: 'a')->assertOk();
        $this->cmsLogin('cms@contoh.test', jar: 'b')->assertOk();

        $this->browser('POST', '/api/v1/auth/logout', jar: 'a')->assertNoContent();

        $this->browser('GET', '/api/v1/auth/me', jar: 'a')->assertUnauthorized();
        $this->browser('GET', '/api/v1/auth/me', jar: 'b')->assertOk();
        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertOk();
    }

    public function test_logout_all_from_mobile_ends_browser_sessions_and_all_tokens(): void
    {
        $admin = $this->admin();
        $this->cmsLogin('cms@contoh.test')->assertOk();
        $phone = $this->bearer($admin, 'hp');
        $tablet = $this->bearer($admin, 'tablet');

        $this->withBearer($phone, 'POST', '/api/v1/auth/logout-all', ['password' => 'Rahasia123'])->assertNoContent();

        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->withBearer($phone, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->withBearer($tablet, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_all_from_browser_ends_that_session_too(): void
    {
        $admin = $this->admin();
        $phone = $this->bearer($admin);
        $this->cmsLogin('cms@contoh.test')->assertOk();

        $this->browser('POST', '/api/v1/auth/logout-all', ['password' => 'Rahasia123'])->assertNoContent();

        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->withBearer($phone, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_reset_ends_existing_browser_sessions(): void
    {
        $admin = $this->admin();
        $this->cmsLogin('cms@contoh.test')->assertOk();
        $reset = Password::broker('akun')->createToken($admin);

        $this->postJson('/api/v1/auth/reset-password', ['email' => 'cms@contoh.test', 'token' => $reset, 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertOk();

        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->cmsLogin('cms@contoh.test', 'BaruSekali9', jar: 'baru')->assertOk();
    }

    public function test_status_change_by_another_admin_ends_browser_session(): void
    {
        $target = $this->admin();
        $other = $this->admin('lain@contoh.test');
        $this->cmsLogin('cms@contoh.test')->assertOk();

        $this->withBearer($this->bearer($other), 'PATCH', "/api/v1/admin/accounts/{$target->id}", ['status' => 'nonaktif', 'alasan' => 'Uji'])->assertOk();

        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->cmsLogin('cms@contoh.test', jar: 'baru')->assertStatus(422);
    }

    public function test_reactivation_does_not_revive_old_browser_session(): void
    {
        $target = $this->admin();
        $other = $this->admin('lain@contoh.test');
        $this->cmsLogin('cms@contoh.test')->assertOk();
        $token = $this->bearer($other);

        $this->withBearer($token, 'PATCH', "/api/v1/admin/accounts/{$target->id}", ['status' => 'nonaktif', 'alasan' => 'Uji'])->assertOk();
        $this->withBearer($token, 'PATCH', "/api/v1/admin/accounts/{$target->id}", ['status' => 'aktif', 'alasan' => 'Uji'])->assertOk();

        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_operator_role_revoke_ends_browser_session(): void
    {
        $this->admin();
        $this->admin('lain@contoh.test');
        $this->cmsLogin('cms@contoh.test')->assertOk();

        $this->artisan('rt05:pengurus', ['aksi' => 'revoke', 'email' => 'cms@contoh.test'])->assertSuccessful();

        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->cmsLogin('cms@contoh.test', jar: 'baru')->assertStatus(422);
    }

    public function test_role_lost_without_version_bump_still_ends_cookie_session(): void
    {
        $admin = $this->admin();
        $this->cmsLogin('cms@contoh.test')->assertOk();
        // Perubahan peran langsung di database (mis. data lama) tetap ditolak karena peran dicek ulang.
        Akun::whereKey($admin->id)->update(['peran' => 'warga']);

        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_change_from_mobile_keeps_that_bearer_and_ends_every_browser_session(): void
    {
        $admin = $this->admin();
        $phone = $this->bearer($admin, 'hp');
        $tablet = $this->bearer($admin, 'tablet');
        $this->cmsLogin('cms@contoh.test')->assertOk();

        $this->withBearer($phone, 'PATCH', '/api/v1/auth/password', ['current_password' => 'Rahasia123', 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'])->assertOk();

        $this->withBearer($phone, 'GET', '/api/v1/auth/me')->assertOk();
        $this->withBearer($tablet, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_password_change_from_browser_rebinds_this_session_only(): void
    {
        $admin = $this->admin();
        $phone = $this->bearer($admin);
        $this->cmsLogin('cms@contoh.test', jar: 'a')->assertOk();
        $this->cmsLogin('cms@contoh.test', jar: 'b')->assertOk();
        $old = $this->jars['a']['rt05_api_session'];

        $this->browser('PATCH', '/api/v1/auth/password', ['current_password' => 'Rahasia123', 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9'], jar: 'a')->assertOk();

        $this->assertNotSame($old, $this->jars['a']['rt05_api_session'], 'ID sesi diganti saat diikat ke generasi baru.');
        $this->browser('GET', '/api/v1/auth/me', jar: 'a')->assertOk();
        $this->browser('GET', '/api/v1/auth/me', jar: 'b')->assertUnauthorized();
        $this->withBearer($phone, 'GET', '/api/v1/auth/me')->assertUnauthorized();
        // Cookie lama sesi a (sebelum regenerate) tidak dapat dipakai ulang.
        $this->jars['lama'] = ['rt05_api_session' => $old] + $this->jars['a'];
        $this->browser('GET', '/api/v1/auth/me', jar: 'lama')->assertUnauthorized();
    }

    public function test_idle_browser_session_expires_after_session_lifetime(): void
    {
        $this->admin();
        $this->cmsLogin('cms@contoh.test')->assertOk();

        $this->travel(119)->minutes();
        $this->browser('GET', '/api/v1/auth/me')->assertOk();
        // Aktivitas memperpanjang sesi (idle, bukan absolut).
        $this->travel(119)->minutes();
        $this->browser('GET', '/api/v1/auth/me')->assertOk();

        $this->travel(121)->minutes();
        $this->browser('GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_mobile_bearer_expires_after_thirty_days(): void
    {
        $token = $this->bearer($this->warga('33'));

        $this->travel(29)->days();
        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertOk();
        $this->travel(2)->days();
        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_pending_account_bearer_is_limited_to_profile_status_and_logout(): void
    {
        $this->warga('34');
        $token = $this->postJson('/api/v1/auth/additional-register', [
            'email' => 'tunggu34@contoh.test', 'password' => 'Rahasia123', 'password_confirmation' => 'Rahasia123',
            'registration_token' => $this->tokenCode, 'device_name' => 'hp', 'nama' => 'Uji Tunggu', 'hubungan_keluarga' => 'Anak',
            'blok' => 'Z', 'jalan' => 'Jl. Uji', 'nomor' => '34',
        ])->assertStatus(202)->json('data.token');

        $this->withBearer($token, 'GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.status', 'menunggu');
        $this->withBearer($token, 'GET', '/api/v1/auth/additional-account-status')->assertOk();
        foreach (['/api/v1/household', '/api/v1/complaints', '/api/v1/agendas', '/api/v1/dues/my', '/api/v1/cash/summary'] as $uri) {
            $this->withBearer($token, 'GET', $uri)->assertForbidden();
        }
        $this->withBearer($token, 'PATCH', '/api/v1/auth/password', [])->assertForbidden();
        $this->withBearer($token, 'POST', '/api/v1/auth/logout')->assertNoContent();
    }

    public function test_csrf_cookie_name_is_configurable_for_the_cms(): void
    {
        config(['rt05.auth.csrf_cookie' => 'cms_xsrf_lain']);

        $res = $this->browser('GET', '/sanctum/csrf-cookie')->assertNoContent();

        $this->assertSame('cms_xsrf_lain', RequireCsrfToken::cookieName());
        $this->assertNotNull(collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'cms_xsrf_lain'));
    }
}
