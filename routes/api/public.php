<?php

use App\Http\Controllers\Api\Public\Content\FaqController;
use App\Http\Controllers\Api\Public\Content\GalleryController;
use App\Http\Controllers\Api\Public\Content\MenuController;
use App\Http\Controllers\Api\Public\Content\PageController;
use App\Http\Controllers\Api\Public\Content\ScheduleController;
use App\Http\Controllers\Api\Public\Content\SponsorController;
use App\Http\Controllers\Api\Public\EventSettingController;
use App\Http\Controllers\Api\Public\PaymentController;
use App\Http\Controllers\Api\Public\RegistrationController;
use App\Http\Controllers\Api\Public\SslCommerzReturnController;
use App\Http\Controllers\Api\Public\TicketTypeController;
use Illuminate\Support\Facades\Route;

Route::get('event', [EventSettingController::class, 'show'])->name('event.show');
Route::get('ticket-types', [TicketTypeController::class, 'index'])->name('ticket-types.index');

Route::post('registrations', [RegistrationController::class, 'store'])
    ->middleware('idempotent:registration.create')
    ->name('registrations.store');
Route::get('registrations/{registration:ulid}', [RegistrationController::class, 'show'])->name('registrations.show');

Route::post('registrations/{registration:ulid}/payment/initiate', [PaymentController::class, 'initiate'])
    ->middleware('idempotent:payment.initiate')
    ->name('registrations.payment.initiate');

// Browser return leg for SSLCommerz (docs/08 Phase 4A). Read-only — never
// trusted to transition a payment; the IPN at routes/webhooks.php is the
// only signal that does that (docs/06 §6.6).
Route::match(['get', 'post'], 'payments/sslcommerz/return/{status}', SslCommerzReturnController::class)
    ->where('status', 'success|fail|cancel')
    ->name('payments.sslcommerz.return');

// CMS read API (docs/08 Phase 3.5). Pages live under `content/pages/{slug}`
// rather than `content/{slug}` so an editor can slug a page `faqs` or
// `sponsors` without colliding with the sibling collection routes.
Route::prefix('content')->name('content.')->group(function (): void {
    Route::get('pages', [PageController::class, 'index'])->name('pages.index');
    Route::get('pages/{slug}', [PageController::class, 'show'])->name('pages.show');

    Route::get('menus', [MenuController::class, 'index'])->name('menus.index');
    Route::get('menus/{code}', [MenuController::class, 'show'])->name('menus.show');

    Route::get('sponsors', [SponsorController::class, 'index'])->name('sponsors.index');
    Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('faqs', [FaqController::class, 'index'])->name('faqs.index');

    Route::get('gallery', [GalleryController::class, 'index'])->name('gallery.index');
    Route::get('gallery/{slug}', [GalleryController::class, 'show'])->name('gallery.show');
});
