<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TicketTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
