<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Payment\Actions\RefundPayment;
use App\Domain\Payment\Actions\VerifyManualPayment;
use App\Domain\Payment\Models\Payment;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
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

class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Payment::query()->latest();

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

        $perPage = min((int) $request->query('per_page', 20), 100);

        return PaymentResource::collection($query->paginate($perPage));
    }

    public function show(Payment $payment): PaymentResource
    {
        $payment->load(['registration', 'attendee', 'verifiedBy', 'transactions', 'refunds']);

        return new PaymentResource($payment);
    }

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
