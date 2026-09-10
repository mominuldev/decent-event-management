<?php

use App\Http\Controllers\Webhooks\BkashWebhookController;
use App\Http\Controllers\Webhooks\NagadWebhookController;
use App\Http\Controllers\Webhooks\PayStationWebhookController;
use App\Http\Controllers\Webhooks\ReveSmsDlrController;
use App\Http\Controllers\Webhooks\RocketWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('bkash', BkashWebhookController::class)->name('webhooks.bkash');
Route::post('nagad', NagadWebhookController::class)->name('webhooks.nagad');
Route::post('rocket', RocketWebhookController::class)->name('webhooks.rocket');
// PayStation IPN. Throttled, which matters more here than for a signed
// gateway: the notification carries no signature (their docs say "Auth:
// None"), so the only cost a forged request can impose is the outbound
// status lookup it prompts — and that is what this bounds. The IP
// allowlist is a deliberate no-op until someone supplies PayStation's
// real source ranges; see EnsureIpnFromAllowlistedIp.
Route::post('paystation', PayStationWebhookController::class)
    ->middleware(['throttle:300,1', 'ipn.allowlist:paystation'])
    ->name('webhooks.paystation');

// REVE Systems SMS delivery receipts. GET as well as POST because the
// vendor's collection shows both, and a gateway configured for the wrong
// verb would otherwise silently drop every receipt. Throttled: the
// callback is unauthenticated at the network layer (its only check is the
// key pair in the body), so this bounds what a wrong or hostile caller
// can cost.
Route::match(['get', 'post'], 'sms/dlr', ReveSmsDlrController::class)
    ->middleware(['throttle:300,1', 'ipn.allowlist:revesms'])
    ->name('webhooks.sms.dlr');
