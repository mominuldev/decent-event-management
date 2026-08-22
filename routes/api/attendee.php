<?php

use App\Http\Controllers\Api\Attendee\AuthController;
use App\Http\Controllers\Api\Attendee\ProfileController;
use App\Http\Controllers\Api\Attendee\RegistrationController;
use App\Http\Controllers\Api\Attendee\TicketController;
use Illuminate\Support\Facades\Route;

Route::post('auth/password', [AuthController::class, 'setPassword'])->name('auth.password');

Route::get('me', [ProfileController::class, 'show'])->name('me.show');
Route::patch('me', [ProfileController::class, 'update'])->name('me.update');
Route::post('me/photo', [ProfileController::class, 'photo'])->name('me.photo.store');
Route::delete('me/photo', [ProfileController::class, 'removePhoto'])->name('me.photo.destroy');

Route::get('registrations', [RegistrationController::class, 'index'])->name('registrations.index');
Route::patch('registrations/{registration:ulid}', [RegistrationController::class, 'update'])->name('registrations.update');
Route::post('registrations/{registration:ulid}/cancel', [RegistrationController::class, 'cancel'])->name('registrations.cancel');

Route::get('tickets/{ticket:ulid}', [TicketController::class, 'show'])->name('tickets.show');
Route::get('tickets/{ticket:ulid}/pdf', [TicketController::class, 'downloadPdf'])->name('tickets.pdf');
