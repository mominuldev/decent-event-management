<?php

use App\Http\Controllers\Webhooks\BkashWebhookController;
use App\Http\Controllers\Webhooks\NagadWebhookController;
use App\Http\Controllers\Webhooks\RocketWebhookController;
use App\Http\Controllers\Webhooks\SslCommerzWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('bkash', BkashWebhookController::class)->name('webhooks.bkash');
Route::post('nagad', NagadWebhookController::class)->name('webhooks.nagad');
Route::post('rocket', RocketWebhookController::class)->name('webhooks.rocket');
Route::post('sslcommerz', SslCommerzWebhookController::class)
    ->middleware('ipn.allowlist:sslcommerz')
    ->name('webhooks.sslcommerz');
