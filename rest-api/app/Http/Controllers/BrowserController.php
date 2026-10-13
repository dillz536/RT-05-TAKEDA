<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Route browser milik API di luar /api/v1 (gateway meneruskannya ke API pada origin yang sama):
 * GET /sanctum/csrf-cookie dan GET /reset-password. Keduanya memakai middleware sesi API
 * (cookie rt05_api_session + cookie CSRF rt05_api_xsrf), bukan sesi website.
 */
class BrowserController extends Controller
{
    /** Memulai sesi API dan menyetel cookie CSRF; CMS mengirim nilainya sebagai X-XSRF-TOKEN. */
    public function csrfCookie(): Response
    {
        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    /**
     * Form reset kata sandi. Tidak menerima token/email lewat query agar rahasia tidak masuk log;
     * tautan email membawa keduanya di fragment yang dibaca JavaScript halaman ini.
     */
    public function resetPasswordForm(Request $request): Response
    {
        $nonce = Str::random(32);

        return response()
            ->view('reset-password', ['csrf' => $request->session()->token(), 'nonce' => $nonce])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }
}
