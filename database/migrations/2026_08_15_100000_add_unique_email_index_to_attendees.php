<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make an attendee's email address unique, as `mobile` already is.
 *
 * `mobile` has carried `uk_attendees_mobile` since the table was created
 * (ADR-08 dedupes attendees on it), but `email` only ever had a plain
 * lookup index — so the same address could be spread across any number of
 * attendee rows, and every email notification, magic-link and export built
 * on top of it had no single person to point at.
 *
 * Two things worth knowing about the resulting constraint:
 *
 * - `attendees.email` is `utf8mb4_0900_ai_ci`, so the index is
 *   case-insensitive at the database level: `A@B.com` collides with
 *   `a@b.com` whatever the application does. The application normalises to
 *   lowercase anyway (see AttendeeIdentity) so stored data matches what is
 *   compared.
 * - It covers soft-deleted rows too, exactly as `uk_attendees_mobile`
 *   already does. MySQL 8 has no partial index, so excluding them would
 *   mean a generated column and a different constraint shape on each of the
 *   two identifiers — worse than the caveat it removes. A soft-deleted
 *   attendee therefore keeps hold of their email and mobile; releasing them
 *   means force-deleting or clearing the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A blank string is not an email address, and — unlike NULL — only
        // one row may hold it once the index exists. Normalise before
        // measuring duplicates, so a table full of `''` reads as "no email"
        // rather than as thousands of conflicts.
        DB::table('attendees')->where('email', '')->update(['email' => null]);
        DB::table('attendees')->whereNotNull('email')->update(['email' => DB::raw('LOWER(TRIM(email))')]);

        $this->assertNoDuplicateEmails();

        Schema::table('attendees', function (Blueprint $table) {
            // Redundant once the unique index exists: MySQL serves the same
            // lookups and range scans from it.
            $table->dropIndex('idx_attendees_email');
            $table->unique('email', 'uk_attendees_email');
        });
    }

    public function down(): void
    {
        Schema::table('attendees', function (Blueprint $table) {
            $table->dropUnique('uk_attendees_email');
            $table->index('email', 'idx_attendees_email');
        });
    }

    /**
     * Refuse to run rather than pick a winner.
     *
     * Deciding which of two attendees keeps a shared address is a merge
     * decision — they have registrations, payments and issued tickets
     * hanging off them, and `merged_into_attendee_id` exists precisely so a
     * human can record that decision. A migration that nulled the losers
     * would destroy the contact detail for real ticket-holders silently, at
     * deploy time, with no audit trail. So it stops and names them instead.
     */
    private function assertNoDuplicateEmails(): void
    {
        /** @var array<int, object{email: string, occurrences: int}> $duplicates */
        $duplicates = DB::table('attendees')
            ->select('email', DB::raw('COUNT(*) AS occurrences'))
            ->whereNotNull('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('occurrences')
            ->get()
            ->all();

        if ($duplicates === []) {
            return;
        }

        $sample = collect($duplicates)
            ->take(10)
            ->map(fn (object $row): string => "  {$row->email} ({$row->occurrences} attendees)")
            ->implode(PHP_EOL);

        throw new RuntimeException(
            count($duplicates).' email address(es) are shared by more than one attendee, so a unique '
            .'index cannot be created. Merge or correct these attendees first:'.PHP_EOL.$sample.PHP_EOL
            .'Find them with: SELECT email, COUNT(*) FROM attendees WHERE email IS NOT NULL '
            .'GROUP BY email HAVING COUNT(*) > 1;'
        );
    }
};
