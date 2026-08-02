<?php

namespace App\Http\Controllers\Api\Scanner;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ManifestController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
