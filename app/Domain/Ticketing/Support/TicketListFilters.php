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
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $params
     * @return Builder<Ticket>
     */
    public static function apply(Builder $query, array $params, bool $sorted = true): Builder
    {
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
