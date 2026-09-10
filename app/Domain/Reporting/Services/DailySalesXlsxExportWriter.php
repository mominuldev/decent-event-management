<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Support\ExportedFile;
use App\Domain\Shared\Support\Taka;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The daily sales export as a workbook: a provenance block, the day grid with
 * a totals row, and a second sheet breaking the takings down by payment
 * method.
 *
 * Unlike the CSV, this one is opened and read by a person, so it carries the
 * two things the CSV deliberately omits — the window and filters it was built
 * from, and a totals row. A reader who cannot see which window a takings
 * sheet covers cannot reconcile it against anything.
 *
 * **Money cells are numbers, not strings.** A currency-formatted float is
 * still a float to `SUM()`; a pre-formatted "৳2,500.00" is text, and every
 * total built on it comes out as zero with no error. The formatting lives in
 * the cell's number format instead — see {@see Taka::numeric()}.
 */
class DailySalesXlsxExportWriter
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * BDT with two decimals and a thousands separator. Written out rather
     * than using a built-in currency constant because PhpSpreadsheet's
     * bundled ones are all dollar/euro/pound.
     */
    private const MONEY_FORMAT = '#,##0.00';

    /** @var list<string> */
    private const HEADINGS = [
        'Date',
        'Online payments',
        'Online amount',
        'Online people',
        'Offline payments',
        'Offline amount',
        'Offline people',
        'Total payments',
        'Total amount',
        'Total people',
        'Refunds',
        'Refunded amount',
        'Net amount',
    ];

    /** Column letters carrying money, so the format is applied in one pass. */
    private const MONEY_COLUMNS = ['C', 'F', 'I', 'L', 'M'];

    /** @var array<string, float> */
    private const COLUMN_WIDTHS = [
        'A' => 12.0,
        'B' => 15.0, 'C' => 15.0, 'D' => 13.0,
        'E' => 15.0, 'F' => 15.0, 'G' => 13.0,
        'H' => 14.0, 'I' => 15.0, 'J' => 12.0,
        'K' => 10.0, 'L' => 16.0, 'M' => 15.0,
    ];

    /**
     * @param  array{filters: array<string, mixed>, totals: array<string, int>, methods: list<array<string, mixed>>, days: list<array<string, int|string>>}  $report
     * @param  array<string, string>  $appliedFilters  human-readable "Batch year: 1998" pairs
     */
    public function write(array $report, string $filename, string $eventName, array $appliedFilters): ExportedFile
    {
        $spreadsheet = new Spreadsheet;

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Daily sales');

            $row = $this->writeProvenance($sheet, $report, $eventName, $appliedFilters);
            $row = $this->writeGrid($sheet, $report, $row);
            $this->writeTotals($sheet, $report, $row);

            $this->writeMethodsSheet($spreadsheet, $report);

            // Leaves the workbook open on the sheet the reader wants first;
            // adding a second sheet otherwise makes it the active one.
            $spreadsheet->setActiveSheetIndex(0);

            $handle = fopen('php://temp', 'r+');

            if ($handle === false) {
                throw new \RuntimeException('Could not open a temporary stream for the XLSX export.');
            }

            try {
                (new Xlsx($spreadsheet))->save($handle);
                rewind($handle);
                $contents = (string) stream_get_contents($handle);
            } finally {
                fclose($handle);
            }
        } finally {
            // PhpSpreadsheet holds cell collections in memory until the
            // workbook is released; without this an export in a long-running
            // queue worker would keep every one of them alive.
            $spreadsheet->disconnectWorksheets();
        }

        return new ExportedFile($filename, self::MIME_TYPE, $contents);
    }

    /**
     * The header block: what this file is, and which window and filters it
     * was built from. Returns the row the grid should start on.
     *
     * @param  array{filters: array<string, mixed>, ...}  $report
     * @param  array<string, string>  $appliedFilters
     */
    private function writeProvenance(Worksheet $sheet, array $report, string $eventName, array $appliedFilters): int
    {
        $filters = $report['filters'];

        $sheet->setCellValue('A1', $eventName.' — Daily sales');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $lines = [
            'Window' => sprintf('%s to %s', (string) $filters['from'], (string) $filters['to']),
            // Named explicitly: the same payment falls on a different date
            // under a different timezone, so a sheet that does not say which
            // one it used cannot be reconciled against one that does.
            'Day boundary' => 'Midnight '.(string) $filters['timezone'],
            'Generated' => now()->timezone((string) config('app.timezone'))->format('j M Y, g:i A').' '.(string) config('app.timezone'),
        ] + $appliedFilters;

        $row = 2;

        foreach ($lines as $label => $value) {
            $sheet->setCellValue('A'.$row, $label);
            $sheet->setCellValue('B'.$row, $value);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $row++;
        }

        return $row + 1;
    }

    /**
     * @param  array{days: list<array<string, int|string>>, ...}  $report
     */
    private function writeGrid(Worksheet $sheet, array $report, int $headingRow): int
    {
        foreach (self::HEADINGS as $i => $heading) {
            $sheet->setCellValue([$i + 1, $headingRow], $heading);
        }

        $sheet->getStyle('A'.$headingRow.':M'.$headingRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE9FE']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        // The provenance block sits above, so the freeze has to be below the
        // heading row rather than at a fixed A2.
        $sheet->freezePane('A'.($headingRow + 1));

        $row = $headingRow + 1;

        foreach ($report['days'] as $day) {
            $sheet->setCellValueExplicit('A'.$row, (string) $day['date'], DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$row, (int) $day['online_payments']);
            $sheet->setCellValue('C'.$row, Taka::numeric((int) $day['online_paisa']));
            $sheet->setCellValue('D'.$row, (int) $day['online_persons']);
            $sheet->setCellValue('E'.$row, (int) $day['offline_payments']);
            $sheet->setCellValue('F'.$row, Taka::numeric((int) $day['offline_paisa']));
            $sheet->setCellValue('G'.$row, (int) $day['offline_persons']);
            $sheet->setCellValue('H'.$row, (int) $day['total_payments']);
            $sheet->setCellValue('I'.$row, Taka::numeric((int) $day['total_paisa']));
            $sheet->setCellValue('J'.$row, (int) $day['total_persons']);
            $sheet->setCellValue('K'.$row, (int) $day['refund_count']);
            $sheet->setCellValue('L'.$row, Taka::numeric((int) $day['refunded_paisa']));
            $sheet->setCellValue('M'.$row, Taka::numeric((int) $day['net_paisa']));
            $row++;
        }

        return $row;
    }

    /**
     * @param  array{totals: array<string, int>, days: list<array<string, int|string>>, ...}  $report
     */
    private function writeTotals(Worksheet $sheet, array $report, int $row): void
    {
        $totals = $report['totals'];

        $sheet->setCellValue('A'.$row, 'Total');
        $sheet->setCellValue('B'.$row, $totals['online_payments']);
        $sheet->setCellValue('C'.$row, Taka::numeric($totals['online_paisa']));
        $sheet->setCellValue('D'.$row, $totals['online_persons']);
        $sheet->setCellValue('E'.$row, $totals['offline_payments']);
        $sheet->setCellValue('F'.$row, Taka::numeric($totals['offline_paisa']));
        $sheet->setCellValue('G'.$row, $totals['offline_persons']);
        $sheet->setCellValue('H'.$row, $totals['total_payments']);
        $sheet->setCellValue('I'.$row, Taka::numeric($totals['total_paisa']));
        $sheet->setCellValue('J'.$row, $totals['total_persons']);
        $sheet->setCellValue('K'.$row, $totals['refund_count']);
        $sheet->setCellValue('L'.$row, Taka::numeric($totals['refunded_paisa']));
        $sheet->setCellValue('M'.$row, Taka::numeric($totals['net_paisa']));

        $sheet->getStyle('A'.$row.':M'.$row)->applyFromArray([
            'font' => ['bold' => true],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $this->applyColumnFormatting($sheet, $row);
    }

    private function applyColumnFormatting(Worksheet $sheet, int $lastRow): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        foreach (self::MONEY_COLUMNS as $column) {
            $sheet->getStyle($column.'1:'.$column.$lastRow)
                ->getNumberFormat()
                ->setFormatCode(self::MONEY_FORMAT);
        }
    }

    /**
     * A second sheet, because a method breakdown is a different grain from a
     * day grid — pivoting them into one table would leave most cells empty.
     *
     * @param  array{methods: list<array<string, mixed>>, ...}  $report
     */
    private function writeMethodsSheet(Spreadsheet $spreadsheet, array $report): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('By method');

        foreach (['Method', 'Channel', 'Online / offline', 'Payments', 'Amount'] as $i => $heading) {
            $sheet->setCellValue([$i + 1, 1], $heading);
        }

        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EDE9FE']],
        ]);

        $row = 2;

        foreach ($report['methods'] as $method) {
            $sheet->setCellValue('A'.$row, (string) $method['method']);
            $sheet->setCellValue('B'.$row, (string) $method['channel']);
            $sheet->setCellValue('C'.$row, (string) $method['kind']);
            $sheet->setCellValue('D'.$row, (int) $method['payments']);
            $sheet->setCellValue('E'.$row, Taka::numeric((int) $method['paisa']));
            $row++;
        }

        foreach (['A' => 16.0, 'B' => 12.0, 'C' => 16.0, 'D' => 11.0, 'E' => 15.0] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getStyle('E1:E'.max(1, $row - 1))->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
    }
}
