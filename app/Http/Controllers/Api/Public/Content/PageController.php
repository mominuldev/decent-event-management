<?php

namespace App\Http\Controllers\Api\Public\Content;

use App\Domain\Content\Models\ContentPage;
use App\Http\Concerns\RespondsWithEtag;
use App\Http\Concerns\ServesLocalisedContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\Content\ContentPageResource;
use App\Http\Resources\Public\Content\ContentPageSummaryResource;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Content')]
class PageController extends Controller
{
    use RespondsWithEtag, ServesLocalisedContent;

    #[OAT\Get(
        path: '/public/content/pages',
        summary: 'List live content pages (slug, title, excerpt) for sitemaps and link lists',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'locale', in: 'query', description: 'Content locale; overrides Accept-Language. Falls back to English per field when a Bangla value is empty.', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', description: 'Conditional request; a matching ETag returns 304', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Live pages',
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
                                        new OAT\Property(property: 'template', type: 'string'),
                                        new OAT\Property(property: 'locale', type: 'string'),
                                        new OAT\Property(property: 'title', type: 'string'),
                                        new OAT\Property(property: 'excerpt', type: 'string', nullable: true),
                                        new OAT\Property(property: 'is_indexable', type: 'boolean'),
                                        new OAT\Property(property: 'published_at', type: 'string', format: 'date-time'),
                                        new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time'),
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

        $pages = ContentPage::query()->live()->orderBy('position')->orderBy('id')->get();

        return $this->withPublicCache(
            $request,
            ContentPageSummaryResource::collection($pages)->response($request)
        );
    }

    #[OAT\Get(
        path: '/public/content/pages/{slug}',
        summary: 'Fetch one content page with its typed block tree',
        description: 'Returns only live pages — published, with a `published_at` in the past. Unpublished, scheduled, archived and soft-deleted pages return 404 (never 403), unless a valid `preview_token` is supplied. Preview responses are `no-store` and `noindex`.',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'slug', in: 'path', required: true, schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'locale', in: 'query', description: 'Content locale; overrides Accept-Language', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'preview_token', in: 'query', description: 'Reveals an unpublished page to a reviewer', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', description: 'Conditional request; a matching ETag returns 304', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'The page and its visible blocks',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'slug', type: 'string'),
                                    new OAT\Property(property: 'template', type: 'string'),
                                    new OAT\Property(property: 'locale', type: 'string'),
                                    new OAT\Property(property: 'title', type: 'string'),
                                    new OAT\Property(property: 'excerpt', type: 'string', nullable: true),
                                    new OAT\Property(property: 'seo_title', type: 'string', nullable: true),
                                    new OAT\Property(property: 'seo_description', type: 'string', nullable: true),
                                    new OAT\Property(property: 'og_image', type: 'object', nullable: true),
                                    new OAT\Property(property: 'is_indexable', type: 'boolean'),
                                    new OAT\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(
                                        property: 'blocks',
                                        type: 'array',
                                        items: new OAT\Items(
                                            properties: [
                                                new OAT\Property(property: 'ulid', type: 'string'),
                                                new OAT\Property(property: 'type', type: 'string', enum: ['rich_text', 'hero', 'image', 'cta', 'stat_row', 'faq_list', 'sponsor_grid', 'schedule', 'gallery', 'video']),
                                                new OAT\Property(property: 'position', type: 'integer'),
                                                new OAT\Property(property: 'data', type: 'object', description: 'Localised block payload; shape is fixed by `type`'),
                                                new OAT\Property(property: 'media', type: 'object', nullable: true),
                                            ],
                                            type: 'object'
                                        )
                                    ),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 304, description: 'Not modified'),
            new OAT\Response(response: 404, description: 'No live page with that slug, and no valid preview token'),
        ]
    )]
    public function show(Request $request, string $slug): Response
    {
        $this->stashLocale($request);

        $page = ContentPage::query()
            ->where('slug', $slug)
            ->with([
                'ogImage',
                'blocks' => fn (Builder $query) => $query->where('is_visible', true),
                'blocks.media',
            ])
            ->first();

        if ($page === null) {
            return $this->contentNotFound($request);
        }

        if ($page->isLive()) {
            return $this->withPublicCache($request, ContentPageResource::make($page)->response($request));
        }

        if (! $this->previewTokenMatches($request, $page)) {
            return $this->contentNotFound($request);
        }

        return $this->withPreviewHeaders(ContentPageResource::make($page)->response($request));
    }

    /**
     * Constant-time comparison, so a caller cannot recover a valid token one
     * character at a time from response timing.
     */
    private function previewTokenMatches(Request $request, ContentPage $page): bool
    {
        $supplied = $request->query('preview_token');
        $expected = $page->preview_token;

        if (! is_string($supplied) || $supplied === '' || ! is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $supplied);
    }
}
