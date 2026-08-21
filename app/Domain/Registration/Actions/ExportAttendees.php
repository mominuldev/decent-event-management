<?php

namespace App\Domain\Registration\Actions;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Services\AttendeePdfExportWriter;
use App\Domain\Registration\Services\AttendeeXlsxExportWriter;
use App\Domain\Registration\Support\AttendeeListFilters;
use App\Domain\Reporting\Exceptions\ExportTooLargeException;
use App\Domain\Reporting\Support\ExportedFile;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the attendee roster export — the same rows the admin list shows for
 * a given filter set, as a spreadsheet or a PDF, each carrying the attendee's
 * profile photo alongside their name, father's name, address, occupation,
 * organization and mobile number.
 *
 * Two things this class is responsible for that the writers are not:
 *
 *  - **Refusing an export that is too big before starting it** — see
 *    {@see ExportTooLargeException}.
 *  - **The audit trail.** An export lifts every matching attendee's contact
 *    details out of the system in one file, which CLAUDE.md names explicitly
 *    among the actions that must reach `activity_logs`. It is written here in
 *    the action rather than in the controller (D8 discipline) so a console
 *    command or a future queued export cannot silently skip it. The log
 *    records the filters and the row count, not the rows — the point is to
 *    know who took what, not to duplicate the payload into the audit table.
 */
class ExportAttendees
{
    public function __construct(
        private readonly AttendeeXlsxExportWriter $xlsxWriter,
        private readonly AttendeePdfExportWriter $pdfWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  search / participant_type / ssc_batch_year
     * @param  'xlsx'|'pdf'  $format
     *
     * @throws ExportTooLargeException
     */
    public function execute(
        array $filters,
        string $format,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): ExportedFile {
        $maxRows = (int) config("exports.attendees.max_rows.{$format}");
        $photoPx = (int) config("exports.attendees.photo_px.{$format}");

        $rowCount = AttendeeListFilters::apply(Attendee::query(), $filters)->count();

        if ($maxRows > 0 && $rowCount > $maxRows) {
            throw new ExportTooLargeException($rowCount, $maxRows, $format);
        }

        $filename = $this->filename($format);

        $file = match ($format) {
            'pdf' => $this->pdfWriter->write($this->chunks($filters), $filename, $photoPx, $this->describeFilters($filters)),
            default => $this->xlsxWriter->write($this->chunks($filters), $filename, $photoPx),
        };

        $this->log($format, $filters, $rowCount, $actor, $ipAddress, $requestId);

        return $file;
    }

    /**
     * Attendees in export order, a page at a time.
     *
     * `chunk()` rather than `get()` keeps the Eloquent result set bounded even
     * though the document being built is not — and it is safe here in a way it
     * would not be on a mutating walk, because nothing in an export writes to
     * the rows it is reading, so no row can shift out from under the offset.
     *
     * @param  array<string, mixed>  $filters
     * @return Generator<int, Collection<int, Attendee>>
     */
    private function chunks(array $filters): Generator
    {
        $query = AttendeeListFilters::apply(
            Attendee::query()->with(['profilePhoto.thumbnail']),
            $filters,
        );

        $size = max(1, (int) config('exports.attendees.chunk_size'));
        $page = 1;

        do {
            /** @var Collection<int, Attendee> $chunk */
            $chunk = $query->forPage($page, $size)->get();

            if ($chunk->isNotEmpty()) {
                yield $chunk;
            }

            $page++;
        } while ($chunk->count() === $size);
    }

    private function filename(string $format): string
    {
        return 'attendees-'.now()->format('Y-m-d-His').'.'.$format;
    }

    /**
     * The filters, as the operator set them, for the PDF's header line.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function describeFilters(array $filters): array
    {
        $labels = [
            'search' => 'Search',
            'participant_type' => 'Participant type',
            'ssc_batch_year' => 'Batch year',
        ];

        $described = [];

        foreach ($labels as $key => $label) {
            $value = $filters[$key] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $described[$label] = $key === 'participant_type'
                ? Str::headline((string) $value)
                : trim((string) $value);
        }

        return $described;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function log(
        string $format,
        array $filters,
        int $rowCount,
        ?User $actor,
        ?string $ipAddress,
        ?string $requestId,
    ): void {
        ActivityLog::create([
            'log_name' => 'attendee',
            'event' => 'exported',
            'description' => "Attendee list exported as {$format} ({$rowCount} rows)",
            'causer_type' => $actor?->getMorphClass(),
            'causer_id' => $actor?->id,
            'subject_type' => null,
            'subject_id' => null,
            'properties' => [
                'format' => $format,
                'row_count' => $rowCount,
                'filters' => $this->describeFilters($filters),
            ],
            'ip_address' => $ipAddress,
            'request_id' => substr($requestId ?? (string) Str::ulid(), 0, 26),
        ]);
    }
}
