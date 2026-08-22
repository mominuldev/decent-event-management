<?php

namespace App\Domain\Registration\Support;

use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Support\ListSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of who appears in the public attendees directory, what
 * may be filtered on, and in what order — shared by the list response and the
 * summary counters rendered above it.
 *
 * Two invariants this class exists to hold:
 *
 *  - **Only a succeeded registration is public.** A registration is created
 *    by an anonymous caller and sits at `pending_payment` until money is
 *    actually verified, so listing anything earlier would let anyone put a
 *    name on the public site for free, and would publish people who never
 *    completed a purchase. `paid` and `confirmed` are the only two states
 *    where a seat has really been bought (docs/04 §4.7); `cancelled`,
 *    `refunded` and `expired` drop back out of the directory by the same
 *    rule, which is why this is a status list and not an `is_public` flag
 *    somebody has to remember to clear.
 *  - **A column name never travels from the request into SQL.** Sorting is a
 *    fixed map from a public sort key to a pair of real columns. The list
 *    endpoint spans two tables, so it cannot use {@see ListSort}
 *    (which qualifies every column with the queried model's own table) — the
 *    allowlist discipline is reproduced here rather than loosened there.
 *
 * The row is a *registration*, not an attendee: the party makeup the cards and
 * counters show (participation type, adults, children, guests) belongs to the
 * registration, and the person to the attendee behind it.
 */
final class PublicAttendeeDirectory
{
    /**
     * Registration states a seat has genuinely been bought in.
     *
     * @var list<string>
     */
    public const VISIBLE_STATUSES = ['paid', 'confirmed'];

    /**
     * Public sort key => [column, direction]. `recent` orders on the
     * registration; the rest on the person behind it.
     *
     * @var array<string, array{0: string, 1: 'asc'|'desc'}>
     */
    public const SORTS = [
        'batch_asc' => ['attendees.ssc_batch_year', 'asc'],
        'batch_desc' => ['attendees.ssc_batch_year', 'desc'],
        'name_asc' => ['attendees.full_name', 'asc'],
        'recent' => ['registrations.created_at', 'desc'],
    ];

    public const DEFAULT_SORT = 'batch_asc';

    /** Attendee columns a free-text query is matched against. */
    private const SEARCHABLE = [
        'attendees.full_name',
        'attendees.full_name_bn',
        'attendees.occupation',
        'attendees.designation',
        'attendees.organization',
        'attendees.address_district',
        'attendees.current_class',
    ];

    /**
     * Every registration that may be shown publicly, joined to its attendee.
     *
     * The join carries no selected columns — it exists so the attendee's own
     * fields can be filtered and sorted on in SQL — and the attendee is
     * eager-loaded through the relation instead, so nothing collides with a
     * same-named registration column (`created_at`, `id`) on the hydrated
     * model. `attendees.deleted_at` is checked by hand because a join
     * bypasses the related model's own soft-delete scope.
     *
     * @return Builder<Registration>
     */
    public static function query(): Builder
    {
        return Registration::query()
            ->select('registrations.*')
            ->join('attendees', 'attendees.id', '=', 'registrations.attendee_id')
            ->whereNull('attendees.deleted_at')
            ->whereIn('registrations.status', self::VISIBLE_STATUSES);
    }

    /**
     * @param  Builder<Registration>  $query
     * @param  array<string, mixed>  $filters  search / participant_type / batch_year / batch_from / batch_to / has_guests / sort
     * @return Builder<Registration>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        $search = self::string($filters, 'search');

        if ($search !== null) {
            $query->where(function (Builder $q) use ($search): void {
                foreach (self::SEARCHABLE as $column) {
                    $q->orWhere($column, 'like', '%'.self::escapeLike($search).'%');
                }

                // A bare year typed into the search box should find that
                // batch, which a LIKE against a SMALLINT column would not.
                if (ctype_digit($search)) {
                    $q->orWhere('attendees.ssc_batch_year', (int) $search);
                }
            });
        }

        $participantType = self::string($filters, 'participant_type');

        if ($participantType !== null && in_array($participantType, AttendeeListFilters::PARTICIPANT_TYPES, true)) {
            $query->where('attendees.participant_type', $participantType);
        }

        $batchYear = self::integer($filters, 'batch_year');

        if ($batchYear !== null) {
            $query->where('attendees.ssc_batch_year', $batchYear);
        }

        // A decade filter arrives as a range rather than a named decade: the
        // decade list is presentation, and duplicating it here would mean two
        // places to edit when the school adds one.
        $batchFrom = self::integer($filters, 'batch_from');
        $batchTo = self::integer($filters, 'batch_to');

        if ($batchFrom !== null) {
            $query->where('attendees.ssc_batch_year', '>=', $batchFrom);
        }

        if ($batchTo !== null) {
            $query->where('attendees.ssc_batch_year', '<=', $batchTo);
        }

        $hasGuests = self::string($filters, 'has_guests');

        if ($hasGuests === 'yes') {
            $query->has('guests');
        } elseif ($hasGuests === 'no') {
            $query->doesntHave('guests');
        }

        return self::sort($query, $filters);
    }

    /**
     * @param  Builder<Registration>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Registration>
     */
    private static function sort(Builder $query, array $filters): Builder
    {
        $requested = $filters['sort'] ?? null;
        $key = is_string($requested) && array_key_exists($requested, self::SORTS)
            ? $requested
            : self::DEFAULT_SORT;

        [$column, $direction] = self::SORTS[$key];

        // An attendee with no batch year (a teacher, a guest) sorts to the
        // end of a batch ordering in both directions rather than heading the
        // ascending page — MySQL sorts NULL first on ASC. The expression is a
        // constant from the allowlist above, never request input.
        if ($column === 'attendees.ssc_batch_year') {
            $query->orderByRaw($column.' IS NULL');
        }

        return $query
            ->orderBy($column, $direction)
            // Total order, so successive LIMIT/OFFSET pages cannot repeat or
            // skip a row where the primary sort ties — and ties are the norm
            // here, since a batch year is shared by everyone in it.
            ->orderBy('registrations.id', 'asc');
    }

    /**
     * Global counters for the page header — deliberately computed over the
     * whole visible directory, not the filtered page, because that is what
     * they claim to be ("1,240 registered"). Two aggregates rather than
     * loading rows: this runs on every filter change.
     *
     * @return array{total_registered: int, total_alumni: int, total_students: int, total_teachers_staff: int, total_guests: int, total_batches: int}
     */
    public static function summary(): array
    {
        /** @var object{total_registered: int, total_alumni: int, total_students: int, total_teachers_staff: int, total_batches: int}|null $counts */
        $counts = DB::table('registrations')
            ->join('attendees', 'attendees.id', '=', 'registrations.attendee_id')
            ->whereNull('attendees.deleted_at')
            ->whereNull('registrations.deleted_at')
            ->whereIn('registrations.status', self::VISIBLE_STATUSES)
            ->selectRaw('COUNT(*) as total_registered')
            ->selectRaw("SUM(attendees.participant_type = 'former_student') as total_alumni")
            ->selectRaw("SUM(attendees.participant_type = 'current_student') as total_students")
            ->selectRaw("SUM(attendees.participant_type IN ('teacher', 'staff')) as total_teachers_staff")
            ->selectRaw('COUNT(DISTINCT attendees.ssc_batch_year) as total_batches')
            ->first();

        $guests = DB::table('registration_guests')
            ->join('registrations', 'registrations.id', '=', 'registration_guests.registration_id')
            ->join('attendees', 'attendees.id', '=', 'registrations.attendee_id')
            ->whereNull('attendees.deleted_at')
            ->whereNull('registrations.deleted_at')
            ->whereIn('registrations.status', self::VISIBLE_STATUSES)
            ->count();

        return [
            'total_registered' => (int) ($counts->total_registered ?? 0),
            'total_alumni' => (int) ($counts->total_alumni ?? 0),
            'total_students' => (int) ($counts->total_students ?? 0),
            'total_teachers_staff' => (int) ($counts->total_teachers_staff ?? 0),
            'total_guests' => $guests,
            'total_batches' => (int) ($counts->total_batches ?? 0),
        ];
    }

    /**
     * Batch years that actually have someone in the directory, newest first —
     * the filter dropdown's options. Offering a year with no attendees behind
     * it only ever leads to an empty result the reader has to undo.
     *
     * @return list<int>
     */
    public static function availableBatches(): array
    {
        /** @var list<int> */
        return DB::table('registrations')
            ->join('attendees', 'attendees.id', '=', 'registrations.attendee_id')
            ->whereNull('attendees.deleted_at')
            ->whereNull('registrations.deleted_at')
            ->whereIn('registrations.status', self::VISIBLE_STATUSES)
            ->whereNotNull('attendees.ssc_batch_year')
            ->distinct()
            ->orderByDesc('attendees.ssc_batch_year')
            ->pluck('attendees.ssc_batch_year')
            ->map(fn (mixed $year): int => (int) $year)
            ->all();
    }

    /**
     * `%`, `_` and `\` are wildcards to LIKE, not literals. Unescaped, a
     * search for `%` matches every row in the directory.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }

    /**
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

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function integer(array $filters, string $key): ?int
    {
        $value = self::string($filters, $key);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
