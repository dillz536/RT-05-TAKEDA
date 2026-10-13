<?php

use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\ResidentsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ServiceInfoController;
use Illuminate\Support\Facades\Route;

/*
| API v1, prefix /api/v1. Setiap route wajib tercantum di docs/openapi.yaml (x-status: implemented);
| OpenApiContractTest memeriksa kesesuaiannya. Middleware:
|   auth:sanctum + akun           → akun aktif
|   auth:sanctum + akun:menunggu  → akun aktif atau menunggu persetujuan
|   auth:sanctum + akun:pengurus  → pengurus aktif (semua pengurus setara)
| Tidak ada endpoint pembayaran online, checkout, QRIS, atau webhook pembayaran.
*/

Route::get('/', ServiceInfoController::class)->name('api.v1.info');

// Publik terbatas dengan rate limit.
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:registrasi');
    Route::post('additional-register', [AuthController::class, 'additionalRegister'])->middleware('throttle:registrasi');
    Route::post('mobile-login', [AuthController::class, 'mobileLogin'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:lupa-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');
});

// Akun aktif atau menunggu persetujuan: hanya profil, status permohonan, logout.
Route::middleware(['auth:sanctum', 'akun:menunggu'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/additional-account-status', [AuthController::class, 'additionalAccountStatus']);
});

// Warga aktif (termasuk pengurus).
Route::middleware(['auth:sanctum', 'akun'])->group(function () {
    Route::post('auth/logout-all', [AuthController::class, 'logoutAll']);
    Route::patch('auth/password', [AuthController::class, 'changePassword']);

    Route::get('household', [HouseholdController::class, 'show']);
    Route::patch('household', [HouseholdController::class, 'update']);
    Route::post('household/members', [HouseholdController::class, 'addMember']);
    Route::patch('household/members/{id}', [HouseholdController::class, 'updateMember'])->whereUuid('id');
    Route::delete('household/members/{id}', [HouseholdController::class, 'endMember'])->whereUuid('id');

    Route::get('complaint-categories', [ReportsController::class, 'categories']);
    Route::get('complaints', [ReportsController::class, 'complaints']);
    Route::post('complaints', [ReportsController::class, 'storeComplaint'])->middleware('throttle:laporan');
    Route::get('complaints/{id}', [ReportsController::class, 'showComplaint'])->whereUuid('id');
    Route::patch('complaints/{id}', [ReportsController::class, 'updateComplaint'])->whereUuid('id');
    Route::get('complaints/{id}/history', [ReportsController::class, 'complaintHistory'])->whereUuid('id');
    Route::get('complaints/{id}/photos/{photoId}', [ReportsController::class, 'complaintPhoto'])->whereUuid(['id', 'photoId']);

    Route::get('aspirations', [ReportsController::class, 'aspirations']);
    Route::post('aspirations', [ReportsController::class, 'storeAspiration'])->middleware('throttle:laporan');
    Route::get('aspirations/{id}', [ReportsController::class, 'showAspiration'])->whereUuid('id');
    Route::patch('aspirations/{id}', [ReportsController::class, 'updateAspiration'])->whereUuid('id');
    Route::get('aspirations/{id}/history', [ReportsController::class, 'aspirationHistory'])->whereUuid('id');

    Route::get('agendas', [CommunityController::class, 'agendas']);
    Route::get('agendas/{id}', [CommunityController::class, 'agenda'])->whereUuid('id');
    Route::get('agendas/{id}/calendar', [CommunityController::class, 'agendaCalendar'])->whereUuid('id');
    Route::get('announcements', [CommunityController::class, 'announcements']);
    Route::get('announcements/{id}', [CommunityController::class, 'announcement'])->whereUuid('id');
    Route::get('notifications', [CommunityController::class, 'notifications']);
    Route::patch('notifications/{id}/read', [CommunityController::class, 'readNotification'])->whereUuid('id');
    Route::post('notifications/read-all', [CommunityController::class, 'readAllNotifications']);
    Route::get('notification-preferences', [CommunityController::class, 'preferences']);
    Route::patch('notification-preferences', [CommunityController::class, 'updatePreferences']);

    Route::get('dues/my', [FinanceController::class, 'my']);
    Route::get('dues/transparency', [FinanceController::class, 'transparency']);
    Route::get('cash/summary', [FinanceController::class, 'cashSummary']);
});

// Pengurus.
Route::middleware(['auth:sanctum', 'akun:pengurus'])->prefix('admin')->group(function () {
    Route::get('dashboard', [OperationsController::class, 'dashboard']);
    Route::get('audit-logs', [OperationsController::class, 'auditLogs']);
    Route::get('exports/{jenis}', [OperationsController::class, 'export'])->whereIn('jenis', ['iuran', 'kas', 'rumah']);

    Route::get('registration-token', [ResidentsController::class, 'token']);
    Route::post('registration-token/rotate', [ResidentsController::class, 'rotateToken']);

    Route::get('houses', [ResidentsController::class, 'houses']);
    Route::post('houses', [ResidentsController::class, 'storeHouse']);
    Route::get('houses/{id}', [ResidentsController::class, 'showHouse'])->whereUuid('id');
    Route::patch('houses/{id}', [ResidentsController::class, 'updateHouse'])->whereUuid('id');
    Route::post('houses/{id}/archive', [ResidentsController::class, 'archiveHouse'])->whereUuid('id');
    Route::get('houses/{id}/dues', [FinanceController::class, 'houseDues'])->whereUuid('id');
    Route::get('families', [ResidentsController::class, 'families']);
    Route::post('families', [ResidentsController::class, 'storeFamily']);
    Route::get('families/{id}', [ResidentsController::class, 'showFamily'])->whereUuid('id');
    Route::patch('families/{id}', [ResidentsController::class, 'updateFamily'])->whereUuid('id');
    Route::post('families/{id}/verify', [ResidentsController::class, 'verifyFamily'])->whereUuid('id');
    Route::post('families/{id}/archive', [ResidentsController::class, 'archiveFamily'])->whereUuid('id');
    Route::post('families/{id}/members', [ResidentsController::class, 'addFamilyMember'])->whereUuid('id');
    Route::post('families/{id}/primary-account', [ResidentsController::class, 'transferPrimary'])->whereUuid('id');
    Route::get('residents/{id}', [ResidentsController::class, 'showResident'])->whereUuid('id');
    Route::patch('residents/{id}', [ResidentsController::class, 'updateResident'])->whereUuid('id');
    Route::post('residents/{id}/end', [ResidentsController::class, 'endResident'])->whereUuid('id');
    Route::post('residents/{id}/move', [ResidentsController::class, 'moveResident'])->whereUuid('id');
    Route::post('occupancies', [ResidentsController::class, 'storeOccupancy']);
    Route::post('occupancies/{id}/end', [ResidentsController::class, 'endOccupancy'])->whereUuid('id');
    Route::post('occupancies/{id}/move', [ResidentsController::class, 'moveOccupancy'])->whereUuid('id');
    Route::get('accounts', [ResidentsController::class, 'accounts']);
    Route::get('accounts/{id}', [ResidentsController::class, 'showAccount'])->whereUuid('id');
    Route::patch('accounts/{id}', [ResidentsController::class, 'updateAccount'])->whereUuid('id');
    Route::post('accounts/{id}/archive', [ResidentsController::class, 'archiveAccount'])->whereUuid('id');
    Route::get('additional-account-requests', [ResidentsController::class, 'requests']);
    Route::post('additional-account-requests/{id}/approve', [ResidentsController::class, 'approve'])->whereUuid('id');
    Route::post('additional-account-requests/{id}/reject', [ResidentsController::class, 'reject'])->whereUuid('id');

    Route::post('complaints/{id}/status', [ReportsController::class, 'changeComplaintStatus'])->whereUuid('id');
    Route::post('complaints/{id}/archive', [ReportsController::class, 'archiveComplaint'])->whereUuid('id');
    Route::post('aspirations/{id}/status', [ReportsController::class, 'changeAspirationStatus'])->whereUuid('id');
    Route::post('aspirations/{id}/archive', [ReportsController::class, 'archiveAspiration'])->whereUuid('id');

    Route::post('agendas', [CommunityController::class, 'storeAgenda']);
    Route::put('agendas/{id}', [CommunityController::class, 'updateAgenda'])->whereUuid('id');
    Route::post('agendas/{id}/archive', [CommunityController::class, 'archiveAgenda'])->whereUuid('id');
    Route::post('announcements', [CommunityController::class, 'storeAnnouncement']);
    Route::put('announcements/{id}', [CommunityController::class, 'updateAnnouncement'])->whereUuid('id');
    Route::post('announcements/{id}/archive', [CommunityController::class, 'archiveAnnouncement'])->whereUuid('id');

    Route::get('dues-rates', [FinanceController::class, 'rates']);
    Route::post('dues-rates', [FinanceController::class, 'storeRate']);
    Route::post('dues/generate', [FinanceController::class, 'generate']);
    Route::get('dues-charges', [FinanceController::class, 'charges']);
    Route::get('dues-payments', [FinanceController::class, 'payments']);
    Route::post('dues-payments', [FinanceController::class, 'storePayment']);
    Route::get('dues-payments/{id}', [FinanceController::class, 'payment'])->whereUuid('id');
    Route::post('dues-payments/{id}/reverse', [FinanceController::class, 'reversePayment'])->whereUuid('id');
    Route::post('dues-payments/{id}/correct', [FinanceController::class, 'correctPayment'])->whereUuid('id');
    Route::get('cash-transactions', [FinanceController::class, 'cash']);
    Route::post('cash-transactions', [FinanceController::class, 'storeCash']);
    Route::post('cash-transactions/{id}/reverse', [FinanceController::class, 'reverseCash'])->whereUuid('id');
});
