<?php

namespace App\Domain\Registration\Services;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Reporting\Support\ExportedFile;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Services\HtmlToPdfRenderer;
use App\Domain\Ticketing\Services\GenerateTicketPdf;
use Illuminate\Support\Collection;

/**
 * The PDF half of the attendee export: a printed alumni directory, three
 * entries across, each a bordered portrait beside a block of Bangla-labelled
 * details — নাম / পিতার নাম / পেশা / পদবীসহ বর্তমান ঠিকানা / ফোন।
 *
 * This is a *directory*, not a table dump, and the difference drives the
 * layout: it is meant to be printed, bound and read by a person looking
 * someone up, so each entry is a self-contained card rather than a row whose
 * meaning depends on a heading several pages back.
 *
 * Rendering is headless Chrome via {@see HtmlToPdfRenderer}, moved off mpdf
 * along with {@see GenerateTicketPdf}. Three mpdf-shaped constraints that
 * used to govern this file are gone with it:
 *
 *  1. **Bold is allowed again.** mpdf's bundled FreeSerifBold.ttf has no
 *     Bengali coverage at all, so bold Bangla vanished from the page rather
 *     than degrading; every label here had to be built out of size and colour
 *     alone. The bundled Noto Sans Bengali is a variable font carrying every
 *     weight.
 *  2. **The extractable text is correct.** mpdf wrote private-use codepoints
 *     into the ToUnicode map for conjuncts, so a name like "উদ্দিন" came out
 *     of the text layer with characters missing. This document is now as
 *     machine-readable as the .xlsx.
 *  3. **No chunking.** mpdf parsed each WriteHTML() call with PCRE and threw
 *     past pcre.backtrack_limit, so the body had to be fed in 20-row slices.
 *     Chrome parses the document once.
 *
 * What the move cost: per-page "Page N of M" numbering. Chrome only resolves
 * CSS page counters inside `@page` margin boxes, which it does not implement,
 * and its CLI cannot supply the footer template its DevTools API can. The
 * repeating footer therefore carries the document's identity instead of a
 * page number. Numbering it properly means driving Chrome over CDP rather
 * than `--print-to-pdf`; it was not worth that machinery here, but that is
 * the route if it is ever wanted.
 */
class AttendeePdfExportWriter
{
    public const MIME_TYPE = 'application/pdf';

    /** Entries per row, per the directory layout this export reproduces. */
    private const COLUMNS = 3;

    /**
     * Portrait proportions (width ÷ height) for the photo. 3:4 is the shape a
     * passport/ID photograph is taken and printed at, which is what these
     * are; letting each photo keep its own aspect would give a ragged column
     * of differently-sized boxes down the page.
     */
    private const PHOTO_ASPECT = 3 / 4;

    public function __construct(
        private readonly AttendeeExportPhoto $photo,
        private readonly HtmlToPdfRenderer $renderer,
    ) {}

    /**
     * @param  iterable<int, Collection<int, Attendee>>  $chunks  attendee chunks, in export order
     * @param  array<string, string>  $appliedFilters  human-readable "Batch year: 1998" pairs, for the header
     */
    public function write(iterable $chunks, string $filename, int $photoPx, array $appliedFilters): ExportedFile
    {
        /** @var list<string> $tempFiles */
        $tempFiles = [];

        // Every photoless attendee shares one placeholder file rather than
        // getting an identical copy of its own — on a roster where most
        // records predate photo upload that is the difference between one
        // temp file and several thousand.
        $placeholderPath = null;

        try {
            /** @var list<array<string, string|null>> $entries */
            $entries = [];

            foreach ($chunks as $chunk) {
                foreach ($chunk as $attendee) {
                    $entries[] = [
                        'photo' => $this->photoFile($attendee, $photoPx, $tempFiles, $placeholderPath),
                        'name' => $this->name($attendee),
                        'father_name' => $attendee->father_name,
                        'occupation' => $attendee->occupation,
                        'address' => $this->address($attendee),
                        'mobile' => $attendee->mobile,
                    ];
                }
            }

            $html = view('exports.attendees', [
                'eventName' => (string) (EventSetting::where('key', 'event.name')->value('value') ?? 'Event'),
                'generatedAt' => now()->timezone(config('app.timezone'))->format('j M Y, g:i A'),
                'appliedFilters' => $appliedFilters,
                'total' => count($entries),
                'rows' => array_chunk($entries, self::COLUMNS),
                'columns' => self::COLUMNS,
                'cellWidth' => round(100 / self::COLUMNS, 4).'%',
                'fontFaceCss' => $this->renderer->fontFaceCss(),
                'title' => 'Attendee Directory',
                'pageSize' => 'A4 landscape',
                'pageMargin' => '12mm 10mm 14mm 10mm',
            ])->render();

            return new ExportedFile($filename, self::MIME_TYPE, $this->renderer->render($html));
        } finally {
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * The Bangla name when the record has one, the Latin name otherwise.
     *
     * Same precedence as the ticket PDF's holder name: this is a Bangla
     * directory, so `full_name_bn` is the primary identity and English is the
     * fallback for a record that never captured one. The English name is
     * still exported in full by the .xlsx, which is the machine-readable half
     * of this feature.
     */
    private function name(Attendee $attendee): string
    {
        $bangla = trim((string) $attendee->full_name_bn);

        return $bangla !== '' ? $bangla : (string) $attendee->full_name;
    }

    /**
     * "পদবীসহ বর্তমান ঠিকানা" — address *including* position, so designation
     * and organization lead into the address exactly as the printed reference
     * has them, rather than being three separate labelled lines that would
     * not fit a one-third-page card.
     */
    private function address(Attendee $attendee): ?string
    {
        $parts = array_values(array_filter(
            [$attendee->designation, $attendee->organization, $attendee->current_address],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        ));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Writes the entry's photo to a temp file and returns a `file://` URL for
     * `<img src>`, falling back to the generated placeholder so every card has
     * a portrait and the grid keeps its rhythm.
     *
     * Temp files rather than `data:` URIs: base64 costs several KB per entry
     * against ~250 bytes for a path, and at the configured export ceiling that
     * is megabytes of markup held in memory for no benefit. (Under mpdf it was
     * not merely wasteful but fatal — its PCRE-based parser threw past
     * pcre.backtrack_limit at roughly 180 entries.)
     *
     * @param  list<string>  $tempFiles
     */
    private function photoFile(Attendee $attendee, int $photoPx, array &$tempFiles, ?string &$placeholderPath): ?string
    {
        $png = $this->photo->render($attendee, $photoPx, self::PHOTO_ASPECT);

        if ($png === null) {
            return $placeholderPath ??= $this->writeTempFile(
                $this->photo->placeholder($photoPx, self::PHOTO_ASPECT),
                $tempFiles,
            );
        }

        return $this->writeTempFile($png, $tempFiles);
    }

    /**
     * @param  list<string>  $tempFiles
     */
    private function writeTempFile(string $png, array &$tempFiles): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'attendee-pdf-');

        if ($tempFile === false) {
            return null;
        }

        $tempFiles[] = $tempFile;

        if (file_put_contents($tempFile, $png) === false) {
            return null;
        }

        // Chrome needs a scheme; it sniffs the image type from the bytes, so
        // the missing extension on a tempnam() path is not a problem.
        return 'file://'.$tempFile;
    }
}
