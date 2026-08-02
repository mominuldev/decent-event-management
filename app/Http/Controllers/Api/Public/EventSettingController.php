<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EventSettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
