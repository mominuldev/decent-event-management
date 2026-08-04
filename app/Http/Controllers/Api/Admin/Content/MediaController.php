<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\UploadContentMedia;
use App\Domain\Content\Models\GalleryItem;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\MediaFile;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\UploadMediaRequest;
use App\Http\Resources\MediaFileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

/**
 * The media library behind every image picker in the CMS — and the upload
 * endpoint D9 flagged as missing.
 */
#[OAT\Tag(name: 'CMS Media')]
class MediaController extends Controller
{
    use ResolvesRequestContext;

    #[OAT\Get(
        path: '/admin/content/media',
        summary: 'Browse the CMS media library',
        description: 'Scoped to the CMS collections only — payment proofs and ticket PDFs live in the same table but are never listed here.',
        tags: ['CMS Media'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'collection', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['content', 'page_og', 'sponsor_logo', 'speaker_photo', 'gallery'])),
            new OAT\Parameter(name: 'per_page', in: 'query', schema: new OAT\Schema(type: 'integer', default: 40)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated media, newest first'),
            new OAT\Response(response: 403, description: 'Missing content.manage_media permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('content.manage_media'), Response::HTTP_FORBIDDEN);

        $query = MediaFile::query()->whereIn('collection', UploadContentMedia::COLLECTIONS);

        if ($request->filled('collection')) {
            $collection = (string) $request->input('collection');
            // Filtering by a non-CMS collection must narrow, never widen.
            abort_unless(in_array($collection, UploadContentMedia::COLLECTIONS, true), Response::HTTP_UNPROCESSABLE_ENTITY);
            $query->where('collection', $collection);
        }

        return MediaFileResource::collection(
            $query->orderByDesc('id')->paginate(min((int) $request->input('per_page', 40), 100))
        );
    }

    #[OAT\Post(
        path: '/admin/content/media',
        summary: 'Upload an image to the media library',
        description: 'Multipart. The file’s type is decided by its magic bytes — the extension and the client `Content-Type` are ignored — and the image is fully re-encoded, which strips EXIF/GPS and anything smuggled into the container. JPEG, PNG and WebP only; SVG is refused because no re-encode makes it safe to serve from our origin.',
        tags: ['CMS Media'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OAT\Schema(
                    required: ['file'],
                    properties: [
                        new OAT\Property(property: 'file', type: 'string', format: 'binary', description: 'Max 8 MB'),
                        new OAT\Property(property: 'collection', type: 'string', enum: ['content', 'page_og', 'sponsor_logo', 'speaker_photo', 'gallery'], default: 'content'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'File stored; returns its ULID and public URL'),
            new OAT\Response(response: 403, description: 'Missing content.manage_media permission'),
            new OAT\Response(
                response: 422,
                description: 'Rejected by size, or by what the bytes actually are',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'unsupported_media'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function store(UploadMediaRequest $request, UploadContentMedia $uploadContentMedia): JsonResponse
    {
        $file = $request->file('file');

        abort_unless($file instanceof UploadedFile, Response::HTTP_UNPROCESSABLE_ENTITY);

        try {
            $media = $uploadContentMedia->execute(
                $file,
                (string) $request->input('collection', 'content'),
                $this->actor($request),
                $request->ip(),
                $this->requestId($request),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 'unsupported_media',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return (new MediaFileResource($media))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Delete(
        path: '/admin/content/media/{media}',
        summary: 'Remove a file from the media library',
        description: 'Super-Admin-only (`content.delete`), and refused while a gallery item still points at the file — otherwise the album would render a hole.',
        tags: ['CMS Media'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'media', description: 'Media ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 204, description: 'File removed'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Not found'),
            new OAT\Response(response: 422, description: 'Still referenced by a gallery item'),
        ]
    )]
    public function destroy(Request $request, MediaFile $media): JsonResponse|Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);

        // Only CMS media is reachable here; a payment proof must not be
        // deletable through the content API.
        abort_unless(in_array($media->collection, UploadContentMedia::COLLECTIONS, true), Response::HTTP_NOT_FOUND);

        if (GalleryItem::query()->where('media_id', $media->id)->exists()) {
            return response()->json([
                'code' => 'deletion_prevented',
                'message' => 'Remove this image from its gallery album before deleting it.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Soft delete: page and sponsor references are nullable and resolve
        // to null once the row is gone, and the stored blob stays recoverable.
        $media->delete();

        ActivityLog::create([
            'log_name' => 'content',
            'event' => 'media_deleted',
            'description' => "Deleted media {$media->ulid}",
            'causer_type' => $this->actor($request)->getMorphClass(),
            'causer_id' => $this->actor($request)->id,
            'subject_type' => $media->getMorphClass(),
            'subject_id' => $media->id,
            'properties' => ['collection' => $media->collection, 'original_name' => $media->original_name],
            'ip_address' => $request->ip(),
            'request_id' => $this->requestId($request),
        ]);

        return response()->noContent();
    }
}
