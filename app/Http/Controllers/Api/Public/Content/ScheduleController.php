<?php

namespace App\Http\Controllers\Api\Public\Content;

use App\Domain\Content\Models\ScheduleItem;
use App\Http\Concerns\RespondsWithEtag;
use App\Http\Concerns\ServesLocalisedContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\Content\ScheduleItemResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Content')]
class ScheduleController extends Controller
{
    use RespondsWithEtag, ServesLocalisedContent;

    #[OAT\Get(
        path: '/public/content/schedule',
        summary: 'List published schedule items in chronological order',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'track', in: 'query', description: 'Filter to a single track', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'locale', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Published schedule items',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'array',
                                items: new OAT\Items(
                                    properties: [
                                        new OAT\Property(property: 'ulid', type: 'string'),
                                        new OAT\Property(property: 'title', type: 'string'),
                                        new OAT\Property(property: 'description', type: 'string', nullable: true),
                                        new OAT\Property(property: 'speaker_name', type: 'string', nullable: true),
                                        new OAT\Property(property: 'speaker_title', type: 'string', nullable: true),
                                        new OAT\Property(property: 'speaker_photo', type: 'object', nullable: true),
                                        new OAT\Property(property: 'venue', type: 'string', nullable: true),
                                        new OAT\Property(property: 'track', type: 'string', nullable: true),
                                        new OAT\Property(property: 'starts_at', type: 'string', format: 'date-time'),
                                        new OAT\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'position', type: 'integer'),
                                    ],
                                    type: 'object'
                                )
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 304, description: 'Not modified'),
        ]
    )]
    public function index(Request $request): Response
    {
        $this->stashLocale($request);

        $query = ScheduleItem::query()->published()->with('speakerPhoto');

        $track = $request->query('track');

        if (is_string($track) && $track !== '') {
            $query->where('track', $track);
        }

        $items = $query->orderBy('starts_at')->orderBy('position')->orderBy('id')->get();

        return $this->withPublicCache($request, ScheduleItemResource::collection($items)->response($request));
    }
}
