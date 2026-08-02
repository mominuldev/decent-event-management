<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function export(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
