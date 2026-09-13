<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the four `ticket_types` price columns from `*_price_paisa` to
 * `*_price_tk`. This is a rename only: the stored value is still an integer
 * number of paisa (250000 = ৳2,500), exactly as every other money column in
 * the system, and nothing about pricing arithmetic changes. The API field
 * names follow the column names, so the public site and the admin SPA were
 * renamed in the same change.
 *
 * The renames are written out literally rather than looped: Larastan derives
 * every model's column list by statically reading these files, and it cannot
 * follow a `renameColumn($from, $to)` whose arguments are variables — the
 * model would then fail static analysis on every read of the new names.
 *
 * Guarded so a half-applied deploy does not fatal on a second run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ticket_types', 'base_price_paisa')) {
            return;
        }

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->renameColumn('base_price_paisa', 'base_price_tk');
            $table->renameColumn('additional_adult_price_paisa', 'additional_adult_price_tk');
            $table->renameColumn('additional_child_price_paisa', 'additional_child_price_tk');
            $table->renameColumn('current_student_price_paisa', 'current_student_price_tk');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ticket_types', 'base_price_tk')) {
            return;
        }

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->renameColumn('base_price_tk', 'base_price_paisa');
            $table->renameColumn('additional_adult_price_tk', 'additional_adult_price_paisa');
            $table->renameColumn('additional_child_price_tk', 'additional_child_price_paisa');
            $table->renameColumn('current_student_price_tk', 'current_student_price_paisa');
        });
    }
};
