<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Payment\Actions\InitiatePayment;
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
        summary: 'Start a gateway payment session for a registration\'s pending payment',
        description: 'Creates a gateway session via the payment method chosen at registration time and returns '
            .'the URL to redirect the payer\'s browser to. This never marks the payment `succeeded` — only a '
            .'server-to-server webhook ({@see \App\Http\Controllers\Webhooks}) or the expiry sweeper can do that.',
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
                description: 'Registration has no payment awaiting initiation',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'no_payable_payment'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function initiate(Registration $registration, InitiatePayment $action): JsonResponse
    {
        $payment = $registration->payments()
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($payment === null) {
            return response()->json([
                'code' => 'no_payable_payment',
                'message' => 'This registration has no payment awaiting initiation.',
            ], 422);
        }

        $callbackUrl = rtrim((string) config('services.frontend.url'), '/')."/registrations/{$registration->ulid}";

        $result = $action->handle($payment, $callbackUrl);

        return response()->json([
            'data' => [
                'redirect_url' => $result['redirect_url'],
                'payment' => new PaymentResource($result['payment']),
            ],
        ]);
    }
}
