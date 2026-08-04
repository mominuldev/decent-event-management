<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\SaveContentResource;
use App\Domain\Content\Models\GalleryAlbum;
use App\Domain\Content\Models\GalleryItem;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Api\Admin\Content\Concerns\ResolvesContentReferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\GalleryItemRequest;
use App\Http\Resources\Admin\Content\AdminGalleryItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

/**
 * Items are always addressed through their album, so a mismatched pair is a
 * 404 rather than a silent cross-album edit.
 */
#[OAT\Tag(name: 'CMS Gallery')]
class GalleryItemController extends Controller
{
    use ResolvesContentReferences, ResolvesRequestContext;

    #[OAT\Post(
        path: '/admin/content/gallery/{album}/items',
        summary: 'Add a picture to an album',
        tags: ['CMS Gallery'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'album', description: 'Album ULID', schema: new OAT\Schema(type: 'string'))],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['media_ulid'],
                    properties: [
                        new OAT\Property(property: 'media_ulid', type: 'string', description: 'A media library file, uploaded via /admin/content/media'),
                        new OAT\Property(property: 'caption', type: 'string', nullable: true),
                        new OAT\Property(property: 'alt_text', type: 'string', nullable: true),
                        new OAT\Property(property: 'position', type: 'integer'),
                        new OAT\Property(property: 'is_published', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Item added'),
            new OAT\Response(response: 403, description: 'Missing content.create permission'),
            new OAT\Response(response: 404, description: 'Album not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(GalleryItemRequest $request, GalleryAlbum $album, SaveContentResource $saveContentResource): JsonResponse
    {
        $attributes = $this->resolveMediaUlid($request->validated(), 'media_ulid', 'media_id');
        $attributes['gallery_album_id'] = $album->id;

        $item = $saveContentResource->execute(
            new GalleryItem,
            $attributes,
            $this->actor($request),
            'gallery_item',
            $request->ip(),
            $this->requestId($request),
        );

        return (new AdminGalleryItemResource($item->load('media')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Patch(
        path: '/admin/content/gallery/{album}/items/{item}',
        summary: 'Update a gallery item',
        tags: ['CMS Gallery'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'album', description: 'Album ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\PathParameter(name: 'item', description: 'Item ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(required: false, content: new OAT\MediaType(mediaType: 'application/json', schema: new OAT\Schema(type: 'object'))),
        responses: [
            new OAT\Response(response: 200, description: 'Item updated'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Item not found in that album'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(
        GalleryItemRequest $request,
        GalleryAlbum $album,
        GalleryItem $item,
        SaveContentResource $saveContentResource,
    ): AdminGalleryItemResource {
        abort_unless($item->gallery_album_id === $album->id, Response::HTTP_NOT_FOUND);

        $saveContentResource->execute(
            $item,
            $this->resolveMediaUlid($request->validated(), 'media_ulid', 'media_id'),
            $this->actor($request),
            'gallery_item',
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminGalleryItemResource($item->load('media'));
    }

    #[OAT\Delete(
        path: '/admin/content/gallery/{album}/items/{item}',
        summary: 'Remove a picture from an album',
        description: 'Super-Admin-only (`content.delete`). The media file itself stays in the library.',
        tags: ['CMS Gallery'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'album', description: 'Album ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\PathParameter(name: 'item', description: 'Item ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 204, description: 'Item removed'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Item not found in that album'),
        ]
    )]
    public function destroy(Request $request, GalleryAlbum $album, GalleryItem $item, SaveContentResource $saveContentResource): Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);
        abort_unless($item->gallery_album_id === $album->id, Response::HTTP_NOT_FOUND);

        $saveContentResource->delete($item, $this->actor($request), 'gallery_item', $request->ip(), $this->requestId($request));

        return response()->noContent();
    }
}
