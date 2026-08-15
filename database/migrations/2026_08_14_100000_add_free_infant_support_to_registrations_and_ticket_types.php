<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits "heads we bill for" from "heads that walk through the gate".
 *
 * The centennial family ticket admits a child under 2 free, but that child
 * still physically attends. Before this migration the only lever was
 * `children_count`, which is read by two things at once: pricing in
 * CreateRegistration, and `admits_total` in IssueTicket. Leaving a free
 * infant out of it to get the price right therefore under-counted the
 * admits and the gate would have turned the infant away.
 *
 * `infants_count` is the third head count: never priced, always admitted.
 * `child_free_under_age` puts the threshold on the ticket type rather than
 * in code, so "under 2" is an editable property of the family ticket and
 * not a constant another ticket type would silently inherit. NULL means the
 * type has no free-infant rule at all, which is every existing type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->unsignedTinyInteger('infants_count')->default(0)->after('children_count');
        });

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('child_free_under_age')->nullable()->after('max_admits');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('infants_count');
        });

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('child_free_under_age');
        });
    }
};
