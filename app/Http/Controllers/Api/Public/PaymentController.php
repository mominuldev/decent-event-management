<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Payment\Actions\InitiatePayment;
use App\Domain\Payment\Actions\ResolvePayablePayment;
use App\Domain\Payment\Actions\VerifyPayment;
use App\Domain\Registration\Models\Registration;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Public')]
class PaymentController extends Controller
{
    #[OAT\Post(
        path: '/public/registrations/{registration}/payment/initiate',
        summary: 'Start (or retry) a gateway payment session for a registration',
        description: 'Creates a gateway session via the payment method chosen at registration time and returns '
            .'the URL to redirect the payer\'s browser to. Safe to call again after an abandoned, declined or '
            .'expired attempt: the gateway is first asked whether the previous attempt actually settled, and if '
            .'not a fresh payment row is opened (an invoice number is single-use at the gateway). A declined or '
            .'expired attempt gave its seat back, so a retry re-reserves capacity and can meet `sold_out`. '
            .'This never marks the payment `succeeded` — only a server-to-server webhook '
            .'({@see \App\Http\Controllers\Webhooks}) or the expiry sweeper can do that.',
        tags: ['Public'],
        parameters: [
            new OAT\Parameter(
                name: 'registration',
                description: 'Registration ULID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\Parameter(
                name: 'Idempotency-Key',
                description: 'Client-generated key; a retried initiate call with the same key and body replays the cached response',
                in: 'header',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Gateway session created',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'object',
                                properties: [
                                    new OAT\Property(property: 'redirect_url', type: 'string'),
                                    new OAT\Property(
                                        property: 'payment',
                                        type: 'object',
                                        properties: [
                                            new OAT\Property(property: 'ulid', type: 'string'),
                                            new OAT\Property(property: 'status', type: 'string'),
                                            new OAT\Property(property: 'method', type: 'string'),
                                        ]
                                    ),
                                ]
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 400, description: 'Missing Idempotency-Key header'),
            new OAT\Response(response: 404, description: 'Registration not found'),
            new OAT\Response(
                response: 422,
                description: 'Nothing to pay right now. `already_paid` means an abandoned checkout had in fact '.
                    'settled (the registration is now paid — reload it); `payment_in_progress` and '.
                    '`payment_under_review` mean an attempt is being confirmed or reconciled; `sold_out` means '.
                    'the seat a failed attempt released has since been taken; `no_payable_payment` covers a '.
                    'registration that is settled, cancelled or has no payment at all.',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'code',
                                type: 'string',
                                enum: ['no_payable_payment', 'already_paid', 'payment_in_progress', 'payment_under_review', 'sold_out'],
                            ),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function initiate(
        Registration $registration,
        ResolvePayablePayment $resolve,
        InitiatePayment $action,
    ): JsonResponse {
        // Throws a self-rendering 422 when there is nothing to pay — see
        // PaymentNotPayableException for the codes the status page reads.
        $payment = $resolve->handle($registration);

        $callbackUrl = rtrim((string) config('services.frontend.url'), '/')."/registrations/{$registration->ulid}";

        $result = $action->handle($payment, $callbackUrl);

        return response()->json([
            'data' => [
                'redirect_url' => $result['redirect_url'],
                'payment' => new PaymentResource($result['payment']),
            ],
        ]);
    }

    #[OAT\Post(
        path: '/public/registrations/{registration}/payment/verify',
        summary: "Ask the gateway, server-to-server, whether this registration's payment has settled",
        description: 'For the return-from-gateway page to call. The browser arriving back is only a '.
            'hint that it is worth asking — this endpoint accepts no payload and reads nothing from '.
            'the request, it re-runs the same server-to-server verification the IPN path uses '.
            '(val_id, falling back to tran_id) and re-checks the amount before anything is marked '.
            'succeeded, per docs/06 §6.6. Exists because an IPN can be delayed or lost — routine for '.
            'Bangladeshi MFS — which would otherwise leave a genuinely paid registration stuck on '.
            '"pending" until the nightly reconciliation.',
        tags: ['Public'],
        parameters: [
            new OAT\Parameter(
                name: 'registration',
                description: 'Registration ULID',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Verification outcome and the registration as it now stands',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'object',
                                properties: [
                                    new OAT\Property(property: 'outcome', type: 'string', enum: ['succeeded', 'failed', 'pending', 'amount_mismatch']),
                                    new OAT\Property(property: 'registration_status', type: 'string'),
                                    new OAT\Property(property: 'payment', type: 'object'),
                                ]
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 404, description: 'Registration not found'),
        ]
    )]
    public function verify(Registration $registration, VerifyPayment $action): JsonResponse
    {
        // The most recent payment that could still move. A registration
        // whose payment already settled needs no gateway round trip — the
        // Action is idempotent, but skipping the call keeps a page that
        // polls from hammering the gateway.
        $payment = $registration->payments()
            ->whereIn('status', ['pending', 'initiated'])
            ->latest('id')
            ->first();

        if ($payment === null) {
            return response()->json([
                'data' => [
                    'outcome' => 'settled',
                    'registration_status' => $registration->status,
                    'payment' => new PaymentResource($registration->payments()->latest('id')->first()),
                ],
            ]);
        }

        $outcome = $action->handle($payment);

        return response()->json([
            'data' => [
                'outcome' => $outcome,
                'registration_status' => $registration->fresh()?->status,
                'payment' => new PaymentResource($payment->fresh()),
            ],
        ]);
    }
}
