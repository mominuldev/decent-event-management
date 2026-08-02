<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function void(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function reissue(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
