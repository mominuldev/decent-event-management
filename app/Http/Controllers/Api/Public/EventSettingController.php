<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Shared\Models\EventSetting;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventSettingResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventSettingController extends Controller
{
    public function show(): AnonymousResourceCollection
    {
        $settings = EventSetting::where('is_public', true)->get();

        return EventSettingResource::collection($settings);
    }
}
