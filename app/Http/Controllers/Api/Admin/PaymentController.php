<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function verifyManual(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function rejectManual(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function refund(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
