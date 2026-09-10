<?php

use App\Domain\Ticketing\Services\TicketNumberGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ticket numbers became `{TYPE}-{SEQ}` on 2026-09-11 (`CEN-00001`, was
 * `DEC100-CEN-2005-00001`), so `TicketNumberGenerator` counts per ticket
 * type instead of per (type, batch year) and reads a single row keyed on
 * the `SCOPE_ALL_BATCHES` label.
 *
 * Nothing about the old numbers breaks without this: they are different
 * strings, so `tickets.uk_tickets_number` is never at risk, and a missing
 * counter row is created at zero by the generator's own upsert. What this
 * avoids is purely an operator-facing one: on a database that has already
 * issued tickets, the new series would otherwise restart at `CEN-00001`
 * beside an existing `DEC100-CEN-2005-00001`, and a ticket list showing
 * two rows both reading "00001" invites someone to conclude the numbers
 * are not unique.
 *
 * The per-batch rows are left exactly as they are — they are the only
 * record of how far each old series got, and the numbers they allocated
 * are printed on tickets people are holding.
 */
return new class extends Migration
{
    public function up(): void
    {
        $scope = TicketNumberGenerator::SCOPE_ALL_BATCHES;

        /** @var array<int, object{ticket_type_id: int, issued: int}> $priorSeries */
        $priorSeries = DB::table('ticket_number_sequences')
            ->where('batch_label', '!=', $scope)
            ->groupBy('ticket_type_id')
            ->select('ticket_type_id', DB::raw('SUM(seq) AS issued'))
            ->get()
            ->all();

        foreach ($priorSeries as $series) {
            // insertOrIgnore, so re-running after the generator has already
            // created its own row never rewinds a live counter — which
            // would re-mint numbers that are on issued tickets.
            DB::table('ticket_number_sequences')->insertOrIgnore([
                'ticket_type_id' => (int) $series->ticket_type_id,
                'batch_label' => $scope,
                'seq' => (int) $series->issued,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately empty. Deleting the counter would restart the new
        // series from zero and re-mint numbers already printed on issued
        // tickets; leaving it costs nothing, since the old per-batch
        // format never reads this row.
    }
};
