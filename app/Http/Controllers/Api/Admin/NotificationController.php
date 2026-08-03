<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Notification\Actions\ResendNotification;
use App\Domain\Notification\Actions\SetChannelKillSwitch;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateNotificationKillSwitchRequest;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\NotificationTemplateResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin delivery-log/dashboard endpoints for the outbox (docs/01
 * §1.6, docs/08 Phase 5). Every mutating action delegates to a
 * Notification\Actions class that writes its own ActivityLog entry
 * (D8 discipline — new code logs from the Action, not the controller).
 */
#[OAT\Tag(name: 'Notifications')]
class NotificationController extends Controller
{
    private const array KILL_SWITCH_CHANNELS = ['email', 'sms', 'whatsapp'];

    #[OAT\Get(
        path: '/admin/notifications',
        summary: 'List notifications (the outbox delivery log) with filters',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OAT\Parameter(name: 'channel', in: 'query', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'status', in: 'query', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'template_key', in: 'query', schema: new OAT\Schema(type: 'string')),
            new OAT\Parameter(name: 'date_from', in: 'query', schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\Parameter(name: 'date_to', in: 'query', schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\Parameter(name: 'per_page', in: 'query', schema: new OAT\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Paginated delivery log'),
            new OAT\Response(response: 403, description: 'Missing notification.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('notification.view_any'), Response::HTTP_FORBIDDEN);

        $query = Notification::query()->latest();

        if ($request->filled('channel')) {
            $query->where('channel', (string) $request->query('channel'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('template_key')) {
            $query->where('template_key', (string) $request->query('template_key'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->query('date_to'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return NotificationResource::collection($query->paginate($perPage));
    }

    #[OAT\Get(
        path: '/admin/notifications/{notification}',
        summary: 'Get a single notification and its delivery-receipt timeline',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OAT\Parameter(name: 'notification', in: 'path', required: true, description: 'Notification ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Notification detail with events timeline'),
            new OAT\Response(response: 403, description: 'Missing notification.view_any permission'),
            new OAT\Response(response: 404, description: 'Notification not found'),
        ]
    )]
    public function show(Request $request, Notification $notification): NotificationResource
    {
        abort_unless((bool) $request->user()?->can('notification.view_any'), Response::HTTP_FORBIDDEN);

        $notification->load('events');

        return new NotificationResource($notification);
    }

    #[OAT\Post(
        path: '/admin/notifications/{notification}/resend',
        summary: 'Resend a failed or bounced notification as a fresh outbox row',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OAT\Parameter(name: 'notification', in: 'path', required: true, description: 'Notification ULID', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Resend queued'),
            new OAT\Response(response: 403, description: 'Missing notification.resend permission'),
            new OAT\Response(response: 404, description: 'Notification not found'),
            new OAT\Response(response: 422, description: 'Notification is not in a resendable status'),
        ]
    )]
    public function resend(Request $request, Notification $notification, ResendNotification $action): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('notification.resend'), Response::HTTP_FORBIDDEN);

        try {
            /** @var User $user */
            $user = $request->user();

            $fresh = $action->execute(
                $notification,
                $user,
                $request->ip(),
                substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            );

            return response()->json([
                'data' => new NotificationResource($fresh),
                'message' => 'Notification resend queued.',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 'resend_failed',
                'message' => $e->getMessage(),
                'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            ], 422);
        }
    }

    #[OAT\Get(
        path: '/admin/notifications/costs',
        summary: 'Aggregate delivery cost and segment count by channel and date',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OAT\Parameter(name: 'date_from', in: 'query', schema: new OAT\Schema(type: 'string', format: 'date')),
            new OAT\Parameter(name: 'date_to', in: 'query', schema: new OAT\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Per-channel, per-day cost and segment totals'),
            new OAT\Response(response: 403, description: 'Missing notification.view_costs permission'),
        ]
    )]
    public function costs(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('notification.view_costs'), Response::HTTP_FORBIDDEN);

        $query = Notification::query()
            ->selectRaw('channel, DATE(created_at) as date, COALESCE(SUM(cost_paisa), 0) as total_cost_paisa, COALESCE(SUM(segment_count), 0) as total_segments, COUNT(*) as message_count')
            ->whereNotNull('sent_at');

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->query('date_to'));
        }

        $rows = $query->groupBy('channel', 'date')->orderByDesc('date')->get();

        return response()->json(['data' => $rows]);
    }

    #[OAT\Get(
        path: '/admin/notifications/kill-switches',
        summary: 'Get the current enabled/disabled state of each notification channel',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OAT\Response(response: 200, description: 'Per-channel enabled state'),
            new OAT\Response(response: 403, description: 'Missing notification.send_broadcast permission'),
        ]
    )]
    public function killSwitches(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('notification.send_broadcast'), Response::HTTP_FORBIDDEN);

        $settings = EventSetting::query()
            ->whereIn('key', array_map(fn (string $channel): string => "notification.{$channel}_enabled", self::KILL_SWITCH_CHANNELS))
            ->get()
            ->keyBy('key');

        $data = [];
        foreach (self::KILL_SWITCH_CHANNELS as $channel) {
            $setting = $settings->get("notification.{$channel}_enabled");
            $data[$channel] = $setting === null || $setting->typedValue() === true;
        }

        return response()->json(['data' => $data]);
    }

    #[OAT\Patch(
        path: '/admin/notifications/kill-switches',
        summary: 'Enable or disable a notification channel (docs/06 §6.7 — a deliberate two-step confirm on the client)',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        requestBody: new OAT\RequestBody(
            required: true,
            content: new OAT\MediaType(
                mediaType: 'application/json',
                schema: new OAT\Schema(
                    properties: [
                        new OAT\Property(property: 'channel', type: 'string', enum: ['email', 'sms', 'whatsapp']),
                        new OAT\Property(property: 'enabled', type: 'boolean'),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(response: 200, description: 'Kill switch updated'),
            new OAT\Response(response: 403, description: 'Missing notification.send_broadcast permission'),
            new OAT\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function updateKillSwitch(UpdateNotificationKillSwitchRequest $request, SetChannelKillSwitch $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $setting = $action->execute(
            (string) $request->validated('channel'),
            (bool) $request->validated('enabled'),
            $user,
            $request->ip(),
            substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
        );

        return response()->json([
            'data' => [
                'channel' => $request->validated('channel'),
                'enabled' => $setting->typedValue() === true,
            ],
            'message' => 'Kill switch updated.',
        ]);
    }

    #[OAT\Get(
        path: '/admin/notifications/templates',
        summary: 'List notification templates with their active version and WhatsApp approval status',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OAT\Parameter(name: 'key', in: 'query', description: 'Filter by template_key', schema: new OAT\Schema(type: 'string')),
        ],
        responses: [
            new OAT\Response(response: 200, description: 'Templates list'),
            new OAT\Response(response: 403, description: 'Missing notification.manage_templates permission'),
        ]
    )]
    public function templates(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('notification.manage_templates'), Response::HTTP_FORBIDDEN);

        $query = NotificationTemplate::query()->orderBy('key')->orderBy('channel')->orderBy('locale');

        if ($request->filled('key')) {
            $query->where('key', (string) $request->query('key'));
        }

        return NotificationTemplateResource::collection($query->get());
    }
}
