<?php

namespace Database\Seeders;

use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The 20,000-row seed called for in docs/08 Phase 2 deliverables, for load
 * testing registration/payment/ticket volume (docs/03 §3.1 row estimates:
 * ~22k attendees, ~20k registrations, ~12k tickets). Bulk raw inserts,
 * not Eloquent — at this row count, per-row model events would dominate
 * runtime for no benefit since this data doesn't need to fire domain
 * events.
 *
 * Not part of the default `db:seed` run — invoke explicitly:
 * `php artisan db:seed --class="Database\Seeders\LoadTestSeeder"`
 */
class LoadTestSeeder extends Seeder
{
    private const int ATTENDEE_COUNT = 22000;

    private const int REGISTRATION_COUNT = 20000;

    private const int CHUNK_SIZE = 1000;

    public function run(): void
    {
        $ticketTypeIds = TicketType::pluck('id')->all();

        if ($ticketTypeIds === []) {
            $this->command->error('Run TicketTypeSeeder first.');

            return;
        }

        $this->command->info('Seeding '.self::ATTENDEE_COUNT.' attendees...');
        $attendeeIds = $this->seedAttendees();

        $this->command->info('Seeding '.self::REGISTRATION_COUNT.' registrations...');
        $this->seedRegistrations($attendeeIds, $ticketTypeIds);

        $this->command->info('Load test seed complete.');
    }

    /**
     * @return array<int, int>
     */
    private function seedAttendees(): array
    {
        $ids = [];
        $now = now();

        for ($chunkStart = 0; $chunkStart < self::ATTENDEE_COUNT; $chunkStart += self::CHUNK_SIZE) {
            $rows = [];
            $count = min(self::CHUNK_SIZE, self::ATTENDEE_COUNT - $chunkStart);

            for ($i = 0; $i < $count; $i++) {
                $participantType = fake()->randomElement([
                    'current_student', 'former_student', 'former_student', 'former_student',
                    'teacher', 'staff', 'guest',
                ]);
                $needsBatchYear = in_array($participantType, ['current_student', 'former_student'], true);

                $rows[] = [
                    'ulid' => (string) Str::ulid(),
                    'full_name' => fake()->name(),
                    'mobile' => '+8801'.fake()->unique()->numerify('#########'),
                    'email' => fake()->boolean(60) ? fake()->safeEmail() : null,
                    'gender' => fake()->randomElement(['male', 'female']),
                    'participant_type' => $participantType,
                    'ssc_batch_year' => $needsBatchYear ? fake()->numberBetween(1971, 2024) : null,
                    'tshirt_required' => fake()->boolean(70) ? 1 : 0,
                    'tshirt_size' => fake()->randomElement(['S', 'M', 'L', 'XL', 'XXL']),
                    'country' => 'BD',
                    'is_verified' => fake()->boolean(40) ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('attendees')->insert($rows);
        }

        return DB::table('attendees')->orderBy('id')->pluck('id')->all();
    }

    /**
     * @param  array<int, int>  $attendeeIds
     * @param  array<int, int>  $ticketTypeIds
     */
    private function seedRegistrations(array $attendeeIds, array $ticketTypeIds): void
    {
        $now = now();
        $ticketSeq = 0;
        $paymentSeq = 0;

        // Realistic funnel distribution across the registration state
        // machine (docs/04 §4.7).
        $statusWeights = [
            'confirmed' => 70,
            'pending_payment' => 10,
            'cancelled' => 8,
            'expired' => 7,
            'draft' => 5,
        ];

        for ($chunkStart = 0; $chunkStart < self::REGISTRATION_COUNT; $chunkStart += self::CHUNK_SIZE) {
            $registrationRows = [];
            $paymentRows = [];
            $ticketRows = [];
            $count = min(self::CHUNK_SIZE, self::REGISTRATION_COUNT - $chunkStart);

            for ($i = 0; $i < $count; $i++) {
                $status = $this->weightedRandom($statusWeights);
                $attendeeId = $attendeeIds[array_rand($attendeeIds)];
                $ticketTypeId = $ticketTypeIds[array_rand($ticketTypeIds)];
                $adults = fake()->numberBetween(1, 2);
                $children = fake()->numberBetween(0, 2);
                $subtotal = fake()->numberBetween(50000, 500000);
                $regNumber = 'REG-100Y-'.str_pad((string) ($chunkStart + $i + 1), 6, '0', STR_PAD_LEFT);

                $registrationRows[] = [
                    'ulid' => (string) Str::ulid(),
                    'registration_number' => $regNumber,
                    'attendee_id' => $attendeeId,
                    'ticket_type_id' => $ticketTypeId,
                    'participation_type' => $children > 0 ? 'family' : ($adults > 1 ? 'couple' : 'single'),
                    'adults_count' => $adults,
                    'children_count' => $children,
                    'status' => $status,
                    'subtotal_paisa' => $subtotal,
                    'discount_paisa' => 0,
                    'total_paisa' => $subtotal,
                    'currency' => 'BDT',
                    'source' => 'web',
                    'submitted_at' => $status !== 'draft' ? $now : null,
                    'confirmed_at' => $status === 'confirmed' ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('registrations')->insert($registrationRows);

            // Re-select the just-inserted rows to get real IDs for FK rows.
            $inserted = DB::table('registrations')
                ->whereIn('registration_number', array_column($registrationRows, 'registration_number'))
                ->get(['id', 'registration_number', 'attendee_id', 'ticket_type_id', 'status', 'total_paisa']);

            foreach ($inserted as $registration) {
                if ($registration->status !== 'confirmed') {
                    continue;
                }

                $paymentSeq++;
                $paymentRows[] = [
                    'ulid' => (string) Str::ulid(),
                    'payment_number' => 'PAY-100Y-'.str_pad((string) $paymentSeq, 6, '0', STR_PAD_LEFT),
                    'registration_id' => $registration->id,
                    'attendee_id' => $registration->attendee_id,
                    'method' => fake()->randomElement(['bkash', 'nagad', 'rocket', 'sslcommerz']),
                    'channel' => 'online',
                    'status' => 'succeeded',
                    'amount_due_paisa' => $registration->total_paisa,
                    'amount_paid_paisa' => $registration->total_paisa,
                    'net_paisa' => $registration->total_paisa,
                    'currency' => 'BDT',
                    'gateway_transaction_id' => strtoupper(Str::random(10)),
                    'paid_at' => $now,
                    'idempotency_key' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $ticketSeq++;
                $ticketRows[] = [
                    'ulid' => (string) Str::ulid(),
                    'ticket_number' => 'DEC100-GEN-'.now()->year.'-'.str_pad((string) $ticketSeq, 5, '0', STR_PAD_LEFT),
                    'registration_id' => $registration->id,
                    'attendee_id' => $registration->attendee_id,
                    'ticket_type_id' => $registration->ticket_type_id,
                    'status' => 'active',
                    'admits_total' => fake()->numberBetween(1, 4),
                    'admitted_count' => 0,
                    'price_paid_paisa' => $registration->total_paisa,
                    'currency' => 'BDT',
                    'holder_name' => fake()->name(),
                    'holder_type_label' => 'Alumni',
                    'issued_at' => $now,
                    'manifest_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($paymentRows !== []) {
                DB::table('payments')->insert($paymentRows);
            }

            if ($ticketRows !== []) {
                DB::table('tickets')->insert($ticketRows);
            }

            $this->command->info('  ...'.($chunkStart + $count).' / '.self::REGISTRATION_COUNT);
        }

        // Reconcile the atomic sold counters against what was actually issued.
        DB::statement('
            UPDATE ticket_types tt
            SET quantity_sold = (
                SELECT COUNT(*) FROM tickets t WHERE t.ticket_type_id = tt.id AND t.status != "voided"
            )
        ');
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weightedRandom(array $weights): string
    {
        $total = array_sum($weights);
        $rand = mt_rand(1, $total);
        $cumulative = 0;

        foreach ($weights as $value => $weight) {
            $cumulative += $weight;

            if ($rand <= $cumulative) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }
}
