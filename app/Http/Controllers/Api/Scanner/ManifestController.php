<?php

namespace App\Http\Controllers\Api\Scanner;

use App\Domain\Ticketing\Models\Ticket;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManifestEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Scanner')]
class ManifestController extends Controller
{
    #[OAT\Get(
        path: '/scanner/v1/manifest',
        summary: 'Fetch the offline admission manifest (ETag-based delta sync)',
        tags: ['Scanner'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'If-None-Match',
                description: 'ETag returned by a previous manifest fetch; when it still matches, a 304 is returned with no body',
                in: 'header',
                required: false,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Full manifest of admissible tickets, with a fresh ETag response header',
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
        $agg = Ticket::whereIn('status', ['active', 'partially_admitted', 'fully_admitted'])
            ->selectRaw('COUNT(*) as count, MAX(manifest_version) as max_version')
            ->first();

        $count = $agg->count ?? 0;
        $maxVersion = $agg->max_version ?? 0;

        $eTag = '"'.md5($count.'-'.$maxVersion).'"';

        if ($request->header('If-None-Match') === $eTag) {
            return response()->noContent(304);
        }

        $tickets = Ticket::whereIn('status', ['active', 'partially_admitted', 'fully_admitted'])->get();

        /** @var JsonResponse $response */
        $response = ManifestEntryResource::collection($tickets)->response();

        return $response->setEtag($eTag);
    }
}
