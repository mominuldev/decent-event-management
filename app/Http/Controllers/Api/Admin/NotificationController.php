<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Notification\Actions\ResendNotification;
use App\Domain\Notification\Actions\SaveNotificationTemplate;
use App\Domain\Notification\Actions\SetChannelKillSwitch;
use App\Domain\Notification\Gateways\ReveSmsClient;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Support\SmsGatewayConfig;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveNotificationTemplateRequest;
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
use Throwable;

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

    #[OAT\Get(
        path: '/admin/notifications/sms-balance',
        summary: 'Live prepaid balance on the SMS gateway account',
        description: 'Asks REVE for the account balance and reports it alongside the low-balance threshold and '
            .'the recharge portal URL, both configurable in Settings. '
            .'`estimated_segments` divides the balance by the configured per-segment cost — it is an estimate '
            .'from a local figure, not something the gateway reports, so it is only as right as '
            .'`sms.cost_paisa_per_segment`. '
            .'**There is no top-up endpoint**: REVE exposes send, status and balance only, so recharging happens '
            .'on their billing portal and this endpoint is how the new balance becomes visible afterwards. '
            .'Answers `200` with `configured: false` when no credentials are set, and `502` when the gateway '
            .'itself cannot be reached — an unreachable gateway is not the same as an empty account, and '
            .'rendering it as a zero balance would send someone to top up a wallet that is already full.',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Balance, or configured=false when no credentials are set',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(properties: [
                        new OAT\Property(property: 'configured', type: 'boolean'),
                        new OAT\Property(property: 'balance', description: 'Account balance in BDT as the gateway reports it; null when it returns no parseable figure', type: 'number', nullable: true),
                        new OAT\Property(property: 'estimated_segments', description: 'Balance divided by the configured per-segment cost', type: 'integer', nullable: true),
                        new OAT\Property(property: 'low_balance_threshold_paisa', type: 'integer', nullable: true),
                        new OAT\Property(property: 'is_low', type: 'boolean'),
                        new OAT\Property(property: 'recharge_url', type: 'string', nullable: true),
                        new OAT\Property(property: 'checked_at', type: 'string', format: 'date-time'),
                    ], type: 'object'),
                ),
            ),
            new OAT\Response(response: 403, description: 'Missing notification.view_costs permission'),
            new OAT\Response(response: 502, description: 'The SMS gateway could not be reached'),
        ],
    )]
    public function smsBalance(Request $request, ReveSmsClient $client): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('notification.view_costs'), Response::HTTP_FORBIDDEN);

        $settings = app(SmsGatewayConfig::class);
        $threshold = $this->intSetting('sms.low_balance_threshold_paisa');
        $rechargeUrl = $this->stringSetting('sms.recharge_url');

        $missing = ReveSmsClient::missingCredentials();

        if ($missing !== []) {
            return response()->json([
                'configured' => false,
                // Named, not just "not configured": all three are required,
                // two are invisible once saved, and without this the operator
                // has no way to tell which field is empty.
                'missing' => $missing,
                'balance' => null,
                'balance_available' => false,
                'estimated_segments' => null,
                'low_balance_threshold_paisa' => $threshold,
                'is_low' => false,
                'recharge_url' => $rechargeUrl,
                'checked_at' => now()->toIso8601String(),
            ]);
        }

        try {
            $result = $client->balance();
        } catch (Throwable $e) {
            // Deliberately not a zero balance: "we could not ask" and "the
            // account is empty" lead an operator to opposite actions.
            return response()->json([
                'code' => 'sms_gateway_unreachable',
                'message' => 'The SMS gateway did not answer: '.$e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $balance = $result['balance'];
        $costPerSegment = max(1, (int) $settings->get('cost_paisa_per_segment', '50'));
        $balancePaisa = $balance !== null ? (int) round($balance * 100) : null;

        return response()->json([
            'configured' => true,
            'missing' => [],
            'balance' => $balance,
            // Not every REVE deployment exposes /api/v2/balance — the one
            // this was verified against answers `{"Status":"ERROR"}` for any
            // credentials at all. That is "this account cannot report a
            // balance", which is a different thing from a balance of zero
            // and must not render as one.
            'balance_available' => $balance !== null,
            'estimated_segments' => $balancePaisa !== null ? intdiv($balancePaisa, $costPerSegment) : null,
            'low_balance_threshold_paisa' => $threshold,
            'is_low' => $balancePaisa !== null && $threshold !== null && $balancePaisa < $threshold,
            'recharge_url' => $rechargeUrl,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    private function intSetting(string $key): ?int
    {
        $setting = EventSetting::query()->where('key', $key)->first();

        return $setting === null || $setting->value === null ? null : (int) $setting->value;
    }

    private function stringSetting(string $key): ?string
    {
        $value = EventSetting::query()->where('key', $key)->first()?->value;

        return $value === null || $value === '' ? null : $value;
    }

    #[OAT\Post(
        path: '/admin/notifications/templates',
        summary: 'Create a notification template',
        description: 'Adds a template for a (key, channel, locale, version) that does not have one yet. '
            .'Editing the identity of an existing template is not offered — it would silently retarget every '
            .'notification that uses it — so a different message is a different row.',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OAT\Response(response: 201, description: 'Template created'),
            new OAT\Response(response: 403, description: 'Missing notification.manage_templates permission'),
            new OAT\Response(response: 422, description: 'Validation failed, or that key/channel/language already exists'),
        ],
    )]
    public function storeTemplate(SaveNotificationTemplateRequest $request, SaveNotificationTemplate $action): JsonResponse
    {
        $template = $action->execute(null, $request->validated(), $request->user(), $request->header('X-Request-Id'));

        return response()->json(
            ['data' => new NotificationTemplateResource($template)],
            Response::HTTP_CREATED,
        );
    }

    #[OAT\Patch(
        path: '/admin/notifications/templates/{template}',
        summary: 'Edit a notification template',
        description: 'Changes the wording, the subject, or whether the template is active. '
            .'For an SMS template the stored segment estimate is recalculated on save — the boundary is '
            .'invisible while typing (160 GSM-7 characters is one segment, 161 is two, and one emoji or a '
            .'plain `|` drops the whole message to 70 per segment), and it is billed per segment per recipient.',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [new OAT\Parameter(name: 'template', in: 'path', required: true, schema: new OAT\Schema(type: 'string'))],
        responses: [
            new OAT\Response(response: 200, description: 'Template updated'),
            new OAT\Response(response: 403, description: 'Missing notification.manage_templates permission'),
            new OAT\Response(response: 404, description: 'No such template'),
        ],
    )]
    public function updateTemplate(
        SaveNotificationTemplateRequest $request,
        NotificationTemplate $template,
        SaveNotificationTemplate $action,
    ): NotificationTemplateResource {
        return new NotificationTemplateResource(
            $action->execute($template, $request->validated(), $request->user(), $request->header('X-Request-Id')),
        );
    }

    #[OAT\Post(
        path: '/admin/notifications/templates/preview',
        summary: 'Cost and encoding of a draft message, before it is saved',
        description: 'Returns what the given body would cost as an SMS: its encoding, segment count, and the '
            .'total across a recipient count. This exists because the cost of an SMS is invisible in an editor '
            .'and the cliffs are sharp — a single emoji, or a plain `|`, moves a message from 160 characters per '
            .'segment to 70. Placeholders are substituted with a sample value first, since `{{event_name}}` is '
            .'shorter than what it renders to and the estimate would flatter the real message otherwise.',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OAT\Response(response: 200, description: 'Encoding, segments and cost'),
            new OAT\Response(response: 403, description: 'Missing notification.manage_templates permission'),
        ],
    )]
    public function previewTemplate(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('notification.manage_templates'), Response::HTTP_FORBIDDEN);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'sample' => ['nullable', 'array'],
            'recipients' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        /** @var array<string, mixed> $sample */
        $sample = $validated['sample'] ?? [];

        // The same helper the save path uses, deliberately: a number in the
        // editor that disagreed with the number in the list would be worse
        // than showing none.
        $body = SmsSegmentCalculator::renderForEstimate((string) $validated['body'], $sample);

        $segments = SmsSegmentCalculator::segmentCount($body);
        $costPerSegment = max(0, (int) app(SmsGatewayConfig::class)->get('cost_paisa_per_segment', '0'));
        $recipients = (int) ($validated['recipients'] ?? 1);

        return response()->json([
            'rendered' => $body,
            'characters' => mb_strlen($body),
            'encoding' => SmsSegmentCalculator::isGsm7($body) ? 'GSM-7' : 'Unicode',
            'characters_per_segment' => SmsSegmentCalculator::isGsm7($body) ? 160 : 70,
            'segments' => $segments,
            'cost_paisa_each' => $segments * $costPerSegment,
            'cost_paisa_total' => $segments * $costPerSegment * $recipients,
            'recipients' => $recipients,
        ]);
    }
}
