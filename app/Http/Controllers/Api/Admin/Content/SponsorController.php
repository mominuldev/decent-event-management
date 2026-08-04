<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\SaveContentResource;
use App\Domain\Content\Models\Sponsor;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Api\Admin\Content\Concerns\ResolvesContentReferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\SponsorRequest;
use App\Http\Resources\Admin\Content\AdminSponsorResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'CMS Sponsors')]
class SponsorController extends Controller
{
    use ResolvesContentReferences, ResolvesRequestContext;

    #[OAT\Get(
        path: '/admin/content/sponsors',
        summary: 'List sponsors',
        description: 'Ordered by tier rank then position — the same order the public sponsor grid renders in.',
        tags: ['CMS Sponsors'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'tier', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['platinum', 'gold', 'silver', 'bronze', 'partner'])),
            new OAT\Parameter(name: 'is_published', in: 'query', schema: new OAT\Schema(type: 'boolean')),
            new OAT\Parameter(name: 'per_page', in: 'query', schema: new OAT\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated sponsors'),
            new OAT\Response(response: 403, description: 'Missing content.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('content.view_any'), Response::HTTP_FORBIDDEN);

        $query = Sponsor::query()->with('logo');

        if ($request->filled('tier')) {
            $query->where('tier', (string) $request->input('tier'));
        }

        if ($request->filled('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        // Tier order lives in Sponsor::TIERS, not a column, so sort by that
        // list rather than alphabetically on the tier string. Placeholders
        // are generated from the list so adding a tier stays a code change.
        $placeholders = implode(', ', array_fill(0, count(Sponsor::TIERS), '?'));

        $query->orderByRaw("FIELD(tier, {$placeholders})", Sponsor::TIERS)
            ->orderBy('position')
            ->orderBy('id');

        return AdminSponsorResource::collection($query->paginate(min((int) $request->input('per_page', 50), 100)));
    }

    #[OAT\Post(
        path: '/admin/content/sponsors',
        summary: 'Create a sponsor',
        tags: ['CMS Sponsors'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['name'],
                    properties: [
                        new OAT\Property(property: 'name', type: 'string'),
                        new OAT\Property(property: 'name_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'tier', type: 'string', enum: ['platinum', 'gold', 'silver', 'bronze', 'partner']),
                        new OAT\Property(property: 'logo_media_ulid', type: 'string', nullable: true),
                        new OAT\Property(property: 'website_url', type: 'string', nullable: true),
                        new OAT\Property(property: 'description', type: 'string', nullable: true),
                        new OAT\Property(property: 'position', type: 'integer'),
                        new OAT\Property(property: 'is_published', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'Sponsor created'),
            new OAT\Response(response: 403, description: 'Missing content.create permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(SponsorRequest $request, SaveContentResource $saveContentResource): JsonResponse
    {
        $sponsor = $saveContentResource->execute(
            new Sponsor,
            $this->resolveMediaUlid($request->validated(), 'logo_media_ulid', 'logo_media_id'),
            $this->actor($request),
            'sponsor',
            $request->ip(),
            $this->requestId($request),
        );

        return (new AdminSponsorResource($sponsor->load('logo')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/content/sponsors/{sponsor}',
        summary: 'Fetch one sponsor',
        tags: ['CMS Sponsors'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'sponsor', description: 'Sponsor ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 200, description: 'Sponsor detail'),
            new OAT\Response(response: 403, description: 'Missing content.view permission'),
            new OAT\Response(response: 404, description: 'Sponsor not found'),
        ]
    )]
    public function show(Request $request, Sponsor $sponsor): AdminSponsorResource
    {
        abort_unless((bool) $request->user()?->can('content.view'), Response::HTTP_FORBIDDEN);

        return new AdminSponsorResource($sponsor->load('logo'));
    }

    #[OAT\Patch(
        path: '/admin/content/sponsors/{sponsor}',
        summary: 'Update a sponsor',
        tags: ['CMS Sponsors'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'sponsor', description: 'Sponsor ULID', schema: new OAT\Schema(type: 'string'))],
        requestBody: new OAT\RequestBody(required: false, content: new OAT\MediaType(mediaType: 'application/json', schema: new OAT\Schema(type: 'object'))),
        responses: [
            new OAT\Response(response: 200, description: 'Sponsor updated'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Sponsor not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(SponsorRequest $request, Sponsor $sponsor, SaveContentResource $saveContentResource): AdminSponsorResource
    {
        $saveContentResource->execute(
            $sponsor,
            $this->resolveMediaUlid($request->validated(), 'logo_media_ulid', 'logo_media_id'),
            $this->actor($request),
            'sponsor',
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminSponsorResource($sponsor->load('logo'));
    }

    #[OAT\Delete(
        path: '/admin/content/sponsors/{sponsor}',
        summary: 'Delete a sponsor',
        description: 'Super-Admin-only (`content.delete`).',
        tags: ['CMS Sponsors'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'sponsor', description: 'Sponsor ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 204, description: 'Sponsor deleted'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Sponsor not found'),
        ]
    )]
    public function destroy(Request $request, Sponsor $sponsor, SaveContentResource $saveContentResource): Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);

        $saveContentResource->delete($sponsor, $this->actor($request), 'sponsor', $request->ip(), $this->requestId($request));

        return response()->noContent();
    }
}
