<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrowserController;
use Illuminate\Support\Facades\Route;

/*
| Route browser dengan sesi cookie API (middleware group sesi-api: cookie terenkripsi, sesi,
| CSRF ketat). Gateway meneruskan /sanctum/csrf-cookie dan /reset-password ke API; web-login
| tetap di bawah /api/v1. Setiap route wajib tercantum di docs/openapi.yaml.
*/

Route::get('/sanctum/csrf-cookie', [BrowserController::class, 'csrfCookie']);
Route::get('/reset-password', [BrowserController::class, 'resetPasswordForm']);
Route::post('/api/v1/auth/web-login', [AuthController::class, 'webLogin'])->middleware('throttle:login');
