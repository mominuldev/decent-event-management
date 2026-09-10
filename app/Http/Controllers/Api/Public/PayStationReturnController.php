<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Payment\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Public')]
class PayStationReturnController extends Controller
{
    #[OAT\Get(
        path: '/public/payments/paystation/return/{payment}',
        summary: 'Browser return target for a PayStation checkout session',
        description: 'PayStation redirects (or form-POSTs) the payer\'s browser here after its hosted '
            .'checkout — never the IPN, which arrives separately at `/webhooks/paystation`. This handler '
            .'writes nothing: it reads the payment only to find which registration page to send the browser '
            .'back to, then redirects to an origin taken entirely from server-side config (`FRONTEND_URL`). '
            .'Per docs/06 §6.6 a browser return proves nothing and must never transition a payment. Unlike '
            .'the removed SSLCommerz legs there is no `next` parameter at all — PayStation accepts a single '
            .'`callback_url`, so the payment ULID travels in the path and there is no caller-supplied '
            .'redirect target to abuse. The `payment_status` hint added to the redirect decides only whether '
            .'the return page polls; what it displays comes from the registration\'s own server-side status.',
        tags: ['Public'],
        parameters: [
            new OAT\Parameter(
                name: 'payment',
                in: 'path',
                required: true,
                description: 'Payment ULID, set into the callback URL server-side when the session was created',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(response: 302, description: 'Redirect to the frontend registration page'),
            new OAT\Response(response: 404, description: 'No such payment'),
        ]
    )]
    public function __invoke(Request $request, Payment $payment): RedirectResponse
    {
        $registration = $payment->registration;

        $target = $registration !== null
            ? $this->frontendUrl()."/registrations/{$registration->ulid}"
            : $this->frontendUrl();

        $separator = str_contains($target, '?') ? '&' : '?';

        return redirect()->away("{$target}{$separator}payment_status={$this->pollingHint($request)}");
    }

    /**
     * PayStation has one callback URL for every outcome, so the return leg
     * cannot say by itself whether the payer paid, failed or backed out.
     *
     * The hint is therefore derived from the presence of a `trx_id` in the
     * gateway's own return parameters — untrusted input, which is fine
     * because of the narrow job it does: the public site treats both
     * `success` and `fail` as "poll `/payment/verify` until the server
     * says otherwise", and only `cancel` as "do not poll". Deriving a
     * *wrong* hint here therefore costs at most one unnecessary
     * server-to-server status lookup; it can never put a success banner in
     * front of an unpaid payer, because the banner is rendered from the
     * registration status the server returns, not from this value.
     *
     * `cancel` is never emitted: a payer who abandoned may still have a
     * session that settles moments later, and not polling is the one
     * outcome that leaves a paid registration looking unpaid.
     */
    private function pollingHint(Request $request): string
    {
        $trxId = $request->input('trx_id');

        return is_string($trxId) && trim($trxId) !== '' ? 'success' : 'fail';
    }

    private function frontendUrl(): string
    {
        return rtrim((string) config('services.frontend.url'), '/');
    }
}
