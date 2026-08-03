<?php

namespace App\Http\Controllers\Api\Public\Content;

use App\Domain\Content\Models\Faq;
use App\Http\Concerns\RespondsWithEtag;
use App\Http\Concerns\ServesLocalisedContent;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\Content\FaqResource;
use Illuminate\Http\Request;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Content')]
class FaqController extends Controller
{
    use RespondsWithEtag, ServesLocalisedContent;

    #[OAT\Get(
        path: '/public/content/faqs',
        summary: 'List published FAQs, grouped by category order then position',
        tags: ['Content'],
        parameters: [
            new OAT\Parameter(name: 'category', in: 'query', description: 'Filter to a single category', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'locale', in: 'query', schema: new OAT\Schema(type: 'string', enum: ['en', 'bn'])),
            new OAT\Parameter(name: 'If-None-Match', in: 'header', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Published FAQs',
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
                                        new OAT\Property(property: 'question', type: 'string'),
                                        new OAT\Property(property: 'answer', type: 'string'),
                                        new OAT\Property(property: 'category', type: 'string', nullable: true),
                                        new OAT\Property(property: 'position', type: 'integer'),
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

        $query = Faq::query()->published();

        $category = $request->query('category');

        if (is_string($category) && $category !== '') {
            $query->where('category', $category);
        }

        $faqs = $query->orderBy('category')->orderBy('position')->orderBy('id')->get();

        return $this->withPublicCache($request, FaqResource::collection($faqs)->response($request));
    }
}
