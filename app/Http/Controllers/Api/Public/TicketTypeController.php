<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Ticketing\Models\TicketType;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketTypeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TicketTypeController extends Controller
{
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
