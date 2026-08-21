<?php

namespace Tests\Feature\Admin;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Tests\TestCase;

/**
 * The attendee roster export — .xlsx and .pdf, both carrying the profile
 * photo alongside the seven fields the admin console lists.
 *
 * The spreadsheet assertions deliberately read the generated file back with
 * PhpSpreadsheet rather than checking the response is non-empty: a workbook
 * that downloads but has the mobile number coerced to a float, or the photo
 * silently dropped, is exactly the failure this export exists to avoid and it
 * is invisible to a status-code assertion.
 */
class AttendeeExportTest extends TestCase
{
    use RefreshDatabase;

    private User $eventManager;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(RbacSeeder::class);

        $this->eventManager = User::factory()->create(['status' => 'active']);
        $this->eventManager->assignRole('Event Manager');
    }

    private function actingAsEventManager(): void
    {
        Sanctum::actingAs($this->eventManager, ['*'], 'web-admin');
    }

    /** An attendee with all seven exported fields populated, and a real photo on disk. */
    private function attendeeWithPhoto(array $attributes = [], bool $noisy = false): Attendee
    {
        $png = $this->pngBytes(300, 300, $noisy);
        $path = 'profile_photo/'.Str::lower((string) Str::ulid()).'.png';

        Storage::disk('local')->put($path, $png);

        $media = MediaFile::create([
            'collection' => 'profile_photo',
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'photo.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => strlen($png),
            'checksum_sha256' => hash('sha256', $png),
            'width' => 300,
            'height' => 300,
            'is_public' => false,
            'scan_status' => 'clean',
            'uploaded_by_type' => 'system',
        ]);

        return Attendee::factory()->create(array_merge([
            'full_name' => 'Rahim Uddin',
            // The factory always fills this from a Bangla name pool, and the
            // PDF directory prefers it over the Latin name. Cleared by default
            // so the fixtures exercise the English fallback; the tests that
            // care about the Bangla name set it explicitly.
            'full_name_bn' => null,
            'father_name' => 'Abdul Karim',
            'current_address' => 'House 12, Road 4, Dhanmondi, Dhaka',
            'occupation' => 'Engineer',
            'organization' => 'Grameenphone Ltd',
            // A leading zero is the case a spreadsheet silently destroys if the
            // cell is written as a number rather than an explicit string.
            'mobile' => '01711223344',
            'profile_photo_media_id' => $media->id,
        ], $attributes));
    }

    private function pngBytes(int $width, int $height, bool $noisy = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 90, 200));

        if ($noisy) {
            // Incompressible pixels, so the encoded PNG is the size a real
            // photograph is rather than the few hundred bytes a flat fill
            // collapses to.
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, ($x * 7 + $y * 13) % 256, ($x * 29 + $y * 3) % 256, ($x * 17 + $y * 91) % 256));
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /** Reads a downloaded workbook back into a [cellValue] grid. */
    private function readWorkbook(string $binary): array
    {
        $file = tempnam(sys_get_temp_dir(), 'export-test-').'.xlsx';
        file_put_contents($file, $binary);

        try {
            $spreadsheet = (new XlsxReader)->load($file);
            $sheet = $spreadsheet->getActiveSheet();

            $rows = $sheet->toArray(null, true, false, false);
            $drawingCount = count($sheet->getDrawingCollection());

            $spreadsheet->disconnectWorksheets();

            return ['rows' => $rows, 'drawings' => $drawingCount];
        } finally {
            @unlink($file);
        }
    }

    // === Authorization ===

    public function test_export_requires_the_attendee_export_permission(): void
    {
        $withoutPermission = User::factory()->create(['status' => 'active']);
        $withoutPermission->assignRole('Volunteer');

        Sanctum::actingAs($withoutPermission, ['*'], 'web-admin');

        $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->assertStatus(403);
    }

    public function test_export_is_unavailable_to_an_unauthenticated_caller(): void
    {
        $this->getJson(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->assertStatus(401);
    }

    /**
     * attendee.view_any is not enough. Listing shows a page at a time behind
     * an audited session; exporting takes every matching row's contact details
     * out of the system in one file, which is why it is its own permission.
     */
    public function test_view_any_alone_does_not_grant_export(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('attendee.view_any');

        Sanctum::actingAs($user, ['*'], 'web-admin');

        $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->assertStatus(403);
    }

    // === Spreadsheet ===

    public function test_xlsx_export_contains_every_requested_field_and_the_photo(): void
    {
        $this->attendeeWithPhoto();
        $this->actingAsEventManager();

        $response = $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment; filename="attendees-', $response->headers->get('content-disposition') ?? '');
        $this->assertStringEndsWith('.xlsx"', $response->headers->get('content-disposition') ?? '');
        // Personal data assembled per request — no shared cache may hold it.
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control') ?? '');

        $workbook = $this->readWorkbook($response->getContent());

        $this->assertSame(
            ['Photo', 'Name', "Father's name", 'Address', 'Occupation', 'Organization', 'Mobile'],
            $workbook['rows'][0],
        );

        $this->assertSame(
            [null, 'Rahim Uddin', 'Abdul Karim', 'House 12, Road 4, Dhanmondi, Dhaka', 'Engineer', 'Grameenphone Ltd', '01711223344'],
            $workbook['rows'][1],
        );

        $this->assertSame(1, $workbook['drawings'], 'the profile photo must be embedded, not linked');
    }

    /**
     * The single most damaging silent failure in a spreadsheet export: a
     * mobile number coerced to a number loses its leading zero, and every
     * downstream use of the file dials the wrong number.
     */
    public function test_mobile_number_keeps_its_leading_zero(): void
    {
        $this->attendeeWithPhoto(['mobile' => '01911002200']);
        $this->actingAsEventManager();

        $workbook = $this->readWorkbook(
            $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->getContent()
        );

        $this->assertSame('01911002200', $workbook['rows'][1][6]);
        $this->assertIsString($workbook['rows'][1][6]);
    }

    /**
     * A photo whose blob has gone gets the placeholder, not a hole. Losing the
     * file must not also lose the row, and an empty image cell is
     * indistinguishable from "the export failed to load its pictures".
     */
    public function test_a_missing_photo_file_falls_back_to_the_placeholder(): void
    {
        $attendee = $this->attendeeWithPhoto();
        Storage::disk('local')->delete($attendee->profilePhoto->path);

        $this->actingAsEventManager();

        $workbook = $this->readWorkbook(
            $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->getContent()
        );

        $this->assertSame('Rahim Uddin', $workbook['rows'][1][1]);
        $this->assertSame(1, $workbook['drawings'], 'the placeholder silhouette must stand in for the lost file');
    }

    public function test_an_attendee_with_no_photo_at_all_gets_the_placeholder(): void
    {
        Attendee::factory()->create(['full_name' => 'Nazmul Hasan', 'full_name_bn' => null, 'profile_photo_media_id' => null]);

        $this->actingAsEventManager();

        $workbook = $this->readWorkbook(
            $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->getContent()
        );

        $this->assertSame('Nazmul Hasan', $workbook['rows'][1][1]);
        $this->assertSame(1, $workbook['drawings']);
    }

    /** Bangla must survive as real UTF-8 — the .xlsx has no text-layer caveat. */
    public function test_bangla_field_values_round_trip_exactly(): void
    {
        $this->attendeeWithPhoto([
            'full_name' => 'Rahim Uddin',
            'father_name' => 'আব্দুল করিম',
            'current_address' => 'ধানমন্ডি, ঢাকা',
        ]);

        $this->actingAsEventManager();

        $workbook = $this->readWorkbook(
            $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->getContent()
        );

        $this->assertSame('আব্দুল করিম', $workbook['rows'][1][2]);
        $this->assertSame('ধানমন্ডি, ঢাকা', $workbook['rows'][1][3]);
    }

    /**
     * The embedded image has to fit inside the cell it is anchored to.
     * Excel does not clip an oversized drawing, it floats it over the
     * neighbouring column — so a photo column that is too narrow silently
     * covers the name beside it, and nothing about the file being valid says
     * so. Column A was originally width 12 (89px) against a 96px image, which
     * is exactly that bug.
     */
    public function test_the_embedded_photo_fits_inside_its_cell(): void
    {
        $this->attendeeWithPhoto();
        $this->actingAsEventManager();

        $binary = $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->getContent();

        $file = tempnam(sys_get_temp_dir(), 'export-geom-').'.xlsx';
        file_put_contents($file, $binary);

        try {
            $sheet = (new XlsxReader)->load($file)->getActiveSheet();
            $drawing = $sheet->getDrawingCollection()[0];

            // Excel's own unit conversions: a column's character width and a
            // row's point height both have to be turned into pixels first.
            $columnPx = round($sheet->getColumnDimension('A')->getWidth() * 7) + 5;
            $rowPx = $sheet->getRowDimension(2)->getRowHeight() * 96 / 72;

            $this->assertLessThanOrEqual(
                $columnPx,
                $drawing->getWidth() + $drawing->getOffsetX(),
                'the photo overflows column A and will float over the name column',
            );
            $this->assertLessThanOrEqual(
                $rowPx,
                $drawing->getHeight() + $drawing->getOffsetY(),
                'the photo is taller than its row and will overlap the row below',
            );
        } finally {
            @unlink($file);
        }
    }

    // === Filters ===

    public function test_export_applies_the_same_filters_as_the_list(): void
    {
        Attendee::factory()->create(['full_name' => 'Teacher Person', 'participant_type' => 'teacher']);
        Attendee::factory()->create(['full_name' => 'Guardian Person', 'participant_type' => 'guardian']);

        $this->actingAsEventManager();

        $workbook = $this->readWorkbook(
            $this->get(route('api.v1.admin.attendees.export', [
                'format' => 'xlsx',
                'participant_type' => 'teacher',
            ]))->getContent()
        );

        $names = array_column(array_slice($workbook['rows'], 1), 1);

        $this->assertSame(['Teacher Person'], $names);
    }

    /**
     * The export takes its order from the list, both by default and when the
     * operator has re-sorted the screen — the point of sharing
     * AttendeeListFilters is that the two cannot disagree.
     *
     * The default is newest-first, not alphabetical: the operator is looking
     * at the most recent registrations, and a file that silently reordered
     * them would describe a different roster from the one on screen.
     */
    public function test_export_rows_follow_the_same_order_as_the_list(): void
    {
        Attendee::factory()->create(['full_name' => 'Zahir Ahmed', 'created_at' => now()->subDays(3)]);
        Attendee::factory()->create(['full_name' => 'Anwar Hossain', 'created_at' => now()->subDays(2)]);
        Attendee::factory()->create(['full_name' => 'Munir Chowdhury', 'created_at' => now()->subDay()]);

        $this->actingAsEventManager();

        $default = $this->readWorkbook(
            $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->getContent()
        );

        $this->assertSame(
            ['Munir Chowdhury', 'Anwar Hossain', 'Zahir Ahmed'],
            array_column(array_slice($default['rows'], 1), 1),
            'The export should default to newest first, exactly as the list does.',
        );

        $byName = $this->readWorkbook(
            $this->get(route('api.v1.admin.attendees.export', [
                'format' => 'xlsx',
                'sort' => 'full_name',
                'direction' => 'asc',
            ]))->getContent()
        );

        $this->assertSame(
            ['Anwar Hossain', 'Munir Chowdhury', 'Zahir Ahmed'],
            array_column(array_slice($byName['rows'], 1), 1),
            'A re-sorted screen should produce a re-sorted file.',
        );
    }

    public function test_an_unknown_participant_type_is_a_validation_error(): void
    {
        $this->actingAsEventManager();

        $this->getJson(route('api.v1.admin.attendees.export', [
            'format' => 'xlsx',
            'participant_type' => 'not_a_type',
        ]))->assertStatus(422)->assertJsonValidationErrors('participant_type');
    }

    public function test_an_unknown_format_is_a_validation_error(): void
    {
        $this->actingAsEventManager();

        $this->getJson(route('api.v1.admin.attendees.export', ['format' => 'csv']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    /**
     * Every participant type the system stores must be exportable. The list
     * validated here used to be narrower on the admin side than in
     * StoreRegistrationRequest, which would have made sponsor/guest rows
     * unfilterable — the shared constant is what keeps the two in step.
     */
    public function test_every_participant_type_is_an_accepted_filter(): void
    {
        $this->actingAsEventManager();

        foreach (['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'guest', 'sponsor', 'other'] as $type) {
            $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx', 'participant_type' => $type]))
                ->assertStatus(200);
        }
    }

    // === PDF ===

    public function test_pdf_export_returns_a_real_pdf(): void
    {
        $this->attendeeWithPhoto();
        $this->actingAsEventManager();

        $response = $this->get(route('api.v1.admin.attendees.export', ['format' => 'pdf']));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');

        $binary = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $binary);
        $this->assertStringContainsString('%%EOF', $binary);
        $this->assertGreaterThan(2000, strlen($binary), 'a PDF this small cannot contain a table and a photo');
    }

    /**
     * The photo has to actually reach the page. Asserting only that a PDF came
     * back would have passed just as happily while the images were being
     * dropped, which is exactly what a `data:` URI does at scale: mpdf parses
     * the document with PCRE and throws once the HTML exceeds
     * pcre.backtrack_limit, so the writer references each photo by temp-file
     * path instead. `pdfimages -list` is the only check that distinguishes
     * "rendered" from "silently skipped".
     */
    public function test_pdf_export_really_embeds_the_profile_photos(): void
    {
        if (trim((string) shell_exec('command -v pdfimages')) === '') {
            $this->markTestSkipped('pdfimages (poppler-utils) is not installed in this environment.');
        }

        $this->attendeeWithPhoto();
        Attendee::factory()->create(['full_name' => 'No Photo Person', 'full_name_bn' => null, 'profile_photo_media_id' => null]);

        $this->actingAsEventManager();

        $binary = $this->get(route('api.v1.admin.attendees.export', ['format' => 'pdf']))->getContent();

        $path = tempnam(sys_get_temp_dir(), 'export-pdf-').'.pdf';
        file_put_contents($path, $binary);

        try {
            $listing = (string) shell_exec('pdfimages -list '.escapeshellarg($path).' 2>&1');
            $text = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }

        // Two entries, two portraits: the real photo and the placeholder
        // silhouette standing in for the attendee who has none. Every card in
        // the directory carries an image, which is what keeps the grid square.
        $imageRows = array_values(array_filter(
            preg_split('/\R/', trim($listing)) ?: [],
            fn (string $line): bool => (bool) preg_match('/^\s*\d+\s+\d+\s+image/', $line),
        ));

        $this->assertCount(2, $imageRows, "expected a portrait for each of the two entries, got:\n{$listing}");

        // The portrait is centre-cropped to 3:4, so both should be taller than
        // they are wide however the source was shaped (the fixture is square).
        foreach ($imageRows as $row) {
            preg_match('/^\s*\d+\s+\d+\s+image\s+(\d+)\s+(\d+)/', $row, $m);
            $this->assertGreaterThan((int) $m[1], (int) $m[2], "portrait should be 3:4, got {$m[1]}x{$m[2]}");
        }

        $this->assertStringContainsString('Rahim Uddin', $text);
        $this->assertStringContainsString('No Photo Person', $text);
        $this->assertStringContainsString('Grameenphone Ltd', $text);
        $this->assertStringContainsString('01711223344', $text);
    }

    /**
     * Guards the failure mode that only appears at scale.
     *
     * mpdf parses the whole document with PCRE and throws
     * "The HTML code size is larger than pcre.backtrack_limit" once the string
     * passed to WriteHTML() exceeds 1,000,000 bytes. Inlining photos as
     * `data:` URIs blew that ceiling at roughly 180 rows — well inside the
     * configured PDF export limit — so every small-fixture test passed while
     * a real export failed. The photos here are noise rather than flat colour
     * on purpose: a solid-colour PNG compresses to almost nothing and would
     * make this test pass against the broken implementation.
     */
    public function test_pdf_export_survives_enough_rows_to_exceed_the_pcre_backtrack_limit(): void
    {
        $rows = 150;

        for ($i = 0; $i < $rows; $i++) {
            $this->attendeeWithPhoto([
                'full_name' => "Attendee {$i}",
                'mobile' => '+88019'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'email' => "bulk{$i}@example.test",
            ], noisy: true);
        }

        $this->actingAsEventManager();

        $response = $this->get(route('api.v1.admin.attendees.export', ['format' => 'pdf']));

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /**
     * The directory is a Bangla document: the labels are Bangla, and the
     * attendee's own Bangla name takes precedence over the Latin one. None of
     * it may be bold — FreeSerifBold has no Bengali glyphs, so bold Bangla
     * disappears from the page rather than merely losing its weight, which is
     * a failure that leaves a perfectly valid PDF with blank fields.
     */
    public function test_pdf_directory_uses_bangla_labels_and_prefers_the_bangla_name(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed in this environment.');
        }

        $this->attendeeWithPhoto([
            'full_name' => 'Rahim Uddin',
            'full_name_bn' => 'রহিম উদ্দিন',
        ]);

        $this->actingAsEventManager();

        $path = tempnam(sys_get_temp_dir(), 'export-pdf-').'.pdf';
        file_put_contents($path, $this->get(route('api.v1.admin.attendees.export', ['format' => 'pdf']))->getContent());

        try {
            $text = (string) shell_exec('pdftotext -enc UTF-8 '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }

        // Every label, including the ones carrying a conjunct (বর্তমান) and a
        // pre-base vowel sign (পেশা, ফোন, পিতার). This assertion used to be
        // limited to the two labels with neither construct, because mpdf
        // mapped conjuncts to private-use codepoints and reordered pre-base
        // vowels — asserting the mangled bytes would have pinned the bug in
        // place. Rendering moved to headless Chrome, so the full set round
        // trips and is asserted.
        //
        // Whitespace is collapsed first: pdftotext inserts word breaks from
        // glyph advances, which splits a Bengali word without losing any of
        // it. That is an extractor heuristic, not a defect in the PDF.
        $flatText = preg_replace('/\s+/u', '', $text) ?? '';

        foreach (['নাম', 'পিতারনাম', 'পেশা', 'পদবীসহবর্তমানঠিকানা', 'ফোন/মোবাইল'] as $label) {
            $this->assertStringContainsString(
                preg_replace('/\s+/u', '', $label) ?? '',
                $flatText,
                "the [{$label}] label is missing from the directory"
            );
        }

        // The Bangla name won over the Latin one, and the Latin one is gone —
        // if both appeared the card would be showing the same person twice.
        $this->assertStringNotContainsString('Rahim Uddin', $text);
    }

    /**
     * "পদবীসহ বর্তমান ঠিকানা" is address *including* position, so designation
     * and organization run into the address on one line rather than becoming
     * three separate labelled lines that would not fit a one-third-page card.
     */
    public function test_pdf_directory_folds_designation_and_organization_into_the_address_line(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed in this environment.');
        }

        $this->attendeeWithPhoto([
            'designation' => 'Head Teacher',
            'organization' => 'Baraipara High School',
            'current_address' => 'Pirgachi, Alampur, Bholahat',
        ]);

        $this->actingAsEventManager();

        $path = tempnam(sys_get_temp_dir(), 'export-pdf-').'.pdf';
        file_put_contents($path, $this->get(route('api.v1.admin.attendees.export', ['format' => 'pdf']))->getContent());

        try {
            $text = (string) shell_exec('pdftotext -enc UTF-8 -layout '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }

        // Whitespace is collapsed before matching: the address is folded into
        // one logical line, but a one-third-page column is narrow enough that
        // it wraps mid-phrase on the page. Where the line breaks is a layout
        // detail; that the three parts run together as one field is the
        // behaviour under test.
        $flat = preg_replace('/\s+/u', ' ', $text) ?? '';

        foreach (['Head Teacher', 'Baraipara High School', 'Pirgachi, Alampur, Bholahat'] as $part) {
            $this->assertStringContainsString($part, $flat);
        }

        // And they are one field, in order, not three separate labelled lines.
        $this->assertMatchesRegularExpression(
            '/Head Teacher,\s*Baraipara High School,\s*Pirgachi, Alampur, Bholahat/u',
            $flat
        );
    }

    /** Three entries per row, so a fourth starts a new row rather than a fourth column. */
    public function test_pdf_directory_lays_out_three_entries_per_row(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed in this environment.');
        }

        foreach (['Aaa Person', 'Bbb Person', 'Ccc Person', 'Ddd Person'] as $i => $name) {
            $this->attendeeWithPhoto([
                'full_name' => $name,
                'mobile' => '+88017000000'.$i,
                'email' => "row{$i}@example.test",
            ]);
        }

        $this->actingAsEventManager();

        $path = tempnam(sys_get_temp_dir(), 'export-pdf-').'.pdf';
        // Sorted explicitly rather than relying on the default order: this
        // test is about the grid being three wide, and naming the order it
        // needs keeps it from breaking every time the default changes.
        file_put_contents($path, $this->get(route('api.v1.admin.attendees.export', [
            'format' => 'pdf',
            'sort' => 'full_name',
            'direction' => 'asc',
        ]))->getContent());

        try {
            $text = (string) shell_exec('pdftotext -enc UTF-8 -layout '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }

        $lines = preg_split('/\R/', $text) ?: [];

        $firstRow = array_values(array_filter(
            $lines,
            fn (string $line): bool => str_contains($line, 'Aaa Person'),
        ));

        $this->assertNotEmpty($firstRow, 'the first entry is missing from the directory');

        // -layout preserves columns, so the three cards of row one share a
        // line and the fourth does not.
        $this->assertStringContainsString('Bbb Person', $firstRow[0]);
        $this->assertStringContainsString('Ccc Person', $firstRow[0]);
        $this->assertStringNotContainsString('Ddd Person', $firstRow[0]);
    }

    public function test_pdf_export_of_an_empty_result_set_still_produces_a_document(): void
    {
        $this->actingAsEventManager();

        $response = $this->get(route('api.v1.admin.attendees.export', [
            'format' => 'pdf',
            'search' => 'nobody-matches-this',
        ]));

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    // === Row ceiling ===

    public function test_an_export_past_the_row_ceiling_is_refused_with_a_422(): void
    {
        Attendee::factory()->count(3)->create();

        config()->set('exports.attendees.max_rows.xlsx', 2);

        $this->actingAsEventManager();

        $this->getJson(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))
            ->assertStatus(422)
            ->assertJsonPath('code', 'export_too_large')
            ->assertJsonFragment(['message' => 'This export would contain 3 rows, which is more than the 2 the XLSX format can build in one request. Narrow the filters and try again.']);
    }

    /**
     * The ceiling counts the *filtered* rows, not the table — otherwise a
     * large database would make every export impossible however narrowly the
     * operator searched.
     */
    public function test_the_ceiling_counts_filtered_rows_not_the_whole_table(): void
    {
        Attendee::factory()->count(5)->create(['participant_type' => 'guardian']);
        Attendee::factory()->create(['full_name' => 'Only Teacher', 'participant_type' => 'teacher']);

        config()->set('exports.attendees.max_rows.xlsx', 2);

        $this->actingAsEventManager();

        $this->get(route('api.v1.admin.attendees.export', [
            'format' => 'xlsx',
            'participant_type' => 'teacher',
        ]))->assertStatus(200);
    }

    public function test_a_refused_export_is_not_recorded_as_a_completed_one(): void
    {
        Attendee::factory()->count(3)->create();
        config()->set('exports.attendees.max_rows.xlsx', 1);

        $this->actingAsEventManager();

        $this->getJson(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->assertStatus(422);

        $this->assertSame(0, ActivityLog::where('log_name', 'attendee')->where('event', 'exported')->count());
    }

    // === Audit trail ===

    public function test_a_completed_export_is_written_to_the_audit_trail(): void
    {
        Attendee::factory()->count(2)->create(['participant_type' => 'teacher']);

        $this->actingAsEventManager();

        $this->get(route('api.v1.admin.attendees.export', [
            'format' => 'xlsx',
            'participant_type' => 'teacher',
        ]))->assertStatus(200);

        $log = ActivityLog::where('log_name', 'attendee')->where('event', 'exported')->sole();

        $this->assertSame($this->eventManager->id, $log->causer_id);
        $this->assertSame('xlsx', $log->properties['format']);
        $this->assertSame(2, $log->properties['row_count']);
        $this->assertSame(['Participant type' => 'Teacher'], $log->properties['filters']);

        // The filters are recorded, the rows are not — the audit trail must
        // say who took what, not duplicate the personal data it describes.
        $this->assertArrayNotHasKey('rows', $log->properties);
    }
}
