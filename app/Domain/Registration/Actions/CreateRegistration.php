<?php

namespace App\Domain\Registration\Actions;

use App\Domain\CheckIn\Models\EventSession;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Events\RegistrationCreated;
use App\Domain\Registration\Exceptions\RegistrationRejectedException;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Models\RegistrationGuest;
use App\Domain\Registration\Support\AttendeeIdentity;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

            $mobile = AttendeeIdentity::normaliseMobile($data['mobile'] ?? null);
            $email = AttendeeIdentity::normaliseEmail($data['email'] ?? null);

            /** @var Attendee|null $attendee */
            $attendee = Attendee::where('mobile', $mobile)->first();

            // Both checked before reserving, so a rejected registration
            // never holds capacity it is not entitled to.
            $this->assertParticipantTypeAllowed($ticketType, (string) $data['participant_type']);
            $this->assertEmailAvailable($email, $attendee);

            if (! $ticketType->tryReserve()) {
                throw RegistrationRejectedException::soldOut();
            }

            if ($attendee) {
                $attendee->update([
                    'full_name' => $data['full_name'],
                    'full_name_bn' => $data['full_name_bn'] ?? $attendee->full_name_bn,
                    // Kept on the `?? existing` pattern the rest of this
                    // branch uses even though the FormRequest makes all three
                    // required: this Action is also reachable from a seeder
                    // or a console command, which must not fatal on a key the
                    // HTTP layer happens to guarantee.
                    'father_name' => $data['father_name'] ?? $attendee->father_name,
                    'email' => $email ?? $attendee->email,
                    'gender' => $data['gender'],
                    'date_of_birth' => $data['date_of_birth'] ?? $attendee->date_of_birth,
                    'occupation' => $data['occupation'] ?? $attendee->occupation,
                    'designation' => $data['designation'] ?? $attendee->designation,
                    'organization' => $data['organization'] ?? $attendee->organization,
                    'current_address' => $data['current_address'] ?? $attendee->current_address,
                    'tshirt_required' => $data['tshirt_required'] ?? $attendee->tshirt_required,
                    'tshirt_size' => $data['tshirt_size'] ?? $attendee->tshirt_size,
                    'current_class' => $data['current_class'] ?? $attendee->current_class,
                ]);

                $this->setInitialPassword($attendee, $data['password'] ?? null);
            } else {
                /** @var Attendee $attendee */
                $attendee = Attendee::create([
                    'full_name' => $data['full_name'],
                    'full_name_bn' => $data['full_name_bn'] ?? null,
                    'father_name' => $data['father_name'] ?? null,
                    'mobile' => $mobile,
                    'email' => $email,
                    'gender' => $data['gender'],
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'occupation' => $data['occupation'] ?? null,
                    'designation' => $data['designation'] ?? null,
                    'organization' => $data['organization'] ?? null,
                    'current_address' => $data['current_address'] ?? null,
                    'participant_type' => $data['participant_type'],
                    'ssc_batch_year' => $data['ssc_batch_year'] ?? null,
                    'current_class' => $data['current_class'] ?? null,
                    'tshirt_required' => $data['tshirt_required'] ?? false,
                    'tshirt_size' => $data['tshirt_size'] ?? null,
                ]);

                $this->setInitialPassword($attendee, $data['password'] ?? null);
            }

            $baseAdmits = $ticketType->base_admits ?? 1;
            $adultsCount = (int) ($data['adults_count'] ?? 1);
            $childrenCount = (int) ($data['children_count'] ?? 0);

            // `children_count` arrives as every child attending, infants
            // included. How many of those are free is decided here from the
            // guests' own ages against the ticket type's threshold — never
            // from a client-supplied count, which would let a caller mint
            // free admits by claiming a party of infants.
            $infantsCount = $this->countFreeInfants($ticketType, $data['guests'] ?? []);
            $infantsCount = min($infantsCount, $childrenCount);
            $billableChildren = $childrenCount - $infantsCount;

            $extraAdults = max(0, $adultsCount - $baseAdmits);

            // The registrant's own seat is the only line that varies by who
            // they are — a current student pays the ticket type's student
            // rate where it has one. Family they bring is priced at the
            // standard extra rates regardless, so the discount follows the
            // student rather than their whole party.
            $basePrice = $ticketType->basePriceFor((string) $data['participant_type']);
            $additionalAdultPrice = (int) $ticketType->additional_adult_price_paisa;
            $additionalChildPrice = (int) $ticketType->additional_child_price_paisa;

            $totalPrice = $basePrice + ($extraAdults * $additionalAdultPrice) + ($billableChildren * $additionalChildPrice);

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
                // Stored split, not as submitted: `children_count` is the
                // billable half so reports and pricing agree, and
                // `infants_count` carries the free heads that IssueTicket
                // still has to admit.
                'children_count' => $billableChildren,
                'infants_count' => $infantsCount,
                'status' => 'pending_payment',
                'subtotal_paisa' => $totalPrice,
                'discount_paisa' => 0,
                'total_paisa' => $totalPrice,
                'currency' => $ticketType->currency ?? 'BDT',
                'source' => 'web_public',
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
                'method' => $data['payment_method'] ?? $this->defaultPaymentMethod(),
                'channel' => 'online',
                'status' => 'pending',
                'amount_due_paisa' => $totalPrice,
                'amount_paid_paisa' => 0,
                'currency' => $ticketType->currency ?? 'BDT',
                'idempotency_key' => $data['idempotency_key'] ?? Str::random(32),
                // Reservation TTL starts now, not at gateway-session open —
                // an attendee who abandons the checkout before ever
                // clicking "pay" must still release capacity (D5).
                'expires_at' => now()->addMinutes($this->intentTtlMinutes()),
            ]);

            $registration->load(['attendee', 'guests', 'ticketType', 'payments']);

            RegistrationCreated::dispatch($registration);

            return $registration;
        });
    }

    /**
     * A ticket type sells only to the participant types it lists.
     *
     * Part of D7: `allowed_participant_types` has been stored, published on
     * TicketTypeResource and rendered by the public site since Phase 2, but
     * nothing ever checked it — so a Sponsor-only ticket would happily sell
     * to anyone who named its ULID. The public ticket form now builds its
     * participant-type dropdown from this same list, which only means
     * anything if the server enforces it too.
     *
     * An empty list means "open to everyone", matching how the frontend's
     * describeAllowedParticipants() already reads it.
     */
    private function assertParticipantTypeAllowed(TicketType $ticketType, string $participantType): void
    {
        $allowed = $ticketType->allowed_participant_types;

        if ($allowed === []) {
            return;
        }

        if (! in_array($participantType, $allowed, true)) {
            throw RegistrationRejectedException::participantTypeNotAllowed($participantType);
        }
    }

    /**
     * An email address identifies exactly one attendee.
     *
     * `attendees.email` is unique alongside `attendees.mobile`, but the two
     * cannot be enforced the same way at this entry point: the mobile number
     * is the dedupe key, so a returning registrant is *expected* to send one
     * that already exists. The email is only a conflict when it is held by
     * somebody other than the attendee this registration resolved to —
     * which is why this takes the already-resolved attendee rather than
     * being a `unique` rule on the FormRequest.
     *
     * Soft-deleted attendees count, because the unique index counts them.
     */
    private function assertEmailAvailable(?string $email, ?Attendee $attendee): void
    {
        if ($email === null) {
            return;
        }

        $query = Attendee::withTrashed()->where('email', $email);

        if ($attendee !== null) {
            $query->whereKeyNot($attendee->getKey());
        }

        if ($query->exists()) {
            throw RegistrationRejectedException::emailAlreadyRegistered();
        }
    }

    /**
     * Guests young enough to attend free under this ticket type's rule.
     *
     * A type with no `child_free_under_age` has no free-infant rule at all,
     * which is every ticket type that predates the centennial single/family
     * pair — so this returns 0 and pricing is byte-identical to before.
     *
     * `$guests` is typed loosely on purpose: it arrives from the untyped
     * `$data` payload, so each entry really is unknown until checked.
     *
     * @param  array<int, mixed>  $guests
     */
    private function countFreeInfants(TicketType $ticketType, array $guests): int
    {
        $threshold = $ticketType->child_free_under_age;

        if ($threshold === null) {
            return 0;
        }

        $count = 0;

        foreach ($guests as $guest) {
            if (! is_array($guest) || ($guest['age_group'] ?? null) !== 'child') {
                continue;
            }

            $age = $guest['age'] ?? null;

            // An age is mandatory to claim the free rate. A child guest sent
            // without one is billed, not waved through.
            if (is_numeric($age) && (int) $age < (int) $threshold) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The gateway a checkout opens against when the caller doesn't name
     * one. Config rather than a literal so pointing the public flow at a
     * different gateway is a deploy-time change, not a code change.
     */
    private function defaultPaymentMethod(): string
    {
        $method = config('services.payment.default_method');

        return is_string($method) && $method !== '' ? $method : 'sslcommerz';
    }

    private function intentTtlMinutes(): int
    {
        $value = EventSetting::where('key', 'payment.intent_ttl_minutes')->value('value');

        return $value !== null ? max(1, (int) $value) : 30;
    }

    /**
     * Sets the sign-in password chosen during checkout — but **only when
     * the attendee does not already have one.**
     *
     * That condition is the whole security of this feature, not a nicety.
     * `POST /public/registrations` is unauthenticated and resolves a
     * *returning* registrant by mobile number, so a path that overwrote an
     * existing password would be a complete account takeover: register with
     * somebody else's mobile, set a password, and their account is yours.
     * A returning registrant keeps the password they already had, and the
     * one in this request is discarded in silence — there is nothing useful
     * to tell an anonymous caller about whether that number already has an
     * account, and saying so would leak exactly the enumeration signal the
     * sign-in flow is careful not to.
     *
     * Someone who has genuinely forgotten it uses the reset flow, which
     * proves possession of the phone first.
     */
    private function setInitialPassword(Attendee $attendee, ?string $password): void
    {
        if ($password === null || $password === '' || $attendee->hasPassword()) {
            return;
        }

        // `password` is cast `hashed`, so this never stores plaintext. It is
        // outside `$fillable` deliberately — a credential must not be
        // settable by mass assignment from any array that happens to carry
        // the key.
        $attendee->forceFill([
            'password' => $password,
            'password_set_at' => now(),
        ])->save();
    }
}
