<?php

namespace App\Http\Controllers\Api\Attendee;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function update(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function cancel(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
