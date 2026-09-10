<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Payment\Actions\ProcessGatewayWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Webhooks')]
class PayStationWebhookController extends Controller
{
    #[OAT\Post(
        path: '/webhooks/paystation',
        summary: 'PayStation Instant Payment Notification (IPN)',
        description: 'Server-to-server notification from PayStation, dispatched to '
            ."`ProcessGatewayWebhook::handle('paystation', \$request)`. PayStation sends a flat JSON body "
            .'(`invoice_number`, `trx_status`, `trx_id`, `trx_amount`, `order_date_time`, `payment_method`, '
            ."`reference`) and only for **successful** transactions.\n\n"
            .'**This request carries no signature.** PayStation publishes no HMAC, shared secret or '
            .'signing scheme for the IPN — their documentation states `Auth: None` — so the adapter reports '
            .'it as `unsigned` rather than pretending a check passed. It is still safe to act on because '
            .'acting on it means exactly one thing: a fresh server-to-server `verify()` call against '
            ."PayStation's own `/transaction-status`, which re-reads the status and the amount. Nothing in "
            ."this body is ever trusted to settle a payment, and the body's own `trx_amount` is ignored in "
            ."favour of the amount the gateway reports when asked directly.\n\n"
            ."The IPN URL is configured per merchant in PayStation's dashboard — it is not sent with each "
            ."payment — so this endpoint must be registered with them before any notification arrives.\n\n"
            .'Always answers `200`, as PayStation retries anything else: an unknown `invoice_number` is '
            .'logged and ignored, and a duplicate notification is recorded once and skipped thereafter.',
        tags: ['Webhooks'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['invoice_number', 'trx_status', 'trx_id'],
                    properties: [
                        new OAT\Property(
                            property: 'invoice_number',
                            type: 'string',
                            description: 'The invoice sent at initiation — this system\'s `payments.payment_number`, matched against `gateway_reference`',
                            example: 'PAY-A1B2C3D4'
                        ),
                        new OAT\Property(
                            property: 'trx_status',
                            type: 'string',
                            description: 'Always "Success" — the IPN fires only for successful transactions. Compared case-insensitively.',
                            example: 'Success'
                        ),
                        new OAT\Property(
                            property: 'trx_id',
                            type: 'string',
                            description: 'PayStation\'s transaction id, recorded on the resulting PaymentTransaction row',
                            example: 'CG20D8AYB4'
                        ),
                        new OAT\Property(
                            property: 'trx_amount',
                            type: 'number',
                            description: 'Amount in BDT. Recorded for the audit trail but never used to settle — the amount check is made against the gateway\'s own status API.',
                            example: 2500
                        ),
                        new OAT\Property(property: 'order_date_time', type: 'string', example: '2026-09-10 15:52:28'),
                        new OAT\Property(property: 'payment_method', type: 'string', example: 'bKash'),
                        new OAT\Property(property: 'reference', type: 'string', example: 'REG-100Y-000123'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Notification acknowledged. Always returned once the payload is parsed, regardless of '
                    .'whether a matching payment was found.',
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
        $processGatewayWebhook->handle('paystation', $request);

        return response()->json(['status' => 'received']);
    }
}
