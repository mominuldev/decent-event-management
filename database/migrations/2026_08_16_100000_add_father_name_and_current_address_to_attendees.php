<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two more things the registration form now asks every registrant for:
     * their father's name and where they currently live.
     *
     * Both columns are nullable even though `StoreRegistrationRequest` makes
     * them required, and that is deliberate rather than an oversight — every
     * attendee row that predates this migration has neither, and there is no
     * value to backfill them with that would not be a fabrication. Requiring
     * them is a rule about what the *public form* may submit from now on;
     * the column only records whether the answer is known.
     *
     * `current_address` is one free-text line beside the existing
     * `address_district`/`country` rather than a structured set of columns:
     * a Bangladeshi address is written as prose (village/road, thana,
     * district), nothing here parses it, and the alumni filling this in span
     * five decades of age — a four-field address block is a form they abandon.
     */
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->string('father_name', 150)->nullable()->after('full_name_bn');
            $table->string('current_address', 255)->nullable()->after('address_district');
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn(['father_name', 'current_address']);
        });
    }
};
