<?php

namespace App\Http\Controllers\Api\Scanner;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\QrSigner;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManifestEntryResource;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Scanner')]
class ManifestController extends Controller
{
    /** Tickets a gate may admit — the cold-start set. */
    private const ADMISSIBLE_STATUSES = ['active', 'partially_admitted', 'fully_admitted'];

    /** Ceiling on an opt-in page, so `?limit=` can't reintroduce an unbounded fetch. */
    private const MAX_PAGE_SIZE = 5000;

    /**
     * Only the columns ManifestEntryResource actually reads. A ticket row is
     * ~30 columns wide; hydrating all of them 12,000 times is memory spent
     * on fields the scanner never sees.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'ulid', 'ticket_number', 'status', 'admits_total', 'admitted_count',
        'holder_name', 'holder_batch_year', 'holder_type_label',
        'event_session_id', 'ticket_type_id', 'manifest_version',
    ];

    public function __construct(
        private readonly QrSigner $qrSigner,
    ) {}

    #[OAT\Get(
        path: '/scanner/v1/manifest',
        summary: 'Fetch the offline admission manifest (ETag delta sync, streamed, optionally paginated)',
        description: 'Streams the manifest so server memory stays flat regardless of ticket count. The default response is the complete manifest and its body shape is unchanged, so a client that does not paginate keeps working. Pass `limit` to opt into bounded, resumable pages.',
        tags: ['Scanner'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'If-None-Match',
                description: 'ETag returned by a previous manifest fetch; when it still matches, a 304 is returned with no body. Only applies to a cold-start fetch (no `since`, no `after`).',
                in: 'header',
                required: false,
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\QueryParameter(
                name: 'since',
                description: 'The device\'s last-known manifest version. When given, only tickets with a manifest_version greater than this are returned — voided/refunded/expired tickets included, so revocations reach the device. Omit for a cold start, which returns every currently admissible ticket.',
                schema: new OAT\Schema(type: 'integer', minimum: 0)
            ),
            new OAT\QueryParameter(
                name: 'limit',
                description: 'Opt in to bounded pages of at most this many entries. When more remain, `meta.next_cursor` is the value to pass back as `after`. Omit for the complete manifest in one streamed response.',
                schema: new OAT\Schema(type: 'integer', minimum: 1, maximum: self::MAX_PAGE_SIZE)
            ),
            new OAT\QueryParameter(
                name: 'after',
                description: 'Resume from this cursor (the `meta.next_cursor` of the previous page). Entries are ordered by ticket ULID, so a sync interrupted on a poor connection resumes instead of restarting.',
                schema: new OAT\Schema(type: 'string', maxLength: 26, minLength: 26)
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
                                    new OAT\Property(property: 'manifest_version', type: 'integer', description: 'Highest manifest_version across all tickets right now — pass back as `since` next fetch, but only once every page has been fetched'),
                                    new OAT\Property(property: 'next_cursor', type: 'string', nullable: true, description: 'Present only when `limit` was given. Non-null means more entries remain: pass it back as `after`. Null means this was the last page and the sync is complete.'),
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
            new OAT\Response(response: 422, description: 'Invalid since/limit/after parameter'),
        ]
    )]
    public function show(Request $request): Response
    {
        /** @var array{since?: int, limit?: int, after?: string} $validated */
        $validated = $request->validate([
            'since' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE_SIZE],
            'after' => ['sometimes', 'string', 'size:26'],
        ]);

        $since = $validated['since'] ?? null;
        $limit = $validated['limit'] ?? null;
        $after = $validated['after'] ?? null;

        $agg = Ticket::query()->selectRaw('COUNT(*) as count, MAX(manifest_version) as max_version')->first();
        $count = (int) ($agg->count ?? 0);
        $maxVersion = (int) ($agg->max_version ?? 0);

        $query = $this->manifestQuery($since, $after);

        // The ETag covers the whole manifest, so it only means anything on a
        // fetch that asks for the whole manifest from the start.
        if ($since === null && $after === null && $limit === null) {
            $eTag = '"'.md5($count.'-'.$maxVersion).'"';

            if ($request->header('If-None-Match') === $eTag) {
                return response()->noContent(304);
            }

            return $this->streamed($query, $request, $maxVersion)->setEtag($eTag);
        }

        if ($limit === null) {
            return $this->streamed($query, $request, $maxVersion);
        }

        return $this->page($query, $request, $limit, $maxVersion);
    }

    /**
     * @return Builder<Ticket>
     */
    private function manifestQuery(?int $since, ?string $after): Builder
    {
        $query = Ticket::query()
            ->select(self::COLUMNS)
            // A total, index-backed order is what makes `after` a stable
            // cursor. ULID rather than the auto-increment id so no internal
            // primary key crosses the API boundary.
            ->orderBy('ulid');

        if ($since === null) {
            $query->whereIn('status', self::ADMISSIBLE_STATUSES);
        } else {
            // No status filter: a ticket that moved to voided/refunded/
            // expired since $since must still reach the device, or the
            // revocation manifest layer (docs/06 §6.5) never propagates.
            $query->where('manifest_version', '>', $since);
        }

        if ($after !== null) {
            $query->where('ulid', '>', $after);
        }

        return $query;
    }

    /**
     * The complete manifest, streamed. `cursor()` is one unbuffered query
     * that hands back a row at a time, so peak memory is one ticket rather
     * than all of them — a 12,000-ticket cold start used to cost ~42 MB of
     * PHP memory per request, which 20+ devices syncing at a gate turns
     * into an outage (docs/08 Phase 6 exit criterion).
     *
     * @param  Builder<Ticket>  $query
     */
    private function streamed(Builder $query, Request $request, int $maxVersion): Response
    {
        $device = $this->device($request);

        $entries = function () use ($query, $request, $device, $maxVersion): Generator {
            foreach ($query->cursor() as $ticket) {
                yield (new ManifestEntryResource($ticket))->toArray($request);
            }

            // Deliberately after the last row, not before the first: if the
            // connection drops mid-stream PHP aborts here and the device is
            // never recorded as holding a manifest it did not finish
            // receiving. Key rotation gates on exactly this field.
            $this->touchDevice($device, $maxVersion);
        };

        return response()->streamJson([
            'data' => $entries(),
            'meta' => $this->meta($maxVersion),
        ]);
    }

    /**
     * One bounded, resumable page. Opt-in via `?limit=`, because a client
     * that does not know about `next_cursor` would otherwise silently sync
     * a fraction of the manifest and turn real ticket-holders away.
     *
     * @param  Builder<Ticket>  $query
     */
    private function page(Builder $query, Request $request, int $limit, int $maxVersion): Response
    {
        // One extra row is the cheapest way to know whether more remain
        // without a second COUNT over the same filtered set.
        $tickets = $query->limit($limit + 1)->get();

        $hasMore = $tickets->count() > $limit;
        $page = $hasMore ? $tickets->take($limit) : $tickets;
        $nextCursor = $hasMore ? $page->last()?->ulid : null;

        if ($nextCursor === null) {
            $this->touchDevice($this->device($request), $maxVersion);
        }

        /** @var Response $response */
        $response = response()->json([
            'data' => ManifestEntryResource::collection($page),
            'meta' => $this->meta($maxVersion) + ['next_cursor' => $nextCursor],
        ]);

        return $response;
    }

    /**
     * @return array{manifest_version: int, active_key_id: string, keys: array<string, string>}
     */
    private function meta(int $maxVersion): array
    {
        return [
            'manifest_version' => $maxVersion,
            'active_key_id' => $this->qrSigner->activeKeyId(),
            'keys' => $this->qrSigner->publicKeys(),
        ];
    }

    private function device(Request $request): ?CheckInDevice
    {
        $device = $request->attributes->get('checkin_device');

        return $device instanceof CheckInDevice ? $device : null;
    }

    private function touchDevice(?CheckInDevice $device, int $manifestVersion): void
    {
        $device?->forceFill([
            'manifest_version' => $manifestVersion,
            'last_sync_at' => now(),
        ])->save();
    }
}
