<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses `registrations.comments` into `registrations.special_notes`.
 *
 * The two columns have carried the same thing since the table was created:
 * both are free text, both nullable, both `max:1000`, and every layer that
 * accepted one accepted the other — public create, attendee self-service
 * update, admin update, the resource, the OpenAPI spec. Nothing in the
 * codebase ever distinguished them, so in practice a note landed in
 * whichever box the form happened to render. One field is the whole point:
 * two meant staff had to read both to be sure they had not missed
 * something.
 *
 * **The merge runs before the drop, and that ordering is the migration.**
 * `comments` is the box the public ticket form has been writing to, so on
 * any environment that has taken a real registration it holds
 * attendee-supplied text — dietary needs, accessibility requests, "my
 * father is 82 and uses a wheelchair". Dropping the column without moving
 * that first would destroy it silently, and it is precisely the text
 * somebody needs on event day.
 *
 * Where both are filled the two are concatenated rather than one winning:
 * picking a survivor is a judgement about content this migration cannot
 * read, and a blank line between them is legible to the human who will.
 *
 * `down()` re-adds an empty column. The merge genuinely cannot be undone —
 * once the two strings are one string there is no marker saying where the
 * seam was — so this is deliberately not a reversible migration, only a
 * re-runnable-forward one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard rather than assume: a fresh database created after this
        // migration's sibling ran will not have the column, and a partial
        // deploy must not fatal here.
        if (! Schema::hasColumn('registrations', 'comments')) {
            return;
        }

        DB::table('registrations')
            ->whereNotNull('comments')
            ->where('comments', '<>', '')
            ->update([
                'special_notes' => DB::raw(
                    "CASE WHEN special_notes IS NULL OR special_notes = '' ".
                    'THEN comments '.
                    "ELSE CONCAT(special_notes, '\n\n', comments) END"
                ),
            ]);

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('comments');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->text('comments')->nullable()->after('discount_code');
        });
    }
};
