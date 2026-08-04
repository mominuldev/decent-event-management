<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\SaveContentResource;
use App\Domain\Content\Models\GalleryAlbum;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Api\Admin\Content\Concerns\ResolvesContentReferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\GalleryAlbumRequest;
use App\Http\Resources\Admin\Content\AdminGalleryAlbumResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'CMS Gallery')]
class GalleryAlbumController extends Controller
{
    use ResolvesContentReferences, ResolvesRequestContext;

    #[OAT\Get(
        path: '/admin/content/gallery',
        summary: 'List gallery albums',
        tags: ['CMS Gallery'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'is_published', in: 'query', schema: new OAT\Schema(type: 'boolean')),
            new OAT\Parameter(name: 'per_page', in: 'query', schema: new OAT\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated albums with item counts'),
            new OAT\Response(response: 403, description: 'Missing content.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('content.view_any'), Response::HTTP_FORBIDDEN);

        $query = GalleryAlbum::query()->with('cover')->withCount('items');

        if ($request->filled('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        return AdminGalleryAlbumResource::collection(
            $query->orderBy('position')->orderBy('id')->paginate(min((int) $request->input('per_page', 50), 100))
        );
    }

    #[OAT\Post(
        path: '/admin/content/gallery',
        summary: 'Create a gallery album',
        tags: ['CMS Gallery'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['slug', 'title'],
                    properties: [
                        new OAT\Property(property: 'slug', type: 'string'),
                        new OAT\Property(property: 'title', type: 'string'),
                        new OAT\Property(property: 'title_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'description', type: 'string', nullable: true),
                        new OAT\Property(property: 'cover_media_ulid', type: 'string', nullable: true),
                        new OAT\Property(property: 'position', type: 'integer'),
                        new OAT\Property(property: 'is_published', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Album created'),
            new OAT\Response(response: 403, description: 'Missing content.create permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(GalleryAlbumRequest $request, SaveContentResource $saveContentResource): JsonResponse
    {
        $album = $saveContentResource->execute(
            new GalleryAlbum,
            $this->resolveMediaUlid($request->validated(), 'cover_media_ulid', 'cover_media_id'),
            $this->actor($request),
            'gallery_album',
            $request->ip(),
            $this->requestId($request),
        );

        return (new AdminGalleryAlbumResource($album->load('cover')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/content/gallery/{album}',
        summary: 'Fetch one album with its items',
        tags: ['CMS Gallery'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'album', description: 'Album ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 200, description: 'Album detail including every item, published or not'),
            new OAT\Response(response: 403, description: 'Missing content.view permission'),
            new OAT\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, GalleryAlbum $album): AdminGalleryAlbumResource
    {
        abort_unless((bool) $request->user()?->can('content.view'), Response::HTTP_FORBIDDEN);

        return new AdminGalleryAlbumResource($album->load(['cover', 'items', 'items.media']));
    }

    #[OAT\Patch(
        path: '/admin/content/gallery/{album}',
        summary: 'Update a gallery album',
        tags: ['CMS Gallery'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'album', description: 'Album ULID', schema: new OAT\Schema(type: 'string'))],
        requestBody: new OAT\RequestBody(required: false, content: new OAT\MediaType(mediaType: 'application/json', schema: new OAT\Schema(type: 'object'))),
        responses: [
            new OAT\Response(response: 200, description: 'Album updated'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(GalleryAlbumRequest $request, GalleryAlbum $album, SaveContentResource $saveContentResource): AdminGalleryAlbumResource
    {
        $saveContentResource->execute(
            $album,
            $this->resolveMediaUlid($request->validated(), 'cover_media_ulid', 'cover_media_id'),
            $this->actor($request),
            'gallery_album',
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminGalleryAlbumResource($album->load(['cover', 'items', 'items.media']));
    }

    #[OAT\Delete(
        path: '/admin/content/gallery/{album}',
        summary: 'Delete a gallery album and its items',
        description: 'Super-Admin-only (`content.delete`). Items cascade with the album; the underlying media files are not removed, since they may be referenced elsewhere.',
        tags: ['CMS Gallery'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'album', description: 'Album ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 204, description: 'Album deleted'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, GalleryAlbum $album, SaveContentResource $saveContentResource): Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);

        $saveContentResource->delete($album, $this->actor($request), 'gallery_album', $request->ip(), $this->requestId($request));

        return response()->noContent();
    }
}
