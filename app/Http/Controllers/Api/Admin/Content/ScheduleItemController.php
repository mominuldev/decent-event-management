<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\SaveContentResource;
use App\Domain\Content\Models\ScheduleItem;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Api\Admin\Content\Concerns\ResolvesContentReferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\ScheduleItemRequest;
use App\Http\Resources\Admin\Content\AdminScheduleItemResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'CMS Schedule')]
class ScheduleItemController extends Controller
{
    use ResolvesContentReferences, ResolvesRequestContext;

    #[OAT\Get(
        path: '/admin/content/schedule',
        summary: 'List schedule items',
        tags: ['CMS Schedule'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'track', in: 'query', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'is_published', in: 'query', schema: new OAT\Schema(type: 'boolean')),
            new OAT\Parameter(name: 'per_page', in: 'query', schema: new OAT\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated schedule items, chronological'),
            new OAT\Response(response: 403, description: 'Missing content.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('content.view_any'), Response::HTTP_FORBIDDEN);

        $query = ScheduleItem::query()->with('speakerPhoto');

        if ($request->filled('track')) {
            $query->where('track', (string) $request->input('track'));
        }

        if ($request->filled('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        return AdminScheduleItemResource::collection(
            $query->orderBy('starts_at')->orderBy('position')->paginate(min((int) $request->input('per_page', 50), 100))
        );
    }

    #[OAT\Post(
        path: '/admin/content/schedule',
        summary: 'Create a schedule item',
        tags: ['CMS Schedule'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['title', 'starts_at'],
                    properties: [
                        new OAT\Property(property: 'title', type: 'string'),
                        new OAT\Property(property: 'title_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'speaker_name', type: 'string', nullable: true),
                        new OAT\Property(property: 'speaker_photo_media_ulid', type: 'string', nullable: true),
                        new OAT\Property(property: 'venue', type: 'string', nullable: true),
                        new OAT\Property(property: 'track', type: 'string', nullable: true),
                        new OAT\Property(property: 'starts_at', type: 'string', format: 'date-time'),
                        new OAT\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
                        new OAT\Property(property: 'event_session_code', type: 'string', nullable: true, description: 'Soft reference to event_sessions.code — deliberately not a foreign key'),
                        new OAT\Property(property: 'is_published', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Schedule item created'),
            new OAT\Response(response: 403, description: 'Missing content.create permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(ScheduleItemRequest $request, SaveContentResource $saveContentResource): JsonResponse
    {
        $item = $saveContentResource->execute(
            new ScheduleItem,
            $this->resolveMediaUlid($request->validated(), 'speaker_photo_media_ulid', 'speaker_photo_media_id'),
            $this->actor($request),
            'schedule_item',
            $request->ip(),
            $this->requestId($request),
        );

        return (new AdminScheduleItemResource($item->load('speakerPhoto')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/content/schedule/{schedule_item}',
        summary: 'Fetch one schedule item',
        tags: ['CMS Schedule'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'schedule_item', description: 'Schedule item ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 200, description: 'Schedule item detail'),
            new OAT\Response(response: 403, description: 'Missing content.view permission'),
            new OAT\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, ScheduleItem $scheduleItem): AdminScheduleItemResource
    {
        abort_unless((bool) $request->user()?->can('content.view'), Response::HTTP_FORBIDDEN);

        return new AdminScheduleItemResource($scheduleItem->load('speakerPhoto'));
    }

    #[OAT\Patch(
        path: '/admin/content/schedule/{schedule_item}',
        summary: 'Update a schedule item',
        tags: ['CMS Schedule'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'schedule_item', description: 'Schedule item ULID', schema: new OAT\Schema(type: 'string'))],
        requestBody: new OAT\RequestBody(required: false, content: new OAT\MediaType(mediaType: 'application/json', schema: new OAT\Schema(type: 'object'))),
        responses: [
            new OAT\Response(response: 200, description: 'Schedule item updated'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(ScheduleItemRequest $request, ScheduleItem $scheduleItem, SaveContentResource $saveContentResource): AdminScheduleItemResource
    {
        $saveContentResource->execute(
            $scheduleItem,
            $this->resolveMediaUlid($request->validated(), 'speaker_photo_media_ulid', 'speaker_photo_media_id'),
            $this->actor($request),
            'schedule_item',
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminScheduleItemResource($scheduleItem->load('speakerPhoto'));
    }

    #[OAT\Delete(
        path: '/admin/content/schedule/{schedule_item}',
        summary: 'Delete a schedule item',
        description: 'Super-Admin-only (`content.delete`).',
        tags: ['CMS Schedule'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'schedule_item', description: 'Schedule item ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 204, description: 'Schedule item deleted'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, ScheduleItem $scheduleItem, SaveContentResource $saveContentResource): Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);

        $saveContentResource->delete($scheduleItem, $this->actor($request), 'schedule_item', $request->ip(), $this->requestId($request));

        return response()->noContent();
    }
}
