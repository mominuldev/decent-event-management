<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\SaveContentResource;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Api\Admin\Content\Concerns\ResolvesContentReferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\MenuItemRequest;
use App\Http\Resources\Admin\Content\AdminMenuItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'CMS Menus')]
class MenuItemController extends Controller
{
    use ResolvesContentReferences, ResolvesRequestContext;

    #[OAT\Post(
        path: '/admin/content/menus/{menu}/items',
        summary: 'Add an item to a menu',
        description: 'Link either to an internal page (`page_ulid`) or an external `url`, never both — an internal reference wins at render time, so accepting both would silently drop one.',
        tags: ['CMS Menus'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'menu', description: 'Menu ULID', schema: new OAT\Schema(type: 'string'))],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['label'],
                    properties: [
                        new OAT\Property(property: 'label', type: 'string'),
                        new OAT\Property(property: 'label_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'parent_ulid', type: 'string', nullable: true, description: 'Nests this item under another'),
                        new OAT\Property(property: 'page_ulid', type: 'string', nullable: true),
                        new OAT\Property(property: 'url', type: 'string', nullable: true),
                        new OAT\Property(property: 'target', type: 'string', enum: ['_self', '_blank']),
                        new OAT\Property(property: 'position', type: 'integer'),
                        new OAT\Property(property: 'is_visible', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Item added'),
            new OAT\Response(response: 403, description: 'Missing content.create permission'),
            new OAT\Response(response: 404, description: 'Menu not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(MenuItemRequest $request, Menu $menu, SaveContentResource $saveContentResource): JsonResponse
    {
        $attributes = $this->resolveAttributes($request->validated());
        $attributes['menu_id'] = $menu->id;

        $item = $saveContentResource->execute(
            new MenuItem,
            $attributes,
            $this->actor($request),
            'menu_item',
            $request->ip(),
            $this->requestId($request),
        );

        return (new AdminMenuItemResource($item->load('page')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Patch(
        path: '/admin/content/menus/{menu}/items/{item}',
        summary: 'Update a menu item',
        tags: ['CMS Menus'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'menu', description: 'Menu ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\PathParameter(name: 'item', description: 'Menu item ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(required: false, content: new OAT\MediaType(mediaType: 'application/json', schema: new OAT\Schema(type: 'object'))),
        responses: [
            new OAT\Response(response: 200, description: 'Item updated'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Item not found in that menu'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(MenuItemRequest $request, Menu $menu, MenuItem $item, SaveContentResource $saveContentResource): AdminMenuItemResource
    {
        abort_unless($item->menu_id === $menu->id, Response::HTTP_NOT_FOUND);

        $saveContentResource->execute(
            $item,
            $this->resolveAttributes($request->validated()),
            $this->actor($request),
            'menu_item',
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminMenuItemResource($item->load('page'));
    }

    #[OAT\Delete(
        path: '/admin/content/menus/{menu}/items/{item}',
        summary: 'Remove a menu item and everything nested under it',
        description: 'Super-Admin-only (`content.delete`). Children cascade with the parent.',
        tags: ['CMS Menus'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'menu', description: 'Menu ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\PathParameter(name: 'item', description: 'Menu item ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 204, description: 'Item removed'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Item not found in that menu'),
        ]
    )]
    public function destroy(Request $request, Menu $menu, MenuItem $item, SaveContentResource $saveContentResource): Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);
        abort_unless($item->menu_id === $menu->id, Response::HTTP_NOT_FOUND);

        $saveContentResource->delete($item, $this->actor($request), 'menu_item', $request->ip(), $this->requestId($request));

        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function resolveAttributes(array $validated): array
    {
        $validated = $this->resolveUlid($validated, 'parent_ulid', 'parent_id', MenuItem::class);

        return $this->resolveUlid($validated, 'page_ulid', 'content_page_id', ContentPage::class);
    }
}
