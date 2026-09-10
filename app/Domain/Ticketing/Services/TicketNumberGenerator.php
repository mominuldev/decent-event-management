<?php

namespace App\Domain\Ticketing\Services;

use Illuminate\Support\Facades\DB;

/**
 * The counter behind a ticket number's `{TYPE}-{SEQ}` tail.
 *
 * Replaces the interim `Ticket::…->lockForUpdate()->count() + 1` counter
 * (full-scans `tickets` on every issuance and can collide under
 * concurrency — docs/08 Phase 2 review). Locks one narrow row per ticket
 * type instead of the whole `tickets` table, so issuing tickets for
 * different types never blocks each other.
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
    /**
     * Ticket numbers dropped their batch-year segment on 2026-09-11
     * (`DEC100-CEN-2005-00001` -> `CEN-00001`), so one counter per ticket
     * type is now the whole of what keeps a number unique — a per-batch
     * counter would mint `CEN-00001` once for every batch year.
     *
     * `ticket_number_sequences.batch_label` is kept rather than dropped:
     * the pre-2026-09-11 rows record where each old per-batch series got
     * to, which is the only evidence of how the numbers already printed
     * on issued tickets were allocated. Pinning it to a constant here,
     * instead of leaving it a parameter, is what stops a future caller
     * reintroducing a per-batch scope and colliding on
     * `tickets.uk_tickets_number`.
     */
    public const string SCOPE_ALL_BATCHES = 'ALL';

    public function next(int $ticketTypeId): int
    {
        $batchLabel = self::SCOPE_ALL_BATCHES;

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
