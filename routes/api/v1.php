<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\TwoFactorController;
use App\Http\Controllers\Api\Attendee\AuthController as AttendeeAuthController;
use Illuminate\Support\Facades\Route;

// Public + attendee-facing browse endpoints (no auth)
Route::prefix('public')->name('public.')->group(base_path('routes/api/public.php'));

// Admin console — unauthenticated
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::post('auth/login', [AdminAuthController::class, 'login'])->name('auth.login');
});

// Admin console — authenticated, but not necessarily past 2FA yet
Route::prefix('admin')->name('admin.')->middleware(['auth:web-admin', 'ability:admin,2fa-setup'])->group(function (): void {
    Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AdminAuthController::class, 'me'])->name('auth.me');

    Route::post('auth/2fa/setup', [TwoFactorController::class, 'setup'])->name('auth.2fa.setup');
    Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('auth.2fa.confirm');
});

// Admin console — fully authenticated (2FA complete where required)
Route::prefix('admin')->name('admin.')->middleware(['auth:web-admin', 'abilities:admin'])->group(function (): void {
    Route::post('auth/2fa/disable', [TwoFactorController::class, 'disable'])->name('auth.2fa.disable');

    Route::group([], base_path('routes/api/admin.php'));
});

// Attendee self-service — unauthenticated (magic link / OTP request + verify)
Route::prefix('attendee')->name('attendee.')->group(function (): void {
    Route::post('auth/request-link', [AttendeeAuthController::class, 'requestLink'])->name('auth.request-link');
    Route::post('auth/verify', [AttendeeAuthController::class, 'verify'])->name('auth.verify');
});

// Attendee self-service — authenticated
Route::prefix('attendee')->name('attendee.')->middleware(['auth:attendee', 'abilities:attendee'])->group(function (): void {
    Route::post('auth/logout', [AttendeeAuthController::class, 'logout'])->name('auth.logout');

    Route::group([], base_path('routes/api/attendee.php'));
});
