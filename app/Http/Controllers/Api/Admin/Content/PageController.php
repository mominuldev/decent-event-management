<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\ChangeContentPageStatus;
use App\Domain\Content\Actions\DeleteContentPage;
use App\Domain\Content\Actions\IssuePagePreviewToken;
use App\Domain\Content\Actions\RestoreContentPageRevision;
use App\Domain\Content\Actions\SaveContentPage;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\ContentPageRevision;
use App\Domain\Shared\Support\InvalidStateTransitionException;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\ChangeContentPageStatusRequest;
use App\Http\Requests\Admin\Content\ContentPageRequest;
use App\Http\Resources\Admin\Content\AdminContentPageResource;
use App\Http\Resources\Admin\Content\AdminContentPageSummaryResource;
use App\Http\Resources\Admin\Content\ContentPageRevisionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CMS page workspace. Editing, workflow and history are separate
 * endpoints on purpose — saving carries `content.update`, publishing carries
 * `content.publish`, and the two are held by different people in a real
 * editorial team.
 */
#[OAT\Tag(name: 'CMS Pages')]
class PageController extends Controller
{
    use ResolvesRequestContext;

    #[OAT\Get(
        path: '/admin/content/pages',
        summary: 'List content pages for the CMS',
        description: 'Unlike the public endpoint this returns every page regardless of status, including scheduled and archived ones.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'status', in: 'query', description: 'Filter by workflow status', schema: new OAT\Schema(type: 'string', enum: ['draft', 'in_review', 'published', 'archived'])),
            new OAT\Parameter(name: 'template', in: 'query', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'q', in: 'query', description: 'Match against slug or title (either language)', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'per_page', in: 'query', description: 'Results per page, capped at 100', schema: new OAT\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Paginated pages',
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
                                        new OAT\Property(property: 'title_bn', type: 'string', nullable: true),
                                        new OAT\Property(property: 'status', type: 'string'),
                                        new OAT\Property(property: 'is_live', type: 'boolean'),
                                        new OAT\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'revision_number', type: 'integer'),
                                        new OAT\Property(property: 'has_preview_token', type: 'boolean'),
                                    ],
                                    type: 'object'
                                )
                            ),
                            new OAT\Property(property: 'links', type: 'object'),
                            new OAT\Property(property: 'meta', type: 'object'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing content.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('content.view_any'), Response::HTTP_FORBIDDEN);

        $query = ContentPage::query()->with('updatedBy');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('template')) {
            $query->where('template', (string) $request->input('template'));
        }

        if ($request->filled('q')) {
            $term = '%'.(string) $request->input('q').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('slug', 'like', $term)
                    ->orWhere('title', 'like', $term)
                    ->orWhere('title_bn', 'like', $term);
            });
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        return AdminContentPageSummaryResource::collection(
            $query->orderBy('position')->orderByDesc('id')->paginate($perPage)
        );
    }

    #[OAT\Post(
        path: '/admin/content/pages',
        summary: 'Create a content page',
        description: 'Always created as a `draft`. Publishing is a separate call to `/status`.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['slug', 'title'],
                    properties: [
                        new OAT\Property(property: 'slug', type: 'string', description: 'Lowercase kebab-case; unique'),
                        new OAT\Property(property: 'title', type: 'string'),
                        new OAT\Property(property: 'title_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'template', type: 'string', enum: ['standard', 'landing', 'article', 'contact']),
                        new OAT\Property(property: 'excerpt', type: 'string', nullable: true),
                        new OAT\Property(property: 'seo_title', type: 'string', nullable: true),
                        new OAT\Property(property: 'seo_description', type: 'string', nullable: true),
                        new OAT\Property(property: 'og_image_media_ulid', type: 'string', nullable: true),
                        new OAT\Property(property: 'is_indexable', type: 'boolean'),
                        new OAT\Property(property: 'change_note', type: 'string', nullable: true, description: 'Recorded on the captured revision'),
                        new OAT\Property(
                            property: 'blocks',
                            type: 'array',
                            description: 'Replaces the page block tree in the order given; `position` is derived, never sent',
                            items: new OAT\Items(
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string', nullable: true, description: 'Present to update an existing block in place'),
                                    new OAT\Property(property: 'type', type: 'string', enum: ['rich_text', 'hero', 'image', 'cta', 'stat_row', 'faq_list', 'sponsor_grid', 'schedule', 'gallery', 'video']),
                                    new OAT\Property(property: 'data', type: 'object'),
                                    new OAT\Property(property: 'data_bn', type: 'object', nullable: true),
                                    new OAT\Property(property: 'media_ulid', type: 'string', nullable: true),
                                    new OAT\Property(property: 'is_visible', type: 'boolean'),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Page created as a draft, revision #1 captured'),
            new OAT\Response(response: 403, description: 'Missing content.create permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(ContentPageRequest $request, SaveContentPage $saveContentPage): JsonResponse
    {
        $validated = $request->validated();

        $page = $saveContentPage->execute(
            null,
            $validated,
            $this->actor($request),
            is_string($validated['change_note'] ?? null) ? $validated['change_note'] : null,
            $request->ip(),
            $this->requestId($request),
        );

        return (new AdminContentPageResource($this->loadPage($page)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/content/pages/{page}',
        summary: 'Fetch one content page with its full block tree',
        description: 'Returns both language halves and every block, including hidden ones — the editorial view, not the public one.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'page', description: 'Page ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Page detail'),
            new OAT\Response(response: 403, description: 'Missing content.view permission'),
            new OAT\Response(response: 404, description: 'Page not found'),
        ]
    )]
    public function show(Request $request, ContentPage $page): AdminContentPageResource
    {
        abort_unless((bool) $request->user()?->can('content.view'), Response::HTTP_FORBIDDEN);

        return new AdminContentPageResource($this->loadPage($page));
    }

    #[OAT\Patch(
        path: '/admin/content/pages/{page}',
        summary: 'Update a content page and capture a revision',
        description: 'Every save writes a new append-only revision. `status` is not accepted — use `/status`.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'page', description: 'Page ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: false,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'slug', type: 'string'),
                        new OAT\Property(property: 'title', type: 'string'),
                        new OAT\Property(property: 'title_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'change_note', type: 'string', nullable: true),
                        new OAT\Property(property: 'blocks', type: 'array', items: new OAT\Items(type: 'object'), description: 'Omit to leave the block tree untouched'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Page updated'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Page not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(ContentPageRequest $request, ContentPage $page, SaveContentPage $saveContentPage): AdminContentPageResource
    {
        $validated = $request->validated();

        $saved = $saveContentPage->execute(
            $page,
            $validated,
            $this->actor($request),
            is_string($validated['change_note'] ?? null) ? $validated['change_note'] : null,
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminContentPageResource($this->loadPage($saved));
    }

    #[OAT\Delete(
        path: '/admin/content/pages/{page}',
        summary: 'Soft-delete a content page',
        description: 'Super-Admin-only, matching every other `*.delete` in config/rbac.php. The row and its revision history survive.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'page', description: 'Page ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 204, description: 'Page deleted'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Page not found'),
        ]
    )]
    public function destroy(Request $request, ContentPage $page, DeleteContentPage $deleteContentPage): Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);

        $deleteContentPage->execute($page, $this->actor($request), $request->ip(), $this->requestId($request));

        return response()->noContent();
    }

    #[OAT\Post(
        path: '/admin/content/pages/{page}/status',
        summary: 'Move a page through the editorial workflow',
        description: 'draft → in_review → published → archived, per ContentPage::TRANSITIONS. A `published_at` in the future schedules the page; scopeLive() keeps it hidden until then with no cron job.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'page', description: 'Page ULID', schema: new OAT\Schema(type: 'string')),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['status'],
                    properties: [
                        new OAT\Property(property: 'status', type: 'string', enum: ['draft', 'in_review', 'published', 'archived']),
                        new OAT\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true, description: 'Only meaningful when publishing; defaults to now'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Status changed'),
            new OAT\Response(response: 403, description: 'Missing content.publish permission'),
            new OAT\Response(response: 404, description: 'Page not found'),
            new OAT\Response(
                response: 422,
                description: 'The transition is not permitted from the page’s current status',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'invalid_transition'),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function changeStatus(
        ChangeContentPageStatusRequest $request,
        ContentPage $page,
        ChangeContentPageStatus $changeContentPageStatus,
    ): AdminContentPageResource|JsonResponse {
        $validated = $request->validated();
        $publishedAt = is_string($validated['published_at'] ?? null) ? Carbon::parse($validated['published_at']) : null;

        try {
            $changeContentPageStatus->execute(
                $page,
                (string) $validated['status'],
                $this->actor($request),
                $publishedAt,
                $request->ip(),
                $this->requestId($request),
            );
        } catch (InvalidStateTransitionException $e) {
            return response()->json([
                'code' => 'invalid_transition',
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new AdminContentPageResource($this->loadPage($page));
    }

    #[OAT\Post(
        path: '/admin/content/pages/{page}/preview-token',
        summary: 'Mint or rotate the page’s preview token',
        description: 'Returns a shareable preview URL for the public read API. The token is returned exactly once — it is never present on any other response — and rotating invalidates every previously shared link.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'page', description: 'Page ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Token issued',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'preview_token', type: 'string'),
                                    new OAT\Property(property: 'preview_url', type: 'string'),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Page not found'),
        ]
    )]
    public function previewToken(Request $request, ContentPage $page, IssuePagePreviewToken $issuePagePreviewToken): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('content.update'), Response::HTTP_FORBIDDEN);

        $token = $issuePagePreviewToken->execute($page, $this->actor($request), $request->ip(), $this->requestId($request));

        // Built server-side from config, never from a request field — the
        // same open-redirect reasoning as InitiatePayment's callback URL.
        $base = rtrim((string) config('services.frontend.url'), '/');

        return response()->json([
            'data' => [
                'preview_token' => $token,
                'preview_url' => "{$base}/{$page->slug}?preview_token={$token}",
            ],
        ]);
    }

    #[OAT\Get(
        path: '/admin/content/pages/{page}/revisions',
        summary: 'List a page’s revision history',
        description: 'Newest first. Each entry carries the full block snapshot taken at that save, so the UI can preview or diff without a second request.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'page', description: 'Page ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'per_page', in: 'query', schema: new OAT\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated revisions'),
            new OAT\Response(response: 403, description: 'Missing content.view permission'),
            new OAT\Response(response: 404, description: 'Page not found'),
        ]
    )]
    public function revisions(Request $request, ContentPage $page): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('content.view'), Response::HTTP_FORBIDDEN);

        $perPage = min((int) $request->input('per_page', 20), 100);

        return ContentPageRevisionResource::collection(
            $page->revisions()->with('createdBy')->paginate($perPage)
        );
    }

    #[OAT\Post(
        path: '/admin/content/pages/{page}/revisions/{revision}/restore',
        summary: 'Restore a page to an earlier revision',
        description: 'Replays the snapshot as a *new* save, so the history stays append-only and the restore is itself recorded. The page’s status is untouched — restoring the text of a live page neither publishes nor unpublishes it.',
        tags: ['CMS Pages'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\PathParameter(name: 'page', description: 'Page ULID', schema: new OAT\Schema(type: 'string')),
            new OAT\PathParameter(name: 'revision', description: 'Revision ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Page restored; a new revision was written'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Page or revision not found, or the revision belongs to another page'),
        ]
    )]
    public function restoreRevision(
        Request $request,
        ContentPage $page,
        ContentPageRevision $revision,
        RestoreContentPageRevision $restoreContentPageRevision,
    ): AdminContentPageResource {
        abort_unless((bool) $request->user()?->can('content.update'), Response::HTTP_FORBIDDEN);

        // 404 rather than 422: a revision of some other page is, from this
        // URL's point of view, simply not there.
        abort_unless($revision->content_page_id === $page->id, Response::HTTP_NOT_FOUND);

        $restored = $restoreContentPageRevision->execute(
            $page,
            $revision,
            $this->actor($request),
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminContentPageResource($this->loadPage($restored));
    }

    private function loadPage(ContentPage $page): ContentPage
    {
        return $page->load(['blocks', 'blocks.media', 'ogImage', 'createdBy', 'updatedBy', 'publishedBy']);
    }
}
