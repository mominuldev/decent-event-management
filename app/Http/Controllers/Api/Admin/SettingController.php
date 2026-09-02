<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Notification\Support\SmsGatewayConfig;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Support\EventSettingCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Http\Resources\EventSettingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
                                            new OAT\Property(property: 'type', description: 'Declared value type', type: 'string', enum: ['string', 'int', 'money', 'bool', 'datetime', 'json']),
                                            new OAT\Property(property: 'is_public', description: 'Whether this setting is exposed on the public event endpoint', type: 'boolean'),
                                            new OAT\Property(property: 'label', type: 'string'),
                                            new OAT\Property(property: 'description', type: 'string', nullable: true),
                                            new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
                                            new OAT\Property(property: 'updated_by', description: 'Name of the staff member who last changed it', type: 'string', nullable: true),
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

        // The catalogue, not the table. A setting is listed because
        // `config/event_settings.php` defines it, so a key added in a release
        // is on screen the moment that release deploys — the deploy runs
        // migrations and no seeders, and before this a new setting stayed
        // invisible until somebody remembered to re-run `EventSettingSeeder`
        // on that environment. Rows that exist supply their values; the rest
        // render their catalogue default and become real rows when saved.
        $data = EventSettingCatalogue::all()
            ->groupBy('group')
            ->map(fn (Collection $group) => EventSettingResource::collection($group->values()));

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
                            new OAT\Property(property: 'type', description: 'Declared value type', type: 'string', enum: ['string', 'int', 'money', 'bool', 'datetime', 'json']),
                            new OAT\Property(property: 'is_public', description: 'Whether this setting is exposed on the public event endpoint', type: 'boolean'),
                            new OAT\Property(property: 'label', type: 'string'),
                            new OAT\Property(property: 'description', type: 'string', nullable: true),
                            new OAT\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
                            new OAT\Property(property: 'updated_by', description: 'Name of the staff member who last changed it', type: 'string', nullable: true),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 403, description: 'Missing settings.update permission'),
            new OAT\Response(response: 404, description: 'The key is neither a stored setting nor one defined in config/event_settings.php'),
        ]
    )]
    public function update(UpdateSettingRequest $request, string $key): JsonResponse
    {
        // Resolved from the catalogue, so a setting that has never been
        // saved on this environment can be saved for the first time here.
        // 404 is now reserved for a key that is neither stored nor defined,
        // rather than being the answer for every un-seeded setting.
        $setting = EventSettingCatalogue::resolve($key);

        abort_if($setting === null, Response::HTTP_NOT_FOUND);

        $oldData = $setting->exists ? $this->loggable($setting) : null;

        // `castForStorage()` branches on `is_encrypted`, and for a row being
        // created here that flag arrives with the catalogue metadata
        // `resolve()` has already filled in — so the cast must come after it,
        // never before, or the first save of a credential writes plaintext.
        $setting->fill([
            'value' => $setting->castForStorage($request->input('value')),
            'updated_by_user_id' => $request->user()?->id,
        ])->save();

        // The gateway credentials are memoised for the life of this
        // process; without this the very next send would still use the old
        // key and the operator would reasonably conclude the save failed.
        if (SmsGatewayConfig::overridesGateway($key)) {
            app(SmsGatewayConfig::class)->flush();
        }

        $requestId = substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26);

        ActivityLog::create([
            'log_name' => 'setting',
            'event' => 'updated',
            'description' => "Setting {$key} updated",
            'causer_type' => $request->user()?->getMorphClass(),
            'causer_id' => $request->user()?->id,
            'subject_type' => $setting->getMorphClass(),
            'subject_id' => $setting->id,
            'properties' => ['old' => $oldData, 'new' => $this->loggable($setting)],
            'ip_address' => $request->ip(),
            'request_id' => $requestId,
        ]);

        // Pinned to 200. A resource wrapping a model that was just inserted
        // answers 201 on its own, and whether this save materialised the row
        // or overwrote one is an implementation detail — from the caller's
        // side a setting was updated either way, which is what the endpoint
        // documents and what the SPA is written against.
        return (new EventSettingResource($setting->refresh()->load('updatedBy')))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * The row as the audit trail should record it.
     *
     * `activity_logs` is append-only and has no redaction path, so a
     * credential written into it is there for good — and the log is read
     * from the admin console. For an encrypted row this records only
     * whether a value was present, never the value or its ciphertext:
     * knowing *that* the SMS secret key changed, by whom and from where is
     * the entire point of the audit entry, and the value itself adds
     * nothing to it but exposure.
     *
     * @return array<string, mixed>
     */
    private function loggable(EventSetting $setting): array
    {
        $data = $setting->toArray();

        if ($setting->isSecret()) {
            $data['value'] = $setting->hasValue() ? '[redacted]' : null;
        }

        return $data;
    }
}
