<?php

namespace App\Http\Controllers\Api\Scanner;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\QrSigner;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManifestEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Scanner')]
class ManifestController extends Controller
{
    public function __construct(
        private readonly QrSigner $qrSigner,
    ) {}

    #[OAT\Get(
        path: '/scanner/v1/manifest',
        summary: 'Fetch the offline admission manifest (ETag-based delta sync)',
        tags: ['Scanner'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'If-None-Match',
                description: 'ETag returned by a previous manifest fetch; when it still matches, a 304 is returned with no body. Only applies to a cold-start fetch (no `since`).',
                in: 'header',
                required: false,
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\QueryParameter(
                name: 'since',
                description: 'The device\'s last-known manifest version. When given, only tickets with a manifest_version greater than this are returned — voided/refunded/expired tickets included, so revocations reach the device. Omit for a cold start, which returns every currently admissible ticket.',
                schema: new OAT\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Manifest entries (full on cold start, delta when `since` is given), plus the signing keys needed to verify a QR offline',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'array',
                                items: new OAT\Items(
                                    properties: [
                                        new OAT\Property(property: 'ticket_ulid', type: 'string'),
                                        new OAT\Property(property: 'ticket_number', type: 'string'),
                                        new OAT\Property(property: 'status', type: 'string'),
                                        new OAT\Property(property: 'admits_total', type: 'integer'),
                                        new OAT\Property(property: 'admitted_count', type: 'integer'),
                                        new OAT\Property(property: 'holder_name', type: 'string'),
                                        new OAT\Property(property: 'holder_batch_year', type: 'integer'),
                                        new OAT\Property(property: 'holder_type_label', type: 'string'),
                                        new OAT\Property(property: 'event_session_id', type: 'integer'),
                                        new OAT\Property(property: 'ticket_type_id', type: 'integer'),
                                        new OAT\Property(property: 'manifest_version', type: 'integer'),
                                    ],
                                    type: 'object'
                                )
                            ),
                            new OAT\Property(
                                property: 'meta',
                                properties: [
                                    new OAT\Property(property: 'manifest_version', type: 'integer', description: 'Highest manifest_version across all tickets right now — pass back as `since` next fetch'),
                                    new OAT\Property(property: 'active_key_id', type: 'string'),
                                    new OAT\Property(property: 'keys', type: 'object', description: 'signing_key_id => base64 Ed25519 public key, every key still needed to verify a ticket that might be in circulation'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 304, description: 'Manifest unchanged since the ETag supplied in If-None-Match; no body'),
            new OAT\Response(response: 401, description: 'Missing or invalid scanner token'),
        ]
    )]
    public function show(Request $request): Response
    {
        $agg = Ticket::query()->selectRaw('COUNT(*) as count, MAX(manifest_version) as max_version')->first();
        $count = (int) ($agg->count ?? 0);
        $maxVersion = (int) ($agg->max_version ?? 0);

        $since = $request->filled('since') ? (int) $request->query('since') : null;

        if ($since === null) {
            $eTag = '"'.md5($count.'-'.$maxVersion).'"';

            if ($request->header('If-None-Match') === $eTag) {
                return response()->noContent(304);
            }

            $tickets = Ticket::whereIn('status', ['active', 'partially_admitted', 'fully_admitted'])->get();
        } else {
            $eTag = null;
            // No status filter: a ticket that moved to voided/refunded/
            // expired since $since must still reach the device, or the
            // revocation manifest layer (docs/06 §6.5) never propagates.
            $tickets = Ticket::where('manifest_version', '>', $since)->get();
        }

        $this->touchDevice($request, $maxVersion);

        /** @var JsonResponse $response */
        $response = response()->json([
            'data' => ManifestEntryResource::collection($tickets),
            'meta' => [
                'manifest_version' => $maxVersion,
                'active_key_id' => $this->qrSigner->activeKeyId(),
                'keys' => $this->qrSigner->publicKeys(),
            ],
        ]);

        return $eTag !== null ? $response->setEtag($eTag) : $response;
    }

    private function touchDevice(Request $request, int $manifestVersion): void
    {
        /** @var CheckInDevice|null $device */
        $device = $request->attributes->get('checkin_device');

        $device?->forceFill([
            'manifest_version' => $manifestVersion,
            'last_sync_at' => now(),
        ])->save();
    }
}
