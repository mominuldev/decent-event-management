<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Registration\Support\RegistrationContext;
use App\Domain\Shared\Support\EventSettingCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of what a day's takings are: which payments count, what
 * makes one an online sale rather than an offline one, and where a day ends.
 *
 * All three of those are decisions rather than lookups, so they live here and
 * are made once — the report endpoint, and anything that later exports it,
 * ask this class rather than each writing their own `where`.
 *
 * **Online versus offline is `payments.channel`, not `registrations.source`.**
 * `channel` is written by {@see RegistrationContext}
 * and is load-bearing elsewhere — the expiry sweeper, the nightly
 * reconciliation and `payments:stuck` all select on `channel != 'manual'` —
 * so it cannot quietly drift. `source` already has: the factory and both
 * seeders write `'web'` while the application writes `'web_public'`, so a
 * report grouped on it would split one column in two on any database that
 * has ever been seeded.
 *
 * Anything that is not `online` counts as offline rather than being dropped.
 * A channel value nobody anticipated must show up in the totals as money
 * taken somehow, not vanish from them — the same reasoning as PayStation's
 * unrecognised-status arm defaulting to pending rather than failed.
 */
final class DailySalesReport
{
    /**
     * Statuses that mean money actually moved.
     *
     * `partially_refunded` and `refunded` are in the list deliberately. A
     * refund is a later, separately-dated event; leaving those two out would
     * retroactively erase the original sale from the day it was made, so a
     * day closed and reconciled on Monday would report a different figure on
     * Friday. Refunds are reported on their own processing date instead.
     *
     * @var list<string>
     */
    public const array COLLECTED_STATUSES = ['succeeded', 'partially_refunded', 'refunded'];

    /** The gateway channel. Everything else is counted as offline. */
    public const string ONLINE_CHANNEL = 'online';

    /** Days in the default window when the caller names no dates. */
    public const int DEFAULT_DAYS = 30;

    /**
     * The longest window this endpoint will answer synchronously.
     *
     * Every day in the range is returned, gaps included, so a chart drawn
     * from the rows does not silently close over a day with no sales — which
     * is why the ceiling is on days rather than on rows.
     */
    public const int MAX_DAYS = 366;

    /**
     * @param  array{from?: mixed, to?: mixed, ssc_batch_year?: mixed, ticket_type_id?: mixed}  $filters
     *                                                                                                    `ticket_type_id` is the internal id, resolved from a ULID by the
     *                                                                                                    request — no auto-increment key crosses the API boundary.
     * @return array{
     *     filters: array<string, mixed>,
     *     totals: array<string, int>,
     *     methods: list<array<string, mixed>>,
     *     days: list<array<string, int|string>>
     * }
     */
    public static function build(array $filters): array
    {
        $timezone = self::timezone();

        [$from, $to] = self::window($filters, $timezone);

        // Half-open in UTC: >= the first local midnight, < the local midnight
        // after the last day. Anything else either loses a payment taken in
        // the final second of the window or counts a midnight twice.
        $startUtc = $from->startOfDay()->utc();
        $endUtc = $to->addDay()->startOfDay()->utc();

        $rows = self::salesRows($startUtc, $endUtc, $filters, $timezone);
        $refunds = self::refundRows($startUtc, $endUtc, $filters, $timezone);

        $days = self::assembleDays($from, $to, $rows, $refunds);

        return [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'timezone' => $timezone,
                'ssc_batch_year' => self::intOrNull($filters, 'ssc_batch_year'),
                'ticket_type_ulid' => self::stringOrNull($filters, 'ticket_type_ulid'),
            ],
            'totals' => self::totals($days),
            'methods' => self::methodBreakdown($rows),
            'days' => $days,
        ];
    }

    /**
     * The timezone whose midnight closes a day.
     *
     * `config('app.timezone')` is UTC, so without this every day would run
     * 6am to 6am in Dhaka and an evening sale would be banked against the
     * following date. Admin-settable rather than hardcoded, because the one
     * thing worse than the wrong timezone is one that cannot be corrected
     * without a deploy.
     */
    public static function timezone(): string
    {
        $value = EventSettingCatalogue::resolve('report.timezone')?->typedValue();
        $timezone = is_string($value) ? trim($value) : '';

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return 'Asia/Dhaka';
        }

        return $timezone;
    }

    /**
     * The inclusive local dates the report covers.
     *
     * @param  array<string, mixed>  $filters
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private static function window(array $filters, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $from = self::date($filters, 'from', $timezone);
        $to = self::date($filters, 'to', $timezone);

        // Either bound alone is meaningful: "from the 1st" means up to today,
        // "to the 10th" means the 30 days ending then.
        if ($from === null && $to === null) {
            return [$today->subDays(self::DEFAULT_DAYS - 1), $today];
        }

        if ($from === null) {
            /** @var CarbonImmutable $to */
            return [$to->subDays(self::DEFAULT_DAYS - 1), $to];
        }

        if ($to === null) {
            return [$from, max($from, $today)];
        }

        return [$from, $to];
    }

    /**
     * One row per (local date, channel, method). Grouping by method as well
     * costs nothing at this cardinality and is what separates cash taken at
     * a desk from a bank transfer somebody approved — both are offline, and
     * only one of them should be in the till at the end of the night.
     *
     * @param  array<string, mixed>  $filters
     * @return list<object{sale_date: string, channel: string, method: string, payments_count: int, gross_paisa: int, persons: int}>
     */
    private static function salesRows(CarbonImmutable $startUtc, CarbonImmutable $endUtc, array $filters, string $timezone): array
    {
        $query = DB::table('payments')
            ->join('registrations', 'registrations.id', '=', 'payments.registration_id')
            ->join('attendees', 'attendees.id', '=', 'registrations.attendee_id')
            ->selectRaw('date(convert_tz(payments.paid_at, ?, ?)) as sale_date', self::timezoneShift($timezone))
            ->addSelect('payments.channel', 'payments.method')
            ->selectRaw('count(*) as payments_count')
            ->selectRaw('sum(payments.amount_paid_paisa) as gross_paisa')
            ->selectRaw('sum(registrations.adults_count + registrations.children_count + registrations.infants_count) as persons')
            ->whereIn('payments.status', self::COLLECTED_STATUSES)
            ->whereNotNull('payments.paid_at')
            ->where('payments.paid_at', '>=', $startUtc)
            ->where('payments.paid_at', '<', $endUtc)
            // A soft-deleted registration is not a sale. The join is a plain
            // one, so Eloquent's global scope does not apply here.
            ->whereNull('registrations.deleted_at')
            ->groupBy('sale_date', 'payments.channel', 'payments.method')
            ->orderBy('sale_date');

        self::applyScopeFilters($query, $filters);

        /** @var list<object{sale_date: string, channel: string, method: string, payments_count: int, gross_paisa: int, persons: int}> $rows */
        $rows = $query->get()->all();

        return $rows;
    }

    /**
     * Refunds by the date they were processed, not the date of the sale they
     * reverse — a day's takings must not change after the day has closed.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array{refund_count: int, refunded_paisa: int}>
     */
    private static function refundRows(CarbonImmutable $startUtc, CarbonImmutable $endUtc, array $filters, string $timezone): array
    {
        $query = DB::table('refunds')
            ->join('registrations', 'registrations.id', '=', 'refunds.registration_id')
            ->join('attendees', 'attendees.id', '=', 'registrations.attendee_id')
            ->selectRaw('date(convert_tz(refunds.processed_at, ?, ?)) as refund_date', self::timezoneShift($timezone))
            ->selectRaw('count(*) as refund_count')
            ->selectRaw('sum(refunds.amount_paisa) as refunded_paisa')
            ->where('refunds.status', 'completed')
            ->whereNotNull('refunds.processed_at')
            ->where('refunds.processed_at', '>=', $startUtc)
            ->where('refunds.processed_at', '<', $endUtc)
            ->whereNull('registrations.deleted_at')
            ->groupBy('refund_date');

        self::applyScopeFilters($query, $filters);

        $byDate = [];

        foreach ($query->get() as $row) {
            /** @var object{refund_date: string, refund_count: int, refunded_paisa: int} $row */
            $byDate[(string) $row->refund_date] = [
                'refund_count' => (int) $row->refund_count,
                'refunded_paisa' => (int) $row->refunded_paisa,
            ];
        }

        return $byDate;
    }

    /**
     * The batch and ticket-type filters, applied identically to sales and to
     * refunds. Filtering one and not the other would produce a net figure
     * for a batch that subtracted refunds belonging to everybody else.
     *
     * @param  Builder  $query
     * @param  array<string, mixed>  $filters
     */
    private static function applyScopeFilters($query, array $filters): void
    {
        $batchYear = self::intOrNull($filters, 'ssc_batch_year');

        if ($batchYear !== null) {
            $query->where('attendees.ssc_batch_year', $batchYear);
        }

        $ticketTypeId = self::intOrNull($filters, 'ticket_type_id');

        if ($ticketTypeId !== null) {
            $query->where('registrations.ticket_type_id', $ticketTypeId);
        }
    }

    /**
     * The `convert_tz()` arguments that shift a UTC column into the reporting
     * timezone, bound rather than interpolated.
     *
     * A **fixed offset**, not the zone name: `CONVERT_TZ` resolves a named
     * zone out of MySQL's `mysql.time_zone_name` tables, which are empty on a
     * default install and on most shared hosting — and it returns NULL rather
     * than erroring when it cannot resolve one, so every row would silently
     * group under a null date. An offset string always works. Bangladesh has
     * no DST, so the offset is exact; for a zone that does observe DST, the
     * hour either side of a transition would land on the neighbouring day.
     *
     * @return array{string, string}
     */
    private static function timezoneShift(string $timezone): array
    {
        return ['+00:00', CarbonImmutable::now($timezone)->format('P')];
    }

    /**
     * Every date in the window, including the ones with nothing on them.
     *
     * @param  list<object{sale_date: string, channel: string, method: string, payments_count: int, gross_paisa: int, persons: int}>  $rows
     * @param  array<string, array{refund_count: int, refunded_paisa: int}>  $refunds
     * @return list<array<string, int|string>>
     */
    private static function assembleDays(CarbonImmutable $from, CarbonImmutable $to, array $rows, array $refunds): array
    {
        $days = [];

        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $days[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'online_payments' => 0,
                'online_paisa' => 0,
                'online_persons' => 0,
                'offline_payments' => 0,
                'offline_paisa' => 0,
                'offline_persons' => 0,
                'total_payments' => 0,
                'total_paisa' => 0,
                'total_persons' => 0,
                'refund_count' => 0,
                'refunded_paisa' => 0,
                'net_paisa' => 0,
            ];
        }

        foreach ($rows as $row) {
            $date = (string) $row->sale_date;

            if (! isset($days[$date])) {
                continue;
            }

            $prefix = self::isOnline((string) $row->channel) ? 'online' : 'offline';

            $days[$date][$prefix.'_payments'] += (int) $row->payments_count;
            $days[$date][$prefix.'_paisa'] += (int) $row->gross_paisa;
            $days[$date][$prefix.'_persons'] += (int) $row->persons;
        }

        foreach ($refunds as $date => $refund) {
            if (! isset($days[$date])) {
                continue;
            }

            $days[$date]['refund_count'] = $refund['refund_count'];
            $days[$date]['refunded_paisa'] = $refund['refunded_paisa'];
        }

        foreach ($days as $date => $day) {
            $days[$date]['total_payments'] = $day['online_payments'] + $day['offline_payments'];
            $days[$date]['total_paisa'] = $day['online_paisa'] + $day['offline_paisa'];
            $days[$date]['total_persons'] = $day['online_persons'] + $day['offline_persons'];
            $days[$date]['net_paisa'] = $days[$date]['total_paisa'] - $days[$date]['refunded_paisa'];
        }

        // Newest first, matching every other admin list. The SPA reverses it
        // for the chart, where left-to-right has to mean older-to-newer.
        return array_values(array_reverse($days));
    }

    /**
     * @param  list<array<string, int|string>>  $days
     * @return array<string, int>
     */
    private static function totals(array $days): array
    {
        $totals = [
            'online_payments' => 0,
            'online_paisa' => 0,
            'online_persons' => 0,
            'offline_payments' => 0,
            'offline_paisa' => 0,
            'offline_persons' => 0,
            'total_payments' => 0,
            'total_paisa' => 0,
            'total_persons' => 0,
            'refund_count' => 0,
            'refunded_paisa' => 0,
            'net_paisa' => 0,
        ];

        foreach ($days as $day) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) $day[$key];
            }
        }

        return $totals;
    }

    /**
     * Takings per payment method, so "offline" can be read as cash in a tin
     * versus a transfer somebody approved against a bank statement.
     *
     * @param  list<object{sale_date: string, channel: string, method: string, payments_count: int, gross_paisa: int, persons: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private static function methodBreakdown(array $rows): array
    {
        $methods = [];

        foreach ($rows as $row) {
            $method = (string) $row->method;
            $key = $row->channel.'|'.$method;

            $methods[$key] ??= [
                'method' => $method,
                'channel' => (string) $row->channel,
                'kind' => self::isOnline((string) $row->channel) ? 'online' : 'offline',
                'payments' => 0,
                'paisa' => 0,
            ];

            $methods[$key]['payments'] += (int) $row->payments_count;
            $methods[$key]['paisa'] += (int) $row->gross_paisa;
        }

        // usort reindexes, so the string keys the accumulator used are gone
        // and this is already a list.
        usort($methods, fn (array $a, array $b) => $b['paisa'] <=> $a['paisa']);

        return $methods;
    }

    public static function isOnline(string $channel): bool
    {
        return $channel === self::ONLINE_CHANNEL;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function date(array $filters, string $key, string $timezone): ?CarbonImmutable
    {
        $value = self::stringOrNull($filters, $key);

        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value, $timezone)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function stringOrNull(array $filters, string $key): ?string
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
    private static function intOrNull(array $filters, string $key): ?int
    {
        $value = self::stringOrNull($filters, $key);

        return $value === null ? null : (int) $value;
    }
}
