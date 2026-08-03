<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\EventSetting;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Http\Resources\EventSettingResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Settings')]
class SettingController extends Controller
{
    #[OAT\Get(
        path: '/admin/settings',
        summary: 'List all event settings, grouped by group',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Settings grouped by their `group` column',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'object',
                                description: 'Map of group name to an array of settings in that group',
                                additionalProperties: new OAT\AdditionalProperties(
                                    type: 'array',
                                    items: new OAT\Items(
                                        properties: [
                                            new OAT\Property(property: 'key', type: 'string'),
                                            new OAT\Property(property: 'group', type: 'string'),
                                            new OAT\Property(property: 'value', type: 'string', nullable: true),
                                            new OAT\Property(property: 'typed_value', description: 'Value cast to its declared type'),
                                            new OAT\Property(property: 'label', type: 'string'),
                                            new OAT\Property(property: 'description', type: 'string', nullable: true),
                                        ],
                                        type: 'object'
                                    )
                                )
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing settings.view permission'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('settings.view'), Response::HTTP_FORBIDDEN);

        $settings = EventSetting::all()->groupBy('group');
        $data = $settings->map(function (Collection $group) {
            return EventSettingResource::collection($group);
        });

        return response()->json(['data' => $data]);
    }

    #[OAT\Patch(
        path: '/admin/settings/{key}',
        summary: 'Update a single event setting by key',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        parameters: [
            new OAT\Parameter(
                name: 'key',
                description: 'The setting\'s `key` column value',
                in: 'path',
                required: true,
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(
                            property: 'value',
                            description: 'The new setting value',
                            required: ['value']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Setting updated',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'key', type: 'string'),
                            new OAT\Property(property: 'group', type: 'string'),
                            new OAT\Property(property: 'value', type: 'string', nullable: true),
                            new OAT\Property(property: 'typed_value', description: 'Value cast to its declared type'),
                            new OAT\Property(property: 'label', type: 'string'),
                            new OAT\Property(property: 'description', type: 'string', nullable: true),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing settings.update permission'),
            new OAT\Response(response: 404, description: 'No setting exists with that key'),
        ]
    )]
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
