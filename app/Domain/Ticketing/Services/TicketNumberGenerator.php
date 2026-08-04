<?php

namespace App\Domain\Ticketing\Services;

use Illuminate\Support\Facades\DB;

/**
 * Replaces the interim `Ticket::…->lockForUpdate()->count() + 1` counter
 * (full-scans `tickets` on every issuance and can collide under
 * concurrency — docs/08 Phase 2 review). Locks one narrow row per
 * (ticket type, batch) pair instead of the whole `tickets` table, so
 * issuing tickets for different types/batches never blocks each other.
 *
 * The idempotent upsert only guarantees the counter row exists; MySQL's
 * `LAST_INSERT_ID(expr)` trick to read the post-increment value back from
 * that same statement is deliberately not used here — empirically, for a
 * genuinely new row (not the ON DUPLICATE KEY UPDATE branch), both PDO's
 * lastInsertId() and a same-session follow-up SELECT LAST_INSERT_ID()
 * return the row's real auto-increment id, not the LAST_INSERT_ID(expr)
 * override, despite MySQL's docs suggesting otherwise. SELECT ... FOR
 * UPDATE is slower by one round trip but its correctness doesn't depend
 * on that undocumented-in-practice behaviour.
 */
class TicketNumberGenerator
{
    public function next(int $ticketTypeId, string $batchLabel): int
    {
        return DB::transaction(function () use ($ticketTypeId, $batchLabel): int {
            DB::statement(
                'INSERT INTO ticket_number_sequences (ticket_type_id, batch_label, seq, created_at, updated_at)
                    VALUES (?, ?, 0, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE id = id',
                [$ticketTypeId, $batchLabel]
            );

            // firstOrFail(), not first(): the upsert above guarantees the
            // row exists, so a miss here means that invariant broke.
            $row = DB::table('ticket_number_sequences')
                ->where('ticket_type_id', $ticketTypeId)
                ->where('batch_label', $batchLabel)
                ->lockForUpdate()
                ->firstOrFail();

            $next = (int) $row->seq + 1;

            DB::table('ticket_number_sequences')
                ->where('id', $row->id)
                ->update(['seq' => $next, 'updated_at' => now()]);

            return $next;
        });
    }
}
