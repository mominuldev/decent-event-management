<?php

use App\Http\Controllers\Api\Public\EventSettingController;
use App\Http\Controllers\Api\Public\PaymentController;
use App\Http\Controllers\Api\Public\RegistrationController;
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
