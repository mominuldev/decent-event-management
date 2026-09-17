<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Registration\Actions\CreateRegistration;
use App\Domain\Registration\Support\RegistrationWindow;
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
        summary: 'List active, publicly visible ticket types whose sale has not ended',
        tags: ['Public'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Public ticket type catalogue. Types whose registration_opens_at is still ahead are included so the site can say when registration opens; only the create endpoint decides whether one is buyable.',
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
                                        new OAT\Property(property: 'base_price_tk', type: 'integer', description: 'Price in paisa (1 BDT = 100 paisa)'),
                                        new OAT\Property(property: 'additional_adult_price_tk', type: 'integer'),
                                        new OAT\Property(property: 'additional_child_price_tk', type: 'integer'),
                                        new OAT\Property(property: 'current_student_price_tk', type: 'integer', nullable: true),
                                        new OAT\Property(property: 'currency', type: 'string'),
                                        new OAT\Property(property: 'base_admits', type: 'integer'),
                                        new OAT\Property(property: 'max_admits', type: 'integer'),
                                        new OAT\Property(property: 'allows_family', type: 'boolean', description: 'Whether family members may be added; on, the party is bounded by the registration.max_family_size setting, off, it is 1'),
                                        new OAT\Property(property: 'max_party_size', type: 'integer', description: 'The party limit a registration is held to, registrant included: the registration.max_family_size setting when allows_family is on, otherwise 1'),
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
                                        new OAT\Property(property: 'registration_opens_at', type: 'string', format: 'date-time', nullable: true, description: 'The moment a registration on this type is accepted from: the later of the registration.opens_at setting and sale_starts_at; null when neither is set'),
                                        new OAT\Property(property: 'registration_closes_at', type: 'string', format: 'date-time', nullable: true, description: 'The moment registration on this type stops being accepted: the earlier of the registration.closes_at setting and sale_ends_at; null when neither is set'),
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
    /**
     * Types whose sale has not opened yet are listed, not hidden. Until
     * 2026-09-17 the query also required `sale_starts_at <= now()`, which
     * made an organiser-set opening time invisible to the public site: with
     * the row absent, the tickets page could only say "nothing is on sale",
     * never "opens on Friday at 10:00". The resource carries the window a
     * registration is accepted in (`registration_opens_at`, the organiser's
     * `registration.opens_at` setting narrowed by the row's own dates — see
     * {@see RegistrationWindow}), so the site reads it from the row and
     * {@see CreateRegistration} refuses a purchase ahead of it — listing
     * is not selling.
     *
     * Ended sales stay excluded: there is nothing left to announce. An
     * event-wide `registration.closes_at` that has passed is not filtered
     * here; the row still lists, carrying `registration_closes_at`, and
     * the site says registration has closed.
     */
    public function index(): AnonymousResourceCollection
    {
        $ticketTypes = TicketType::where('is_active', true)
            ->where('is_public', true)
            ->where(function ($query) {
                $query->whereNull('sale_ends_at')
                    ->orWhere('sale_ends_at', '>=', now());
            })
            ->get();

        return TicketTypeResource::collection($ticketTypes);
    }
}
