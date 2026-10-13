<?php

namespace Tests\Feature;

use App\Models\Akun;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedData;
use Tests\TestCase;

/**
 * Bukti CSRF/cookie lewat HTTP nyata: server PHP terpisah (APP_ENV=local, bukan testing, jadi tidak
 * ada jalur bypass unit test di mana pun) dan klien dengan cookie jar seperti browser. Database
 * sama (*_test); data commit dibersihkan di tearDown.
 */
class M06BrowserHttpTest extends TestCase
{
    use CommittedData;

    private ?Process $server = null;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markCommittedData();

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->base = "http://127.0.0.1:$port";

        $this->server = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:$port", base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
            public_path(),
            [
                'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'APP_URL' => $this->base,
                'DB_CONNECTION' => 'pgsql', 'DATABASE_URL' => (string) config('database.connections.pgsql.url'), 'DATABASE_URL_UNPOOLED' => '',
                'DB_SSLMODE' => 'prefer', 'BCRYPT_ROUNDS' => '4', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
                'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'rt05_api_session', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => 'null',
                'SANCTUM_STATEFUL_DOMAINS' => '', 'RT05_XSRF_COOKIE' => 'rt05_api_xsrf', 'LOG_CHANNEL' => 'stderr', 'TRUSTED_PROXIES' => '',
            ],
        );
        $this->server->start();

        $deadline = microtime(true) + 20;
        while (! @fsockopen('127.0.0.1', $port) && microtime(true) < $deadline) {
            usleep(100_000);
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
        $this->purgeCommittedData();
        parent::tearDown();
    }

    private function client(CookieJar $jar): Client
    {
        return new Client(['base_uri' => $this->base, 'cookies' => $jar, 'http_errors' => false, 'timeout' => 30, 'headers' => ['Accept' => 'application/json']]);
    }

    private function cms(array $headers = []): array
    {
        return $headers + ['Origin' => $this->base, 'Referer' => $this->base.'/cms/artikel'];
    }

    private function xsrf(CookieJar $jar): string
    {
        return urldecode((string) $jar->getCookieByName('rt05_api_xsrf')?->getValue());
    }

    private function code(ResponseInterface $res): ?string
    {
        return json_decode((string) $res->getBody(), true)['error']['code'] ?? null;
    }

    public function test_cms_cookie_login_and_logout_over_real_http_enforce_csrf(): void
    {
        $admin = new Akun(['nama' => 'Uji CMS', 'email' => 'cms.http@proses.test', 'password_hash' => 'Rahasia123', 'jenis' => 'utama', 'status' => 'aktif']);
        $admin->peran = 'pengurus';
        $admin->save();
        $jar = new CookieJar;
        $http = $this->client($jar);
        $login = ['json' => ['email' => 'cms.http@proses.test', 'password' => 'Rahasia123']];

        $res = $http->get('/sanctum/csrf-cookie', ['headers' => $this->cms()]);
        $this->assertSame(204, $res->getStatusCode(), $this->server->getErrorOutput());
        $this->assertNotNull($jar->getCookieByName('rt05_api_session'));
        $this->assertNotSame('', $this->xsrf($jar));
        $this->assertNull($jar->getCookieByName('XSRF-TOKEN'));

        $res = $http->post('/api/v1/auth/web-login', $login + ['headers' => $this->cms()]);
        $this->assertSame([419, 'CSRF_EXPIRED'], [$res->getStatusCode(), $this->code($res)]);
        $res = $http->post('/api/v1/auth/web-login', $login + ['headers' => $this->cms(['Sec-Fetch-Site' => 'same-origin'])]);
        $this->assertSame(419, $res->getStatusCode(), 'Sec-Fetch-Site tanpa token tidak cukup.');

        $res = $http->post('/api/v1/auth/web-login', $login + ['headers' => $this->cms(['X-XSRF-TOKEN' => $this->xsrf($jar)])]);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = json_decode((string) $res->getBody(), true);
        $this->assertSame('pengurus', $body['data']['peran']);
        $this->assertArrayNotHasKey('token', $body['data']);

        $this->assertSame(200, $http->get('/api/v1/auth/me', ['headers' => $this->cms()])->getStatusCode());
        $this->assertSame(200, $http->get('/api/v1/admin/dashboard', ['headers' => $this->cms()])->getStatusCode());
        // Cookie yang sama dari halaman origin lain tidak diterima.
        $this->assertSame(401, $http->get('/api/v1/auth/me', ['headers' => ['Origin' => 'http://jahat.test', 'Referer' => 'http://jahat.test/']])->getStatusCode());

        $res = $http->post('/api/v1/auth/logout', ['headers' => $this->cms()]);
        $this->assertSame([419, 'CSRF_EXPIRED'], [$res->getStatusCode(), $this->code($res)]);
        $this->assertSame(200, $http->get('/api/v1/auth/me', ['headers' => $this->cms()])->getStatusCode());

        $this->assertSame(204, $http->post('/api/v1/auth/logout', ['headers' => $this->cms(['X-XSRF-TOKEN' => $this->xsrf($jar)])])->getStatusCode());
        $this->assertSame(401, $http->get('/api/v1/auth/me', ['headers' => $this->cms()])->getStatusCode());
    }

    public function test_reset_page_logo_and_fonts_are_served_same_origin_over_real_http(): void
    {
        $http = $this->client(new CookieJar);
        $html = (string) $http->get('/reset-password', ['headers' => ['Accept' => 'text/html']])->getBody();

        // Semua aset halaman berasal dari origin sendiri (CSP img-src/font-src 'self').
        preg_match_all('#(?:src="|href="|url\()(/auth-assets/[^")]+)#', $html, $m);
        $assets = array_values(array_unique($m[1]));
        sort($assets);
        $this->assertSame(['/auth-assets/bricolage-grotesque-latin-wght.woff2', '/auth-assets/public-sans-latin-wght.woff2', '/auth-assets/rt05-mark.svg'], $assets);
        $this->assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//#', $html, 'Tanpa aset eksternal.');

        foreach ($assets as $path) {
            $res = $http->get($path);
            $this->assertSame(200, $res->getStatusCode(), $path);
            $this->assertSame(hash_file('sha256', public_path(ltrim($path, '/'))), hash('sha256', (string) $res->getBody()), $path);
        }
    }

    public function test_reset_form_over_real_http_requires_its_csrf_token(): void
    {
        $jar = new CookieJar;
        $http = $this->client($jar);

        $page = $http->get('/reset-password', ['headers' => ['Accept' => 'text/html']]);
        $this->assertSame(200, $page->getStatusCode(), $this->server->getErrorOutput());
        $this->assertSame('no-referrer', $page->getHeaderLine('Referrer-Policy'));
        $this->assertTrue((bool) preg_match('/name="_token" value="([^"]+)"/', (string) $page->getBody(), $m));
        $body = ['json' => ['email' => 'tidakada@proses.test', 'token' => str_repeat('b', 64), 'password' => 'BaruSekali9', 'password_confirmation' => 'BaruSekali9']];

        // Halaman mengirim tanpa Referer (no-referrer) tetapi browser selalu menyertakan Origin pada POST.
        $res = $http->post('/api/v1/auth/reset-password', $body + ['headers' => ['Origin' => $this->base]]);
        $this->assertSame([419, 'CSRF_EXPIRED'], [$res->getStatusCode(), $this->code($res)]);

        $res = $http->post('/api/v1/auth/reset-password', $body + ['headers' => ['Origin' => $this->base, 'X-CSRF-TOKEN' => $m[1]]]);
        $this->assertSame([422, 'VALIDATION_FAILED'], [$res->getStatusCode(), $this->code($res)], 'Lolos CSRF; token reset fiktif ditolak generik.');
    }
}
