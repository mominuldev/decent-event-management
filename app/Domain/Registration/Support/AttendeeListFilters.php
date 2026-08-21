<?php

namespace App\Domain\Registration\Support;

use App\Domain\Registration\Models\Attendee;
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
 * The ordering is deliberate and applies to the list too. `index()` previously
 * had no ORDER BY at all, which MySQL is free to answer inconsistently between
 * one LIMIT/OFFSET page and the next — the same attendee can appear on page 2
 * and page 3 while another never appears at all. `id` breaks the tie so the
 * order is total, not merely "by name".
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
     * @param  Builder<Attendee>  $query
     * @param  array<string, mixed>  $filters
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

        return $query->orderBy('full_name')->orderBy('id');
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
