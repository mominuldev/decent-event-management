<?php

namespace App\Http\Controllers\Api\Public\Content;

use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;
use App\Http\Concerns\RespondsWithEtag;
use App\Http\Concerns\ServesLocalisedContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\Content\MenuResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Content')]
class MenuController extends Controller
{
    use RespondsWithEtag, ServesLocalisedContent;

    /**
     * Items are eager-loaded with their linked page so
     * {@see MenuItem::resolvedUrl()} can drop
     * entries pointing at pages that are no longer live, without a query
     * per item.
     *
     * @var list<string>
     */
    private const array EAGER_LOADS = ['items', 'items.page'];

    #[OAT\Get(
        path: '/public/content/menus',
        summary: 'List active navigation menus with their nested items',
        description: 'Items linked to a page that is not live are omitted, so the public site never renders a link into a 404.',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'locale', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Active menus',
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
                                        new OAT\Property(property: 'code', type: 'string'),
                                        new OAT\Property(property: 'name', type: 'string'),
                                        new OAT\Property(
                                            property: 'items',
                                            type: 'array',
                                            items: new OAT\Items(
                                                properties: [
                                                    new OAT\Property(property: 'ulid', type: 'string'),
                                                    new OAT\Property(property: 'label', type: 'string'),
                                                    new OAT\Property(property: 'url', type: 'string'),
                                                    new OAT\Property(property: 'target', type: 'string'),
                                                    new OAT\Property(property: 'position', type: 'integer'),
                                                    new OAT\Property(property: 'children', type: 'array', items: new OAT\Items(type: 'object')),
                                                ],
                                                type: 'object'
                                            )
                                        ),
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

        $menus = Menu::query()
            ->where('is_active', true)
            ->with(self::EAGER_LOADS)
            ->orderBy('code')
            ->get();

        return $this->withPublicCache($request, MenuResource::collection($menus)->response($request));
    }

    #[OAT\Get(
        path: '/public/content/menus/{code}',
        summary: 'Fetch one navigation menu by its stable code (primary, footer)',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'code', in: 'path', required: true, schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'locale', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'The menu and its nested items'),
            new OAT\Response(response: 304, description: 'Not modified'),
            new OAT\Response(response: 404, description: 'No active menu with that code'),
        ]
    )]
    public function show(Request $request, string $code): Response
    {
        $this->stashLocale($request);

        $menu = Menu::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->with(self::EAGER_LOADS)
            ->first();

        if ($menu === null) {
            return $this->contentNotFound($request);
        }

        return $this->withPublicCache($request, MenuResource::make($menu)->response($request));
    }
}
