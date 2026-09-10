<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Registration\Services\AttendeePdfExportWriter;
use App\Domain\Reporting\Support\ExportedFile;
use App\Domain\Shared\Services\HtmlToPdfRenderer;
use App\Domain\Shared\Support\Taka;

/**
 * The daily sales sheet as a PDF — the format an operator prints, signs and
 * files, or attaches to a message to the treasurer.
 *
 * It is a table rather than the card layout {@see AttendeePdfExportWriter}
 * builds, because this document is read down a column: the question it
 * answers is "what did each day take, and how much of it was cash", and a
 * reader comparing days needs them aligned.
 *
 * Portrait A4 with the days newest-first, matching the screen it was launched
 * from — a printed report that disagrees with the console about ordering
 * costs the reader a second reconciliation for nothing.
 */
class DailySalesPdfExportWriter
{
    public const MIME_TYPE = 'application/pdf';

    public function __construct(private readonly HtmlToPdfRenderer $renderer) {}

    /**
     * @param  array{filters: array<string, mixed>, totals: array<string, int>, methods: list<array<string, mixed>>, days: list<array<string, int|string>>}  $report
     * @param  array<string, string>  $appliedFilters  human-readable "Batch year: 1998" pairs
     */
    public function write(array $report, string $filename, string $eventName, array $appliedFilters): ExportedFile
    {
        $html = view('exports.daily-sales', [
            'eventName' => $eventName,
            'generatedAt' => now()->timezone((string) config('app.timezone'))->format('j M Y, g:i A'),
            'appliedFilters' => $appliedFilters,
            'reportFilters' => $report['filters'],
            'totals' => $report['totals'],
            'methods' => $report['methods'],
            // Every day in the window is in the payload, but a printed sheet
            // of 300 zero rows is not a report — it is a way to hide the four
            // days that matter. The screen makes the same cut, with a control
            // to undo it; paper has no such control, so this one is final and
            // the header says how many days were left out.
            'days' => array_values(array_filter(
                $report['days'],
                static fn (array $day): bool => (int) $day['total_payments'] > 0 || (int) $day['refund_count'] > 0,
            )),
            'totalDays' => count($report['days']),
            'money' => static fn (int $paisa): string => Taka::display($paisa),
            'fontFaceCss' => $this->renderer->fontFaceCss(),
            'title' => 'Daily Sales',
            'pageSize' => 'A4 portrait',
            'pageMargin' => '12mm 10mm 14mm 10mm',
        ])->render();

        return new ExportedFile($filename, self::MIME_TYPE, $this->renderer->render($html));
    }
}
