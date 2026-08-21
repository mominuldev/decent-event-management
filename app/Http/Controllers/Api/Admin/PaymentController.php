<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Payment\Actions\RefundPayment;
use App\Domain\Payment\Actions\VerifyManualPayment;
use App\Domain\Payment\Models\Payment;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\ListSort;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RefundPaymentRequest;
use App\Http\Requests\Admin\RejectManualPaymentRequest;
use App\Http\Requests\Admin\VerifyManualPaymentRequest;
use App\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenApi\Attributes as OAT;
use Symfony\Component\HttpFoundation\Response;

#[OAT\Tag(name: 'Payments')]
class PaymentController extends Controller
{
    /**
     * Sortable columns, public field name => real column.
     *
     * Replaces a bare `->latest()`, which ordered on `created_at` alone: two
     * payments written in the same second could swap places between one page
     * and the next. ListSort adds the primary-key tiebreaker that fixes it.
     *
     * @var array<string, string>
     */
    private const SORTABLE = [
        'payment_number' => 'payment_number',
        'method' => 'method',
        'status' => 'status',
        'amount_paid_paisa' => 'amount_paid_paisa',
        'created_at' => 'created_at',
    ];

    private const DEFAULT_SORT = 'created_at';

    #[OAT\Get(
        path: '/admin/payments',
        summary: 'List payments with filters',
        tags: ['Payments'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'status',
                in: 'query',
                description: 'Filter by payment status',
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\Parameter(
                name: 'method',
                in: 'query',
                description: 'Filter by payment method (bkash, nagad, rocket, sslcommerz, manual, ...)',
                schema: new OAT\Schema(type: 'string')
            ),
            new OAT\Parameter(
                name: 'date_from',
                in: 'query',
                description: 'Filter by created_at on or after this date',
                schema: new OAT\Schema(type: 'string', format: 'date')
            ),
            new OAT\Parameter(
                name: 'date_to',
                in: 'query',
                description: 'Filter by created_at on or before this date',
                schema: new OAT\Schema(type: 'string', format: 'date')
            ),
            new OAT\Parameter(
                name: 'sort',
                in: 'query',
                description: 'Column to order by. Unknown values fall back to the default.',
                schema: new OAT\Schema(type: 'string', default: 'created_at', enum: ['payment_number', 'method', 'status', 'amount_paid_paisa', 'created_at'])
            ),
            new OAT\Parameter(
                name: 'direction',
                in: 'query',
                description: 'Order direction. Defaults to newest first.',
                schema: new OAT\Schema(type: 'string', default: 'desc', enum: ['asc', 'desc'])
            ),
            new OAT\Parameter(
                name: 'per_page',
                in: 'query',
                description: 'Results per page, capped at 100',
                schema: new OAT\Schema(type: 'integer', default: 20)
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Paginated list of payments',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                type: 'array',
                                items: new OAT\Items(
                                    properties: [
                                        new OAT\Property(property: 'ulid', type: 'string'),
                                        new OAT\Property(property: 'payment_number', type: 'string'),
                                        new OAT\Property(property: 'method', type: 'string'),
                                        new OAT\Property(property: 'channel', type: 'string', nullable: true),
                                        new OAT\Property(property: 'status', type: 'string'),
                                        new OAT\Property(property: 'amount_due_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                        new OAT\Property(property: 'amount_paid_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                        new OAT\Property(property: 'fee_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                        new OAT\Property(property: 'net_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                        new OAT\Property(property: 'refunded_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                        new OAT\Property(property: 'currency', type: 'string'),
                                        new OAT\Property(property: 'gateway_transaction_id', type: 'string', nullable: true),
                                        new OAT\Property(property: 'payer_msisdn', type: 'string', nullable: true),
                                        new OAT\Property(property: 'manual_trx_id', type: 'string', nullable: true),
                                        new OAT\Property(property: 'reconciliation_status', type: 'string', nullable: true),
                                        new OAT\Property(property: 'initiated_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'failed_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
                                        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                    ],
                                    type: 'object'
                                )
                            ),
                            new OAT\Property(property: 'links', type: 'object'),
                            new OAT\Property(property: 'meta', type: 'object'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing payment.view_any permission'),
        ]
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can('payment.view_any'), Response::HTTP_FORBIDDEN);

        $query = Payment::query();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('method')) {
            $query->where('method', (string) $request->query('method'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->query('date_to'));
        }

        ListSort::apply($query, $request->query(), self::SORTABLE, self::DEFAULT_SORT);

        $perPage = min((int) $request->query('per_page', 20), 100);

        return PaymentResource::collection($query->paginate($perPage));
    }

    #[OAT\Get(
        path: '/admin/payments/{payment}',
        summary: 'Get a single payment',
        tags: ['Payments'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'payment',
                in: 'path',
                required: true,
                description: 'Payment ULID',
                schema: new OAT\Schema(type: 'string')
            ),
        ],
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Payment detail, including transactions and refunds',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'payment_number', type: 'string'),
                                    new OAT\Property(property: 'method', type: 'string'),
                                    new OAT\Property(property: 'channel', type: 'string', nullable: true),
                                    new OAT\Property(property: 'status', type: 'string'),
                                    new OAT\Property(property: 'amount_due_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                    new OAT\Property(property: 'amount_paid_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                    new OAT\Property(property: 'fee_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                    new OAT\Property(property: 'net_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                    new OAT\Property(property: 'refunded_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                    new OAT\Property(property: 'currency', type: 'string'),
                                    new OAT\Property(property: 'gateway_transaction_id', type: 'string', nullable: true),
                                    new OAT\Property(property: 'payer_msisdn', type: 'string', nullable: true),
                                    new OAT\Property(property: 'manual_trx_id', type: 'string', nullable: true),
                                    new OAT\Property(property: 'reconciliation_status', type: 'string', nullable: true),
                                    new OAT\Property(property: 'initiated_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'failed_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                    new OAT\Property(property: 'verified_by_name', type: 'string', nullable: true),
                                ],
                                type: 'object'
                            ),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing payment.view permission'),
            new OAT\Response(response: 404, description: 'Payment not found'),
        ]
    )]
    public function show(Request $request, Payment $payment): PaymentResource
    {
        abort_unless((bool) $request->user()?->can('payment.view'), Response::HTTP_FORBIDDEN);

        $payment->load(['registration', 'attendee', 'verifiedBy', 'transactions', 'refunds']);

        return new PaymentResource($payment);
    }

    #[OAT\Post(
        path: '/admin/payments/{payment}/verify-manual',
        summary: 'Verify a manual (offline) payment as an authenticated Event Manager',
        tags: ['Payments'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'payment',
                in: 'path',
                required: true,
                description: 'Payment ULID',
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
                            property: 'verification_note',
                            type: 'string',
                            maxLength: 255,
                            required: ['verification_note']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Payment verified',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'payment_number', type: 'string'),
                                    new OAT\Property(property: 'status', type: 'string'),
                                    new OAT\Property(property: 'verified_at', type: 'string', format: 'date-time', nullable: true),
                                    new OAT\Property(property: 'verified_by_name', type: 'string', nullable: true),
                                ],
                                type: 'object'
                            ),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing payment.verify_manual permission'),
            new OAT\Response(response: 404, description: 'Payment not found'),
            new OAT\Response(
                response: 422,
                description: 'Payment cannot be verified from its current status',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'verification_failed'),
                            new OAT\Property(property: 'message', type: 'string'),
                            new OAT\Property(property: 'request_id', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function verifyManual(VerifyManualPaymentRequest $request, Payment $payment, VerifyManualPayment $action): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();

            $payment = $action->execute(
                $payment,
                $user,
                $request->validated('verification_note')
            );

            ActivityLog::create([
                'log_name' => 'payment',
                'event' => 'verified_manual',
                'description' => "Verified manual payment {$payment->payment_number}",
                'causer_type' => $user->getMorphClass(),
                'causer_id' => $user->id,
                'subject_type' => $payment->getMorphClass(),
                'subject_id' => $payment->id,
                'properties' => [
                    'note' => $request->validated('verification_note'),
                ],
                'ip_address' => $request->ip(),
                'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            ]);

            $payment->load(['registration', 'attendee', 'verifiedBy', 'transactions', 'refunds']);

            return response()->json([
                'data' => new PaymentResource($payment),
                'message' => 'Payment verified successfully.',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 'verification_failed',
                'message' => $e->getMessage(),
                'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            ], 422);
        }
    }

    #[OAT\Post(
        path: '/admin/payments/{payment}/reject-manual',
        summary: 'Reject a manual (offline) payment and cancel its registration',
        tags: ['Payments'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'payment',
                in: 'path',
                required: true,
                description: 'Payment ULID',
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
                            property: 'rejection_reason',
                            type: 'string',
                            maxLength: 255,
                            required: ['rejection_reason']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Payment rejected',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'payment_number', type: 'string'),
                                    new OAT\Property(property: 'status', type: 'string'),
                                    new OAT\Property(property: 'failed_at', type: 'string', format: 'date-time', nullable: true),
                                ],
                                type: 'object'
                            ),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing payment.reject_manual permission'),
            new OAT\Response(response: 404, description: 'Payment not found'),
            new OAT\Response(
                response: 422,
                description: 'Payment cannot be rejected from its current status',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'rejection_failed'),
                            new OAT\Property(property: 'message', type: 'string'),
                            new OAT\Property(property: 'request_id', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function rejectManual(RejectManualPaymentRequest $request, Payment $payment): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $payment): void {
                if (! in_array($payment->status, ['awaiting_verification', 'pending'], true)) {
                    throw new InvalidArgumentException("Payment cannot be rejected from status: {$payment->status}");
                }

                $payment->transitionTo('failed');
                $payment->rejection_reason = $request->validated('rejection_reason');
                $payment->failed_at = now();
                $payment->save();

                $registration = $payment->registration;
                if ($registration !== null) {
                    $registration->transitionTo('cancelled');
                    $registration->cancelled_at = now();
                    $registration->save();

                    if ($registration->ticketType !== null) {
                        $registration->ticketType->releaseReservation(1);
                    }
                }

                /** @var User $user */
                $user = $request->user();

                ActivityLog::create([
                    'log_name' => 'payment',
                    'event' => 'rejected_manual',
                    'description' => "Rejected manual payment {$payment->payment_number}",
                    'causer_type' => $user->getMorphClass(),
                    'causer_id' => $user->id,
                    'subject_type' => $payment->getMorphClass(),
                    'subject_id' => $payment->id,
                    'properties' => [
                        'reason' => $request->validated('rejection_reason'),
                    ],
                    'ip_address' => $request->ip(),
                    'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
                ]);
            });

            $payment->refresh()->load(['registration', 'attendee', 'verifiedBy', 'transactions', 'refunds']);

            return response()->json([
                'data' => new PaymentResource($payment),
                'message' => 'Payment rejected successfully.',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 'rejection_failed',
                'message' => $e->getMessage(),
                'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            ], 422);
        }
    }

    #[OAT\Post(
        path: '/admin/payments/{payment}/refund',
        summary: 'Refund a payment, fully or partially',
        tags: ['Payments'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OAT\Parameter(
                name: 'payment',
                in: 'path',
                required: true,
                description: 'Payment ULID',
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
                            property: 'reason',
                            type: 'string',
                            maxLength: 255,
                            required: ['reason']
                        ),
                        new OAT\Property(
                            property: 'amount_paisa',
                            type: 'integer',
                            nullable: true,
                            minimum: 1,
                            description: 'Integer paisa amount to refund; omit for a full refund'
                        ),
                        new OAT\Property(
                            property: 'type',
                            type: 'string',
                            enum: ['full', 'partial'],
                            required: ['type']
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OAT\Response(
                response: 200,
                description: 'Payment refunded',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(
                                property: 'data',
                                properties: [
                                    new OAT\Property(property: 'ulid', type: 'string'),
                                    new OAT\Property(property: 'refund_number', type: 'string'),
                                    new OAT\Property(property: 'amount_paisa', type: 'integer', description: 'Integer paisa, 1 BDT = 100 paisa'),
                                    new OAT\Property(property: 'status', type: 'string'),
                                ],
                                type: 'object'
                            ),
                            new OAT\Property(property: 'message', type: 'string'),
                        ]
                    )
                )
            ),
            new OAT\Response(response: 401, description: 'Unauthenticated'),
            new OAT\Response(response: 403, description: 'Missing payment.refund permission'),
            new OAT\Response(response: 404, description: 'Payment not found'),
            new OAT\Response(
                response: 422,
                description: 'Refund cannot be processed (e.g. amount exceeds remaining refundable balance)',
                content: new OAT\MediaType(
                    mediaType: 'application/json',
                    schema: new OAT\Schema(
                        properties: [
                            new OAT\Property(property: 'code', type: 'string', example: 'refund_failed'),
                            new OAT\Property(property: 'message', type: 'string'),
                            new OAT\Property(property: 'request_id', type: 'string'),
                        ]
                    )
                )
            ),
        ]
    )]
    public function refund(RefundPaymentRequest $request, Payment $payment, RefundPayment $action): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();

            $refund = $action->execute(
                $payment,
                $user,
                $request->validated('reason'),
                $request->validated('amount_paisa'),
                $request->validated('type')
            );

            ActivityLog::create([
                'log_name' => 'payment',
                'event' => 'refunded',
                'description' => "Refunded payment {$payment->payment_number}",
                'causer_type' => $user->getMorphClass(),
                'causer_id' => $user->id,
                'subject_type' => $payment->getMorphClass(),
                'subject_id' => $payment->id,
                'properties' => [
                    'refund_ulid' => $refund->ulid,
                    'amount_paisa' => $refund->amount_paisa,
                    'reason' => $request->validated('reason'),
                ],
                'ip_address' => $request->ip(),
                'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            ]);

            return response()->json([
                'data' => [
                    'ulid' => $refund->ulid,
                    'refund_number' => $refund->refund_number,
                    'amount_paisa' => $refund->amount_paisa,
                    'status' => $refund->status,
                ],
                'message' => 'Payment refunded successfully.',
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'code' => 'refund_failed',
                'message' => $e->getMessage(),
                'request_id' => substr((string) ($request->header('X-Request-Id') ?? Str::ulid()), 0, 26),
            ], 422);
        }
    }
}
