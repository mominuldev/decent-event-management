<?php

use App\Http\Controllers\Api\Admin\AttendeeController;
use App\Http\Controllers\Api\Admin\CheckInController;
use App\Http\Controllers\Api\Admin\Content\FaqController;
use App\Http\Controllers\Api\Admin\Content\GalleryAlbumController;
use App\Http\Controllers\Api\Admin\Content\GalleryItemController;
use App\Http\Controllers\Api\Admin\Content\MediaController;
use App\Http\Controllers\Api\Admin\Content\MenuController;
use App\Http\Controllers\Api\Admin\Content\MenuItemController;
use App\Http\Controllers\Api\Admin\Content\PageController;
use App\Http\Controllers\Api\Admin\Content\ScheduleItemController;
use App\Http\Controllers\Api\Admin\Content\SponsorController;
use App\Http\Controllers\Api\Admin\DeviceController;
use App\Http\Controllers\Api\Admin\GateController;
use App\Http\Controllers\Api\Admin\NotificationController;
use App\Http\Controllers\Api\Admin\PaymentController;
use App\Http\Controllers\Api\Admin\QrSigningKeyController;
use App\Http\Controllers\Api\Admin\RegistrationController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\TicketController;
use App\Http\Controllers\Api\Admin\TicketTypeController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\VolunteerController;
use Illuminate\Support\Facades\Route;

Route::apiResource('registrations', RegistrationController::class)->except(['store']);
// Registered before the apiResource, or `export` is swallowed by the
// `{attendee}` binding and answers 404 instead of downloading.
Route::get('attendees/export', [AttendeeController::class, 'export'])->name('attendees.export');
Route::apiResource('attendees', AttendeeController::class)->except(['store']);
Route::apiResource('ticket-types', TicketTypeController::class);
Route::apiResource('tickets', TicketController::class)->only(['index', 'show']);
Route::post('tickets/{ticket:ulid}/void', [TicketController::class, 'void'])->name('tickets.void');
Route::post('tickets/{ticket:ulid}/reissue', [TicketController::class, 'reissue'])->name('tickets.reissue');

Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
Route::post('payments/{payment:ulid}/verify-manual', [PaymentController::class, 'verifyManual'])->name('payments.verify-manual');
Route::post('payments/{payment:ulid}/reject-manual', [PaymentController::class, 'rejectManual'])->name('payments.reject-manual');
Route::post('payments/{payment:ulid}/refund', [PaymentController::class, 'refund'])->name('payments.refund');

// Static sub-paths registered before the `{notification}` binding so
// `costs`/`kill-switches`/`templates` never get swallowed as a ULID.
Route::get('notifications/costs', [NotificationController::class, 'costs'])->name('notifications.costs');
Route::get('notifications/kill-switches', [NotificationController::class, 'killSwitches'])->name('notifications.kill-switches');
Route::patch('notifications/kill-switches', [NotificationController::class, 'updateKillSwitch'])->name('notifications.kill-switches.update');
Route::get('notifications/templates', [NotificationController::class, 'templates'])->name('notifications.templates');
Route::apiResource('notifications', NotificationController::class)->only(['index', 'show']);
Route::post('notifications/{notification:ulid}/resend', [NotificationController::class, 'resend'])->name('notifications.resend');

Route::get('reports/{reportKey}', [ReportController::class, 'show'])->name('reports.show');
Route::post('reports/{reportKey}/export', [ReportController::class, 'export'])->name('reports.export');

Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
Route::patch('settings/{key}', [SettingController::class, 'update'])->name('settings.update');

Route::apiResource('volunteers', VolunteerController::class)->only(['index', 'store', 'show', 'update']);
Route::post('volunteers/{volunteer:ulid}/enrolment-token', [VolunteerController::class, 'issueEnrolmentToken'])
    ->name('volunteers.enrolment-token');
Route::post('volunteers/{volunteer:ulid}/assign-gate', [VolunteerController::class, 'assignGate'])->name('volunteers.assign-gate');
Route::post('volunteers/{volunteer:ulid}/revoke-access', [VolunteerController::class, 'revokeAccess'])->name('volunteers.revoke-access');

Route::apiResource('devices', DeviceController::class)->only(['index', 'show']);
Route::post('devices/{device:ulid}/revoke', [DeviceController::class, 'revoke'])->name('devices.revoke');
Route::get('devices/{device:ulid}/sync-status', [DeviceController::class, 'syncStatus'])->name('devices.sync-status');

Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
Route::apiResource('users', UserController::class)->only(['index', 'show']);
Route::post('users/{user:ulid}/assign-role', [UserController::class, 'assignRole'])->name('users.assign-role');

Route::apiResource('gates', GateController::class);

// CMS admin API (docs/08 Phase 3.5). Mirrors the public read API's shape:
// pages live under `content/pages/{page}` rather than `content/{page}`, so a
// page can be slugged `faqs` without colliding with the sibling collections.
Route::prefix('content')->name('content.')->group(function (): void {
    // Static and nested sub-paths first — `pages/{page}` would otherwise
    // swallow them and 404 on the failed ULID lookup.
    Route::get('media', [MediaController::class, 'index'])->name('media.index');
    Route::post('media', [MediaController::class, 'store'])->name('media.store');
    Route::delete('media/{media:ulid}', [MediaController::class, 'destroy'])->name('media.destroy');

    Route::post('pages/{page:ulid}/status', [PageController::class, 'changeStatus'])->name('pages.status');
    Route::post('pages/{page:ulid}/preview-token', [PageController::class, 'previewToken'])->name('pages.preview-token');
    Route::get('pages/{page:ulid}/revisions', [PageController::class, 'revisions'])->name('pages.revisions');
    Route::post('pages/{page:ulid}/revisions/{revision:ulid}/restore', [PageController::class, 'restoreRevision'])
        ->name('pages.revisions.restore');
    Route::apiResource('pages', PageController::class)->parameters(['pages' => 'page:ulid']);

    Route::post('menus/{menu:ulid}/items', [MenuItemController::class, 'store'])->name('menus.items.store');
    Route::patch('menus/{menu:ulid}/items/{item:ulid}', [MenuItemController::class, 'update'])->name('menus.items.update');
    Route::delete('menus/{menu:ulid}/items/{item:ulid}', [MenuItemController::class, 'destroy'])->name('menus.items.destroy');
    Route::apiResource('menus', MenuController::class)->parameters(['menus' => 'menu:ulid']);

    Route::post('gallery/{album:ulid}/items', [GalleryItemController::class, 'store'])->name('gallery.items.store');
    Route::patch('gallery/{album:ulid}/items/{item:ulid}', [GalleryItemController::class, 'update'])->name('gallery.items.update');
    Route::delete('gallery/{album:ulid}/items/{item:ulid}', [GalleryItemController::class, 'destroy'])->name('gallery.items.destroy');
    Route::apiResource('gallery', GalleryAlbumController::class)->parameters(['gallery' => 'album:ulid']);

    Route::apiResource('sponsors', SponsorController::class)->parameters(['sponsors' => 'sponsor:ulid']);
    Route::apiResource('schedule', ScheduleItemController::class)->parameters(['schedule' => 'schedule_item:ulid']);
    Route::apiResource('faqs', FaqController::class)->parameters(['faqs' => 'faq:ulid']);
});

// live-dashboard and manual-override must be registered before the
// apiResource below, or its {check_in} show route greedily matches them
// first and 404s on the failed ULID lookup.
Route::get('check-ins/live-dashboard', [CheckInController::class, 'liveDashboard'])->name('check-ins.live-dashboard');
Route::post('check-ins/manual-override', [CheckInController::class, 'manualOverride'])->name('check-ins.manual-override');
Route::apiResource('check-ins', CheckInController::class)->only(['index', 'show']);
Route::post('check-ins/{check_in:ulid}/resolve-conflict', [CheckInController::class, 'resolveConflict'])->name('check-ins.resolve-conflict');

// QR signing key rotation (docs/06 §6.5). Super Admin only via
// `qr.rotate_signing_key`; every mutation additionally requires recent
// re-authentication, because rotating the wrong way breaks every scanner
// at the gate and an unattended session must not be able to do it.
Route::prefix('qr-signing')->name('qr-signing.')->group(function (): void {
    Route::get('keys', [QrSigningKeyController::class, 'index'])->name('keys.index');

    Route::middleware('reauth')->group(function (): void {
        Route::post('keys', [QrSigningKeyController::class, 'store'])->name('keys.store');
        Route::post('keys/{key:ulid}/activate', [QrSigningKeyController::class, 'activate'])->name('keys.activate');
        Route::post('keys/{key:ulid}/retire', [QrSigningKeyController::class, 'retire'])->name('keys.retire');
    });
});
