<?php

namespace App\Http\Controllers\Api\Public\Content;

use App\Domain\Content\Models\Sponsor;
use App\Http\Concerns\RespondsWithEtag;
use App\Http\Concerns\ServesLocalisedContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\Content\SponsorResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Content')]
class SponsorController extends Controller
{
    use RespondsWithEtag, ServesLocalisedContent;

    #[OAT\Get(
        path: '/public/content/sponsors',
        summary: 'List published sponsors, ordered by tier then position',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'tier', in: 'query', description: 'Filter to a single tier', schema: new OAT\Schema(type: 'string', enum: ['platinum', 'gold', 'silver', 'bronze', 'partner'])),
            new OAT\Parameter(name: 'locale', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Published sponsors',
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
                                        new OAT\Property(property: 'name', type: 'string'),
                                        new OAT\Property(property: 'tier', type: 'string'),
                                        new OAT\Property(property: 'website_url', type: 'string', nullable: true),
                                        new OAT\Property(property: 'description', type: 'string', nullable: true),
                                        new OAT\Property(property: 'logo', type: 'object', nullable: true),
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

        $query = Sponsor::query()->published()->with('logo');

        $tier = $request->query('tier');

        if (is_string($tier) && in_array($tier, Sponsor::TIERS, true)) {
            $query->where('tier', $tier);
        }

        // Tier order lives in Sponsor::TIERS, not the column, so sorting is
        // done in PHP against that list rather than with a fragile SQL FIELD().
        $sponsors = $query->get()
            ->sortBy([
                fn (Sponsor $a, Sponsor $b): int => $a->tierRank() <=> $b->tierRank(),
                fn (Sponsor $a, Sponsor $b): int => $a->position <=> $b->position,
                fn (Sponsor $a, Sponsor $b): int => $a->id <=> $b->id,
            ])
            ->values();

        return $this->withPublicCache($request, SponsorResource::collection($sponsors)->response($request));
    }
}
