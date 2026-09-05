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

    // Forgotten password. Unauthenticated by necessity — the whole point is
    // that the caller cannot sign in — so `forgot-password` carries its own
    // strict limiter: it puts mail in somebody else's inbox on request, and
    // is the one route here that could be used to enumerate staff addresses.
    // `reset-password` stays on the shared api bucket; its token is 64 random
    // characters, so guessing is not the threat.
    Route::post('auth/forgot-password', [AdminAuthController::class, 'forgotPassword'])
        ->middleware('throttle:staff-password-reset')
        ->name('auth.forgot-password');

    Route::post('auth/reset-password', [AdminAuthController::class, 'resetPassword'])
        ->name('auth.reset-password');
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

    // Your own account. Fully authenticated rather than in the group above:
    // a 2fa-setup token exists only to finish setting 2FA up, and must not be
    // able to change the email you sign in with or the password it protects.
    Route::patch('auth/me', [AdminAuthController::class, 'updateProfile'])->name('auth.me.update');
    Route::post('auth/password', [AdminAuthController::class, 'changePassword'])->name('auth.password');

    Route::group([], base_path('routes/api/admin.php'));
});

// Attendee self-service — unauthenticated sign-in.
//
// `login` is the ordinary path and costs nothing. `request-code` sends an
// SMS, so it carries its own strict limiter rather than the shared `api`
// bucket: it is the only unauthenticated route in this application that
// spends money on every call.
Route::prefix('attendee')->name('attendee.')->group(function (): void {
    Route::post('auth/login', [AttendeeAuthController::class, 'login'])
        ->middleware('throttle:attendee-login')
        ->name('auth.login');

    Route::post('auth/request-code', [AttendeeAuthController::class, 'requestLink'])
        ->middleware('throttle:sms-code')
        ->name('auth.request-code');

    Route::post('auth/verify', [AttendeeAuthController::class, 'verify'])
        ->middleware('throttle:attendee-login')
        ->name('auth.verify');

    // Answers whether an account exists, which every other route here is
    // built to refuse — read the note on the controller method before
    // changing or reusing it. Its own bucket: this is bounded per caller,
    // not per account, because sweeping many identifiers is the abuse.
    Route::post('auth/check', [AttendeeAuthController::class, 'check'])
        ->middleware('throttle:account-check')
        ->name('auth.check');
});

// Attendee self-service — authenticated
Route::prefix('attendee')->name('attendee.')->middleware(['auth:attendee', 'abilities:attendee'])->group(function (): void {
    Route::post('auth/logout', [AttendeeAuthController::class, 'logout'])->name('auth.logout');

    Route::group([], base_path('routes/api/attendee.php'));
});
