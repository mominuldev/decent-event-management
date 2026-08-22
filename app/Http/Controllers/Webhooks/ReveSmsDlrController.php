<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Notification\Actions\RecordDeliveryReceipt;
use App\Domain\Notification\Gateways\ReveSmsDeliveryState;
use App\Domain\Notification\Models\Notification;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OAT;

/**
 * REVE's delivery-receipt push. The vendor's own server exposes this as
 * `POST /submitstatus` taking `{apikey, secretkey, messageid, text}`
 * (their Postman collection, "Dlr Push"), and calls the same shape
 * outward at whatever DLR URL is configured on the account — so this
 * accepts exactly that, by POST or GET, since their collection shows
 * both.
 *
 * Two things this deliberately does *not* do:
 *
 * - **It never trusts the callback for anything but a status.** There is
 *   no amount, no recipient and no content in the payload it acts on; the
 *   worst a forged call can achieve is mislabelling one notification's
 *   delivery state. Compare the payment IPNs, which are never allowed to
 *   settle anything without a server-to-server re-verify — the equivalent
 *   here is `sms:poll-dlr`, which asks the gateway directly and is what
 *   runs when no push is configured at all.
 * - **It never says whether a message id is one of ours.** An unknown id
 *   gets the same 200 and the same body as a known one. Answering 404
 *   would turn this into an oracle for enumerating live message ids, and
 *   REVE would treat the error as a failed callback and retry it forever.
 */
#[OAT\Tag(name: 'Webhooks')]
class ReveSmsDlrController extends Controller
{
    #[OAT\Post(
        path: '/webhooks/sms/dlr',
        summary: 'REVE Systems SMS delivery receipt (DLR) callback',
        description: 'Delivery receipt pushed by the REVE SMS gateway for a message this system sent. '
            .'Mirrors the vendor\'s own `POST /submitstatus` shape (`apikey`, `secretkey`, `messageid`, `text`) '
            .'and is accepted by `GET` as well, since REVE\'s published collection shows both verbs. '
            .'`messageid` is matched against `notifications.provider_message_id`; `text` is an SMPP v3.4 '
            .'`message_state` value (`DELIVRD`, `UNDELIV`, `REJECTD`, `EXPIRED`, `ACCEPTD`, …) mapped by '
            .'`ReveSmsDeliveryState`, which settles the outbox row to `delivered` or `bounced` and leaves a '
            .'still-in-flight state (`ACCEPTD`, `ENROUTE`) as a timeline entry only. '
            .'Authenticated solely by the configured `apikey`/`secretkey` pair — a wrong pair is `401`, and the '
            .'endpoint is refused outright when no credentials are configured rather than accepting an '
            .'unauthenticated caller. An **unknown** `messageid` answers `200` with the identical body a known '
            .'one does: a `404` would be an oracle for enumerating live message ids, and REVE would read any '
            .'error as a failed callback and retry it indefinitely. The callback is never trusted for anything '
            .'but a delivery status — `sms:poll-dlr` asks the gateway directly and is what runs when no push '
            .'is configured on the REVE account at all.',
        tags: ['Webhooks'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['apikey', 'secretkey', 'messageid'],
                    properties: [
                        new OAT\Property(property: 'apikey', type: 'string', description: 'REVE account API key'),
                        new OAT\Property(property: 'secretkey', type: 'string', description: 'REVE account secret key'),
                        new OAT\Property(property: 'messageid', type: 'string', description: 'Gateway message id, matched against notifications.provider_message_id'),
                        new OAT\Property(property: 'text', type: 'string', description: 'SMPP message_state, e.g. DELIVRD / UNDELIV / REJECTD / ACCEPTD'),
                        new OAT\Property(property: 'donedate', type: 'string', description: 'When the carrier reported the state; falls back to now() if absent or unparseable'),
                    ],
                    type: 'object',
                ),
            ),
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Receipt acknowledged (whether or not the message id is known here)'),
            new OAT\Response(response: 401, description: 'Missing or wrong apikey/secretkey, or no DLR credentials configured'),
            new OAT\Response(response: 422, description: 'No messageid in the payload'),
        ],
    )]
    public function __invoke(Request $request, RecordDeliveryReceipt $action): JsonResponse
    {
        if (! $this->authenticated($request)) {
            // 401 rather than a silent 200: this one *is* safe to be
            // explicit about, because it tells a caller nothing except that
            // the keys they already sent are wrong.
            return response()->json(['code' => 'unauthorized', 'message' => 'Invalid DLR credentials.'], 401);
        }

        $messageId = $this->stringInput($request, ['messageid', 'message_id', 'Message_ID', 'MessageID']);
        $status = $this->stringInput($request, ['text', 'Text', 'status', 'Status', 'dlr', 'stat']);

        if ($messageId === null) {
            return response()->json(['code' => 'invalid_payload', 'message' => 'messageid is required.'], 422);
        }

        $notification = Notification::query()
            ->where('channel', 'sms')
            ->where('provider_message_id', $messageId)
            ->latest('id')
            ->first();

        if ($notification !== null) {
            $action->execute(
                notification: $notification,
                state: ReveSmsDeliveryState::fromProviderStatus($status),
                providerStatus: $status,
                rawPayload: $this->safePayload($request),
                occurredAt: $this->occurredAt($request),
            );
        }

        return response()->json(['status' => 'ok']);
    }

    private function authenticated(Request $request): bool
    {
        $expectedKey = $this->credential('dlr_api_key', 'api_key');
        $expectedSecret = $this->credential('dlr_secret_key', 'secret_key');

        // No credentials configured at all means the callback cannot be
        // authenticated, so it is refused rather than accepted — an
        // unauthenticated endpoint that rewrites delivery state is worse
        // than one that is switched off.
        if ($expectedKey === null || $expectedSecret === null) {
            return false;
        }

        $key = $this->stringInput($request, ['apikey', 'api_key', 'apiKey']) ?? '';
        $secret = $this->stringInput($request, ['secretkey', 'secret_key', 'secretKey']) ?? '';

        return hash_equals($expectedKey, $key) && hash_equals($expectedSecret, $secret);
    }

    private function credential(string $key, string $fallbackKey): ?string
    {
        foreach ([$key, $fallbackKey] as $candidate) {
            $value = config("services.revesms.{$candidate}");

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function stringInput(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function occurredAt(Request $request): ?Carbon
    {
        $raw = $this->stringInput($request, ['donedate', 'doneDate', 'occurred_at', 'timestamp', 'time']);

        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            // A receipt with an unparseable timestamp is still a receipt —
            // fall back to now() rather than discarding the delivery status
            // over a date format.
            return null;
        }
    }

    /**
     * The whole callback minus its credentials. `notification_events.raw_payload`
     * is read in the admin delivery timeline, so storing the keys would put
     * the account's API secret on an admin screen and in every database
     * backup.
     *
     * @return array<string, mixed>
     */
    private function safePayload(Request $request): array
    {
        return $request->except(['apikey', 'api_key', 'apiKey', 'secretkey', 'secret_key', 'secretKey']);
    }
}
