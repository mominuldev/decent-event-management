<?php

namespace App\Http\Controllers\Api\Scanner;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CheckInSyncController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
