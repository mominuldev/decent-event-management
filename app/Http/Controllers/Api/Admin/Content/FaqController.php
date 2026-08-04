<?php

namespace App\Http\Controllers\Api\Admin\Content;

use App\Domain\Content\Actions\SaveContentResource;
use App\Domain\Content\Models\Faq;
use App\Http\Concerns\ResolvesRequestContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Content\FaqRequest;
use App\Http\Resources\Admin\Content\AdminFaqResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'CMS FAQs')]
class FaqController extends Controller
{
    use ResolvesRequestContext;

    #[OAT\Get(
        path: '/admin/content/faqs',
        summary: 'List FAQs',
        tags: ['CMS FAQs'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(name: 'category', in: 'query', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'is_published', in: 'query', schema: new OAT\Schema(type: 'boolean')),
            new OAT\Parameter(name: 'per_page', in: 'query', schema: new OAT\Schema(type: 'integer', default: 50)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated FAQs, grouped by category then position'),
            new OAT\Response(response: 403, description: 'Missing content.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('content.view_any'), Response::HTTP_FORBIDDEN);

        $query = Faq::query();

        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }

        if ($request->filled('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        return AdminFaqResource::collection(
            $query->orderBy('category')->orderBy('position')->orderBy('id')
                ->paginate(min((int) $request->input('per_page', 50), 100))
        );
    }

    #[OAT\Post(
        path: '/admin/content/faqs',
        summary: 'Create an FAQ',
        tags: ['CMS FAQs'],
        security: [['bearerAuth' => []]],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    required: ['question', 'answer'],
                    properties: [
                        new OAT\Property(property: 'question', type: 'string'),
                        new OAT\Property(property: 'question_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'answer', type: 'string'),
                        new OAT\Property(property: 'answer_bn', type: 'string', nullable: true),
                        new OAT\Property(property: 'category', type: 'string', nullable: true),
                        new OAT\Property(property: 'position', type: 'integer'),
                        new OAT\Property(property: 'is_published', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 201, description: 'FAQ created'),
            new OAT\Response(response: 403, description: 'Missing content.create permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(FaqRequest $request, SaveContentResource $saveContentResource): JsonResponse
    {
        $faq = $saveContentResource->execute(
            new Faq,
            $request->validated(),
            $this->actor($request),
            'faq',
            $request->ip(),
            $this->requestId($request),
        );

        return (new AdminFaqResource($faq))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OAT\Get(
        path: '/admin/content/faqs/{faq}',
        summary: 'Fetch one FAQ',
        tags: ['CMS FAQs'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'faq', description: 'FAQ ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 200, description: 'FAQ detail'),
            new OAT\Response(response: 403, description: 'Missing content.view permission'),
            new OAT\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(Request $request, Faq $faq): AdminFaqResource
    {
        abort_unless((bool) $request->user()?->can('content.view'), Response::HTTP_FORBIDDEN);

        return new AdminFaqResource($faq);
    }

    #[OAT\Patch(
        path: '/admin/content/faqs/{faq}',
        summary: 'Update an FAQ',
        tags: ['CMS FAQs'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'faq', description: 'FAQ ULID', schema: new OAT\Schema(type: 'string'))],
        requestBody: new OAT\RequestBody(required: false, content: new OAT\MediaType(mediaType: 'application/json', schema: new OAT\Schema(type: 'object'))),
        responses: [
            new OAT\Response(response: 200, description: 'FAQ updated'),
            new OAT\Response(response: 403, description: 'Missing content.update permission'),
            new OAT\Response(response: 404, description: 'Not found'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(FaqRequest $request, Faq $faq, SaveContentResource $saveContentResource): AdminFaqResource
    {
        $saveContentResource->execute(
            $faq,
            $request->validated(),
            $this->actor($request),
            'faq',
            $request->ip(),
            $this->requestId($request),
        );

        return new AdminFaqResource($faq);
    }

    #[OAT\Delete(
        path: '/admin/content/faqs/{faq}',
        summary: 'Delete an FAQ',
        description: 'Super-Admin-only (`content.delete`).',
        tags: ['CMS FAQs'],
        security: [['bearerAuth' => []]],
        parameters: [new OAT\PathParameter(name: 'faq', description: 'FAQ ULID', schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 204, description: 'FAQ deleted'),
            new OAT\Response(response: 403, description: 'Missing content.delete permission'),
            new OAT\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, Faq $faq, SaveContentResource $saveContentResource): Response
    {
        abort_unless((bool) $request->user()?->can('content.delete'), Response::HTTP_FORBIDDEN);

        $saveContentResource->delete($faq, $this->actor($request), 'faq', $request->ip(), $this->requestId($request));

        return response()->noContent();
    }
}
