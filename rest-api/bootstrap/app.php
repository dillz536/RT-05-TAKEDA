<?php

use App\Exceptions\DomainConflict;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAkun;
use App\Http\Middleware\RequireCsrfToken;
use App\Http\Responses\ApiError;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/health.php'));
            Route::middleware('sesi-api')->group(base_path('routes/browser.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias(['akun' => EnsureAkun::class]);
        // CMS: request dari origin sendiri (SANCTUM_STATEFUL_DOMAINS) memakai sesi cookie API +
        // CSRF ketat; mobile tanpa Origin/Referer tetap bearer murni.
        $middleware->statefulApi();
        $middleware->group('sesi-api', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            RequireCsrfToken::class,
            SubstituteBindings::class,
        ]);
        // Hanya gateway di jaringan privat Docker yang dipercaya untuk X-Forwarded-* (SECURITY.md).
        $proxies = array_filter(explode(',', (string) env('TRUSTED_PROXIES', '')));
        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Aplikasi ini hanya API: setiap error menjadi JSON envelope, tanpa halaman HTML/stack trace.
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (Throwable $e, Request $request) => ApiError::fromThrowable($e, $request));
        $exceptions->dontReportDuplicates();
        // Pelanggaran aturan bisnis yang wajar (nominal salah, versi basi) bukan error server.
        $exceptions->dontReport([DomainConflict::class]);
    })->create();
