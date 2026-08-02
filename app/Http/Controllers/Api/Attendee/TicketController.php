<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function downloadPdf(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
