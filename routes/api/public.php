<?php

use App\Http\Controllers\Api\Public\EventSettingController;
use App\Http\Controllers\Api\Public\RegistrationController;
use App\Http\Controllers\Api\Public\TicketTypeController;
use Illuminate\Support\Facades\Route;

Route::get('event', [EventSettingController::class, 'show'])->name('event.show');
Route::get('ticket-types', [TicketTypeController::class, 'index'])->name('ticket-types.index');

Route::post('registrations', [RegistrationController::class, 'store'])->name('registrations.store');
Route::get('registrations/{registration:ulid}', [RegistrationController::class, 'show'])->name('registrations.show');
