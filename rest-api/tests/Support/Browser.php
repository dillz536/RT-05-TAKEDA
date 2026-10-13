<?php

namespace Tests\Support;

use App\Http\Middleware\RequireCsrfToken;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Testing\TestResponse;

/**
 * Peramban sederhana untuk tes in-process: cookie jar (nilai terenkripsi persis seperti yang
 * disimpan browser), header Origin/Referer CMS, dan X-XSRF-TOKEN dari cookie CSRF API.
 * Setiap request memakai store sesi/guard baru agar tidak ada state memori yang bocor antar
 * request; sesi tersimpan di satu handler bersama seperti Redis pada staging. Middleware CSRF tidak
 * dilewati di tes (RequireCsrfToken), jadi 419 di sini adalah penolakan nyata.
 */
trait Browser
{
    protected const CMS_ORIGIN = 'https://cms.rt05.test';

    /** @var array<string, array<string, string>> jar per nama browser */
    protected array $jars = [];

    protected function setUpBrowser(): void
    {
        // Satu handler array dipakai bersama semua request tes (seperti Redis bersama), tetapi
        // Store dibuat ulang per request. TTL store sengaja 24 jam, lebih panjang dari batas idle
        // 120 menit, agar tes idle membuktikan pemeriksaan aplikasi (BrowserSession), bukan TTL.
        $handler = new ArraySessionHandler(24 * 60);
        $this->app['session']->extend('tes-bersama', fn () => $handler);
        config([
            'session.driver' => 'tes-bersama',
            'sanctum.stateful' => ['cms.rt05.test'],
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  bool|string  $csrf  true = kirim X-XSRF-TOKEN dari jar; string = nilai mentah; false = tanpa
     */
    protected function browser(string $method, string $uri, array $data = [], array $headers = [], bool|string $csrf = true, string $jar = 'a', ?string $origin = self::CMS_ORIGIN): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $cookies = $this->jars[$jar] ?? [];
        if ($origin !== null) {
            $headers += ['Origin' => $origin, 'Referer' => $origin.'/cms'];
        }
        if ($csrf === true && isset($cookies[RequireCsrfToken::cookieName()])) {
            $headers['X-XSRF-TOKEN'] = $cookies[RequireCsrfToken::cookieName()];
        } elseif (is_string($csrf)) {
            $headers['X-XSRF-TOKEN'] = $csrf;
        }

        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $response = $this->withCredentials()->withUnencryptedCookies($cookies)->json($method, $uri, $data, $headers + ['Accept' => 'application/json']);
        $this->flushHeaders();

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getValue() === null || $cookie->getValue() === '' || ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < now()->getTimestamp())) {
                unset($this->jars[$jar][$cookie->getName()]);
            } else {
                $this->jars[$jar][$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    /** csrf-cookie lalu web-login, seperti CMS. */
    protected function cmsLogin(string $email, string $password = 'Rahasia123', string $jar = 'a'): TestResponse
    {
        $this->browser('GET', '/sanctum/csrf-cookie', jar: $jar)->assertNoContent();

        return $this->browser('POST', '/api/v1/auth/web-login', ['email' => $email, 'password' => $password], jar: $jar);
    }
}
