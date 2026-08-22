<?php

use App\Http\Controllers\Webhooks\BkashWebhookController;
use App\Http\Controllers\Webhooks\NagadWebhookController;
use App\Http\Controllers\Webhooks\ReveSmsDlrController;
use App\Http\Controllers\Webhooks\RocketWebhookController;
use App\Http\Controllers\Webhooks\SslCommerzWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('bkash', BkashWebhookController::class)->name('webhooks.bkash');
Route::post('nagad', NagadWebhookController::class)->name('webhooks.nagad');
Route::post('rocket', RocketWebhookController::class)->name('webhooks.rocket');
Route::post('sslcommerz', SslCommerzWebhookController::class)
    ->middleware('ipn.allowlist:sslcommerz')
    ->name('webhooks.sslcommerz');

// REVE Systems SMS delivery receipts. GET as well as POST because the
// vendor's collection shows both, and a gateway configured for the wrong
// verb would otherwise silently drop every receipt. Throttled: the
// callback is unauthenticated at the network layer (its only check is the
// key pair in the body), so this bounds what a wrong or hostile caller
// can cost.
Route::match(['get', 'post'], 'sms/dlr', ReveSmsDlrController::class)
    ->middleware(['throttle:300,1', 'ipn.allowlist:revesms'])
    ->name('webhooks.sms.dlr');
