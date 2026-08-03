<?php

use App\Http\Controllers\Api\Admin\AttendeeController;
use App\Http\Controllers\Api\Admin\CheckInController;
use App\Http\Controllers\Api\Admin\GateController;
use App\Http\Controllers\Api\Admin\PaymentController;
use App\Http\Controllers\Api\Admin\RegistrationController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\TicketController;
use App\Http\Controllers\Api\Admin\TicketTypeController;
use App\Http\Controllers\Api\Admin\VolunteerController;
use Illuminate\Support\Facades\Route;

Route::apiResource('registrations', RegistrationController::class)->except(['store']);
Route::apiResource('attendees', AttendeeController::class)->except(['store']);
Route::apiResource('ticket-types', TicketTypeController::class);
Route::apiResource('tickets', TicketController::class)->only(['index', 'show']);
Route::post('tickets/{ticket:ulid}/void', [TicketController::class, 'void'])->name('tickets.void');
Route::post('tickets/{ticket:ulid}/reissue', [TicketController::class, 'reissue'])->name('tickets.reissue');

Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
Route::post('payments/{payment:ulid}/verify-manual', [PaymentController::class, 'verifyManual'])->name('payments.verify-manual');
Route::post('payments/{payment:ulid}/reject-manual', [PaymentController::class, 'rejectManual'])->name('payments.reject-manual');
Route::post('payments/{payment:ulid}/refund', [PaymentController::class, 'refund'])->name('payments.refund');

Route::get('reports/{reportKey}', [ReportController::class, 'show'])->name('reports.show');
Route::post('reports/{reportKey}/export', [ReportController::class, 'export'])->name('reports.export');

Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
Route::patch('settings/{key}', [SettingController::class, 'update'])->name('settings.update');

Route::post('volunteers/{volunteer:ulid}/enrolment-token', [VolunteerController::class, 'issueEnrolmentToken'])
    ->name('volunteers.enrolment-token');

Route::apiResource('gates', GateController::class);

// live-dashboard and manual-override must be registered before the
// apiResource below, or its {check_in} show route greedily matches them
// first and 404s on the failed ULID lookup.
Route::get('check-ins/live-dashboard', [CheckInController::class, 'liveDashboard'])->name('check-ins.live-dashboard');
Route::post('check-ins/manual-override', [CheckInController::class, 'manualOverride'])->name('check-ins.manual-override');
Route::apiResource('check-ins', CheckInController::class)->only(['index', 'show']);
Route::post('check-ins/{check_in:ulid}/resolve-conflict', [CheckInController::class, 'resolveConflict'])->name('check-ins.resolve-conflict');
