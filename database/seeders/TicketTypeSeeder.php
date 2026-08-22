<?php

namespace Database\Seeders;

use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Database\Seeder;

/**
 * The seven ticket-type codes referenced throughout docs/03 §3.7.
 */
class TicketTypeSeeder extends Seeder
{
    /**
     * Who may buy a centennial ticket from the public site — every
     * participant type except the two that have their own approval-gated
     * ticket types.
     *
     * @var list<string>
     */
    private const array CENTENNIAL_AUDIENCE = [
        'former_student',
        'current_student',
        'teacher',
        'staff',
        'guardian',
        'other',
    ];

    public function run(): void
    {
        $types = [
            ['code' => 'ALM', 'name' => 'Alumni', 'base_admits' => 1, 'max_admits' => 1, 'base_price_paisa' => 150000, 'allowed_participant_types' => ['former_student'], 'quantity_total' => 8000],
            ['code' => 'STU', 'name' => 'Current Student', 'base_admits' => 1, 'max_admits' => 1, 'base_price_paisa' => 50000, 'allowed_participant_types' => ['current_student'], 'quantity_total' => 3000],
            ['code' => 'TCH', 'name' => 'Teacher', 'base_admits' => 1, 'max_admits' => 1, 'base_price_paisa' => 0, 'allowed_participant_types' => ['teacher'], 'quantity_total' => 200],
            ['code' => 'STF', 'name' => 'Staff', 'base_admits' => 1, 'max_admits' => 1, 'base_price_paisa' => 0, 'allowed_participant_types' => ['staff'], 'quantity_total' => 200],
            ['code' => 'VIP', 'name' => 'VIP Guest', 'base_admits' => 2, 'max_admits' => 2, 'base_price_paisa' => 500000, 'allowed_participant_types' => ['guest'], 'quantity_total' => 200, 'requires_approval' => true, 'is_public' => false],
            ['code' => 'FAM', 'name' => 'Family', 'base_admits' => 4, 'max_admits' => 6, 'base_price_paisa' => 400000, 'additional_adult_price_paisa' => 100000, 'additional_child_price_paisa' => 50000, 'allowed_participant_types' => ['former_student', 'current_student', 'teacher', 'staff'], 'quantity_total' => 4000],
            ['code' => 'SPN', 'name' => 'Sponsor', 'base_admits' => 2, 'max_admits' => 4, 'base_price_paisa' => 1000000, 'allowed_participant_types' => ['sponsor'], 'quantity_total' => 100, 'requires_approval' => true, 'is_public' => false],

            // The two centennial categories the public ticket page sells.
            // These are the money authority for that page — it renders these
            // rows, it does not carry its own constants.
            //
            // CEN-FAMILY encodes "every head at the same flat rate, the
            // registering alumnus included" through the generic formula:
            // with base_admits = 1, a party of N costs
            //   200000 + (N-1)×200000 = 200000 × N.
            // Setting the adult and child extras to the same figure is what
            // makes "flat per person" fall out of a tiered pricing model.
            // ONE centennial ticket, not a single/family pair. Every
            // participant type registers on this row and may bring family;
            // bringing nobody is simply a party of one, so there is no
            // "family ticket" to pick and no way to pick the wrong one.
            //
            // The tiered columns carry the whole rule:
            //   registrant        → base_price_paisa          (৳2,500)
            //   a current student → current_student_price     (৳500)
            //   each extra adult  → additional_adult_price    (৳2,000)
            //   each extra child  → additional_child_price    (৳2,000)
            //   child under 2     → free, still admitted
            //
            // The student rate applies to the student's own seat only —
            // family they bring pays the standard extra rates, so the
            // discount follows the student, not their whole party.
            //
            // ⚠️ ৳500 is carried over from the standalone STU ticket type
            // above, which is the only current-student price this system has
            // ever had. It is a starting value, not a client decision: set
            // the real one in the admin console (Tickets → Centennial
            // Ticket → Student price), or here before first seeding a
            // production database. Note the price lock — once CEN has sold
            // anything, PATCH refuses to change it.
            //
            // `allowed_participant_types` is the public form's own dropdown
            // — it builds the list from this column and CreateRegistration
            // enforces it, so widening the audience is a seeder/admin edit
            // rather than a frontend change. `guest` and `sponsor` are
            // deliberately absent: they have their own VIP/SPN types, which
            // are is_public=false and requires_approval=true, and must not
            // become self-serve at the centennial price.
            ['code' => 'CEN', 'name' => 'Centennial Ticket', 'name_bn' => 'শতবর্ষ টিকিট', 'base_admits' => 1, 'max_admits' => 9, 'base_price_paisa' => 250000, 'additional_adult_price_paisa' => 200000, 'additional_child_price_paisa' => 200000, 'current_student_price_paisa' => 50000, 'child_free_under_age' => 2, 'allowed_participant_types' => self::CENTENNIAL_AUDIENCE, 'quantity_total' => 12000, 'includes_tshirt' => true],
        ];

        foreach ($types as $i => $type) {
            $ticketType = TicketType::updateOrCreate(
                ['code' => $type['code']],
                array_merge([
                    'currency' => 'BDT',
                    'includes_meal' => true,
                    'is_active' => true,
                    'is_public' => true,
                    'sort_order' => $i,
                ], $type)
            );

            // The public ticket-types endpoint filters on
            // `sale_starts_at <= now()`, and SQL's NULL comparison is not
            // true — so a type seeded without a sale window is invisible to
            // the public site no matter how active and public it is.
            // Backfilled rather than merged into the attributes above so a
            // re-seed never drags an admin-chosen opening date forward.
            if ($ticketType->sale_starts_at === null) {
                $ticketType->forceFill(['sale_starts_at' => now()])->save();
            }
        }

        $this->retireSupersededTypes();
    }

    /**
     * CEN-SINGLE and CEN-FAMILY briefly existed as a single/family pair
     * before the two collapsed into one CEN ticket with optional family.
     *
     * They are retired rather than deleted: `registrations.ticket_type_id`
     * is ON DELETE RESTRICT, so any environment that sold one cannot drop
     * the row without destroying that history. Deactivating takes them off
     * the public API and out of the sale window, which is all that's needed
     * — and is the same thing an admin would do to withdraw any ticket type
     * that has already sold.
     */
    private function retireSupersededTypes(): void
    {
        TicketType::whereIn('code', ['CEN-SINGLE', 'CEN-FAMILY'])
            ->update(['is_active' => false, 'is_public' => false]);
    }
}
