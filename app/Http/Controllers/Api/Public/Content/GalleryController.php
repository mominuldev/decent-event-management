<?php

namespace App\Http\Controllers\Api\Public\Content;

use App\Domain\Content\Models\GalleryAlbum;
use App\Http\Concerns\RespondsWithEtag;
use App\Http\Concerns\ServesLocalisedContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\Content\GalleryAlbumResource;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Content')]
class GalleryController extends Controller
{
    use RespondsWithEtag, ServesLocalisedContent;

    #[OAT\Get(
        path: '/public/content/gallery',
        summary: 'List published gallery albums with their cover image',
        description: 'Album items are omitted here — fetch a single album to get them.',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'locale', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Published albums',
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
                                        new OAT\Property(property: 'slug', type: 'string'),
                                        new OAT\Property(property: 'title', type: 'string'),
                                        new OAT\Property(property: 'description', type: 'string', nullable: true),
                                        new OAT\Property(property: 'cover', type: 'object', nullable: true),
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

        $albums = GalleryAlbum::query()
            ->published()
            ->with('cover')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return $this->withPublicCache($request, GalleryAlbumResource::collection($albums)->response($request));
    }

    #[OAT\Get(
        path: '/public/content/gallery/{slug}',
        summary: 'Fetch one published gallery album with its published items',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'slug', in: 'path', required: true, schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'locale', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'The album and its published items'),
            new OAT\Response(response: 304, description: 'Not modified'),
            new OAT\Response(response: 404, description: 'No published album with that slug'),
        ]
    )]
    public function show(Request $request, string $slug): Response
    {
        $this->stashLocale($request);

        $album = GalleryAlbum::query()
            ->published()
            ->where('slug', $slug)
            ->with([
                'cover',
                'items' => fn (Builder $query) => $query->where('is_published', true),
                'items.media',
            ])
            ->first();

        if ($album === null) {
            return $this->contentNotFound($request);
        }

        return $this->withPublicCache($request, GalleryAlbumResource::make($album)->response($request));
    }
}
