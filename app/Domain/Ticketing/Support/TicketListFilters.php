<?php

namespace App\Domain\Ticketing\Support;

use App\Domain\Shared\Support\ListSort;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of which tickets a filter set selects, and in what
 * order — shared by the admin list, the bulk-resend preview and the bulk
 * resend itself.
 *
 * Shared for the reason `AttendeeListFilters` already is: a bulk action
 * that quietly disagrees with the screen it was launched from is worse
 * than no bulk action. The operator filters to 40 tickets, presses
 * "resend to all", and a second implementation of the same filters sends
 * to a different 40 — with no way to tell from either screen, and, on
 * SMS, a bill for it.
 */
class TicketListFilters
{
    /**
     * Sortable columns, public field name => real column. Ticket type is
     * shown in the table but lives behind a relation, so it is unsortable;
     * holder name is not — it is snapshotted onto the ticket at issuance,
     * precisely because a ticket must not change when the attendee record
     * does.
     *
     * @var array<string, string>
     */
    public const array SORTABLE = [
        'ticket_number' => 'ticket_number',
        'holder_name' => 'holder_name',
        'status' => 'status',
        'admitted_count' => 'admitted_count',
        'created_at' => 'created_at',
    ];

    /** Newest first — this list had no ORDER BY at all before. */
    public const string DEFAULT_SORT = 'created_at';

    /**
     * How many tickets an operator may hand-pick in one go.
     *
     * The ceiling is the bulk-resend *preview*, which is a GET so the
     * selection travels in the query string — 100 ULIDs is roughly 3.4 KB
     * of URL, comfortably inside the 8 KB request line most servers accept,
     * where 200 would not be. It is not a limit on how many tickets can be
     * resent to: a larger send is what the filters and "resend to all" are
     * for, and the 422 says so rather than leaving the operator to guess.
     */
    public const int MAX_ULIDS = 100;

    /**
     * Validation for the hand-picked-tickets filter, shared by the preview
     * and the bulk resend so the two cannot disagree about what is
     * acceptable — a selection the preview prices must be one the send
     * will accept.
     *
     * @return array<string, mixed>
     */
    public static function ulidRules(): array
    {
        return [
            'ulids' => ['sometimes', 'array', 'max:'.self::MAX_ULIDS],
            'ulids.*' => ['string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function ulidMessages(): array
    {
        return [
            'ulids.max' => 'Select at most '.self::MAX_ULIDS.' tickets at a time. '
                .'To reach more than that, filter the list and use "resend to all" instead.',
        ];
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $params
     * @return Builder<Ticket>
     */
    public static function apply(Builder $query, array $params, bool $sorted = true): Builder
    {
        // Hand-picked tickets. Composed *with* the other filters rather
        // than replacing them, so a selection can only ever narrow the set
        // — there is no combination of parameters that makes an explicit
        // pick reach somebody who was not picked.
        //
        // Present-but-empty means "none", not "no filter", and that
        // distinction is the load-bearing one: a client that posts an
        // empty selection must send to nobody, never to the entire
        // roster. `whereIn` on an empty list already answers nothing; what
        // matters is that presence alone is what triggers it.
        if (array_key_exists('ulids', $params) && $params['ulids'] !== null) {
            $ulids = is_array($params['ulids']) ? $params['ulids'] : [$params['ulids']];
            $query->whereIn('ulid', array_values(array_filter($ulids, 'is_string')));
        }

        $status = self::str($params, 'status');
        if ($status !== null) {
            $query->where('status', $status);
        }

        $ticketTypeId = $params['ticket_type_id'] ?? null;
        if (is_numeric($ticketTypeId) && (int) $ticketTypeId > 0) {
            $query->where('ticket_type_id', (int) $ticketTypeId);
        }

        $search = self::str($params, 'search');
        if ($search !== null) {
            // `%`, `_` and `\` escaped: unescaped, a search for `%` matches
            // every row, which turns a filtered bulk action into an
            // unfiltered one while looking filtered.
            $escaped = addcslashes($search, '%_\\');
            $query->where(function (Builder $q) use ($escaped): void {
                $q->where('ticket_number', 'like', "%{$escaped}%")
                    ->orWhere('holder_name', 'like', "%{$escaped}%");
            });
        }

        if ($sorted) {
            ListSort::apply($query, $params, self::SORTABLE, self::DEFAULT_SORT);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function str(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
