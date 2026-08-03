<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\EventSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Http\Resources\EventSettingResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = EventSetting::all()->groupBy('group');
        $data = $settings->map(function (Collection $group) {
            return EventSettingResource::collection($group);
        });

        return response()->json(['data' => $data]);
    }

    public function update(UpdateSettingRequest $request, string $key): EventSettingResource
    {
        /** @var EventSetting $setting */
        $setting = EventSetting::where('key', $key)->firstOrFail();

        $oldData = $setting->toArray();

        $setting->update([
            'value' => $request->input('value'),
            'updated_by_user_id' => $request->user()?->id,
        ]);

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'setting',
            'event' => 'updated',
            'description' => "Setting {$key} updated",
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $setting->getMorphClass(),
            'subject_id' => $setting->id,
            'properties' => ['old' => $oldData, 'new' => $setting->toArray()],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        return new EventSettingResource($setting->refresh());
    }
}
