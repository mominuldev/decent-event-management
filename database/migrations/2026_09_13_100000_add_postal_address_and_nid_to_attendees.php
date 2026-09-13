<?php

use App\Domain\Registration\Support\NationalId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Three more things the registration form asks every registrant for, and
     * one it asks for only when they have it to hand.
     *
     * Only two of the six fields added to the form in this change are new
     * columns. `date_of_birth`, `blood_group` and `address_district` have
     * existed since the table was created and were simply never collected at
     * registration — `address_district` and `blood_group` could only be set
     * from the attendee's own profile screen, and `date_of_birth` was
     * accepted by both create paths as `nullable` and never sent by any
     * form. So what lands here is the postal half of a Bangladeshi address
     * plus the NID number.
     *
     * All three columns are nullable even though `StoreRegistrationRequest`
     * makes `post_office` and `upazila` required, for the same reason the
     * 2026-08-16 pair are: required is a rule about what the *public form*
     * may submit from now on, while the column only records whether the
     * answer is known. Every attendee row that predates this has none of
     * them and there is nothing truthful to backfill with, so `NOT NULL`
     * would force a fabricated address onto real people.
     *
     * `post_office` and `upazila` are free-text lines beside the existing
     * `address_district`, not foreign keys into a reference table. There are
     * ~495 upazilas and ~9,900 post offices, both of which get renamed,
     * split and reassigned by government notification; nothing here parses
     * an address, and a stale lookup table would refuse a place that really
     * exists. Same reasoning the 2026-08-16 migration gives for
     * `current_address` being prose.
     *
     * Column order ends up as address_district, current_address, post_office,
     * upazila — the district reads out of sequence because it was already
     * there and physical column order is cosmetic. Read the address in the
     * order the form asks for it: current_address, post_office, upazila,
     * address_district.
     *
     * `nid_number` is VARCHAR(32) against a value that is 10, 13 or 17 digits
     * (the three real Bangladeshi NID formats — see
     * {@see NationalId}) and stored digits
     * only. The slack is deliberate: the column must never be the thing that
     * refuses a number, because a database-length error is a 500 where the
     * validator's refusal is a field-level 422.
     *
     * There is deliberately **no unique index on `nid_number`**. A repeated
     * NID is a strong signal of a duplicated person and worth a report, but
     * it is not a fact this system may act on at registration time: the
     * field is optional, a mistyped digit would collide with a stranger, and
     * a `UNIQUE` index would turn that typo into a refused registration for
     * whoever typed second. Attendee identity is deduplicated on the
     * normalised mobile number (ADR-08) and that stays the only key.
     */
    public function up(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->string('post_office', 100)->nullable()->after('current_address');
            $table->string('upazila', 100)->nullable()->after('post_office');
            $table->string('nid_number', 32)->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropColumn(['post_office', 'upazila', 'nid_number']);
        });
    }
};
