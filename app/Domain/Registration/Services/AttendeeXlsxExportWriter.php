<?php

namespace App\Domain\Registration\Services;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Reporting\Support\ExportedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The .xlsx half of the attendee export: one row per attendee, with the
 * profile photo embedded in the first column rather than linked.
 *
 * A link would be the cheaper build and the wrong deliverable — the photo
 * lives on a private disk behind a 15-minute signed URL, so a column of URLs
 * is a column of dead links by the time anyone opens the file.
 *
 * Two decisions worth not re-litigating:
 *
 *  - **Images go through temp files, not MemoryDrawing.** PhpSpreadsheet's
 *    `Drawing` stores a path and only reads it when the writer zips the
 *    workbook, so a 5,000-row export holds 5,000 short strings in memory
 *    instead of 5,000 decoded bitmaps. `MemoryDrawing` would hold every GD
 *    handle open until save() and is what turns this export into an
 *    out-of-memory kill. The temp files are removed in a `finally`, whether
 *    the write succeeds or throws.
 *  - **Mobile numbers are written as explicit strings.** `+8801711223344`
 *    is a formula-looking value to a spreadsheet, and a leading `0` on a
 *    local-format number is silently eaten by numeric coercion — either way
 *    the operator gets a mangled phone number, which is the one field in
 *    this export most likely to be dialled straight from the sheet.
 */
class AttendeeXlsxExportWriter
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @var list<string> */
    private const HEADINGS = [
        'Photo',
        'Name',
        "Father's name",
        'Address',
        'Occupation',
        'Organization',
        'Mobile',
    ];

    /**
     * The photo cell's box, sized so the image sits inside it rather than
     * spilling over the name column. Both units are awkward and neither is
     * pixels, so the arithmetic is written down rather than tuned by eye:
     *
     *   width  → px = round(width × 7) + 5   (default Calibri 11 metrics)
     *   height → px = points × 96 / 72
     *
     * At the default 96px photo and a 4px inset that gives 100px of image to
     * fit: width 15 → 110px, height 78pt → 104px. Change the photo size in
     * config/exports.php and these two need re-deriving, which is what
     * AttendeeExportTest's geometry test is there to catch.
     */
    private const PHOTO_INSET_PX = 4;

    private const PHOTO_COLUMN_WIDTH = 15.0;

    private const PHOTO_ROW_HEIGHT = 78.0;

    /** @var array<string, float> */
    private const COLUMN_WIDTHS = [
        'B' => 28.0,
        'C' => 26.0,
        'D' => 40.0,
        'E' => 20.0,
        'F' => 26.0,
        'G' => 18.0,
    ];

    public function __construct(
        private readonly AttendeeExportPhoto $photo,
    ) {}

    /**
     * @param  iterable<int, Collection<int, Attendee>>  $chunks  attendee chunks, in export order
     */
    public function write(iterable $chunks, string $filename, int $photoPx): ExportedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Attendees');

        /** @var list<string> $tempFiles */
        $tempFiles = [];

        try {
            $this->writeHeadings($sheet);

            $row = 2;

            foreach ($chunks as $chunk) {
                foreach ($chunk as $attendee) {
                    $this->writeAttendee($sheet, $row, $attendee, $photoPx, $tempFiles);
                    $row++;
                }
            }

            $this->finishLayout($sheet, $row - 1);

            $writer = new Xlsx($spreadsheet);

            ob_start();
            $writer->save('php://output');
            $binary = (string) ob_get_clean();
        } finally {
            $spreadsheet->disconnectWorksheets();

            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }

        return new ExportedFile($filename, self::MIME_TYPE, $binary);
    }

    private function writeHeadings(Worksheet $sheet): void
    {
        foreach (self::HEADINGS as $index => $heading) {
            $sheet->setCellValue([$index + 1, 1], $heading);
        }

        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F2937']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getRowDimension(1)->setRowHeight(22);

        // So the headings stay visible while the operator scrolls — this is a
        // list people read, not a data feed.
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:G1');
    }

    /**
     * @param  list<string>  $tempFiles
     */
    private function writeAttendee(
        Worksheet $sheet,
        int $row,
        Attendee $attendee,
        int $photoPx,
        array &$tempFiles,
    ): void {
        $sheet->setCellValue([2, $row], (string) $attendee->full_name);
        $sheet->setCellValue([3, $row], (string) ($attendee->father_name ?? ''));
        $sheet->setCellValue([4, $row], (string) ($attendee->current_address ?? ''));
        $sheet->setCellValue([5, $row], (string) ($attendee->occupation ?? ''));
        $sheet->setCellValue([6, $row], (string) ($attendee->organization ?? ''));

        // Explicit string, not setCellValue — see the class docblock.
        $sheet->setCellValueExplicit([7, $row], (string) ($attendee->mobile ?? ''), DataType::TYPE_STRING);

        $sheet->getRowDimension($row)->setRowHeight(self::PHOTO_ROW_HEIGHT);

        // Falls back to the generated silhouette so the column reads as a
        // column of faces with gaps in it, rather than a column that looks
        // like the images failed to load.
        $png = $this->photo->render($attendee, $photoPx) ?? $this->photo->placeholder($photoPx);

        $tempFile = tempnam(sys_get_temp_dir(), 'attendee-export-');

        if ($tempFile === false || file_put_contents($tempFile, $png) === false) {
            if ($tempFile !== false) {
                $tempFiles[] = $tempFile;
            }

            return;
        }

        $tempFiles[] = $tempFile;

        $drawing = new Drawing;
        $drawing->setName('Photo');
        $drawing->setDescription((string) $attendee->full_name);
        $drawing->setPath($tempFile);
        $drawing->setCoordinates('A'.$row);
        $drawing->setOffsetX(self::PHOTO_INSET_PX);
        $drawing->setOffsetY(self::PHOTO_INSET_PX);
        $drawing->setHeight($photoPx);
        $drawing->setWorksheet($sheet);
    }

    private function finishLayout(Worksheet $sheet, int $lastRow): void
    {
        $sheet->getColumnDimension('A')->setWidth(self::PHOTO_COLUMN_WIDTH);

        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        if ($lastRow < 2) {
            return;
        }

        $sheet->getStyle("A2:G{$lastRow}")->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']],
            ],
        ]);
    }
}
