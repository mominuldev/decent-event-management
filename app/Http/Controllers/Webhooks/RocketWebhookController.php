<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payment\Actions\ProcessGatewayWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Webhooks')]
class RocketWebhookController extends Controller
{
    #[OAT\Post(
        path: '/webhooks/rocket',
        summary: 'Rocket payment gateway IPN callback',
        description: 'Server-to-server instant payment notification (IPN) from Rocket, dispatched to '
            .'`ProcessGatewayWebhook::handle(\'rocket\', $request)`. The payload is parsed and signature-verified '
            .'by the resolved gateway adapter\'s `parseWebhook()` (see `PaymentGatewayResolver::forMethod()` — as '
            .'of Phase 2 every gateway name, including `rocket`, resolves to the `FakeGateway` stand-in; a real '
            .'`RocketClient` adapter with Rocket-specific verification handling lands in Phase 4, per '
            .'docs/01-system-architecture.md §"Payment Gateway Integration"). A valid signature only triggers a '
            .'fresh server-to-server `verify()` call (`VerifyPayment`) against the matched `Payment` — this '
            .'webhook body is never itself trusted to transition a payment to `succeeded`. An invalid signature, '
            .'or a `gateway_reference` matching no known payment, is recorded/logged and silently ignored; the '
            .'response is always `200` regardless of outcome, since the gateway only expects a plain '
            .'acknowledgement, never an error status.',
        tags: ['Webhooks'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'gateway_reference',
                            type: 'string',
                            description: 'Matches Payment::gateway_reference for the intent this IPN concerns',
                            required: ['gateway_reference']
                        ),
                        new OAT\Property(
                            property: 'status',
                            type: 'string',
                            enum: ['succeeded', 'failed', 'pending'],
                            description: 'Gateway-reported settlement status',
                            required: ['status']
                        ),
                        new OAT\Property(
                            property: 'gateway_transaction_id',
                            type: 'string',
                            description: 'Gateway-assigned transaction id, stored on the resulting PaymentTransaction row'
                        ),
                        new OAT\Property(
                            property: 'signature',
                            type: 'string',
                            description: 'HMAC-SHA256 of "{gateway_reference}|{status}", keyed by the shared '
                                .'webhook secret (`services.fake_gateway.webhook_secret` in the current stand-in). '
                                .'This is the authenticity check for the request in place of bearer-token auth.',
                            required: ['signature']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Webhook acknowledged. Always returned once the payload is parsed, regardless of '
                    .'whether the payment was matched or the signature was valid.',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'status', type: 'string', example: 'received'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function __invoke(Request $request, ProcessGatewayWebhook $processGatewayWebhook): JsonResponse
    {
        $processGatewayWebhook->handle('rocket', $request);

        return response()->json(['status' => 'received']);
    }
}
