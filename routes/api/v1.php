<?php

use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\TwoFactorController;
use App\Http\Controllers\Api\Attendee\AuthController as AttendeeAuthController;
use App\Http\Controllers\Api\SignedMediaController;
use Illuminate\Support\Facades\Route;

// Public + attendee-facing browse endpoints (no auth)
Route::prefix('public')->name('public.')->group(base_path('routes/api/public.php'));

// Private media (ticket PDFs, QR images) — the signature itself is the
// authorization, minted only after a policy check by the issuing endpoint
// (e.g. Attendee\TicketController::downloadPdf), so this route carries no
// auth middleware of its own. docs/06 §6.4 file-serving rules.
// Asset traffic, not API calls — one admin screen can request a hundred of
// these at once (the attendee list's avatars), so it gets its own bucket
// rather than starving the SPA's real requests out of the shared `api` one.
Route::get('media/{mediaFile:ulid}', [SignedMediaController::class, 'show'])
    ->middleware(['signed', 'throttle:media'])
    ->withoutMiddleware('throttle:api')
    ->name('media.show');

// Admin console — unauthenticated
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::post('auth/login', [AdminAuthController::class, 'login'])->name('auth.login');
});

// Admin console — authenticated, but not necessarily past 2FA yet
Route::prefix('admin')->name('admin.')->middleware(['auth:web-admin', 'ability:admin,2fa-setup'])->group(function (): void {
    Route::post('auth/logout', [AdminAuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AdminAuthController::class, 'me'])->name('auth.me');
    Route::post('auth/reauth', [AdminAuthController::class, 'reauth'])->name('auth.reauth');

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
