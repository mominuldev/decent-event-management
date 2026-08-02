<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function update(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function destroy(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
