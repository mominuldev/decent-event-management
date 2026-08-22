<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Ticketing\Models\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketTypeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Public')]
class TicketTypeController extends Controller
{
    #[OAT\Get(
        path: '/public/ticket-types',
        summary: 'List active, on-sale, publicly visible ticket types',
        tags: ['Public'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Publicly purchasable ticket type catalogue',
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
                                        new OAT\Property(property: 'code', type: 'string'),
                                        new OAT\Property(property: 'name', type: 'string'),
                                        new OAT\Property(property: 'name_bn', type: 'string'),
                                        new OAT\Property(property: 'description', type: 'string'),
                                        new OAT\Property(property: 'base_price_paisa', type: 'integer', description: 'Price in paisa (1 BDT = 100 paisa)'),
                                        new OAT\Property(property: 'additional_adult_price_paisa', type: 'integer'),
                                        new OAT\Property(property: 'additional_child_price_paisa', type: 'integer'),
                                        new OAT\Property(property: 'current_student_price_paisa', type: 'integer', nullable: true),
                                        new OAT\Property(property: 'currency', type: 'string'),
                                        new OAT\Property(property: 'base_admits', type: 'integer'),
                                        new OAT\Property(property: 'max_admits', type: 'integer'),
                                        new OAT\Property(property: 'allowed_participant_types', type: 'array', items: new OAT\Items(type: 'string')),
                                        new OAT\Property(property: 'quantity_total', type: 'integer'),
                                        new OAT\Property(property: 'quantity_sold', type: 'integer'),
                                        new OAT\Property(property: 'quantity_reserved', type: 'integer'),
                                        new OAT\Property(property: 'quantity_available', type: 'integer', description: 'max(0, quantity_total - quantity_sold - quantity_reserved)'),
                                        new OAT\Property(property: 'requires_approval', type: 'boolean'),
                                        new OAT\Property(property: 'includes_tshirt', type: 'boolean'),
                                        new OAT\Property(property: 'includes_meal', type: 'boolean'),
                                        new OAT\Property(property: 'sale_starts_at', type: 'string', format: 'date-time'),
                                        new OAT\Property(property: 'sale_ends_at', type: 'string', format: 'date-time'),
                                        new OAT\Property(property: 'is_active', type: 'boolean'),
                                        new OAT\Property(property: 'is_public', type: 'boolean'),
                                        new OAT\Property(property: 'badge_color', type: 'string'),
                                        new OAT\Property(property: 'sort_order', type: 'integer'),
                                    ],
                                    type: 'object'
                                )
                            ),
                        ]
                    )
                )
            ),
        ]
    )]
    public function index(): AnonymousResourceCollection
    {
        $ticketTypes = TicketType::where('is_active', true)
            ->where('is_public', true)
            ->where('sale_starts_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('sale_ends_at')
                    ->orWhere('sale_ends_at', '>=', now());
            })
            ->get();

        return TicketTypeResource::collection($ticketTypes);
    }
}
