<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An explicit switch on the ticket type for whether family may be added.
 *
 * Until now "can this ticket carry family?" was inferred from
 * `max_admits > 1`, which conflates two decisions: how many the ticket
 * *could* admit and whether the organiser wants the public form offering
 * family rows on it at all. An admin who wanted to turn family off on the
 * centennial ticket had to set `max_admits` to 1 and lose the figure; one
 * who set `max_admits` to 9 for a VIP table got a family section they
 * never asked for. This column separates the two: `PartySize::limitFor()`
 * answers 1 when it is off and the event-wide `registration.max_family_size`
 * when it is on — `max_admits` no longer bounds the party at all — and
 * the public site hides the family section on the same flag.
 *
 * Backfilled from the inference it replaces, so nothing on a live system
 * changes behaviour on deploy: a type that could carry family keeps
 * carrying it, a one-seat type stays one-seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->boolean('allows_family')->default(false)->after('max_admits');
        });

        DB::table('ticket_types')->where('max_admits', '>', 1)->update(['allows_family' => true]);
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('allows_family');
        });
    }
};
