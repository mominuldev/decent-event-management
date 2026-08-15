<?php

namespace Database\Seeders;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Models\RegistrationGuest;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding staff users and roles...');
        $this->seedStaffUsers();

        $ticketTypes = TicketType::all();
        if ($ticketTypes->isEmpty()) {
            $this->command->error('Please run TicketTypeSeeder first.');

            return;
        }

        $gates = Gate::all();
        if ($gates->isEmpty()) {
            $this->command->error('Please run GateSeeder first.');

            return;
        }

        $this->command->info('Seeding attendees, registrations, payments, tickets, and check-ins...');

        // Create CheckIn Devices
        $device1 = CheckInDevice::firstOrCreate(
            ['device_code' => 'DEV-MAIN-01'],
            [
                'device_name' => 'Gate A Handheld Scanner 1',
                'device_fingerprint' => hash('sha256', 'device-main-01-token'),
                'platform' => 'android',
                'status' => 'active',
                'enrolled_at' => now(),
            ]
        );

        $device2 = CheckInDevice::firstOrCreate(
            ['device_code' => 'DEV-VIP-01'],
            [
                'device_name' => 'Gate B VIP Tablet',
                'device_fingerprint' => hash('sha256', 'device-vip-01-token'),
                'platform' => 'android',
                'status' => 'active',
                'enrolled_at' => now(),
            ]
        );

        $mainGate = $gates->firstWhere('code', 'GATE-A') ?? $gates->first();
        $vipGate = $gates->firstWhere('code', 'GATE-B') ?? $gates->first();

        // 1. Seed Confirmed Registrations with Payments & Tickets
        $confirmedCount = 35;
        for ($i = 1; $i <= $confirmedCount; $i++) {
            $ticketType = $ticketTypes->random();
            $attendee = Attendee::factory()->create([
                'participant_type' => fake()->randomElement($ticketType->allowed_participant_types ?? ['former_student']),
                'is_verified' => true,
            ]);

            $party = $this->buildParty($ticketType);
            $subtotal = $party['subtotal_paisa'];
            $regNum = 'REG-100Y-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);

            $registration = Registration::create([
                'ulid' => (string) Str::ulid(),
                'registration_number' => $regNum,
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $ticketType->id,
                'participation_type' => $party['participation_type'],
                'adults_count' => $party['adults'],
                'children_count' => $party['children'],
                'infants_count' => $party['infants'],
                'status' => 'confirmed',
                'subtotal_paisa' => $subtotal,
                'discount_paisa' => 0,
                'total_paisa' => $subtotal,
                'currency' => 'BDT',
                'source' => 'web',
                'submitted_at' => now()->subDays(fake()->numberBetween(2, 30)),
                'confirmed_at' => now()->subDays(fake()->numberBetween(1, 29)),
            ]);

            $this->seedGuests($registration, $party);

            // Create Succeeded Payment
            $payment = Payment::create([
                'ulid' => (string) Str::ulid(),
                'payment_number' => 'PAY-100Y-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'registration_id' => $registration->id,
                'attendee_id' => $attendee->id,
                'method' => fake()->randomElement(['bkash', 'nagad', 'rocket', 'sslcommerz']),
                'channel' => 'online',
                'status' => 'succeeded',
                'amount_due_paisa' => $subtotal,
                'amount_paid_paisa' => $subtotal,
                'net_paisa' => $subtotal,
                'currency' => 'BDT',
                'gateway_transaction_id' => 'TXN'.strtoupper(Str::random(8)),
                'initiated_at' => $registration->submitted_at,
                'paid_at' => $registration->confirmed_at,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            // Create Issued Ticket
            $ticket = Ticket::create([
                'ulid' => (string) Str::ulid(),
                'ticket_number' => 'DEC100-'.$ticketType->code.'-'.now()->year.'-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'registration_id' => $registration->id,
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $ticketType->id,
                'status' => 'active',
                // Must match IssueTicket: every head that walks through the
                // gate, free infants included. This used to be the ticket
                // type's `base_admits` (nearly always 1), so a demo family of
                // four carried a one-admit ticket and the check-in screens
                // never showed a realistic partial admission.
                'admits_total' => $party['adults'] + $party['children'] + $party['infants'],
                'admitted_count' => 0,
                'price_paid_paisa' => $subtotal,
                'currency' => 'BDT',
                'holder_name' => $attendee->full_name,
                'holder_batch_year' => $attendee->ssc_batch_year,
                'holder_type_label' => ucfirst(str_replace('_', ' ', $attendee->participant_type)),
                'issued_at' => $registration->confirmed_at,
                'manifest_version' => 1,
            ]);

            // Create CheckIn event for ~40% of confirmed tickets
            if ($i <= 15) {
                $targetGate = ($ticketType->code === 'VIP') ? $vipGate : $mainGate;
                $targetDevice = ($ticketType->code === 'VIP') ? $device2 : $device1;
                $admitCount = rand(1, $ticket->admits_total);

                CheckIn::create([
                    'client_scan_uuid' => (string) Str::uuid(),
                    'ticket_id' => $ticket->id,
                    'gate_id' => $targetGate->id,
                    'device_id' => $targetDevice->id,
                    'scanned_by_user_id' => null,
                    'result' => 'admitted',
                    'admitted_count' => $admitCount,
                    'signature_valid' => true,
                    'scan_mode' => 'online',
                    'is_manual_override' => false,
                    'scanned_at' => now()->subHours(rand(1, 24)),
                ]);

                $ticket->update(['admitted_count' => $admitCount]);
            }
        }

        // 2. Seed Pending Payment Registrations
        for ($i = 1; $i <= 10; $i++) {
            $ticketType = $ticketTypes->random();
            $attendee = Attendee::factory()->create([
                'participant_type' => fake()->randomElement($ticketType->allowed_participant_types ?? ['former_student']),
            ]);

            $subtotal = $ticketType->base_price_paisa;
            $regNum = 'REG-100Y-'.str_pad((string) ($confirmedCount + $i), 6, '0', STR_PAD_LEFT);

            $registration = Registration::create([
                'ulid' => (string) Str::ulid(),
                'registration_number' => $regNum,
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $ticketType->id,
                'participation_type' => 'single',
                'adults_count' => 1,
                'children_count' => 0,
                'infants_count' => 0,
                'status' => 'pending_payment',
                'subtotal_paisa' => $subtotal,
                'discount_paisa' => 0,
                'total_paisa' => $subtotal,
                'currency' => 'BDT',
                'source' => 'web',
                'submitted_at' => now()->subHours(rand(1, 12)),
            ]);

            Payment::create([
                'ulid' => (string) Str::ulid(),
                'payment_number' => 'PAY-100Y-'.str_pad((string) ($confirmedCount + $i), 6, '0', STR_PAD_LEFT),
                'registration_id' => $registration->id,
                'attendee_id' => $attendee->id,
                'method' => fake()->randomElement(['bkash', 'nagad', 'rocket']),
                'channel' => 'online',
                'status' => 'initiated',
                'amount_due_paisa' => $subtotal,
                'amount_paid_paisa' => 0,
                'net_paisa' => 0,
                'currency' => 'BDT',
                'initiated_at' => $registration->submitted_at,
                'idempotency_key' => (string) Str::uuid(),
            ]);
        }

        // 3. Seed Cancelled / Draft Registrations
        for ($i = 1; $i <= 5; $i++) {
            $ticketType = $ticketTypes->random();
            $attendee = Attendee::factory()->create();

            Registration::create([
                'ulid' => (string) Str::ulid(),
                'registration_number' => 'REG-100Y-'.str_pad((string) (45 + $i), 6, '0', STR_PAD_LEFT),
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $ticketType->id,
                'participation_type' => 'single',
                'adults_count' => 1,
                'children_count' => 0,
                'infants_count' => 0,
                'status' => 'cancelled',
                'subtotal_paisa' => $ticketType->base_price_paisa,
                'discount_paisa' => 0,
                'total_paisa' => $ticketType->base_price_paisa,
                'currency' => 'BDT',
                'source' => 'web',
                'submitted_at' => now()->subDays(5),
            ]);
        }

        // 4. Update TicketType Sold Counters
        DB::statement('
            UPDATE ticket_types tt
            SET quantity_sold = (
                SELECT COUNT(*) FROM tickets t WHERE t.ticket_type_id = tt.id AND t.status != "voided"
            )
        ');

        $this->command->info('Dummy data seeding completed successfully!');
    }

    /**
     * Builds one party and prices it the way `CreateRegistration` does, so
     * demo rows are internally consistent: the tiered ticket-type columns
     * decide the price (not a hardcoded per-child rate), a child young enough
     * for the type's `child_free_under_age` becomes a free infant, and the
     * party never exceeds `max_admits`.
     *
     * @return array{adults: int, children: int, infants: int, subtotal_paisa: int, participation_type: string}
     */
    private function buildParty(TicketType $ticketType): array
    {
        $baseAdmits = (int) ($ticketType->base_admits ?: 1);
        $maxAdmits = (int) ($ticketType->max_admits ?: $baseAdmits);

        $adults = min(fake()->numberBetween(1, 2), $maxAdmits);
        $children = min(fake()->numberBetween(0, 2), max(0, $maxAdmits - $adults));

        // Only a ticket type that actually carries a free-infant rule can
        // produce one — every type predating the centennial ticket has
        // `child_free_under_age` NULL and prices exactly as it always did.
        $infants = 0;
        if ($ticketType->child_free_under_age !== null && $adults + $children < $maxAdmits && fake()->boolean(30)) {
            $infants = 1;
        }

        $extraAdults = max(0, $adults - $baseAdmits);

        $subtotal = (int) $ticketType->base_price_paisa
            + ($extraAdults * (int) $ticketType->additional_adult_price_paisa)
            + ($children * (int) $ticketType->additional_child_price_paisa);

        return [
            'adults' => $adults,
            'children' => $children,
            'infants' => $infants,
            'subtotal_paisa' => $subtotal,
            'participation_type' => $children + $infants > 0 ? 'family' : ($adults > 1 ? 'couple' : 'single'),
        ];
    }

    /**
     * The registrant is the attendee, so guests are only the *additional*
     * heads. An infant needs a real age below the ticket type's threshold —
     * that age is what makes the stored `infants_count` defensible rather
     * than a number nothing backs up.
     *
     * @param  array{adults: int, children: int, infants: int, subtotal_paisa: int, participation_type: string}  $party
     */
    private function seedGuests(Registration $registration, array $party): void
    {
        $sort = 0;

        for ($n = 1; $n < $party['adults']; $n++) {
            RegistrationGuest::create([
                'registration_id' => $registration->id,
                'full_name' => fake()->name(),
                'relation' => 'spouse',
                'age_group' => 'adult',
                'age' => fake()->numberBetween(25, 65),
                'gender' => fake()->randomElement(['male', 'female']),
                'sort_order' => $sort++,
            ]);
        }

        for ($n = 0; $n < $party['children']; $n++) {
            RegistrationGuest::create([
                'registration_id' => $registration->id,
                'full_name' => fake()->firstName(),
                'relation' => 'child',
                'age_group' => 'child',
                'age' => fake()->numberBetween(4, 15),
                'gender' => fake()->randomElement(['male', 'female']),
                'sort_order' => $sort++,
            ]);
        }

        for ($n = 0; $n < $party['infants']; $n++) {
            RegistrationGuest::create([
                'registration_id' => $registration->id,
                'full_name' => fake()->firstName(),
                'relation' => 'child',
                'age_group' => 'child',
                'age' => max(0, (int) $registration->ticketType?->child_free_under_age - 1),
                'gender' => fake()->randomElement(['male', 'female']),
                'sort_order' => $sort++,
            ]);
        }
    }

    private function seedStaffUsers(): void
    {
        $staffRoles = [
            [
                'name' => 'Event Manager',
                'email' => 'manager@decent100.example',
                'role' => 'Event Manager',
            ],
            [
                'name' => 'Volunteer Staff',
                'email' => 'volunteer@decent100.example',
                'role' => 'Volunteer',
            ],
        ];

        foreach ($staffRoles as $staff) {
            $user = User::firstOrCreate(
                ['email' => $staff['email']],
                [
                    'name' => $staff['name'],
                    'phone' => '+8801'.fake()->numerify('#########'),
                    'password' => 'password',
                    'status' => 'active',
                ]
            );

            $user->syncRoles([$staff['role']]);
        }
    }
}
