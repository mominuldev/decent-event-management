<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third price tier on the ticket type: what a current student pays for
 * their own seat.
 *
 * The centennial ticket already carried two rates — `base_price_paisa` for
 * the registrant and the `additional_adult`/`additional_child` pair for
 * family they bring. A current student was billed the full base price,
 * because nothing on the row could say otherwise; the standalone STU ticket
 * type that does carry a student rate is a different row the centennial
 * page does not sell.
 *
 * This is a discount on the *registrant's own seat only*. Family a student
 * brings is priced at the standard extra-adult/extra-child rates, so the
 * discount follows the student rather than their whole party.
 *
 * NULL means the type has no student rate and every participant type pays
 * `base_price_paisa` — which is every ticket type that predates this, so
 * their pricing is byte-identical. Same discipline as `child_free_under_age`
 * above it: the rule is an editable property of the one ticket type that
 * has it, not a constant every other type silently inherits. In particular
 * 0 is a real price (free), distinct from NULL (no rule), which is why this
 * is nullable rather than defaulting to zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->unsignedBigInteger('current_student_price_paisa')
                ->nullable()
                ->after('additional_child_price_paisa');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('current_student_price_paisa');
        });
    }
};
