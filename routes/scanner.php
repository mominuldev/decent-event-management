<?php

use App\Http\Controllers\Api\Scanner\CheckInSyncController;
use App\Http\Controllers\Api\Scanner\DeviceEnrolmentController;
use App\Http\Controllers\Api\Scanner\ManifestController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('scanner.v1.')->group(function (): void {
    Route::post('enrol', [DeviceEnrolmentController::class, 'enrol'])->name('enrol');

    Route::middleware(['auth:scanner', 'abilities:scanner', 'device.active', 'checkin.window', 'gate.assigned', 'throttle:scanner'])->group(function (): void {
        Route::get('manifest', [ManifestController::class, 'show'])->name('manifest.show');
        Route::post('scans', [CheckInSyncController::class, 'store'])->name('scans.store');
    });
});
