<?php

namespace App\Http\Controllers\Api\Public;

use App\Domain\Shared\Models\EventSetting;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventSettingResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OAT;

#[OAT\Tag(name: 'Public')]
class EventSettingController extends Controller
{
    #[OAT\Get(
        path: '/public/event',
        summary: 'List publicly visible event settings (branding, dates, contact info)',
        tags: ['Public'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Public event settings',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'array',
                                items: new OAT\Items(
                                    properties: [
                                        new OAT\Property(property: 'key', type: 'string'),
                                        new OAT\Property(property: 'group', type: 'string'),
                                        new OAT\Property(property: 'value', type: 'string', description: 'Raw stored value'),
                                        new OAT\Property(property: 'typed_value', description: 'Value cast to its configured type (string, integer, boolean, etc.)'),
                                        new OAT\Property(property: 'label', type: 'string'),
                                        new OAT\Property(property: 'description', type: 'string'),
                                    ],
                                    type: 'object'
                                )
                            ),
                        ]
                    )
                )
            ),
        ]
    )]
    public function show(): AnonymousResourceCollection
    {
        $settings = EventSetting::where('is_public', true)->get();

        return EventSettingResource::collection($settings);
    }
}
