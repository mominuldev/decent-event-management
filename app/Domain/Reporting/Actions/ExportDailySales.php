<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Registration\Actions\ExportAttendees;
use App\Domain\Reporting\Services\DailySalesCsvExportWriter;
use App\Domain\Reporting\Services\DailySalesPdfExportWriter;
use App\Domain\Reporting\Services\DailySalesXlsxExportWriter;
use App\Domain\Reporting\Support\DailySalesReport;
use App\Domain\Reporting\Support\ExportedFile;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Support\Str;

/**
 * Builds the daily sales export in whichever of the three formats was asked
 * for, from exactly the report {@see DailySalesReport} would have rendered on
 * screen for the same filters.
 *
 * **Sharing that one call is the point**, and it is the same discipline
 * {@see ExportAttendees} follows: an export whose numbers disagree with the
 * screen it was launched from is worse than no export, because the operator
 * has no way to tell which one is wrong. There is no second query here.
 *
 * **No row ceiling, unlike the attendee export**, and that is a structural
 * fact rather than an omission. `DailySalesReport::MAX_DAYS` already caps the
 * window at 366 days and a day is one row, so the largest document this can
 * ever be asked for is 366 rows of integers with no images — orders of
 * magnitude below anything that needed a measured limit in
 * `config/exports.php`. Adding a second, larger number there would be dead
 * config that reads as a real constraint.
 *
 * The audit trail is written here rather than in the controller (D8), so a
 * console command or a future queued export cannot skip it. Every day's
 * takings for the whole event leaving the system in one file is exactly the
 * kind of act CLAUDE.md names as needing to reach `activity_logs`; the row
 * records the window and the totals, not the grid.
 */
class ExportDailySales
{
    public function __construct(
        private readonly DailySalesCsvExportWriter $csvWriter,
        private readonly DailySalesXlsxExportWriter $xlsxWriter,
        private readonly DailySalesPdfExportWriter $pdfWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  from / to / ssc_batch_year / ticket_type_id
     * @param  'csv'|'xlsx'|'pdf'  $format
     */
    public function execute(
        array $filters,
        string $format,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): ExportedFile {
        $report = DailySalesReport::build($filters);

        $eventName = $this->eventName();
        $applied = $this->describeFilters($report['filters']);
        $filename = $this->filename($report['filters'], $format);

        $file = match ($format) {
            'pdf' => $this->pdfWriter->write($report, $filename, $eventName, $applied),
            'xlsx' => $this->xlsxWriter->write($report, $filename, $eventName, $applied),
            default => $this->csvWriter->write($report, $filename),
        };

        $this->log($format, $report, $applied, $actor, $ipAddress, $requestId);

        return $file;
    }

    /**
     * The window is in the filename, not just in the document.
     *
     * A takings sheet is filed, mailed and compared against another one, and
     * "daily-sales.csv" in a folder of six of them tells the reader nothing.
     * The generation timestamp is there too, because the same window exported
     * twice a week apart can legitimately differ — a late IPN settles, a
     * refund is processed.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filename(array $filters, string $format): string
    {
        return sprintf(
            'daily-sales-%s_%s-%s.%s',
            (string) $filters['from'],
            (string) $filters['to'],
            now()->format('Ymd-His'),
            $format,
        );
    }

    private function eventName(): string
    {
        $name = EventSetting::query()->where('key', 'event.name')->value('value');

        return is_string($name) && trim($name) !== '' ? $name : 'Event';
    }

    /**
     * The filters as an operator would recognise them, for the document
     * header and the audit row.
     *
     * The ticket type is resolved to its name rather than shown as a ULID: a
     * 26-character identifier at the top of a printed sheet tells the reader
     * nothing and takes a line to do it. The ULID stays in the audit row's
     * own properties, where it is the useful form.
     *
     * @param  array<string, mixed>  $reportFilters  the *resolved* filters the report echoes back
     * @return array<string, string>
     */
    private function describeFilters(array $reportFilters): array
    {
        $described = [];

        $batchYear = $reportFilters['ssc_batch_year'] ?? null;

        if (is_int($batchYear)) {
            $described['Batch year'] = (string) $batchYear;
        }

        $ulid = $reportFilters['ticket_type_ulid'] ?? null;

        if (is_string($ulid) && $ulid !== '') {
            $name = TicketType::query()->where('ulid', $ulid)->value('name');
            $described['Ticket type'] = is_string($name) ? $name : $ulid;
        }

        return $described;
    }

    /**
     * @param  array{filters: array<string, mixed>, totals: array<string, int>, days: list<array<string, int|string>>, ...}  $report
     * @param  array<string, string>  $applied
     */
    private function log(
        string $format,
        array $report,
        array $applied,
        ?User $actor,
        ?string $ipAddress,
        ?string $requestId,
    ): void {
        $filters = $report['filters'];
        $totals = $report['totals'];

        ActivityLog::create([
            'log_name' => 'report',
            'event' => 'exported',
            'description' => sprintf(
                'Daily sales report exported as %s (%s to %s)',
                $format,
                (string) $filters['from'],
                (string) $filters['to'],
            ),
            'causer_type' => $actor?->getMorphClass(),
            'causer_id' => $actor?->id,
            'subject_type' => null,
            'subject_id' => null,
            'properties' => [
                'report_key' => 'daily_sales',
                'format' => $format,
                'from' => $filters['from'],
                'to' => $filters['to'],
                // Recorded because it decides which date a payment fell on:
                // the same window under a different timezone is a different
                // document, and an audit row that does not say which one was
                // used cannot be matched against the file that left.
                'timezone' => $filters['timezone'],
                'ssc_batch_year' => $filters['ssc_batch_year'],
                'ticket_type_ulid' => $filters['ticket_type_ulid'],
                'described_filters' => $applied,
                'day_count' => count($report['days']),
                // The headline figures, so the audit row says what was taken
                // out rather than only that something was.
                'total_paisa' => $totals['total_paisa'],
                'online_paisa' => $totals['online_paisa'],
                'offline_paisa' => $totals['offline_paisa'],
                'total_payments' => $totals['total_payments'],
            ],
            'ip_address' => $ipAddress,
            'request_id' => substr($requestId ?? (string) Str::ulid(), 0, 26),
        ]);
    }
}
