<?php

namespace App\Domain\Registration\Actions;

use App\Domain\CheckIn\Models\EventSession;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Models\RegistrationGuest;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CreateRegistration
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Registration
    {
        return DB::transaction(function () use ($data): Registration {
            /** @var TicketType $ticketType */
            $ticketType = TicketType::where('ulid', $data['ticket_type_ulid'])->firstOrFail();

            if (! $ticketType->tryReserve()) {
                throw new RuntimeException('Tickets are sold out or capacity is full.');
            }

            $mobile = preg_replace('/[^0-9+]/', '', (string) $data['mobile']);

            /** @var Attendee|null $attendee */
            $attendee = Attendee::where('mobile', $mobile)->first();

            if ($attendee) {
                $attendee->update([
                    'full_name' => $data['full_name'],
                    'full_name_bn' => $data['full_name_bn'] ?? $attendee->full_name_bn,
                    'email' => $data['email'] ?? $attendee->email,
                    'gender' => $data['gender'],
                    'date_of_birth' => $data['date_of_birth'] ?? $attendee->date_of_birth,
                    'occupation' => $data['occupation'] ?? $attendee->occupation,
                    'designation' => $data['designation'] ?? $attendee->designation,
                    'organization' => $data['organization'] ?? $attendee->organization,
                    'tshirt_required' => $data['tshirt_required'] ?? $attendee->tshirt_required,
                    'tshirt_size' => $data['tshirt_size'] ?? $attendee->tshirt_size,
                    'current_class' => $data['current_class'] ?? $attendee->current_class,
                ]);
            } else {
                /** @var Attendee $attendee */
                $attendee = Attendee::create([
                    'full_name' => $data['full_name'],
                    'full_name_bn' => $data['full_name_bn'] ?? null,
                    'mobile' => $mobile,
                    'email' => $data['email'] ?? null,
                    'gender' => $data['gender'],
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'occupation' => $data['occupation'] ?? null,
                    'designation' => $data['designation'] ?? null,
                    'organization' => $data['organization'] ?? null,
                    'participant_type' => $data['participant_type'],
                    'ssc_batch_year' => $data['ssc_batch_year'] ?? null,
                    'current_class' => $data['current_class'] ?? null,
                    'tshirt_required' => $data['tshirt_required'] ?? false,
                    'tshirt_size' => $data['tshirt_size'] ?? null,
                ]);
            }

            $baseAdmits = $ticketType->base_admits ?? 1;
            $adultsCount = (int) ($data['adults_count'] ?? 1);
            $childrenCount = (int) ($data['children_count'] ?? 0);

            $extraAdults = max(0, $adultsCount - $baseAdmits);
            $extraChildren = $childrenCount;

            $basePrice = (int) $ticketType->base_price_paisa;
            $additionalAdultPrice = (int) $ticketType->additional_adult_price_paisa;
            $additionalChildPrice = (int) $ticketType->additional_child_price_paisa;

            $totalPrice = $basePrice + ($extraAdults * $additionalAdultPrice) + ($extraChildren * $additionalChildPrice);

            $eventSessionId = null;
            if (! empty($data['event_session_ulid'])) {
                $eventSessionId = EventSession::where('ulid', $data['event_session_ulid'])->value('id');
            }

            /** @var Registration $registration */
            $registration = Registration::create([
                'registration_number' => 'REG-'.Str::upper(Str::random(8)),
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $ticketType->id,
                'event_session_id' => $eventSessionId,
                'participation_type' => $data['participation_type'],
                'adults_count' => $adultsCount,
                'children_count' => $childrenCount,
                'status' => 'pending_payment',
                'subtotal_paisa' => $totalPrice,
                'discount_paisa' => 0,
                'total_paisa' => $totalPrice,
                'currency' => $ticketType->currency ?? 'BDT',
                'source' => 'web_public',
                'comments' => $data['comments'] ?? null,
                'special_notes' => $data['special_notes'] ?? null,
            ]);

            if (isset($data['guests']) && is_array($data['guests'])) {
                foreach ($data['guests'] as $index => $guestData) {
                    RegistrationGuest::create([
                        'registration_id' => $registration->id,
                        'full_name' => $guestData['full_name'],
                        'relation' => $guestData['relation'],
                        'age_group' => $guestData['age_group'],
                        'age' => $guestData['age'] ?? null,
                        'gender' => $guestData['gender'] ?? null,
                        'tshirt_required' => $guestData['tshirt_required'] ?? false,
                        'tshirt_size' => $guestData['tshirt_size'] ?? null,
                        'sort_order' => $index,
                    ]);
                }
            }

            Payment::create([
                'payment_number' => 'PAY-'.Str::upper(Str::random(8)),
                'registration_id' => $registration->id,
                'attendee_id' => $attendee->id,
                'method' => $data['payment_method'] ?? 'bkash',
                'channel' => 'online',
                'status' => 'pending',
                'amount_due_paisa' => $totalPrice,
                'amount_paid_paisa' => 0,
                'currency' => $ticketType->currency ?? 'BDT',
                'idempotency_key' => $data['idempotency_key'] ?? Str::random(32),
            ]);

            $registration->load(['attendee', 'guests', 'ticketType', 'payments']);

            return $registration;
        });
    }
}
