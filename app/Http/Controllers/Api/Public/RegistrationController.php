<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
