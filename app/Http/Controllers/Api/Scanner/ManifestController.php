<?php

namespace App\Http\Controllers\Api\Scanner;

use App\Domain\Ticketing\Models\Ticket;
use App\Http\Controllers\Controller;
use App\Http\Resources\ManifestEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManifestController extends Controller
{
    public function show(Request $request): Response
    {
        $agg = Ticket::whereIn('status', ['active', 'partially_admitted', 'fully_admitted'])
            ->selectRaw('COUNT(*) as count, MAX(manifest_version) as max_version')
            ->first();

        $count = $agg->count ?? 0;
        $maxVersion = $agg->max_version ?? 0;

        $eTag = '"'.md5($count.'-'.$maxVersion).'"';

        if ($request->header('If-None-Match') === $eTag) {
            return response()->noContent(304);
        }

        $tickets = Ticket::whereIn('status', ['active', 'partially_admitted', 'fully_admitted'])->get();

        /** @var JsonResponse $response */
        $response = ManifestEntryResource::collection($tickets)->response();

        return $response->setEtag($eTag);
    }
}
