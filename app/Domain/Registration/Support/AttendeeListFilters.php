<?php

namespace App\Domain\Registration\Support;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Support\ListSort;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "which attendees does this filter set select, and in
 * what order" — shared by the admin list endpoint and the attendee export.
 *
 * Sharing it is the point. An export whose filters drift from the list it was
 * launched from is worse than no export: the operator sees 40 rows on screen,
 * downloads a file with a different 40, and has no way to tell. Both callers
 * pass the same request input through here, so a new filter is added once.
 *
 * Ordering is part of that contract, not an afterthought: it runs through
 * ListSort here, so an operator who sorts the screen by name and then exports
 * gets a file in that same order. Both the ordering and the tiebreaking that
 * keeps LIMIT/OFFSET paging stable are ListSort's to define — see its docblock.
 */
final class AttendeeListFilters
{
    /**
     * Every participant type the system stores. Kept in step with
     * StoreRegistrationRequest's own enum — an export filter validated
     * against a narrower list would silently reject rows that really exist.
     *
     * @var list<string>
     */
    public const PARTICIPANT_TYPES = [
        'current_student',
        'former_student',
        'teacher',
        'staff',
        'guardian',
        'guest',
        'sponsor',
        'other',
    ];

    /**
     * Sortable columns, public field name => real column. Deliberately only
     * columns of `attendees` itself: the admin table also shows values that
     * would need a join to sort on, and those are marked unsortable in the
     * SPA rather than given a join this list cannot afford at 20,000 rows.
     *
     * @var array<string, string>
     */
    public const SORTABLE = [
        'full_name' => 'full_name',
        'participant_type' => 'participant_type',
        'ssc_batch_year' => 'ssc_batch_year',
        'is_verified' => 'is_verified',
        'created_at' => 'created_at',
    ];

    /** Newest first — an operator opening the screen wants today's arrivals. */
    public const DEFAULT_SORT = 'created_at';

    public const DEFAULT_DIRECTION = 'desc';

    /**
     * @param  Builder<Attendee>  $query
     * @param  array<string, mixed>  $filters  search / participant_type / ssc_batch_year / sort / direction
     * @return Builder<Attendee>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        $search = self::string($filters, 'search');

        if ($search !== null) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $participantType = self::string($filters, 'participant_type');

        if ($participantType !== null) {
            $query->where('participant_type', $participantType);
        }

        $batchYear = self::string($filters, 'ssc_batch_year');

        if ($batchYear !== null) {
            $query->where('ssc_batch_year', (int) $batchYear);
        }

        return ListSort::apply($query, $filters, self::SORTABLE, self::DEFAULT_SORT, self::DEFAULT_DIRECTION);
    }

    /**
     * A present, non-blank scalar as a string; null for anything else.
     *
     * Treating `''` as absent matches how the SPA sends a cleared filter
     * (`|| undefined` drops most, but a blank string still reaches an
     * explicit query-string caller) — a `LIKE '%%'` scan would otherwise
     * pass while meaning nothing.
     *
     * @param  array<string, mixed>  $filters
     */
    private static function string(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
