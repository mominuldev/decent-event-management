<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Support\ExportedFile;
use App\Domain\Shared\Support\Taka;

/**
 * The machine-readable half of the daily sales export: one header row, one
 * row per day, and nothing else.
 *
 * **No totals row and no filter preamble, deliberately.** Both are in the
 * other two formats, and both would break this one: a trailing "Total" line
 * double-counts the moment anyone sums the column or loads the file into
 * anything, and a preamble above the header stops it being a CSV at all.
 * Provenance lives in the filename (which carries the window) and in the
 * `activity_logs` row the action writes.
 *
 * Amounts are taka with two decimals rather than paisa integers, because the
 * destination is a spreadsheet or an accounts package where the column will
 * be summed and compared against a bank statement — see {@see Taka}.
 */
class DailySalesCsvExportWriter
{
    /**
     * `text/csv` alone would be right, but Excel on Windows treats a bare
     * `text/csv` download as something to open in the browser; the charset
     * is what makes it open the file in Excel with UTF-8 intact.
     */
    public const MIME_TYPE = 'text/csv; charset=UTF-8';

    /** @var list<string> */
    private const HEADINGS = [
        'Date',
        'Online payments',
        'Online amount (BDT)',
        'Online people',
        'Offline payments',
        'Offline amount (BDT)',
        'Offline people',
        'Total payments',
        'Total amount (BDT)',
        'Total people',
        'Refunds',
        'Refunded amount (BDT)',
        'Net amount (BDT)',
    ];

    /**
     * @param  array{days: list<array<string, int|string>>, ...}  $report
     */
    public function write(array $report, string $filename): ExportedFile
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for the CSV export.');
        }

        try {
            // A BOM, because the destination is Excel more often than not and
            // Excel reads a BOM-less UTF-8 CSV as the system's legacy code
            // page. There is no Bangla in these rows today, but the ৳ in a
            // future column would arrive as mojibake without it, and a BOM
            // costs three bytes that every other reader skips.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, self::HEADINGS);

            foreach ($report['days'] as $day) {
                fputcsv($handle, [
                    (string) $day['date'],
                    (int) $day['online_payments'],
                    Taka::plain((int) $day['online_paisa']),
                    (int) $day['online_persons'],
                    (int) $day['offline_payments'],
                    Taka::plain((int) $day['offline_paisa']),
                    (int) $day['offline_persons'],
                    (int) $day['total_payments'],
                    Taka::plain((int) $day['total_paisa']),
                    (int) $day['total_persons'],
                    (int) $day['refund_count'],
                    Taka::plain((int) $day['refunded_paisa']),
                    Taka::plain((int) $day['net_paisa']),
                ]);
            }

            rewind($handle);
            $contents = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return new ExportedFile($filename, self::MIME_TYPE, $contents);
    }
}
