<?php

use App\Http\Middleware\RequireCsrfToken;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    // Origin CMS yang memakai sesi cookie API (host[:port], dipisah koma). Kosong = host APP_URL
    // (CMS satu origin dengan API lewat gateway). Mobile tanpa Origin/Referer memakai bearer token.
    'stateful' => array_values(array_filter(explode(',', (string) (env('SANCTUM_STATEFUL_DOMAINS')
        ?: preg_replace('#^https?://([^/]+).*$#', '$1', (string) env('APP_URL', '')))))),

    'guard' => ['web'],

    // /sanctum/csrf-cookie didaftarkan sendiri di routes/browser.php (cookie CSRF rt05_api_xsrf).
    'routes' => false,

    // Token mobile berlaku 30 hari (docs/SECURITY.md).
    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 60 * 24 * 30),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'rt05_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => RequireCsrfToken::class,
    ],

];
