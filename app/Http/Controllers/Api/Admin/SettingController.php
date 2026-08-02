<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => []]);
    }

    public function update(): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
