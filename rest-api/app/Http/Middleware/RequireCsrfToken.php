<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * CSRF sesi cookie API (CMS dan form reset). Lebih ketat dari bawaan Laravel 13:
 *  - tidak dilewati saat runningUnitTests, sehingga tes membuktikan penolakan nyata;
 *  - Sec-Fetch-Site: same-origin saja tidak cukup; token sesi selalu wajib cocok.
 * Cookie token bernama config rt05.auth.csrf_cookie (default rt05_api_xsrf) agar tidak
 * ditimpa XSRF-TOKEN website pada origin yang sama. Client mengirim nilainya sebagai
 * header X-XSRF-TOKEN (atau X-CSRF-TOKEN berisi token mentah dari form HTML).
 */
class RequireCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }

    protected function hasValidOrigin($request)
    {
        return false;
    }

    protected function newCookie($request, $config)
    {
        return new Cookie(
            self::cookieName(),
            $request->session()->token(),
            $this->availableAt(60 * $config['lifetime']),
            $config['path'],
            $config['domain'],
            $config['secure'],
            false,
            false,
            $config['same_site'] ?? null,
            $config['partitioned'] ?? false
        );
    }

    public static function serialized()
    {
        return EncryptCookies::serialized(self::cookieName());
    }

    public static function cookieName(): string
    {
        return (string) config('rt05.auth.csrf_cookie', 'rt05_api_xsrf');
    }
}
