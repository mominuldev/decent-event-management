<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\SaveContentResource;
use App\Domain\Content\Models\Menu;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\MenuRequest;
use App\Http\Resources\Admin\Content\AdminMenuResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'CMS Menus')]
class MenuController extends Controller
{
    use ResolvesRequestContext;

    #[OAT\Get(
        path: '/admin/content/menus',
        summary: 'List navigation menus with their full item trees',
        description: 'Not paginated: there are a handful of regions and the editor reorders the whole tree at once.',
        tags: ['CMS Menus'],
        security: [['bearerAuth' => []]],
        responses: [
            new OAT\Response(response: 200, description: 'Menus, each with a nested `items` tree'),
            new OAT\Response(response: 403, description: 'Missing content.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('content.view_any'), Response::HTTP_FORBIDDEN);

        return AdminMenuResource::collection(
            Menu::query()->with(['items', 'items.page'])->orderBy('code')->get()
        );
    }

    #[OAT\Post(
        path: '/admin/content/menus',
        summary: 'Create a navigation menu',
        tags: ['CMS Menus'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['code', 'name'],
                    properties: [
                        new OAT\Property(property: 'code', type: 'string', description: 'Stable region key the public site fetches by, e.g. `primary`'),
                        new OAT\Property(property: 'name', type: 'string'),
                        new OAT\Property(property: 'name_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'is_active', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Menu created'),
            new OAT\Response(response: 403, description: 'Missing content.create permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(MenuRequest $request, SaveContentResource $saveContentResource): JsonResponse
    {
        $menu = $saveContentResource->execute(
            new Menu,
            $request->validated(),
            $this->actor($request),
            'menu',
            $request->ip(),
            $this->requestId($request),
        );

        return (new AdminMenuResource($menu->load(['items', 'items.page'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/content/menus/{menu}',
        summary: 'Fetch one menu with its item tree',
        tags: ['CMS Menus'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'menu', description: 'Menu ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 200, description: 'Menu detail'),
            new OAT\Response(response: 403, description: 'Missing content.view permission'),
            new OAT\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, Menu $menu): AdminMenuResource
    {
        abort_unless((bool) $request->user()?->can('content.view'), Response::HTTP_FORBIDDEN);

        return new AdminMenuResource($menu->load(['items', 'items.page']));
    }

    #[OAT\Patch(
        path: '/admin/content/menus/{menu}',
        summary: 'Update a menu',
        tags: ['CMS Menus'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'menu', description: 'Menu ULID', schema: new OAT\Schema(type: 'string'))],
        requestBody: new OAT\RequestBody(required: false, content: new OAT\MediaType(mediaType: 'application/json', schema: new OAT\Schema(type: 'object'))),
        responses: [
            new OAT\Response(response: 200, description: 'Menu updated'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(MenuRequest $request, Menu $menu, SaveContentResource $saveContentResource): AdminMenuResource
    {
        $saveContentResource->execute(
            $menu,
            $request->validated(),
            $this->actor($request),
            'menu',
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminMenuResource($menu->load(['items', 'items.page']));
    }

    #[OAT\Delete(
        path: '/admin/content/menus/{menu}',
        summary: 'Delete a menu and its items',
        description: 'Super-Admin-only (`content.delete`).',
        tags: ['CMS Menus'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'menu', description: 'Menu ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 204, description: 'Menu deleted'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, Menu $menu, SaveContentResource $saveContentResource): Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);

        $saveContentResource->delete($menu, $this->actor($request), 'menu', $request->ip(), $this->requestId($request));

        return response()->noContent();
    }
}
